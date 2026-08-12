<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AiBusinessCompatibilityService;
use app\service\AiCapability;
use app\service\AiCapabilityAssignmentService;
use app\service\AiModelCatalogService;
use app\service\AiModelTestService;
use app\service\Sub2ApiModelSyncService;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;
use Throwable;

final class AdminAiCapabilities extends BaseController
{
    public function settings(
        AiModelCatalogService $catalog,
        AiCapabilityAssignmentService $assignments,
        AiBusinessCompatibilityService $compatibilities,
        Sub2ApiModelSyncService $sync
    ): Response {
        $admin = $this->admin();
        $tab = trim((string) $this->request->get('tab', 'models'));
        $tab = in_array($tab, ['models', 'pool', 'functions'], true) ? $tab : 'models';
        $selectedCapability = trim((string) $this->request->get('capability', ''));
        if (!AiCapability::exists($selectedCapability)) {
            $selectedCapability = (string) (array_key_first(AiCapability::definitions()) ?? '');
        }
        return $this->render('admin/ai_settings', [
            'admin' => $admin,
            'tab' => $tab,
            'selectedCapability' => $selectedCapability,
            'catalogData' => $catalog->dashboard(),
            'assignmentData' => $assignments->dashboard(),
            'compatibilityData' => $compatibilities->dashboard(),
            'lastSuccessfulSync' => $sync->lastSuccessfulBatch(),
            'pageTitle' => '模型与价格',
        ]);
    }

    public function index(AiModelCatalogService $catalog, AiCapabilityAssignmentService $assignments): Response
    {
        return Response::create('/admin/ai-settings', 'redirect');
    }

    public function models(AiModelCatalogService $catalog, Sub2ApiModelSyncService $sync): Response
    {
        return Response::create('/admin/ai-settings?tab=models', 'redirect');
    }

    public function model(int $id, AiModelCatalogService $catalog): Response
    {
        $admin = $this->admin();
        $model = $catalog->model($id);
        if (!$model) {
            return Web::redirect('/admin/ai-models', '模型不存在。', 'error');
        }
        $section = trim((string) $this->request->get('section', 'overview'));
        $section = in_array($section, ['overview', 'validation', 'tests'], true) ? $section : 'overview';
        return $this->render('admin/ai_model_detail', compact('admin', 'model') + [
            'section' => $section,
            'definitions' => AiCapability::definitions(),
            'products' => AiBusinessCompatibilityService::PRODUCTS,
            'pageTitle' => (string) $model['display_name'],
        ]);
    }

