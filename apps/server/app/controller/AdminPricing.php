<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\PricingService;
use app\service\SystemSettings;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminPricing extends BaseController
{
    public function index(PricingService $pricing): Response
    {
        $admin = $this->admin();
        $configuration = $pricing->configuration();
        return $this->render('admin/pricing', compact('admin', 'configuration') + ['pageTitle' => '商户侧定价']);
    }

    public function save(SystemSettings $settings): Response
    {
        $admin = $this->admin();
        $imageCredits = trim((string) $this->request->post('image_generation_credits', ''));
        $videoCredits = trim((string) $this->request->post(
            'video_generation_credits_per_second',
            $settings->get('video_generation_credits_per_second')
        ));
        $canvasCredits = trim((string) $this->request->post('canvas_edit_credits', ''));
        foreach ([$imageCredits, $videoCredits, $canvasCredits] as $credits) {
            if (!preg_match('/^\d+$/', $credits) || (int) $credits > 1000000) {
                return Web::redirect('/admin/pricing', '费用必须是 0 到 1000000 之间的整数。', 'error');
            }
        }

        $settings->setMany([
            'image_generation_credits' => $imageCredits,
            'video_generation_credits_per_second' => $videoCredits,
            'canvas_edit_credits' => $canvasCredits,
            'product_recognition_credits' => '0',
            'prompt_optimization_credits' => '0',
        ], (int) $admin['id']);
        Audit::log('admin', (int) $admin['id'], 'pricing.settings.update', 'system_settings', null, $this->request->ip(), [
            'image_generation_credits' => (int) $imageCredits,
            'video_generation_credits_per_second' => (int) $videoCredits,
            'canvas_edit_credits' => (int) $canvasCredits,
            'product_recognition_credits' => 0,
            'prompt_optimization_credits' => 0,
        ]);

        return Web::redirect('/admin/pricing', '计费设置已保存，新任务将按新价格计费。');
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
