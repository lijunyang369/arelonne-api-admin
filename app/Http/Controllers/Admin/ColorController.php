<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ColorResource;
use App\Models\Color;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    /**
     * 后台颜色列表。
     */
    public function index(Request $request): JsonResponse
    {
        $query = Color::query();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $colors = $query->orderBy('name')->paginate(
            $request->get('per_page', 20),
        );

        return response()->json([
            'data'  => ColorResource::collection($colors),
            'meta'  => [
                'current_page' => $colors->currentPage(),
                'per_page'     => $colors->perPage(),
                'total'        => $colors->total(),
                'last_page'    => $colors->lastPage(),
            ],
        ]);
    }

    /**
     * 创建颜色。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255|unique:colors,name',
            'name_zh' => 'nullable|string|max:255',
            'hex'     => ['required', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status'  => 'required|in:active,inactive',
        ]);

        $validated['updated_by'] = $request->user()?->name;

        $color = Color::create($validated);

        return response()->json([
            'data' => new ColorResource($color),
        ], 201);
    }

    /**
     * 查看单个颜色。
     */
    public function show(Color $color): JsonResponse
    {
        return response()->json([
            'data' => new ColorResource($color),
        ]);
    }

    /**
     * 更新颜色。
     */
    public function update(Request $request, Color $color): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255|unique:colors,name,' . $color->id,
            'name_zh' => 'nullable|string|max:255',
            'hex'     => ['sometimes', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status'  => 'sometimes|in:active,inactive',
        ]);

        $validated['updated_by'] = $request->user()?->name;

        $color->update($validated);

        return response()->json([
            'data' => new ColorResource($color),
        ]);
    }

    /**
     * 删除颜色（硬删除）。
     */
    public function destroy(Color $color): JsonResponse
    {
        $color->delete();

        return response()->json(null, 204);
    }
}
