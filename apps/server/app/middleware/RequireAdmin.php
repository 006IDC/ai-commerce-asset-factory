<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\AdminOnboardingService;
use app\support\AuthSession;
use app\support\Web;
use Closure;
use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class RequireAdmin
{
    public function __construct(private readonly AdminOnboardingService $onboarding)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = (int) Session::get('admin_user_id', 0);
        if ($adminId <= 0) {
            return Web::redirect('/admin/login', '请先登录管理后台。', 'error');
        }

        $admin = Db::name('admin_users')->where('id', $adminId)->find();
        if (!$admin || $admin['status'] !== 'active') {
            Session::clear();
            return Web::redirect('/admin/login', '管理员账号不可用。', 'error');
        }
        if (!AuthSession::adminIsCurrent($admin)) {
            Session::clear();
            return Web::redirect('/admin/login', '登录状态已失效，请使用当前密码重新登录。', 'error');
        }

        $path = '/' . ltrim($request->pathinfo(), '/');
        if ($this->onboarding->enabled()
            && !str_starts_with($path, '/admin/setup')
            && $path !== '/admin/logout') {
            try {
                if (!$this->onboarding->isComplete($adminId)) {
                    return Web::redirect('/admin/setup', '请先完成首次配置，进度会自动保存。');
                }
            } catch (\Throwable) {
                // Upgrades create the onboarding table before this gate becomes authoritative.
            }
        }

        return $next($request);
    }
}
