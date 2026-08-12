<?php

declare(strict_types=1);

namespace app\middleware;

use app\support\Installer;
use Closure;
use think\Request;
use think\Response;

final class EnsureInstalled
{
    public function __construct(private readonly Installer $installer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = '/' . ltrim($request->pathinfo(), '/');
        if ($path === '/install' || str_starts_with($path, '/install/')) {
            return $next($request);
        }
        if (!$this->installer->isInstalled()) {
            return Response::create('/install', 'redirect');
        }

        return $next($request);
    }
}
