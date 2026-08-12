<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\Installer;
use think\Response;

final class Install extends BaseController
{
    public function index(Installer $installer): Response
    {
        if ($installer->isInstalled()) {
            return $this->render('install/locked', ['pageTitle' => '系统已安装'])->code(403);
        }

        return $this->installerPage($installer);
    }

    public function submit(Installer $installer): Response
    {
        if ($installer->isInstalled()) {
            return $this->render('install/locked', ['pageTitle' => '系统已安装'])->code(403);
        }

        $values = [
            'db_driver' => trim((string) $this->request->post('db_driver', 'mysql')),
            'db_host' => trim((string) $this->request->post('db_host', '127.0.0.1')),
            'db_port' => trim((string) $this->request->post('db_port', '3306')),
            'db_name' => trim((string) $this->request->post('db_name', 'asset_factory')),
            'db_user' => trim((string) $this->request->post('db_user', '')),
            'db_password' => (string) $this->request->post('db_password', ''),
            'admin_name' => trim((string) $this->request->post('admin_name', '系统管理员')),
            'admin_email' => trim((string) $this->request->post('admin_email', '')),
            'admin_password' => (string) $this->request->post('admin_password', ''),
        ];
        $confirmation = (string) $this->request->post('admin_password_confirmation', '');
        if ($values['admin_password'] !== $confirmation) {
            return $this->installerPage($installer, ['两次输入的管理员密码不一致。'], $this->safeValues($values));
        }

        try {
            $installer->install($values, $this->request->isSsl());
        } catch (\Throwable $exception) {
            return $this->installerPage($installer, [$exception->getMessage()], $this->safeValues($values));
        }

        return Response::create('/admin/login', 'redirect')->with('success', '系统安装完成，请使用刚创建的管理员账户登录。');
    }

    private function installerPage(Installer $installer, array $errors = [], array $values = []): Response
    {
        $defaults = [
            'db_driver' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => 'asset_factory',
            'db_user' => '',
            'admin_name' => '系统管理员',
            'admin_email' => '',
        ];

        return $this->render('install/index', [
            'pageTitle' => '系统安装',
            'requirements' => $installer->requirements(),
            'canInstall' => $installer->canInstall(),
            'errors' => $errors,
            'values' => array_merge($defaults, $values),
        ]);
    }

    private function safeValues(array $values): array
    {
        unset($values['db_password'], $values['admin_password']);
        return $values;
    }
}
