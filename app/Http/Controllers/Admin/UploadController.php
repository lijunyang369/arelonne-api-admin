<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 运营上传图片（presigned 直传 + 确认）。
 */
class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    /**
     * 签发 S3 直传签名 URL（dev 环境返回本地 signed route）。
     */
    public function presign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'type'     => ['required', 'string', 'in:banner,editorial,product-shot'],
            'mime'     => ['required', 'string', 'in:image/jpeg,image/png,image/webp'],
            'size'     => ['required', 'integer', 'min:1', 'max:15728640'],
        ]);

        return response()->json(
            $this->uploads->presign($validated['type'], $validated['filename'], $validated['mime'], $validated['size']),
            201
        );
    }

    /**
     * 确认上传：生成变体并移入正式路径（幂等）。
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->uploads->confirm($validated['key']));
    }

    /**
     * dev 专用直传端点（signed URL + 配置开关）。
     */
    public function devPut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        $this->uploads->devPut($validated['key'], (string) $request->getContent());

        return response()->json(['key' => $validated['key']]);
    }
}
