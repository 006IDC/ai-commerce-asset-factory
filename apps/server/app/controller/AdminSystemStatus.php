<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AdminSystemStatusService;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminSystemStatus extends BaseController
{
    public function index(AdminSystemStatusService $status): Response
    {
        $admin = Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
        $data = $status->status();
        return $this->render('admin/system_status', compact('admin', 'data') + ['pageTitle' => '系统状态']);
    }
}