    public function sync(Sub2ApiModelSyncService $sync): Response
    {
        $admin = $this->admin();
        try {
            $result = $sync->sync((int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'ai_catalog.sync', 'ai_model_sync_batch', (int) $result['batch_id'], $this->request->ip(), [
                'discovered_count' => count((array) $result['discovered_aliases']),
                'new_count' => count((array) $result['new_aliases']),
                'missing_count' => count((array) $result['missing_aliases']),
                'sensitive_fields_copied' => false,
            ]);
            return Web::redirect(
                '/admin/ai-settings?tab=models',
                '模型同步完成，当前目录共 ' . count((array) $result['discovered_aliases'])
                . ' 个；同步不会自动分配或开放模型。'
            );
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-settings?tab=models', $exception->getMessage(), 'error');
        }
    }

    public function updateModel(int $id, AiModelCatalogService $catalog): Response
    {
        $admin = $this->admin();
        try {
            $model = $catalog->update($id, (array) $this->request->post(), (int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'ai_model.business_metadata.update', 'ai_model', $id, $this->request->ip(), [
                'display_name' => (string) $model['display_name'],
                'enabled' => (int) $model['enabled'],
            ]);
            return Web::redirect('/admin/ai-models/' . $id . '?section=overview', '模型业务信息已保存；未改变 Sub2API 上游配置。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-models/' . $id . '?section=overview', $exception->getMessage(), 'error');
        }
    }

    public function archiveModel(int $id, AiModelCatalogService $catalog): Response
    {
        $admin = $this->admin();
        try {
            $catalog->archive($id, (int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'ai_model.archived', 'ai_model', $id, $this->request->ip(), [
                'physical_delete' => false,
            ]);
            return Web::redirect('/admin/ai-models', '模型已归档；历史任务、调用、价格和账务记录均已保留。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-models/' . $id, $exception->getMessage(), 'error');
        }
    }

    public function protocolTest(int $id, AiModelTestService $tests): Response
    {
        $admin = $this->admin();
        try {
            $result = $tests->runProtocolTest($id, (int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'ai_model.protocol_test', 'ai_model', $id, $this->request->ip(), [
                'ok' => !empty($result['ok']),
                'test_id' => (int) $result['testId'],
            ]);
            return Web::redirect('/admin/ai-models/' . $id . '?section=overview', (string) $result['message'], !empty($result['ok']) ? 'success' : 'error');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-models/' . $id . '?section=overview', $exception->getMessage(), 'error');
        }
    }

    public function capabilityTest(int $id, AiModelTestService $tests): Response
    {
        $admin = $this->admin();
        $capability = trim((string) $this->request->post('capability', ''));
        try {
            $result = $tests->runCapabilityTest(
                $id,
                $capability,
                (string) $this->request->post('confirm_cost', '') === '1',
                (int) $admin['id']
            );
            Audit::log('admin', (int) $admin['id'], 'ai_model.capability_real_test', 'ai_model', $id, $this->request->ip(), [
                'capability' => $capability,
                'ok' => !empty($result['ok']),
                'test_id' => (int) $result['testId'],
            ]);
            return Web::redirect('/admin/ai-models/' . $id . '?section=validation', (string) $result['message'], !empty($result['ok']) ? 'success' : 'error');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-models/' . $id . '?section=validation', $exception->getMessage(), 'error');
        }
    }

    public function refreshVideoTest(int $id, AiModelTestService $tests): Response
    {
        $admin = $this->admin();
        $testId = max(0, (int) $this->request->post('test_id', 0));
        try {
            $result = $tests->refreshVideoTest($id, $testId, (int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'ai_model.video_test.refresh', 'ai_capability_test', $testId, $this->request->ip(), [
                'model_id' => $id,
                'status' => (string) ($result['status'] ?? ''),
                'new_paid_request_started' => false,
            ]);
            return Web::redirect(
                '/admin/ai-models/' . $id . '?section=tests',
                (string) $result['message'],
                !empty($result['ok']) ? 'success' : (!empty($result['pending']) ? 'success' : 'error')
            );
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-models/' . $id . '?section=tests', $exception->getMessage(), 'error');
        }
    }

    public function assignments(AiCapabilityAssignmentService $assignments): Response
    {
        return Response::create('/admin/ai-settings?tab=functions', 'redirect');
    }

    public function saveAssignments(AiCapabilityAssignmentService $assignments): Response
    {
        $admin = $this->admin();
        $returnCapability = trim((string) $this->request->post('return_capability', ''));
        $returnCapability = AiCapability::exists($returnCapability) ? $returnCapability : '';
        $returnUrl = '/admin/ai-settings?tab=functions'
            . ($returnCapability !== '' ? '&capability=' . rawurlencode($returnCapability) : '');
        try {
            $routes = (array) $this->request->post('routes', []);
            $assignments->saveRoutes($routes, (int) $admin['id']);
            Audit::log('admin', (int) $admin['id'], 'ai_capability.assignments.update', 'ai_capability_route', null, $this->request->ip(), [
                'capabilities' => array_keys($routes),
                'merchant_gray_rollout' => false,
            ]);
            return Web::redirect($returnUrl, '功能分配已保存；图片和视频失败时不会静默换模型。');
        } catch (Throwable $exception) {
            return Web::redirect($returnUrl, $exception->getMessage(), 'error');
        }
    }

    public function savePrices(AiCapabilityAssignmentService $assignments): Response
    {
        $admin = $this->admin();
        $returnCapability = trim((string) $this->request->post('return_capability', ''));
        $returnCapability = AiCapability::exists($returnCapability) ? $returnCapability : '';
        $returnUrl = '/admin/ai-settings?tab=functions'
            . ($returnCapability !== '' ? '&capability=' . rawurlencode($returnCapability) : '');
        try {
            $updated = $assignments->savePrices((array) $this->request->post('prices', []));
            Audit::log('admin', (int) $admin['id'], 'ai_capability.prices.update', 'ai_model_price', null, $this->request->ip(), [
                'updated' => $updated,
            ]);
            return Web::redirect($returnUrl, "模型计费策略已保存，共处理 {$updated} 项。");
        } catch (Throwable $exception) {
            return Web::redirect($returnUrl, $exception->getMessage(), 'error');
        }
    }

    public function compatibility(AiBusinessCompatibilityService $compatibilities): Response
    {
        return Response::create('/admin/ai-settings?tab=models', 'redirect');
    }

    public function compatibilityTest(int $id, AiModelTestService $tests): Response
    {
        $admin = $this->admin();
        $capability = trim((string) $this->request->post('capability', ''));
        $productCode = trim((string) $this->request->post('product_code', ''));
        try {
            $result = $tests->runCompatibilityTest(
                $id,
                $capability,
                $productCode,
                (string) $this->request->post('confirm_cost', '') === '1',
                (int) $admin['id']
            );
            Audit::log('admin', (int) $admin['id'], 'ai_model.business_compatibility_test', 'ai_model', $id, $this->request->ip(), [
                'capability' => $capability,
                'product_code' => $productCode,
                'ok' => !empty($result['ok']),
                'test_id' => (int) $result['testId'],
            ]);
            return Web::redirect('/admin/ai-settings?tab=models', (string) $result['message'], !empty($result['ok']) ? 'success' : 'error');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/ai-settings?tab=models', $exception->getMessage(), 'error');
        }
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
