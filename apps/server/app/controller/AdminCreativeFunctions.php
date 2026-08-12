<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AdminCreativeFunctionService;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;
use Throwable;

final class AdminCreativeFunctions extends BaseController
{
    public function index(AdminCreativeFunctionService $functions): Response
    {
        $this->admin();
        return Web::redirect('/admin/prompts', '创作功能已合并到六项生成配置。');
    }

    public function edit(string $code, AdminCreativeFunctionService $functions): Response
    {
        $this->admin();
        $product = $functions->product($code);
        return Web::redirect(
            $product ? '/admin/prompts?product=' . rawurlencode($code) : '/admin/prompts',
            $product ? '已进入对应的生成配置。' : '生成业务不存在。',
            $product ? 'success' : 'error'
        );
    }

    public function update(string $code, AdminCreativeFunctionService $functions): Response
    {
        $admin = $this->admin();
        try {
            $product = $functions->update($code, (array) $this->request->post(), (int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'creative_function.update', 'creative_product', (int) $product['id'], $this->request->ip(), [
                'product_code' => $code,
                'status' => (string) $product['status'],
            ]);
            return Web::redirect('/admin/creative-functions', '创作功能设置已保存。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/creative-functions/' . rawurlencode($code) . '/edit', $exception->getMessage(), 'error');
        }
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
