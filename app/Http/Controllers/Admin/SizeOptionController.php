<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SizeOptionResource;
use App\Models\SizeOption;
use App\Services\SizeOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SizeOptionController extends Controller
{
    /** 尺码服务(删除保护的业务规则) */
    public function __construct(private SizeOptionService $service) {}

    /**
     * 尺码列表:按 sort、id 升序返回全部。
     */
    public function index(): JsonResponse
    {
        $options = SizeOption::orderBy('sort')->orderBy('id')->get();

        return response()->json([
            'data' => SizeOptionResource::collection($options),
        ]);
    }

    /**
     * 创建尺码。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:size_options,name',
            'sort' => 'required|integer|min:0',
        ]);

        $option = SizeOption::create($validated);

        return response()->json([
            'data' => new SizeOptionResource($option),
        ], 201);
    }

    /**
     * 更新尺码。
     */
    public function update(Request $request, SizeOption $sizeOption): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:size_options,name,' . $sizeOption->id,
            'sort' => 'sometimes|integer|min:0',
        ]);

        $sizeOption->update($validated);

        return response()->json([
            'data' => new SizeOptionResource($sizeOption),
        ]);
    }

    /**
     * 删除尺码(依赖检查通过后物理删除)。
     */
    public function destroy(SizeOption $sizeOption): JsonResponse
    {
        $this->service->destroy($sizeOption);

        return response()->json(null, 204);
    }
}
