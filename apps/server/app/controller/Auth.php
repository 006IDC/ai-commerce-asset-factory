<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\EmailVerificationService;
use app\service\GeetestService;
use app\service\OperationsSettings;
use app\service\PublicReleaseService;
use app\support\Audit;
use app\support\AuthSession;
use app\support\LoginThrottle;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class Auth extends BaseController
{
    public function home(PublicReleaseService $releases): Response
    {
        if (Session::has('merchant_user_id')) {
            return Web::redirect('/workspace/generate');
        }
        return $this->render('/home', [
            'pageTitle' => 'AI 电商素材工厂',
            'signedIn' => false,
            'publicPlans' => Db::name('subscription_plans')->where('status', 'active')->order('sort_order', 'asc')->order('id', 'asc')->limit(6)->select()->toArray(),
            'publicReleases' => $releases->all(),
            'publicReleaseFilters' => $releases->filters(),
            'publicVersion' => $releases->publicVersion(),
        ]);
    }

    public function loginPage(GeetestService $geetest): Response
    {
        if (Session::has('merchant_user_id')) {
            return Web::redirect('/workspace');
        }

        return $this->render('auth/login', [
            'pageTitle' => '商家登录',
            'geetestConfiguration' => $geetest->configuration(),
            'returnTo' => $this->safeReturnTo((string) $this->request->get('return_to', '/workspace')),
        ]);
    }

    public function login(GeetestService $geetest): Response
    {
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $returnTo = $this->safeReturnTo((string) $this->request->post('return_to', '/workspace'));
        $ipAddress = $this->request->ip();
        if (LoginThrottle::isLocked('merchant', $email, $ipAddress)) {
            return Web::redirect($this->authPath('/login', $returnTo), '登录尝试次数过多，请在 ' . LoginThrottle::remainingSeconds('merchant', $email, $ipAddress) . ' 秒后重试。', 'error');
        }
        try {
            $geetest->verify(
                trim((string) $this->request->post('geetest_challenge', '')),
                trim((string) $this->request->post('geetest_validate', '')),
                trim((string) $this->request->post('geetest_seccode', '')),
                $ipAddress
            );
        } catch (\Throwable $exception) {
            return Web::redirect($this->authPath('/login', $returnTo), $exception->getMessage(), 'error');
        }
        $password = (string) $this->request->post('password', '');
        $user = Db::name('merchant_users')->where('email', $email)->find();
        $merchant = $user ? Db::name('merchants')->where('id', $user['merchant_id'])->find() : null;

        if (!$user || !$merchant || $user['status'] !== 'active' || $merchant['status'] !== 'active'
            || !password_verify($password, $user['password_hash'])) {
            LoginThrottle::fail('merchant', $email, $ipAddress);
            return Web::redirect($this->authPath('/login', $returnTo), '邮箱、密码或账号状态不正确。', 'error');
        }

        LoginThrottle::clear('merchant', $email);
        Session::regenerate(true);
        Session::set('merchant_user_id', (int) $user['id']);
        Session::set('merchant_id', (int) $merchant['id']);
        AuthSession::bindMerchant($user);
        Web::rotateCsrfToken();
        Db::name('merchant_users')->where('id', $user['id'])->update([
            'last_login_at' => Web::now(),
            'last_login_ip' => $ipAddress,
            'updated_at' => Web::now(),
        ]);
        Audit::log('merchant_user', (int) $user['id'], 'login', 'merchant', (int) $merchant['id'], $this->request->ip());

        return Web::redirect($returnTo, '欢迎回来，' . $user['name'] . '。');
    }

    public function forgotPasswordPage(GeetestService $geetest): Response
    {
        if (Session::has('merchant_user_id')) {
            return Web::redirect('/workspace');
        }

        if ((string) $this->request->get('reset_email', '') === '1') {
            Session::delete('password_reset_email');
        }

        return $this->render('auth/forgot_password', [
            'pageTitle' => '找回密码',
            'passwordResetEmail' => (string) Session::get('password_reset_email', ''),
            'geetestConfiguration' => $geetest->configuration(),
        ]);
    }

    public function geetestPasswordReset(GeetestService $geetest): Response
    {
        try {
            return Response::create(['ok' => true, 'config' => $geetest->register($this->request->ip())], 'json');
        } catch (\Throwable $exception) {
            return Response::create(['ok' => false, 'message' => $exception->getMessage()], 'json')->code(503);
        }
    }

    public function sendPasswordResetCode(EmailVerificationService $verification, GeetestService $geetest): Response
    {
        $email = strtolower(trim((string) $this->request->post('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Web::redirect('/forgot-password', '请输入有效邮箱。', 'error');
        }
        try {
            $geetest->verify(
                trim((string) $this->request->post('geetest_challenge', '')),
                trim((string) $this->request->post('geetest_validate', '')),
                trim((string) $this->request->post('geetest_seccode', '')),
                $this->request->ip()
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/forgot-password', $exception->getMessage(), 'error');
        }

        $user = Db::name('merchant_users')->where('email', $email)->find();
        $merchant = $user ? Db::name('merchants')->where('id', $user['merchant_id'])->find() : null;
        if ($user && $merchant && $user['status'] === 'active' && $merchant['status'] === 'active') {
            try {
                $verification->send($email, 'reset_password', (int) $user['id'], $this->request->ip());
            } catch (\Throwable $exception) {
                return Web::redirect('/forgot-password', $exception->getMessage(), 'error');
            }
        }

        Session::set('password_reset_email', $email);
        return Web::redirect('/forgot-password', '如该邮箱已注册，验证码已发送，请检查邮箱。');
    }

    public function resetPassword(EmailVerificationService $verification): Response
    {
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $code = trim((string) $this->request->post('verification_code', ''));
        $password = (string) $this->request->post('password', '');
        $confirmation = (string) $this->request->post('password_confirmation', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Web::redirect('/forgot-password', '请输入有效邮箱。', 'error');
        }
        if (!password_is_strong($password) || $password !== $confirmation) {
            return Web::redirect('/forgot-password', '新密码至少 10 位，并同时包含字母和数字，且两次输入必须一致。', 'error');
        }

        $user = Db::name('merchant_users')->where('email', $email)->find();
        $merchant = $user ? Db::name('merchants')->where('id', $user['merchant_id'])->find() : null;
        if (!$user || !$merchant || $user['status'] !== 'active' || $merchant['status'] !== 'active') {
            return Web::redirect('/login', '验证码无效或账户当前不可用，请重新获取验证码。', 'error');
        }

        try {
            Db::transaction(function () use ($verification, $email, $code, $user): void {
                $verification->consume($email, 'reset_password', $code, (int) $user['id']);
                Db::name('merchant_users')->where('id', $user['id'])->update([
                    'password_hash' => password_hash($this->request->post('password', ''), PASSWORD_DEFAULT),
                    'updated_at' => Web::now(),
                ]);
            });
        } catch (\Throwable $exception) {
            return Web::redirect('/forgot-password', $exception->getMessage(), 'error');
        }

        Session::delete('password_reset_email');
        LoginThrottle::clear('merchant', $email);
        Audit::log('merchant_user', (int) $user['id'], 'password.reset', 'merchant_user', (int) $user['id'], $this->request->ip());

        return Web::redirect('/login', '密码已重置，请使用新密码登录。');
    }

    public function registerPage(GeetestService $geetest, OperationsSettings $operations): Response
    {
        if (Session::has('merchant_user_id')) {
            return Web::redirect('/workspace');
        }

        if ((string) $this->request->get('reset_email', '') === '1') {
            Session::delete('registration_email');
        }

        return $this->render('auth/register', [
            'pageTitle' => '注册商家',
            'registrationEmail' => (string) Session::get('registration_email', ''),
            'geetestConfiguration' => $geetest->configuration(),
            'registrationBenefit' => $operations->registrationBenefit(),
            'returnTo' => $this->safeReturnTo((string) $this->request->get('return_to', '/workspace')),
        ]);
    }

    public function sendRegistrationCode(EmailVerificationService $verification, GeetestService $geetest): Response
    {
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $returnTo = $this->safeReturnTo((string) $this->request->post('return_to', '/workspace'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Web::redirect($this->authPath('/register', $returnTo), '请输入有效邮箱。', 'error');
        }
        try {
            $geetest->verify(
                trim((string) $this->request->post('geetest_challenge', '')),
                trim((string) $this->request->post('geetest_validate', '')),
                trim((string) $this->request->post('geetest_seccode', '')),
                $this->request->ip()
            );
            if (!Db::name('merchant_users')->where('email', $email)->find()) {
                $verification->send($email, 'register', null, $this->request->ip());
            }
        } catch (\Throwable $exception) {
            return Web::redirect($this->authPath('/register', $returnTo), $exception->getMessage(), 'error');
        }
        Session::set('registration_email', $email);

        return Web::redirect($this->authPath('/register', $returnTo), '如果该邮箱可以注册，验证码将发送到邮箱，请检查收件箱。');
    }

    public function geetestRegister(GeetestService $geetest): Response
    {
        try {
            return Response::create(['ok' => true, 'config' => $geetest->register($this->request->ip())], 'json');
        } catch (\Throwable $exception) {
            return Response::create(['ok' => false, 'message' => $exception->getMessage()], 'json')->code(503);
        }
    }

    public function register(EmailVerificationService $verification, OperationsSettings $operations): Response
    {
        $returnTo = $this->safeReturnTo((string) $this->request->post('return_to', '/workspace'));
        $merchantName = trim((string) $this->request->post('merchant_name', ''));
        $name = trim((string) $this->request->post('name', ''));
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $password = (string) $this->request->post('password', '');
        $confirmation = (string) $this->request->post('password_confirmation', '');
        $verificationCode = trim((string) $this->request->post('verification_code', ''));

        if (mb_strlen($merchantName) < 2 || mb_strlen($merchantName) > 120) {
            return Web::redirect('/register', '商户名称应为 2 到 120 个字符。', 'error');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return Web::redirect('/register', '联系人姓名应为 2 到 80 个字符。', 'error');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Web::redirect('/register', '请输入有效邮箱。', 'error');
        }
        if (!password_is_strong($password) || $password !== $confirmation) {
            return Web::redirect('/register', '密码至少 10 位，并同时包含字母和数字，且两次输入必须一致。', 'error');
        }
        $lastDay = date('Y-m-d H:i:s', time() - 86400);
        $registrationsFromIp = Db::name('audit_logs')->where('action', 'register')->where('ip_address', $this->request->ip())
            ->where('created_at', '>=', $lastDay)->count();
        if ($registrationsFromIp >= 5) {
            return Web::redirect('/register', '该网络今日注册账户过多，请稍后再试。', 'error');
        }

        $registrationBenefit = $operations->registrationBenefit();
        $giftCredits = (int) $registrationBenefit['credits'];
        $giftPlanId = (int) $registrationBenefit['planId'];
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        try {
            [$merchantId, $userId, $giftPlanName] = Db::transaction(function () use ($merchantName, $name, $email, $passwordHash, $verificationCode, $verification, $giftCredits, $giftPlanId) {
                $verification->consume($email, 'register', $verificationCode, null);
                if (Db::name('merchant_users')->where('email', $email)->find()) {
                    throw new \RuntimeException('该邮箱已经注册。');
                }
                $now = Web::now();
                $giftPlan = null;
                if ($giftPlanId > 0) {
                    $giftPlan = Db::name('subscription_plans')->where('id', $giftPlanId)->lock(true)->find();
                    if (!$giftPlan) {
                        throw new \RuntimeException('平台注册福利配置异常，请联系平台管理员。');
                    }
                }
                $merchantId = Db::name('merchants')->insertGetId([
                    'name' => $merchantName,
                    'slug' => 'mch_' . bin2hex(random_bytes(8)),
                    'status' => 'active',
                    'plan_code' => $giftPlan['code'] ?? 'trial',
                    'credit_balance' => $giftCredits,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $userId = Db::name('merchant_users')->insertGetId([
                    'merchant_id' => $merchantId,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => 'owner',
                    'status' => 'active',
                    'last_login_at' => $now,
                    'last_login_ip' => $this->request->ip(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::name('merchants')->where('id', $merchantId)->update(['owner_user_id' => $userId]);
                if ($giftCredits > 0) {
                    Db::name('credit_ledger')->insert([
                        'merchant_id' => $merchantId,
                        'type' => 'grant',
                        'amount' => $giftCredits,
                        'balance_after' => $giftCredits,
                        'source' => 'registration',
                        'note' => '新商户注册赠送积分',
                        'created_at' => $now,
                    ]);
                }
                if ($giftPlan) {
                    $durationDays = max(1, (int) $giftPlan['duration_days']);
                    Db::name('merchant_subscriptions')->insert([
                        'merchant_id' => $merchantId,
                        'plan_id' => $giftPlan['id'],
                        'plan_code' => $giftPlan['code'],
                        'plan_name' => $giftPlan['name'],
                        'price_credits' => 0,
                        'tier_level' => max(1, (int) ($giftPlan['tier_level'] ?? 1)),
                        'audience_label' => ($giftPlan['audience_label'] ?? null) ?: null,
                        'starts_at' => $now,
                        'expires_at' => date('Y-m-d H:i:s', strtotime($now . " +{$durationDays} days")),
                        'status' => 'active',
                        'generation_unlimited' => (int) $giftPlan['generation_unlimited'],
                        'recognition_unlimited' => (int) $giftPlan['recognition_unlimited'],
                        'daily_generation_limit' => (int) $giftPlan['daily_generation_limit'],
                        'sku_limit' => max(0, (int) ($giftPlan['sku_limit'] ?? 0)),
                        'batch_image_limit' => max(0, (int) ($giftPlan['batch_image_limit'] ?? 0)),
                        'concurrent_job_limit' => max(0, (int) ($giftPlan['concurrent_job_limit'] ?? 0)),
                        'purchased_at' => $now,
                        'created_at' => $now,
                    ]);
                }

                return [$merchantId, $userId, (string) ($giftPlan['name'] ?? '')];
            });
        } catch (\Throwable $exception) {
            return Web::redirect('/register', $exception->getMessage(), 'error');
        }

        Session::regenerate(true);
        Session::set('merchant_user_id', (int) $userId);
        Session::set('merchant_id', (int) $merchantId);
        AuthSession::bindMerchant(['password_hash' => $passwordHash]);
        Web::rotateCsrfToken();
        Session::delete('registration_email');
        Audit::log('merchant_user', (int) $userId, 'register', 'merchant', (int) $merchantId, $this->request->ip());

        $benefits = [];
        if ($giftCredits > 0) {
            $benefits[] = number_format($giftCredits) . ' 点数';
        }
        if ($giftPlanName !== '') {
            $benefits[] = $giftPlanName;
        }
        return Web::redirect($returnTo, $benefits ? '注册成功，已发放' . implode('和', $benefits) . '。' : '注册成功。');
    }

    public function logout(): Response
    {
        $userId = (int) Session::get('merchant_user_id', 0);
        $merchantId = (int) Session::get('merchant_id', 0);
        if ($userId > 0) {
            Audit::log('merchant_user', $userId, 'logout', 'merchant', $merchantId, $this->request->ip());
        }
        Session::destroy();

        return Web::redirect('/login', '已安全退出。');
    }

    private function safeReturnTo(string $returnTo): string
    {
        $returnTo = trim($returnTo);
        if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            return '/workspace';
        }
        $path = (string) parse_url($returnTo, PHP_URL_PATH);
        if (!str_starts_with($path, '/workspace')) {
            return '/workspace';
        }
        return $returnTo;
    }

    private function authPath(string $path, string $returnTo): string
    {
        return $path . '?return_to=' . rawurlencode($returnTo);
    }
}
