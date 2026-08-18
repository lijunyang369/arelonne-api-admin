<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CategoryService
{
    /**
     * 规范化 slug:空值按 name 生成小写 kebab-case;手填值同样走规范化后回写。
     */
    public function normalizeSlug(?string $slug, string $name): string
    {
        $source = $slug !== null && $slug !== '' ? $slug : $name;
        $normalized = strtolower(trim($source));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $normalized = trim($normalized, '-');
        return $normalized ?: strtolower(trim($name));
    }

    /**
     * 创建分类(事务 + 行锁:锁定候选父分类,防止并发建商品/建子分类竞态)。
     */
    public function create(array $data): Category
    {
        $slug = $this->normalizeSlug($data['slug'] ?? null, $data['name']);
        $this->assertSlugRules($slug);

        return DB::transaction(function () use ($data, $slug) {
            $parent = null;
            if (! empty($data['parent_id'])) {
                $parent = Category::whereKey($data['parent_id'])->lockForUpdate()->firstOrFail();
                $this->assertParentEligible($parent, null);
            }
            return Category::create([
                'name'      => $data['name'],
                'slug'      => $slug,
                'parent_id' => $parent?->id,
                'sort'      => $data['sort'] ?? 0,
                'status'    => $data['status'],
            ]);
        });
    }

    /**
     * 更新分类(行锁:目标与候选父按 id 升序加锁,避免死锁)。
     * slug 创建后锁定(UpdateCategoryRequest 已禁止提交),name 仅更新名称。
     */
    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            // 锁序:目标与候选父的 id 升序加锁(先小后大),并发交叉更新也不会死锁
            // 显式 parent_id:null 视为「升根」提交,须与「未提交」区分(array_key_exists)
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id;
            $lockIds = array_values(array_unique(array_filter(
                [$category->id, $parentId],
                fn ($id) => $id !== null,
            )));
            sort($lockIds, SORT_NUMERIC);

            $locked = [];
            foreach ($lockIds as $id) {
                $locked[$id] = Category::whereKey($id)->lockForUpdate()->firstOrFail();
            }

            $target = $locked[$category->id];
            $parent = $parentId !== null ? $locked[$parentId] : null;
            if ($parent !== null) {
                $this->assertParentEligible($parent, $target);
            }

            $target->update([
                'name'      => $data['name'] ?? $target->name,
                'parent_id' => $parent?->id,
                'sort'      => $data['sort'] ?? $target->sort,
                'status'    => $data['status'] ?? $target->status,
            ]);
            return $target;
        });
    }

    /**
     * 删除分类:事务内检查子分类与商品(含软删),通过后物理删除。
     */
    public function destroy(Category $category): void
    {
        DB::transaction(function () use ($category) {
            $target = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();

            if ($target->children()->exists()) {
                throw new UnprocessableEntityHttpException('该分类下有子分类,请先删除或移动子分类');
            }
            if (Product::withTrashed()->where('category_id', $target->id)->exists()) {
                throw new UnprocessableEntityHttpException('该分类下有商品,请先移出商品或停用分类');
            }

            $target->delete();
        });
    }

    /**
     * 商品关联校验:新建或变更分类时必须为 active 叶子;
     * 传入当前 category_id 且与目标一致时,允许保留(即使已停用)。
     */
    public function assertAssignableLeaf(?int $categoryId, ?int $currentCategoryId = null): void
    {
        if ($categoryId === null) {
            return;
        }
        if ($currentCategoryId !== null && $categoryId === $currentCategoryId) {
            return; // 未变更,允许保留
        }

        $category = Category::find($categoryId);
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => '分类不存在']);
        }
        if ($category->status !== 'active') {
            throw ValidationException::withMessages(['category_id' => '只能关联启用中的分类']);
        }
        if ($category->children()->exists()) {
            throw ValidationException::withMessages(['category_id' => '只能关联叶子分类']);
        }
    }

    /**
     * 候选父分类资格检查(两级不变量):
     * 1. 父必须是根(自身 parent_id 为 null)
     * 2. 父不能是目标自身(自环)
     * 3. 父不能有直属商品(含软删)——有商品的叶子不得成为父
     * 4. 目标已有子分类时不得设置非空父(禁止降级)
     */
    private function assertParentEligible(Category $parent, ?Category $target): void
    {
        if ($target !== null && $parent->id === $target->id) {
            throw ValidationException::withMessages(['parent_id' => '分类不能作为自己的父分类']);
        }
        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => '父分类必须是根分类(最多两级)']);
        }
        if (Product::withTrashed()->where('category_id', $parent->id)->exists()) {
            throw ValidationException::withMessages(['parent_id' => '该分类下有商品,不能成为父分类']);
        }
        if ($target !== null && $target->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => '有子分类的分类不能降级为子分类']);
        }
    }

    /**
     * slug 规则复核(保留字 + 唯一,规范化后校验)。
     */
    private function assertSlugRules(string $slug): void
    {
        if (in_array($slug, \App\Http\Requests\StoreCategoryRequest::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages(['slug' => "slug '{$slug}' 为保留值,不能使用"]);
        }
        if (Category::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => "slug '{$slug}' 已存在"]);
        }
    }
}
