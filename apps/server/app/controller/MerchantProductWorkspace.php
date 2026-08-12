<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AiCapability;
use app\service\CreativeModelService;
use app\service\GenerationPreferenceService;
use app\service\PlanService;
use app\service\ProductCreativeReadinessService;
use app\service\PricingService;
use app\service\ProductLaunchTargetService;
use app\service\SkuProductReport;
use app\service\SkuWorkbenchService;
use app\service\VisionTaskQueue;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

/** Focused controller for the one-SKU, one-flow product workbench. */
final class MerchantProductWorkspace extends BaseController
{
    public function detail(
        int $id,
        PricingService $pricing,
        PlanService $plans,
        SkuProductReport $profiles,
        GenerationPreferenceService $preferences,
        ProductLaunchTargetService $targets,
        SkuWorkbenchService $workbenches,
        CreativeModelService $creativeModels
    ): Response {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $legacySection = trim((string) $this->request->get('section', ''));
        $legacyAnchor = [
            'overview' => 'workbench-overview', 'profile' => 'product-identity',
            'foundation' => 'product-report', 'report' => 'product-report', 'channels' => 'delivery-targets',
        ][$legacySection] ?? '';
        $sku = Db::name('skus')->where('id', $id)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find();
        if (!$sku) {
            return Web::redirect('/workspace/skus', 'SKU 不存在。', 'error');
        }
        $sku = $profiles->ensureFoundation($id, $merchantId) ?? $sku;
        $detailProjects = Db::name('detail_page_projects')->where('merchant_id', $merchantId)->where('sku_id', $id)
            ->where('status', '<>', 'deleted')->order('updated_at', 'desc')->select()->toArray();
        $launchTask = Db::name('vision_tasks')->where('merchant_id', $merchantId)->where('sku_id', $id)
            ->where('task_type', 'product_launch')->order('id', 'desc')->find();
        $targetState = $targets->forSku($sku, $launchTask ?: null, $detailProjects);
        $workbench = $workbenches->build($sku, $detailProjects, $targetState);
        $generationPreference = $preferences->forUser((int) $user['id']);
        $modelOptions = $creativeModels->options(
            $merchantId,
            [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT],
            'listing_bundle'
        );
        $modelName = trim((string) ($modelOptions[0]['name'] ?? $modelOptions[0]['modelId'] ?? '平台推荐图像模型'));
        $unitCredits = max(0, (int) $pricing->imageGenerationCredits());
        $deliveryManifests = [];
        foreach ((array) $workbench['uses'] as $use) {
            $count = (int) $use['listing_count'] + (int) $use['detail_count'];
            $quote = $plans->generationQuote($merchantId, $count, $unitCredits);
            $deliveryManifests[(string) $use['code']] = [
                'platforms' => array_values(array_unique(array_column((array) $workbench['targets'], 'platform_label'))),
                'languages' => array_values(array_unique(array_column((array) $workbench['targets'], 'language_label'))),
                'count' => $count,
                'roles' => $this->manifestRoles((string) $use['code']),
                'model' => $modelName !== '' ? $modelName : '平台推荐图像模型',
                'spec' => (string) $use['code'] === 'detail_page' ? '1024×1536 竖版' : ((string) $use['code'] === 'full_package' ? '上架图 1:1 + 详情页 2:3' : '1024×1024 1:1'),
                'credits' => max(0, (int) ($quote['credits'] ?? 0)),
            ];
        }
        return $this->render('merchant/sku_workbench', compact(
            'merchant', 'user', 'sku', 'detailProjects', 'workbench', 'deliveryManifests', 'legacyAnchor'
        ) + [
            'pageTitle' => '商品上新 · ' . $sku['name'],
            'workspaceProjectTitle' => (string) $sku['name'],
            'productReportReady' => (string) ($sku['report_status'] ?? '') === 'ready',
            'recognitionPrice' => $plans->recognitionCredits($merchantId, $pricing->productRecognitionCredits()),
            'platforms' => generation_platform_options(),
            'languages' => generation_language_options(),
            'preferredPlatform' => $generationPreference['platform'],
            'preferredLanguage' => $generationPreference['language'],
        ]);
    }

