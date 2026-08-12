<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AdminOnboardingService;
use app\service\AiCapabilityAssignmentService;
use app\service\AiModelCatalogService;
use app\service\Sub2ApiClient;
use app\service\Sub2ApiModelSyncService;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;
use Throwable;

final class AdminSetup extends BaseController
{
    public function index(
        AdminOnboardingService $onboarding,
        Sub2ApiClient $gateway,
        AiModelCatalogService $catalog,
        AiCapabilityAssignmentService $assignments
    ): Response {
        if (!$onboarding->enabled()) {
            return Web::redirect('/admin');
        }
        $admin = $this->admin();
        $progress = $onboarding->progress((int) $admin['id']);
        return $this->render('admin/setup', [
            'admin' => $admin,
            'progress' => $progress,
            'gateway' => $gateway->configuration(),
            'catalog' => $catalog->dashboard(),
            'assignments' => $assignments->dashboard(),
            'pageTitle' => '首次配置向导',
            'bodyClass' => 'admin-setup-page',
        ]);
    }

    public function advance(
        AdminOnboardingService $onboarding,
        Sub2ApiClient $gateway,
        Sub2ApiModelSyncService $sync
    ): Response {
        if (!$onboarding->enabled()) {
            return Web::redirect('/admin');
        }
        $admin = $this->admin();
        $adminId = (int) $admin['id'];
        $step = trim((string) $this->request->post('step', ''));
        try {
            $details = [];
            if ($step === 'connect_gateway') {
                $configuration = $gateway->configuration();
                if (empty($configuration['configured'])) {
                    throw new \RuntimeException('AI 网关尚未完成连接配置，请先在服务器安全配置中补全地址和服务密钥。');
                }
                $details = ['configured' => true, 'base_url' => (string) $configuration['baseUrl']];
            } elseif ($step === 'sync_models') {
                if ((string) $this->request->post('confirm_sync', '') !== '1') {
                    throw new \RuntimeException('请确认后再同步模型目录。');
                }
                $result = $sync->sync($adminId);
                $details = [
                    'discovered_count' => count((array) ($result['discovered_aliases'] ?? [])),
                    'new_count' => count((array) ($result['new_aliases'] ?? [])),
                    'missing_count' => count((array) ($result['missing_aliases'] ?? [])),
                ];
            } elseif ($step === 'verify_models') {
                $details = [
                    'paid_test_started' => false,
                    'note' => '向导只检查已有结果；图片和视频真实测试需要另行确认费用。',
                ];
            } elseif ($step === 'first_creation') {
                $details = [
                    'paid_request_started' => false,
                    'note' => '向导不会自动创建付费任务。',
                ];
            }
            $progress = $onboarding->advance($adminId, $step, $details);
            Audit::log('admin', $adminId, 'admin_onboarding.advance', 'admin_onboarding_progress', (int) $progress['id'], $this->request->ip(), [
                'step' => $step,
                'status' => (string) $progress['status'],
                'paid_request_started' => false,
            ]);
            if ((string) $progress['status'] === 'completed') {
                return Web::redirect('/admin', '首次配置已完成，可以开始日常运营。');
            }
            return Web::redirect('/admin/setup', '本步骤已保存，可以继续下一步。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/setup', $exception->getMessage(), 'error');
        }
    }

    public function skipVideo(AdminOnboardingService $onboarding): Response
    {
        if (!$onboarding->enabled()) {
            return Web::redirect('/admin');
        }
        $admin = $this->admin();
        $progress = $onboarding->skipVideo((int) $admin['id']);
        Audit::log('admin', (int) $admin['id'], 'admin_onboarding.video_skipped', 'admin_onboarding_progress', (int) $progress['id'], $this->request->ip(), [
            'video_models_opened' => false,
        ]);
        return Web::redirect('/admin/setup', '视频能力已暂时跳过；未经真实测试的视频模型不会向用户开放。');
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
