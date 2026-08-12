<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;
use think\facade\Session;

final class VerifyCsrfToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) Session::get('csrf_token', '');
        $provided = (string) ($request->post('_token') ?: $request->header('X-CSRF-Token', ''));

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return Response::create('请求已过期，请返回页面刷新后重试。', 'html', 419);
        }

        return $next($request);
    }
}
