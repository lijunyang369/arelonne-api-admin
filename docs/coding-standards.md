# HOLP 通用代码规范

## 1. 文件编码规范

### 1.1 统一编码

所有文件必须使用：

- `UTF-8`
- `UTF-8 without BOM`

适用范围：

- 源码文件（`.php`、`.ts`、`.tsx`、`.js`）
- 测试文件
- 样式文件（`.css`、`.scss`）
- 模板文件（`.blade.php`，如用到）
- 文档文件（`.md`）
- 配置文件（`.json`、`.env`、`.yaml`）

### 1.2 禁止编码污染

发现以下情况必须先修复再继续迭代：

- 中文乱码
- 同一文件混用多种编码
- 因错误编码导致的字符串异常

### 1.3 读写规则

- 写入文件时显式使用 UTF-8，不得依赖系统默认编码
- 读取文件时优先显式指定编码

---

## 2. 文件与模块规范

### 2.1 单文件单主题

每个文件必须只承载一个清晰主题或一类稳定职责。

禁止：

- 一个文件同时承担 API、状态机、数据库访问、业务编排
- 以 `utils.ts`、`common.ts`、`helpers.ts`、`misc.php` 承载长期逻辑

允许的例外：

- `lib/utils.ts` 仅放纯工具函数（格式化、校验），不放业务逻辑
- `app/Helpers/` 仅放跨模块共享的辅助函数，不放核心业务

### 2.2 入口文件只做装配

入口文件职责仅限：

- 初始化
- 注册依赖
- 装配组件
- 启动服务

禁止在入口文件堆复杂业务逻辑。

Next.js `page.tsx` 是页面入口，不是业务逻辑容器——业务逻辑下沉到 `lib/` 或 `components/`。

### 2.3 模块边界必须稳定

每个模块明确属于以下一种稳定角色：

```
存储层  → Eloquent Models / Database\Migrations
服务层  → app/Services/
编排层  → app/Actions/ (单动作类)
协议层  → app/Http/Requests/ (表单验证)
API 层  → app/Http/Controllers/ + app/Http/Resources/
展示层  → components/ + lib/ (Next.js)
```

禁止跨层写业务逻辑到 Controller。

---

## 3. 函数规范

### 3.1 单函数单动作

一个函数只做一件事。

禁止一个函数长期混合：

- 参数解析
- 权限判断
- 状态变更
- 远程调用
- 数据持久化
- 输出格式化

### 3.2 函数名必须表达动作

优先使用能直接表达动作的命名：

- `createProduct` / `updateOrderStatus` / `calculateCartTotal`
- `markAsPaid` / `sendConfirmationEmail`

避免模糊命名：

- `handle` / `process` / `doIt` / `runAll` / `manage`
- `handler` 仅在路由映射层允许（如 `ProductHandler` → route → controller）

### 3.3 控制流必须清晰

优先：

- 早返回（early return）
- 显式分支
- 小而清楚的 helper

避免：

- 多层嵌套（超过 3 层必须重构）
- 长链式条件判断
- 隐式副作用穿透多个 helper

### 3.4 函数必须有注释

所有正式函数必须带注释。

注释要求：

- **PHP**：使用 PHPDoc docstring
- **TypeScript/Next.js**：使用 JSDoc 注释
- 使用中文
- 结构化描述

PHP 模板：

```php
/**
 * 根据筛选条件查询商品列表并分页。
 *
 * @param  array  $filters  筛选条件（category_id, price_min, price_max, search）
 * @param  int    $perPage  每页条数，默认 20
 * @return \Illuminate\Pagination\LengthAwarePaginator
 */
public function listProducts(array $filters, int $perPage = 20): LengthAwarePaginator
{
    // ...
}
```

TypeScript 模板：

```typescript
/**
 * 根据购物车项和当前商品数据计算含税含运费的总价。
 *
 * @param cartItems 购物车项列表，每项包含 variantId 和 quantity
 * @param shippingCountry 配送国家（影响税率和运费规则）
 * @returns 总价、税费、运费和逐项明细
 */
export function calculateCartTotal(
  cartItems: CartItem[],
  shippingCountry: string
): CartTotal {
  // ...
}
```

禁止只写无信息量注释如：

- `处理数据`
- `执行逻辑`
- `这里做一些操作`

---

## 4. 类规范

### 4.1 类名必须表达角色

类必须有清晰职责身份。

PHP 例：`ProductController`、`OrderService`、`CalculateCartAction`
TypeScript 例：`ProductCard`、`CartProvider`、`useProductQuery`

### 4.2 避免上帝类

一个类不应同时掌握：状态机、存储、远程调用、UI 渲染、日志、工具执行。

Laravel 特别注意：**Controller 不能包含业务逻辑**。
Controller 只做：接收请求 → 验证参数 → 调用 Service/Action → 返回响应。

### 4.3 优先组合，谨慎继承

默认优先：

- 组合
- 显式依赖注入（Laravel DI / React props drilling 或 Context）

谨慎使用继承层级，避免抽象过深。

### 4.4 Laravel 单动作控制器

推荐对非资源类 API 使用 Invokable Controller：

```bash
php artisan make:controller CalculateCartController --invokable
```

```php
class CalculateCartController extends Controller
{
    public function __invoke(CalculateCartRequest $request): JsonResponse
    {
        // 单一职责：计算购物车价格
    }
}
```

传统资源 CRUD 使用标准 Resource Controller：

