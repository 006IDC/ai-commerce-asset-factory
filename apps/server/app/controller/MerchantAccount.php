<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\EmailVerificationService;
use app\service\RedemptionCardService;
use app\support\AuthSession;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class MerchantAccount extends BaseController
{
    public function updateProfile(): Response
    {
        [$merchant, $user] = $this->identity();
        $name = trim((string) $this->request->post('name', ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return Web::redirect('/workspace/account', '用户名应为 2 到 80 个字符。', 'error');
        }
        Db::name('merchant_users')->where('id', $user['id'])->update(['name' => $name, 'updated_at' => Web::now()]);
        Audit::log('merchant_user', (int) $user['id'], 'account.profile.update', 'merchant_user', (int) $user['id'], $this->request->ip());

        return Web::redirect('/workspace/account', '用户名已更新。');
    }

    public function sendEmailCode(EmailVerificationService $verification): Response
    {
        [$merchant, $user] = $this->identity();
        $email = strtolower(trim((string) $this->request->post('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Web::redirect('/workspace/account', '请输入有效的新邮箱。', 'error');
        }
        if ($email === strtolower((string) $user['email'])) {
            return Web::redirect('/workspace/account', '新邮箱不能与当前邮箱相同。', 'error');
        }
        if (Db::name('merchant_users')->where('email', $email)->find()) {
            return Web::redirect('/workspace/account', '该邮箱已绑定其他账户。', 'error');
        }
        try {
            $verification->send($email, 'change_email', (int) $user['id'], $this->request->ip());
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/account', $exception->getMessage(), 'error');
        }
        Session::set('pending_account_email', $email);

        return Web::redirect('/workspace/account', '验证码已发送到新邮箱。');
    }

    public function changeEmail(EmailVerificationService $verification): Response
    {
        [$merchant, $user] = $this->identity();
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $code = trim((string) $this->request->post('verification_code', ''));
        $currentPassword = (string) $this->request->post('current_password', '');
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            return Web::redirect('/workspace/account', '当前密码不正确。', 'error');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || Db::name('merchant_users')->where('email', $email)->find()) {
            return Web::redirect('/workspace/account', '新邮箱格式不正确或已被使用。', 'error');
        }
        try {
            Db::transaction(function () use ($verification, $email, $code, $user): void {
                $verification->consume($email, 'change_email', $code, (int) $user['id']);
                Db::name('merchant_users')->where('id', $user['id'])->update(['email' => $email, 'updated_at' => Web::now()]);
            });
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/account', $exception->getMessage(), 'error');
        }
        Session::delete('pending_account_email');
        Audit::log('merchant_user', (int) $user['id'], 'account.email.update', 'merchant_user', (int) $user['id'], $this->request->ip(), ['email' => $email]);

        return Web::redirect('/workspace/account', '绑定邮箱已更新。');
    }

    public function changePassword(): Response
    {
        [$merchant, $user] = $this->identity();
        $currentPassword = (string) $this->request->post('current_password', '');
        $password = (string) $this->request->post('password', '');
        $confirmation = (string) $this->request->post('password_confirmation', '');
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            return Web::redirect('/workspace/account', '当前密码不正确。', 'error');
        }
        if (!password_is_strong($password) || $password !== $confirmation) {
            return Web::redirect('/workspace/account', '新密码至少 10 位，并同时包含字母和数字，且两次输入必须一致。', 'error');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        Db::name('merchant_users')->where('id', $user['id'])->update([
            'password_hash' => $passwordHash,
            'updated_at' => Web::now(),
        ]);
        Session::regenerate(true);
        Session::set('merchant_user_id', (int) $user['id']);
        Session::set('merchant_id', (int) $merchant['id']);
        AuthSession::bindMerchant(array_replace($user, ['password_hash' => $passwordHash]));
        Web::rotateCsrfToken();
        Audit::log('merchant_user', (int) $user['id'], 'account.password.update', 'merchant_user', (int) $user['id'], $this->request->ip());

        return Web::redirect('/workspace/account', '登录密码已修改。');
    }

    public function redeemCard(RedemptionCardService $cards): Response
    {
        [$merchant, $user] = $this->identity();
        try {
            $credits = $cards->redeem((string) $this->request->post('code', ''), (int) $merchant['id'], (int) $user['id']);
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/usage', $exception->getMessage(), 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'redemption_card.redeem', 'merchant', (int) $merchant['id'], $this->request->ip(), ['credits' => $credits]);

        return Web::redirect('/workspace/usage', '卡密兑换成功，已增加 ' . $credits . ' 点数。');
    }

    private function identity(): array
    {
        return [
            Db::name('merchants')->where('id', (int) Session::get('merchant_id'))->find(),
            Db::name('merchant_users')->where('id', (int) Session::get('merchant_user_id'))->find(),
        ];
    }
}
