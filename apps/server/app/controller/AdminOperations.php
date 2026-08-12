<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\OperationsSettings;
use app\service\SystemSettings;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminOperations extends BaseController
{
    public function index(OperationsSettings $operations): Response
    {
        $admin = $this->admin();
        $registrationBenefit = $operations->registrationBenefit();
        $announcement = $operations->announcement();
        $plans = Db::name('subscription_plans')->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();

        return $this->render('admin/operations', compact('admin', 'registrationBenefit', 'announcement', 'plans') + [
            'pageTitle' => '运营设置',
        ]);
    }

    public function save(SystemSettings $settings): Response
    {
        $admin = $this->admin();
        $credits = trim((string) $this->request->post('registration_gift_credits', ''));
        $planId = (int) $this->request->post('registration_gift_plan_id', 0);
        $announcementEnabled = $this->request->post('merchant_announcement_enabled') ? '1' : '0';
        $title = trim((string) $this->request->post('merchant_announcement_title', ''));
        $content = trim((string) $this->request->post('merchant_announcement_content', ''));
        $contactLabel = trim((string) $this->request->post('merchant_announcement_contact_label', ''));
        $contactUrl = trim((string) $this->request->post('merchant_announcement_contact_url', ''));

        if (!preg_match('/^\d+$/', $credits) || (int) $credits > 1000000) {
            return Web::redirect('/admin/operations', '注册送点数必须是 0 到 1000000 之间的整数。', 'error');
        }
        if ($planId > 0 && !Db::name('subscription_plans')->where('id', $planId)->find()) {
            return Web::redirect('/admin/operations', '选择的注册赠送套餐不存在。', 'error');
        }
        if (mb_strlen($title) > 120 || mb_strlen($content) > 1000 || mb_strlen($contactLabel) > 80 || mb_strlen($contactUrl) > 500) {
            return Web::redirect('/admin/operations', '公告标题、内容或联系方式超过长度限制。', 'error');
        }
        if ($announcementEnabled === '1' && ($title === '' || $content === '')) {
            return Web::redirect('/admin/operations', '启用商户公告时必须填写标题和内容。', 'error');
        }
        if ($contactUrl !== '' && !$this->validContactUrl($contactUrl)) {
            return Web::redirect('/admin/operations', '联系链接仅支持站内路径、http、https、mailto 或 tel。', 'error');
        }

        $values = [
            'registration_gift_credits' => $credits,
            'registration_gift_plan_id' => $planId > 0 ? (string) $planId : '',
            'merchant_announcement_enabled' => $announcementEnabled,
            'merchant_announcement_title' => $title,
            'merchant_announcement_content' => $content,
            'merchant_announcement_contact_label' => $contactLabel,
            'merchant_announcement_contact_url' => $contactUrl,
        ];
        $settings->setMany($values, (int) $admin['id']);
        Audit::log('admin', (int) $admin['id'], 'operations.settings.update', 'system_settings', null, $this->request->ip(), [
            'registration_gift_credits' => (int) $credits,
            'registration_gift_plan_id' => $planId,
            'merchant_announcement_enabled' => $announcementEnabled === '1',
        ]);

        return Web::redirect('/admin/operations', '运营设置已保存，新注册商户和工作台公告将立即使用新配置。');
    }

    private function validContactUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
