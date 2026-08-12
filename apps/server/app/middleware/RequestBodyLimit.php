<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

final class RequestBodyLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        $contentLength = max(0, (int) $request->server('CONTENT_LENGTH', '0'));
        $postLimit = $this->bytes((string) ini_get('post_max_size'));

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $postLimit > 0 && $contentLength > $postLimit) {
            $limitLabel = $this->formatBytes($postLimit);
            $message = "图片上传请求超过 {$limitLabel} 上限。本次未创建任务，也不会扣除点数。请减少图片数量，并确保单张图片不超过 24MB 后重新提交。";

            if ($request->isAjax() || str_contains(strtolower((string) $request->header('Accept', '')), 'application/json')) {
                return Response::create([
                    'ok' => false,
                    'code' => 'upload_request_too_large',
                    'message' => $message,
                    'charged' => false,
                    'limitBytes' => $postLimit,
                ], 'json')->code(413);
            }

            $body = '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>图片超过允许大小</title><body style="margin:0;padding:32px;font:14px/1.7 system-ui;color:#172033;background:#f2f6fa">'
                . '<main style="max-width:620px;margin:8vh auto;padding:24px;background:#fff;border:1px solid #c8d4e2;border-radius:8px">'
                . '<h1 style="font-size:22px;margin:0 0 8px">图片超过允许大小</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p><a href="/workspace/generate" style="display:inline-block;margin-top:8px;padding:9px 14px;color:#fff;background:#087c8f;border-radius:6px;text-decoration:none">返回后重新选择图片</a>'
                . '</main></body></html>';

            return Response::create($body, 'html')->code(413);
        }

        return $next($request);
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024 / 1024, 1, '.', ''), '0'), '.') . 'GB';
        }
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.') . 'MB';
    }
}
