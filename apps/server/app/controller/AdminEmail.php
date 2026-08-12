<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\SmtpMailer;
use app\service\SystemSettings;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminEmail extends BaseController
{
    public function index(SmtpMailer $mailer): Response
    {
        $admin = $this->admin();
        $configuration = $mailer->configuration();
        return $this->render('admin/email', compact('admin', 'configuration') + ['pageTitle' => '邮件服务']);
    }

    public function save(SystemSettings $settings, SmtpMailer $mailer): Response
    {
        $admin = $this->admin();
        $host = trim((string) $this->request->post('host', ''));
        $port = (int) $this->request->post('port', 0);
        $encryption = (string) $this->request->post('encryption', '');
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');
        $fromEmail = strtolower(trim((string) $this->request->post('from_email', '')));
        $fromName = trim((string) $this->request->post('from_name', ''));
        if ($host === '' || mb_strlen($host) > 190 || $port < 1 || $port > 65535) {
            return Web::redirect('/admin/email', 'SMTP 主机或端口不正确。', 'error');
        }
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            return Web::redirect('/admin/email', 'SMTP 加密方式不正确。', 'error');
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($fromName) > 120) {
            return Web::redirect('/admin/email', '发件邮箱或发件名称不正确。', 'error');
        }
        if ($username !== '' && $password === '' && !$mailer->configuration()['passwordConfigured']) {
            return Web::redirect('/admin/email', '启用 SMTP 认证时必须填写密码。', 'error');
        }
        $values = [
            'smtp_host' => $host,
            'smtp_port' => (string) $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => $username,
            'smtp_from_email' => $fromEmail,
            'smtp_from_name' => $fromName,
        ];
        if ($password !== '') {
            $values['smtp_password'] = $password;
        }
        try {
            $changed = $settings->setMany($values, (int) $admin['id']);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/email', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'smtp.settings.update', 'system_settings', null, $this->request->ip(), [
            'changed_keys' => $changed,
            'password_updated' => $password !== '',
        ]);

        return Web::redirect('/admin/email', 'SMTP 配置已保存。');
    }

    public function test(SmtpMailer $mailer): Response
    {
        $admin = $this->admin();
        $email = strtolower(trim((string) $this->request->post('email', '')));
        try {
            $mailer->sendTest($email);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/email', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'smtp.test', 'system_settings', null, $this->request->ip(), ['email' => $email]);

        return Web::redirect('/admin/email', '测试邮件已发送。');
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
