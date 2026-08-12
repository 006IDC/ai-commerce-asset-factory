<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\GeetestService;
use app\service\AdminOnboardingService;
use app\support\AuthSession;
use app\support\Audit;
use app\support\LoginThrottle;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminAuth extends BaseController
{
    public function loginPage(GeetestService $geetest, AdminOnboardingService $onboarding): Response
    {
        if (Session::has('admin_user_id')) {
            $adminId = (int) Session::get('admin_user_id');
            return Web::redirect(
                $onboarding->enabled() && !$onboarding->isComplete($adminId)
                    ? '/admin/setup'
                    : '/admin'
            );
        }

        return $this->render('admin_auth/login', [
            'pageTitle' => '管理后台登录',
            'geetestConfiguration' => $geetest->configuration(),
        ]);
    }

    public function login(GeetestService $geetest, AdminOnboardingService $onboarding): Response
    {
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $ipAddress = $this->request->ip();
        if (LoginThrottle::isLocked('admin', $email, $ipAddress)) {
            return Web::redirect('/admin/login', '登录尝试次数过多，请在 ' . LoginThrottle::remainingSeconds('admin', $email, $ipAddress) . ' 秒后重试。', 'error');
        }
        try {
            $geetest->verify(
                trim((string) $this->request->post('geetest_challenge', '')),
                trim((string) $this->request->post('geetest_validate', '')),
                trim((string) $this->request->post('geetest_seccode', '')),
                $ipAddress
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/login', $exception->getMessage(), 'error');
        }
        $password = (string) $this->request->post('password', '');
        $admin = Db::name('admin_users')->where('email', $email)->find();
        if (!$admin || $admin['status'] !== 'active' || !password_verify($password, $admin['password_hash'])) {
            LoginThrottle::fail('admin', $email, $ipAddress);
            return Web::redirect('/admin/login', '邮箱、密码或账号状态不正确。', 'error');
        }

        LoginThrottle::clear('admin', $email);
        Session::regenerate(true);
        Session::set('admin_user_id', (int) $admin['id']);
        AuthSession::bindAdmin($admin);
        Web::rotateCsrfToken();
        Db::name('admin_users')->where('id', $admin['id'])->update([
            'last_login_at' => Web::now(),
            'updated_at' => Web::now(),
        ]);
        Audit::log('admin', (int) $admin['id'], 'login', 'admin_user', (int) $admin['id'], $this->request->ip());

        $target = $onboarding->enabled() && !$onboarding->isComplete((int) $admin['id'])
            ? '/admin/setup'
            : '/admin';
        return Web::redirect($target, $target === '/admin/setup' ? '登录成功，请继续完成首次配置。' : '登录成功。');
    }

    public function logout(): Response
    {
        $adminId = (int) Session::get('admin_user_id', 0);
        if ($adminId > 0) {
            Audit::log('admin', $adminId, 'logout', 'admin_user', $adminId, $this->request->ip());
        }
        Session::destroy();

        return Web::redirect('/admin/login', '已安全退出管理后台。');
    }
}