```php
Route::apiResource('products', ProductController::class);
```

---

## 5. API 设计规范

### 5.1 RESTful 原则

- 资源导向设计
- 路径使用名词，不用动词
- 路径使用复数形式
- HTTP 方法语义明确

```
✅ GET    /api/store/products
✅ POST   /api/store/orders
✅ PUT    /api/admin/products/{id}
✅ DELETE /api/admin/products/{id}

❌ POST   /api/store/createProduct
❌ GET    /api/store/getProductList
```

### 5.2 请求与响应

- 请求和响应统一使用 JSON
- 成功响应必须结构化：

```json
{
  "data": { ... },
  "meta": { "current_page": 1, "per_page": 20, "total": 60 }
}
```

- 错误响应必须结构化：

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

- 使用 Laravel API Resource 做数据转换层：

```php
class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
```

### 5.3 状态码

| 状态码 | 含义 |
|--------|------|
| 200 | 请求成功 |
| 201 | 创建成功 |
| 400 | 客户端参数错误 |
| 401 | 未认证 |
| 403 | 无权限 |
| 404 | 资源不存在 |
| 422 | 表单验证失败 |
| 500 | 服务端错误 |

---

## 6. Next.js / TypeScript 规范

### 6.1 组件规范

- 组件文件使用 PascalCase：`ProductCard.tsx`
- 每个组件文件只 export 一个主要组件
- 子组件放在同目录 `components/` 下，或与父组件同文件（< 50 行时）
- Server Component 优先，仅在需要交互时使用 `'use client'`

### 6.2 目录约定

```
src/
├── app/              # App Router 页面和布局（仅路由职责）
│   ├── (store)/      # 前台路由组
│   └── admin/        # 后台路由组
├── components/       # 共享 UI 组件
│   ├── ui/           # 原子 UI（Button, Input, Modal...）
│   ├── product/      # 商品相关组件
│   ├── cart/         # 购物车相关组件
│   └── layout/       # 布局组件（Header, Footer, Sidebar...）
├── lib/              # 业务逻辑、API 客户端、工具
│   ├── api/          # API 请求封装（按资源拆分）
│   ├── utils.ts      # 纯工具函数
│   └── validators.ts # 前端校验
└── hooks/            # 自定义 React Hooks
```

### 6.3 命名约定

- 组件文件/函数：PascalCase
- 工具函数/变量：camelCase
- 常量：UPPER_SNAKE_CASE 或 camelCase（文件作用域内）
- 类型/接口：PascalCase，接口不加 `I` 前缀

---

## 7. Laravel / PHP 规范

### 7.1 目录约定

```
api/
├── app/
│   ├── Actions/          # 单动作业务类（每个类一个动作）
│   ├── Enums/            # PHP 枚举
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Store/    # 前台 API 控制器
│   │   │   └── Admin/    # 后台 API 控制器
│   │   ├── Middleware/
│   │   ├── Requests/     # FormRequest 表单验证
│   │   └── Resources/    # API Resource 响应转换
│   ├── Models/           # Eloquent 模型
│   └── Services/         # 业务逻辑服务层
├── database/
│   └── migrations/       # 数据库迁移
├── routes/
│   └── api.php           # 所有 API 路由
└── tests/
```

### 7.2 控制器分层

```
前台（无需认证）:
  app/Http/Controllers/Store/
    ProductController.php     → GET /api/store/products
    CategoryController.php    → GET /api/store/categories
    CartController.php        → POST /api/store/cart/calculate
    OrderController.php       → POST /api/store/orders
    SettingController.php     → GET /api/store/settings

后台（JWT 认证）:
  app/Http/Controllers/Admin/
    AuthController.php        → POST /api/admin/login
    ProductController.php     → CRUD /api/admin/products
    OrderController.php       → GET/PUT /api/admin/orders
    SettingController.php     → GET/PUT /api/admin/settings
    ImportController.php      → POST /api/admin/products/batch-import
```

### 7.3 模型约定

- 表名：复数 snake_case（`products`、`order_items`）
- 模型类：单数 PascalCase（`Product`、`OrderItem`）
- 外键：`{model}_id`（`category_id`、`product_id`）
- 时间戳：使用 `$timestamps = true`（created_at / updated_at）
- 软删除：需要时使用 `SoftDeletes` trait（deleted_at）

### 7.4 迁移文件

- 命名：`YYYY_MM_DD_HHMMSS_{动作}_{表名}_table.php`
- 单表单文件，一个 migration 只负责一张表的变更
- up() 和 down() 必须对称可逆

---

## 8. Git 提交规范

提交信息格式：

```
<type>: <简短描述>
```

类型：

| type | 含义 |
|------|------|
| feat | 新功能 |
| fix | 修复 bug |
| docs | 文档变更 |
| style | 格式调整（不影响逻辑） |
| refactor | 重构（不改变功能） |
| test | 测试相关 |
| chore | 构建/工具/依赖 |

示例：

```
feat: 添加商品列表 API 和分页筛选
fix: 修复购物车计算时运费遗漏问题
docs: 补充独立站架构方案文档
```

---

## 9. 适用范围

本文规范适用于 `/var/www/hope/` 下所有代码文件：

- `api/` — Laravel PHP 后端
- `web/` — Next.js TypeScript 前端

规范属于工程治理的一部分，不是个人风格偏好。后续可追加项目级细则，但不能破坏本文通用约束。
