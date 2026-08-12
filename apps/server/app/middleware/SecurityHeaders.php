<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "script-src 'self' 'unsafe-inline' https://unpkg.com https://*.geetest.com https://*.geevisit.com",
                "style-src 'self' 'unsafe-inline' https://*.geetest.com https://*.geevisit.com",
                "img-src 'self' data: blob: https:",
                "media-src 'self' blob: https:",
                "font-src 'self' data:",
                "connect-src 'self' https: wss:",
                "frame-src https://*.geetest.com https://*.geevisit.com",
                "worker-src 'self' blob:",
            ]),
        ];

        if ($request->isSsl()) {
            $headers['Strict-Transport-Security'] = 'max-age=15552000';
        }

        $path = '/' . ltrim($request->pathinfo(), '/');
        if ($this->isSensitivePath($path)) {
            $headers['Cache-Control'] = 'private, no-store, max-age=0';
            $headers['Pragma'] = 'no-cache';
        }

        return $response->header($headers);
    }

    private function isSensitivePath(string $path): bool
    {
        return in_array($path, ['/login', '/register', '/forgot-password'], true)
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/workspace');
    }
}