    public function updateFoundation(int $id, SkuProductReport $profiles, VisionTaskQueue $visionTasks, ProductCreativeReadinessService $readiness): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        if (!Db::name('skus')->where('id', $id)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find()) {
            return Web::redirect('/workspace/skus', 'SKU 不存在。', 'error');
        }
        $profiles->updateFoundation($id, $merchantId, $this->request->post());
        $visionTasks->resumeAfterSkuCorrection($id, $merchantId);
        Audit::log('merchant_user', (int) $user['id'], 'sku.foundation.update', 'sku', $id, $this->request->ip());
        $returnTo = $this->generationReturnPath($id);
        if ($returnTo !== '') {
            parse_str((string) (parse_url($returnTo, PHP_URL_QUERY) ?? ''), $query);
            $mode = (string) ($query['mode'] ?? 'bundle');
            $state = $mode === 'assets'
                ? $readiness->forVisualSku($id, $merchantId, 'marketing_creative')
                : $readiness->forSku($id, $merchantId);
            if (!empty($state['ready'])) {
                return Web::redirect($returnTo, '商品资料已保存并通过检查，可以继续创建。');
            }
        }
        return Web::redirect('/workspace/skus/' . $id . '#product-report', '产品报告已保存。');
    }

    public function updateTargets(int $id, ProductLaunchTargetService $targets): Response
    {
        [$merchant, $user] = $this->identity();
        if (!Db::name('skus')->where('id', $id)->where('merchant_id', (int) $merchant['id'])->where('status', '<>', 'deleted')->find()) {
            return Web::redirect('/workspace/skus', 'SKU 不存在。', 'error');
        }
        $state = $targets->save(
            $id,
            (int) $merchant['id'],
            $this->request->post('target_platform', []),
            $this->request->post('target_language', []),
            trim((string) $this->request->post('launch_direction', ''))
        );
        if ((array) ($state['targets'] ?? []) === []) {
            return Web::redirect('/workspace/skus/' . $id . '#delivery-targets', '请至少保留一个有效的平台与画面语言。', 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'sku.delivery_targets.update', 'sku', $id, $this->request->ip(), [
            'target_count' => count((array) $state['targets']),
        ]);
        return Web::redirect('/workspace/skus/' . $id . '#delivery-targets', '投放目标已保存；后续无需重新识别商品。');
    }

    private function manifestRoles(string $use): array
    {
        $listing = ['商品身份主图', '差异化主图', '卖点解释', '结构/多角度', '使用场景', '细节证明'];
        $detail = array_values(array_map(static fn (array $option): string => (string) $option['label'], detail_page_screen_options()));
        return match ($use) {
            'listing_images' => $listing,
            'detail_page' => $detail,
            default => array_merge($listing, $detail),
        };
    }

    private function generationReturnPath(int $skuId): string
    {
        $returnTo = trim((string) $this->request->post('return_to', ''));
        if ($returnTo === '' || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
            return '';
        }
        $parts = parse_url($returnTo);
        if ($parts === false || ($parts['path'] ?? '') !== '/workspace/generate' || isset($parts['scheme']) || isset($parts['host'])) {
            return '';
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $mode = (string) ($query['mode'] ?? 'bundle');
        if (!in_array($mode, ['assets', 'bundle', 'detail', 'video'], true) || (int) ($query['sku_id'] ?? 0) !== $skuId) {
            return '';
        }
        $safeQuery = ['mode' => $mode, 'sku_id' => $skuId];
        if ((int) ($query['project_id'] ?? 0) > 0) {
            $safeQuery['project_id'] = (int) $query['project_id'];
        }
        return '/workspace/generate?' . http_build_query($safeQuery);
    }

    private function identity(): array
    {
        return [
            Db::name('merchants')->where('id', (int) Session::get('merchant_id'))->find(),
            Db::name('merchant_users')->where('id', (int) Session::get('merchant_user_id'))->find(),
        ];
    }
}
