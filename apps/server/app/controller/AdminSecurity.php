<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\ContentSafetyService;
use app\service\GeetestService;
use app\service\NewApiClient;
use app\service\SystemSettings;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminSecurity extends BaseController
{
    public function index(GeetestService $geetest, ContentSafetyService $contentSafety): Response
    {
        $admin = $this->admin();
        $geetestConfiguration = $geetest->configuration();
        $safetyConfiguration = $contentSafety->configuration();
        $violations = Db::name('content_violations')->alias('v')
            ->leftJoin('merchants m', 'm.id = v.merchant_id')
            ->leftJoin('merchant_users u', 'u.id = v.user_id')
            ->field('v.*,m.name as merchant_name,u.email as user_email')
            ->order('v.id', 'desc')->limit(200)->select()->toArray();
        $summary = [
            'total' => Db::name('content_violations')->count(),
            'penalties' => (int) Db::name('content_violations')->sum('penalty_credits'),
            'emailFailures' => Db::name('content_violations')->where('warning_email_status', 'failed')->count(),
        ];

        return $this->render('admin/security', compact('admin', 'geetestConfiguration', 'safetyConfiguration', 'violations', 'summary', 'contentSafety') + ['pageTitle' => '安全风控']);
    }

    public function save(SystemSettings $settings): Response
    {
        $admin = $this->admin();
        $captchaId = trim((string) $this->request->post('geetest_captcha_id', ''));
        $privateKey = trim((string) $this->request->post('geetest_private_key', ''));
        $model = trim((string) $this->request->post('content_safety_model', ''));
        $fallbackModel = trim((string) $this->request->post('content_safety_fallback_model', ''));
        $penaltyCredits = trim((string) $this->request->post('content_safety_penalty_credits', ''));
        $terms = trim((string) $this->request->post('content_safety_terms', ''));
        if (mb_strlen($captchaId) > 200 || mb_strlen($privateKey) > 300) {
            return Web::redirect('/admin/security', '极验密钥长度不正确。', 'error');
        }
        foreach ([$model, $fallbackModel] as $index => $modelName) {
            if (($index === 1 && $modelName === '') || mb_strlen($modelName) > 120 || ($modelName !== '' && !preg_match('/^[A-Za-z0-9._:\/-]+$/', $modelName))) {
                return Web::redirect('/admin/security', '内容安全模型名称格式不正确。', 'error');
            }
        }
        if (!preg_match('/^\d+$/', $penaltyCredits) || (int) $penaltyCredits > 1000000) {
            return Web::redirect('/admin/security', '违规处罚点数必须是 0 到 1000000 之间的整数。', 'error');
        }
        if (mb_strlen($terms) > 20000) {
            return Web::redirect('/admin/security', '违禁词列表不能超过 20000 个字符。', 'error');
        }
        $values = [
            'geetest_captcha_id' => $captchaId,
            'content_safety_enabled' => $this->request->post('content_safety_enabled') === '1' ? '1' : '0',
            'content_safety_external_enabled' => $this->request->post('content_safety_external_enabled') === '1' ? '1' : '0',
            'content_safety_fail_closed' => $this->request->post('content_safety_fail_closed') === '1' ? '1' : '0',
            'content_safety_model' => $model,
            'content_safety_fallback_model' => $fallbackModel,
            'content_safety_penalty_credits' => $penaltyCredits,
            'content_safety_terms' => $terms,
        ];
        if ($privateKey !== '') {
            $values['geetest_private_key'] = $privateKey;
        }
        $settings->setMany($values, (int) $admin['id']);
        Audit::log('admin', (int) $admin['id'], 'security.settings.update', 'system_settings', null, $this->request->ip(), [
            'geetest_captcha_id_configured' => $captchaId !== '',
            'geetest_private_key_updated' => $privateKey !== '',
            'content_safety_enabled' => $values['content_safety_enabled'] === '1',
            'content_safety_external_enabled' => $values['content_safety_external_enabled'] === '1',
            'content_safety_fail_closed' => $values['content_safety_fail_closed'] === '1',
            'content_safety_model' => $model,
            'content_safety_fallback_model' => $fallbackModel,
            'content_safety_penalty_credits' => (int) $penaltyCredits,
        ]);

        return Web::redirect('/admin/security', '安全风控设置已保存。');
    }

    public function test(NewApiClient $newApi, ContentSafetyService $contentSafety): Response
    {
        $admin = $this->admin();
        $configuration = $contentSafety->configuration();
        $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $textResult = $newApi->moderateContent(
            '普通家居用品，简洁实用的电商商品介绍。',
            null,
            $configuration['model'],
            $configuration['fallbackModel']
        );
        $imageResult = $newApi->moderateContent(null, [
            'content' => $imageContent === false ? '' : $imageContent,
            'mime' => 'image/png',
            'filename' => 'safety-test.png',
        ], $configuration['model'], $configuration['fallbackModel']);
        $ok = $textResult['ok'] && !$textResult['flagged'] && $imageResult['ok'] && !$imageResult['flagged'];
        $reason = !$textResult['ok'] || $textResult['flagged']
            ? '文字审核：' . ((string) ($textResult['reason'] ?? '') ?: '测试内容被异常判定为违规。')
            : '图片审核：' . ((string) ($imageResult['reason'] ?? '') ?: '测试内容被异常判定为违规。');
        Audit::log('admin', (int) $admin['id'], 'security.moderation.test', 'content_safety', null, $this->request->ip(), [
            'ok' => $ok,
            'text_model' => $textResult['model'] ?? null,
            'image_model' => $imageResult['model'] ?? null,
            'reason' => $ok ? null : $reason,
        ]);

        return Web::redirect('/admin/security', $ok
            ? '内容安全服务测试通过：文字使用 ' . (string) $textResult['model'] . '，图片使用 ' . (string) $imageResult['model'] . '。'
            : '内容安全服务测试失败：' . $reason, $ok ? 'success' : 'error');
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
