<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\AuthSession;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminAccount extends BaseController
{
    public function index(): Response
    {
        $admin = $this->admin();
        return $this->render('admin/account', compact('admin') + ['pageTitle' => '管理员账户']);
    }

    public function updateProfile(): Response
    {
        $admin = $this->admin();
        $name = trim((string) $this->request->post('name', ''));
        $email = strtolower(trim((string) $this->request->post('email', '')));
        $currentPassword = (string) $this->request->post('current_password', '');
        if (!password_verify($currentPassword, (string) $admin['password_hash'])) {
            return Web::redirect('/admin/account', '当前密码不正确。', 'error');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Web::redirect('/admin/account', '管理员名称或邮箱格式不正确。', 'error');
        }
        $duplicate = Db::name('admin_users')->where('email', $email)->where('id', '<>', $admin['id'])->find();
        if ($duplicate) {
            return Web::redirect('/admin/account', '该邮箱已被其他管理员使用。', 'error');
        }
        Db::name('admin_users')->where('id', $admin['id'])->update(['name' => $name, 'email' => $email, 'updated_at' => Web::now()]);
        Audit::log('admin', (int) $admin['id'], 'admin_account.profile.update', 'admin_user', (int) $admin['id'], $this->request->ip(), ['email' => $email]);

        return Web::redirect('/admin/account', '管理员资料已更新。');
    }

    public function changePassword(): Response
    {
        $admin = $this->admin();
        $currentPassword = (string) $this->request->post('current_password', '');
        $password = (string) $this->request->post('password', '');
        $confirmation = (string) $this->request->post('password_confirmation', '');
        if (!password_verify($currentPassword, (string) $admin['password_hash'])) {
            return Web::redirect('/admin/account', '当前密码不正确。', 'error');
        }
        if (strlen($password) < 12 || !password_is_strong($password) || $password !== $confirmation) {
            return Web::redirect('/admin/account', '新密码至少 12 位，并同时包含字母和数字，且两次输入必须一致。', 'error');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        Db::name('admin_users')->where('id', $admin['id'])->update([
            'password_hash' => $passwordHash,
            'updated_at' => Web::now(),
        ]);
        Session::regenerate(true);
        Session::set('admin_user_id', (int) $admin['id']);
        AuthSession::bindAdmin(array_replace($admin, ['password_hash' => $passwordHash]));
        Web::rotateCsrfToken();
        Audit::log('admin', (int) $admin['id'], 'admin_account.password.update', 'admin_user', (int) $admin['id'], $this->request->ip());

        return Web::redirect('/admin/account', '管理员密码已修改。');
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
