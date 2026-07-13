<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Color;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColorSyncController extends Controller
{
    /** 同步白名单：Admin 推送的字段 */
    private const SYNC_FIELDS = ['id', 'name', 'name_zh', 'hex', 'status', 'updated_at', 'updated_by'];

    /**
     * 接收 Admin 同步的颜色数据（创建 / 更新）。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'         => 'required|integer',
            'name'       => 'required|string|max:255',
            'name_zh'    => 'nullable|string|max:255',
            'hex'        => 'required|string|max:7',
            'status'     => 'nullable|in:active,inactive',
            'updated_by' => 'nullable|string|max:255',
        ]);

        $color = Color::updateOrCreate(
            ['id' => $validated['id']],
            $validated,
        );

        return response()->json(['data' => $color], 200);
    }

    /**
     * 接收 Admin 同步的颜色更新。
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'name_zh'    => 'nullable|string|max:255',
            'hex'        => 'sometimes|string|max:7',
            'status'     => 'nullable|in:active,inactive',
            'updated_by' => 'nullable|string|max:255',
        ]);

        $color = Color::find($id);
        if (!$color) {
            $validated['id'] = $id;
            $validated['name'] ??= '';
            $validated['hex'] ??= '#CCCCCC';
            $color = Color::create($validated);
            return response()->json(['data' => $color], 201);
        }

        $color->update($validated);
        return response()->json(['data' => $color], 200);
    }

    /**
     * 接收 Admin 同步的颜色删除。
     */
    public function destroy(int $id): JsonResponse
    {
        $color = Color::find($id);
        if ($color) {
            $color->delete();
        }
        return response()->json(null, 204);
    }
}
