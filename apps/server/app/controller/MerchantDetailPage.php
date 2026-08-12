<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AiCapability;
use app\service\AssetStorage;
use app\service\ContentSafetyService;
use app\service\CreativeModelService;
use app\service\DetailPageSelectionService;
use app\service\GenerationItemQueue;
use app\service\GenerationSubmissionGuard;
use app\service\GenerationPreferenceService;
use app\service\GenerationOperationResult;
use app\service\MarketResearchService;
use app\service\ModelCapabilityRouter;
use app\service\NewApiClient;
use app\service\PlanService;
use app\service\PricingService;
use app\service\ProductLaunchTargetService;
use app\service\RecognitionBillingService;
use app\service\SkuProductReport;
use app\service\UploadedImageNormalizer;
use app\service\VisionTaskQueue;
use app\support\Audit;
use app\support\Web;
use RuntimeException;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class MerchantDetailPage extends BaseController
{
    public function index(): Response
    {
        [$merchant, $user] = $this->identity();
        $projects = Db::name('detail_page_projects')->alias('p')
            ->leftJoin('generation_jobs j', 'j.detail_page_project_id=p.id')
            ->field('p.*,COUNT(j.id) as job_count,MAX(j.created_at) as last_generated_at')
            ->where('p.merchant_id', $merchant['id'])
            ->where('p.status', '<>', 'deleted')
            ->group('p.id')->order('p.id', 'desc')->limit(100)->select()->toArray();
        return $this->render('merchant/detail_pages', compact('merchant', 'user', 'projects') + ['pageTitle' => 'AI 详情页']);
    }

    public function createPage(PricingService $pricing, PlanService $plans, GenerationPreferenceService $preferences): Response
    {
        [$merchant, $user] = $this->identity();
        $generationPreference = $preferences->forUser((int) $user['id'], 'amazon', 'en-US');
        return $this->render('merchant/detail_page_new', compact('merchant', 'user') + [
            'pageTitle' => '新建 AI 详情页',
            'platforms' => generation_platform_options(),
            'languages' => generation_language_options(),
            'recognitionCredits' => $plans->recognitionCredits((int) $merchant['id'], $pricing->productRecognitionCredits()),
            'preferredPlatform' => $generationPreference['platform'],
            'preferredLanguage' => $generationPreference['language'],
        ]);
    }

    public function create(
        NewApiClient $newApi,
        AssetStorage $storage,
        UploadedImageNormalizer $normalizer,
        PricingService $pricing,
        PlanService $plans,
        ContentSafetyService $contentSafety,
        MarketResearchService $research,
        SkuProductReport $profiles,
        GenerationPreferenceService $preferences,
        VisionTaskQueue $visionTasks,
        RecognitionBillingService $recognitionBilling,
        ModelCapabilityRouter $router,
        ProductLaunchTargetService $launchTargets
    ): Response
    {
        [$merchant, $user] = $this->identity();
        $platform = (string) $this->request->post('platform', 'amazon');
        $language = (string) $this->request->post('language', 'en-US');
        $brief = $this->launchBrief();
        $asyncLaunch = $this->request->post('async_launch') === '1';
        $useMarketResearch = false;
        $marketQuery = '';
        if (!isset(generation_platform_options()[$platform]) || !isset(generation_language_options()[$language])) {
            if ($asyncLaunch) {
                return Response::create(GenerationOperationResult::failure(
                    'invalid_preferences', 'validation', '请选择有效的目标平台和画面语言。'
                ), 'json')->code(422);
            }
            return Web::redirect('/workspace/detail-pages/new', '请选择有效的目标平台和画面语言。', 'error');
        }
        $files = $this->request->file();
        $file = is_array($files) ? ($files['source_image'] ?? null) : null;
        if ($asyncLaunch) {
            try {
                $launchFiles = $this->productLaunchFiles(is_array($files) ? $files : []);
            } catch (\Throwable $exception) {
                return Response::create(GenerationOperationResult::failure(
                    'invalid_upload', 'validation', $exception->getMessage()
                ), 'json')->code(422);
            }
            if ($launchFiles === []) {
                return Response::create(GenerationOperationResult::failure(
                    'image_required', 'validation', '请至少上传一张清晰的商品参考图。'
                ), 'json')->code(422);
            }
            return $this->enqueueProductLaunch(
                $merchant,
                $user,
                $launchFiles,
                $platform,
                $language,
                $brief,
                $newApi,
                $storage,
                $normalizer,
                $pricing,
                $plans,
                $visionTasks,
                $recognitionBilling,
                $router
            );
        }
        if (!$file) {
            return Web::redirect('/workspace/detail-pages/new', '请上传一张清晰的商品实拍图。', 'error');
        }
        if ($this->request->post('create_product_report', '1') !== '1') {
            return $this->createSkuWithoutReport($merchant, $user, $file, $normalizer, $storage, $plans, $contentSafety, $preferences, $launchTargets, $platform, $language, $brief);
        }
        if ($useMarketResearch && $marketQuery === '') {
            return Web::redirect('/workspace/detail-pages/new', '启用联网市场洞察时，请填写商品名称或搜索关键词。', 'error');
        }
        $credits = 0;
        $draftToken = trim((string) $this->request->post('analysis_draft_token', ''));
        $reference = 'DETAIL-REPORT-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $charged = false;
        try {
            $image = $normalizer->normalize($file);
            $draft = $draftToken !== '' ? Session::get('market_query_draft_' . $draftToken) : null;
            $draftValid = is_array($draft)
                && (int) ($draft['merchant_id'] ?? 0) === (int) $merchant['id']
                && (int) ($draft['user_id'] ?? 0) === (int) $user['id']
                && (int) ($draft['expires_at'] ?? 0) >= time()
                && hash_equals((string) ($draft['image_hash'] ?? ''), hash('sha256', (string) $image['content']));
            $visionModel = $newApi->visionModel((int) $merchant['id']);
            $profilePricing = $pricing->priceForModel(AiCapability::PRODUCT_VISION, $visionModel, $pricing->productRecognitionCredits());
            $credits = ($draftValid ? 0 : $plans->recognitionCredits((int) $merchant['id'], (int) $profilePricing['credits']))
                + ($useMarketResearch ? $research->businessCredits() : 0);
            $contentSafety->enforceImage($merchant, $user, $image, 'detail_report_image', $this->request->ip());
            $contentSafety->enforceText($merchant, $user, $brief, 'detail_report_text', $this->request->ip());
            if ($credits > 0) {
                $this->chargeRecognition((int) $merchant['id'], $credits, $reference, 'AI 详情页商品资料');
                $charged = true;
            }
            $marketResult = $useMarketResearch ? $research->research((int) $merchant['id'], null, [
                'query' => $marketQuery, 'brief' => $brief,
            ], $platform) : null;
            $draftProduct = $draftValid && is_array($draft['product'] ?? null) ? $draft['product'] : [];
            $productContext = $draftProduct === [] ? [] : [
                'AI 预识别商品名称' => (string) ($draftProduct['name'] ?? ''),
                'AI 预识别商品类目' => (string) ($draftProduct['category'] ?? ''),
                'AI 预识别搜索词' => (array) ($draftProduct['searchTerms'] ?? []),
                'AI 预识别卖点' => (array) ($draftProduct['sellingPoints'] ?? []),
                'AI 预识别详情' => (string) ($draftProduct['productDescription'] ?? ''),
            ];
            if ($marketResult !== null) {
                $productContext['联网市场参考资料'] = $research->promptContext($marketResult);
            }
            $result = $newApi->analyzeDetailPageProduct($image, $platform, $language, $brief, $productContext, (int) $merchant['id']);
            if (!($result['ok'] ?? false)) {
                throw new RuntimeException((string) ($result['message'] ?? '商品资料整理失败。'));
            }
            $stored = $storage->storeSourceImageRecord((string) $image['content'], (string) $image['mime'], (int) $merchant['id'], 'detail-' . date('ymdHis'));
            $report = $result['report'];
            $now = Web::now();
            $skuCode = 'AI-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $marketFields = $this->marketProjectData($marketResult);
            $ratio = $this->preferredRatio();
            $model = $this->preferredImageModel($newApi);
            $projectId = (int) Db::transaction(function () use ($merchant, $user, $report, $stored, $platform, $language, $brief, $now, $result, $skuCode, $marketFields, $marketResult, $profiles, $launchTargets, $ratio, $model): int {
                $reportData = $this->projectData($report);
                $skuId = (int) Db::name('skus')->insertGetId([
                    'merchant_id' => $merchant['id'], 'sku_code' => $skuCode,
                    'name' => $reportData['product_name'], 'category' => $reportData['category'],
                    'selling_points' => $reportData['selling_points'],
                    'creative_brief' => null,
                    'product_description' => $reportData['product_description'],
                    'preferred_image_ratio' => $ratio, 'preferred_image_model' => $model,
                    'source_image_path' => $stored['url'], 'source_image_backup_path' => $stored['backupUrl'],
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $id = (int) Db::name('detail_page_projects')->insertGetId($reportData + $marketFields + [
                    'merchant_id' => $merchant['id'], 'created_by_user_id' => $user['id'],
                    'sku_id' => $skuId,
                    'creative_brief' => $brief !== '' ? $brief : null,
                    'source_image_url' => $stored['url'], 'source_image_backup_url' => $stored['backupUrl'],
                    'platform' => $platform, 'language' => $language, 'status' => 'draft',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $profiles->importReport($skuId, (int) $merchant['id'], $report);
                $launchTargets->persistFromLaunch($skuId, (int) $merchant['id'], [
                    'platform' => $platform, 'language' => $language, 'brief' => $brief,
                ]);
                if ((int) ($marketResult['cacheId'] ?? 0) > 0) {
                    Db::name('market_research_cache')->where('id', (int) $marketResult['cacheId'])->update(['sku_id' => $skuId, 'updated_at' => $now]);
                }
                if ($brief !== '') {
                    $this->insertMessage($id, (int) $merchant['id'], 'user', $brief, null, $now);
                }
                $this->insertMessage($id, (int) $merchant['id'], 'assistant', (string) $report['assistant_reply'], (string) ($result['requestId'] ?? ''), $now);
                return $id;
            });
            $preferences->remember((int) $user['id'], $platform, $language);
            Audit::log('merchant_user', (int) $user['id'], 'detail_page_project.create', 'detail_page_project', $projectId, $this->request->ip(), [
                'platform' => $platform, 'language' => $language, 'credits' => $credits,
                'market_research' => $useMarketResearch, 'market_cache_id' => (int) ($marketResult['cacheId'] ?? 0),
            ]);
            if ($draftValid) {
                Session::delete('market_query_draft_' . $draftToken);
            }
            $skuId = (int) Db::name('detail_page_projects')->where('id', $projectId)->value('sku_id');
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId . '&project_id=' . $projectId, '商品资料和详情页方案已建立，选择素材后即可开始创作。');
        } catch (\Throwable $exception) {
            if ($charged) {
                $this->refundRecognition((int) $merchant['id'], $credits, $reference, 'AI 详情页商品资料失败退款');
            }
            return Web::redirect('/workspace/detail-pages/new', $exception->getMessage() . ($charged ? '，已退回识别点数。' : ''), 'error');
        }
    }

    public function createForSku(int $id, NewApiClient $newApi, AssetStorage $storage, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety, MarketResearchService $research, SkuProductReport $profiles, GenerationPreferenceService $preferences): Response
    {
        [$merchant, $user] = $this->identity();
        $sku = Db::name('skus')->where('id', $id)->where('merchant_id', $merchant['id'])->where('status', 'active')->find();
        if (!$sku || empty($sku['source_image_path'])) {
            return Web::redirect('/workspace/skus/' . $id, '请先为 SKU 上传清晰的商品参考图。', 'error');
        }
        $platform = (string) $this->request->post('platform', 'douyin');
        $language = (string) $this->request->post('language', 'zh-CN');
        $brief = mb_strimwidth(trim((string) $this->request->post('creative_brief', '')), 0, 2000, '');
        $useMarketResearch = false;
        $marketQuery = mb_strimwidth(trim((string) $this->request->post('market_query', '')), 0, 180, '');
        $marketResearchPrepared = $this->request->post('market_research_prepared') === '1';
        $selectedMarketSources = $this->request->post('selected_market_sources', []);
        $selectedMarketSources = is_array($selectedMarketSources) ? array_values(array_filter(array_map('strval', $selectedMarketSources))) : [];
        $refreshProfile = $this->request->post('refresh_profile') === '1' || !$profiles->isReady($sku);
        if (!isset(generation_platform_options()[$platform]) || !isset(generation_language_options()[$language])) {
            return Web::redirect('/workspace/skus/' . $id, '请选择有效的目标平台和画面语言。', 'error');
        }

        $visionModel = $newApi->visionModel((int) $merchant['id']);
        $profilePricing = $pricing->priceForModel(AiCapability::PRODUCT_VISION, $visionModel, $pricing->productRecognitionCredits());
        $profileCredits = $refreshProfile ? $plans->recognitionCredits((int) $merchant['id'], (int) $profilePricing['credits']) : 0;
        $credits = $profileCredits;
        $reference = 'SKU-REPORT-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $charged = false;
        try {
            $image = $storage->readImageWithBackup((string) $sku['source_image_path'], (string) ($sku['source_image_backup_path'] ?? ''));
            $productContext = $profiles->reportContext($sku);
            $contentSafety->enforceImage($merchant, $user, $image, 'sku_detail_report_image', $this->request->ip());
            $contentSafety->enforceText($merchant, $user, json_encode($productContext + ['创作想法' => $brief], JSON_UNESCAPED_UNICODE), 'sku_detail_report_text', $this->request->ip());
            $marketResult = null;
            if ($useMarketResearch && $marketResearchPrepared) {
                $storedResearch = json_decode((string) ($sku['report_market_research_json'] ?? ''), true);
                if (!is_array($storedResearch) || empty($storedResearch['sources'])) {
                    throw new RuntimeException('联网候选来源已失效，请重新检索。');
                }
                $marketResult = $research->selectSources($storedResearch, $selectedMarketSources);
                if ((int) ($marketResult['selectedCount'] ?? 0) < 1) {
                    throw new RuntimeException('请至少选择一条联网来源，或关闭联网洞察。');
                }
            } elseif ($useMarketResearch) {
                $marketResult = $research->research((int) $merchant['id'], $id, [
                    'query' => $marketQuery, 'name' => (string) $sku['name'], 'category' => (string) $sku['category'], 'brief' => $brief,
                ], $platform);
                $marketResult = $research->selectSources($marketResult, array_map(static fn (array $source): string => (string) ($source['id'] ?? substr(hash('sha256', (string) ($source['url'] ?? '')), 0, 16)), (array) ($marketResult['sources'] ?? [])));
                if (empty($marketResult['cached'])) {
                    $credits += $research->businessCredits();
                }
            }
            if ($credits > 0) {
                $this->chargeRecognition((int) $merchant['id'], $credits, $reference, $refreshProfile ? 'SKU 商品事实识别' : 'SKU 联网市场洞察');
                $charged = true;
            }
            if ($marketResult !== null) {
                $productContext['联网市场参考资料'] = $research->promptContext($marketResult);
            }
            $result = $refreshProfile
                ? $newApi->analyzeDetailPageProduct($image, $platform, $language, $brief, $productContext, (int) $merchant['id'])
                : $newApi->buildDetailPagePlan(
                    $profiles->reportContext($sku),
                    $platform,
                    $language,
                    $brief,
                    $marketResult === null ? [] : $research->promptContext($marketResult),
                    (int) $merchant['id']
                );
            if (!($result['ok'] ?? false)) {
                throw new RuntimeException((string) ($result['message'] ?? '商品资料与详情页方案生成失败。'));
            }
            $report = $result['report'];
            $reportData = $this->projectData($report);
            $marketFields = $this->marketProjectData($marketResult);
            $now = Web::now();
            $existing = Db::name('detail_page_projects')->where('merchant_id', $merchant['id'])->where('sku_id', $id)
                ->where('platform', $platform)->where('language', $language)->order('id', 'desc')->find();
            $projectId = Db::transaction(function () use ($existing, $id, $merchant, $user, $sku, $report, $reportData, $marketFields, $marketResult, $result, $platform, $language, $brief, $now, $profiles, $refreshProfile): int {
                if ($existing) {
                    $projectId = (int) $existing['id'];
                    Db::name('detail_page_projects')->where('id', $projectId)->update($reportData + $marketFields + [
                        'created_by_user_id' => $user['id'], 'source_image_url' => $sku['source_image_path'],
                        'source_image_backup_url' => $sku['source_image_backup_path'] ?? null,
                        'platform' => $platform, 'language' => $language, 'creative_brief' => $brief !== '' ? $brief : null,
                        'status' => 'draft', 'updated_at' => $now,
                    ]);
                    Db::name('detail_page_messages')->where('project_id', $projectId)->delete();
                } else {
                    $projectId = (int) Db::name('detail_page_projects')->insertGetId($reportData + $marketFields + [
                        'merchant_id' => $merchant['id'], 'created_by_user_id' => $user['id'], 'sku_id' => $id,
                        'source_image_url' => $sku['source_image_path'], 'source_image_backup_url' => $sku['source_image_backup_path'] ?? null,
                        'platform' => $platform, 'language' => $language, 'creative_brief' => $brief !== '' ? $brief : null,
                        'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                if ($refreshProfile) {
                    $profiles->importReport($id, (int) $merchant['id'], $report);
                }
                if ($brief !== '') {
                    $this->insertMessage($projectId, (int) $merchant['id'], 'user', $brief, null, $now);
                }
                $this->insertMessage($projectId, (int) $merchant['id'], 'assistant', (string) $report['assistant_reply'], (string) ($result['requestId'] ?? ''), $now);
                return $projectId;
            });
            $preferences->remember((int) $user['id'], $platform, $language);
            Audit::log('merchant_user', (int) $user['id'], $existing ? 'sku.detail_report.refresh' : 'sku.detail_report.create', 'detail_page_project', $projectId, $this->request->ip(), [
                'sku_id' => $id, 'platform' => $platform, 'language' => $language, 'credits' => $credits,
                'market_research' => $useMarketResearch, 'market_cache_id' => (int) ($marketResult['cacheId'] ?? 0),
                'profile_reused' => !$refreshProfile,
            ]);
            if ($this->request->post('return_to_generation') === '1') {
                $mode = (string) $this->request->post('return_generation_mode', '') === 'bundle' ? 'bundle' : 'detail';
                return Web::redirect('/workspace/generate?mode=' . $mode . '&sku_id=' . $id . '&project_id=' . $projectId, $existing ? '详情页方案已更新，可以开始生成。' : '详情页方案已建立，可以开始生成。');
            }
            return Web::redirect('/workspace/detail-pages/' . $projectId, $existing ? '详情页方案已更新。' : '详情页方案已生成。');
        } catch (\Throwable $exception) {
            if ($charged) {
                $this->refundRecognition((int) $merchant['id'], $credits, $reference, '商品资料与详情页方案失败退款');
            }
            return Web::redirect('/workspace/skus/' . $id, $exception->getMessage() . ($charged ? '，已退回识别点数。' : ''), 'error');
        }
    }

    public function detail(int $id, PricingService $pricing, PlanService $plans, NewApiClient $newApi, CreativeModelService $creativeModels): Response
    {
        [$merchant, $user] = $this->identity();
        $project = $this->project($id, (int) $merchant['id']);
        if (!$project) {
            return Web::redirect('/workspace/detail-pages', '详情页项目不存在。', 'error');
        }
        $messages = Db::name('detail_page_messages')->where('project_id', $id)->where('merchant_id', $merchant['id'])->order('id', 'asc')->limit(100)->select()->toArray();
        $jobs = Db::name('generation_jobs')->where('detail_page_project_id', $id)->where('merchant_id', $merchant['id'])->order('id', 'desc')->limit(10)->select()->toArray();
        $marketResearch = json_decode((string) ($project['market_research_json'] ?? ''), true);
        $marketResearch = is_array($marketResearch) ? $marketResearch : null;
        $merchantId = (int) $merchant['id'];
        try {
            $selection = $creativeModels->resolveTask(
                AiCapability::IMAGE_EDIT,
                null,
                $merchantId,
                $pricing->imageGenerationCredits(),
                'detail_page'
            );
            $unitCredits = (int) $selection['price']['credits'];
            $defaultImageChoice = [
                'id' => max(0, (int) ($selection['resolvedModelId'] ?? 0)),
                'modelId' => (string) $selection['model'],
                'name' => (string) ($selection['route']['displayName'] ?? $selection['model']),
                'price' => $unitCredits,
            ];
        } catch (\Throwable) {
            $unitCredits = $pricing->imageGenerationCredits();
            $defaultImageChoice = ['id' => 0, 'modelId' => $newApi->defaultModel(), 'name' => $newApi->defaultModel(), 'price' => $unitCredits];
        }
        $quote = $plans->generationQuote($merchantId, 10, $unitCredits);
        $unitQuote = $plans->generationQuote($merchantId, 1, $unitCredits);
        return $this->render('merchant/detail_page_detail', compact('merchant', 'user', 'project', 'messages', 'jobs', 'marketResearch') + [
            'pageTitle' => '详情页方案 · ' . $project['product_name'],
            'screens' => json_decode((string) $project['screen_plan'], true) ?: [],
            'generationCredits' => (int) $quote['credits'],
            'generationUnitCredits' => (int) $unitQuote['credits'],
            'recognitionCredits' => $plans->recognitionCredits((int) $merchant['id'], $pricing->productRecognitionCredits()),
            'imageEditEnabled' => $creativeModels->canResolve(AiCapability::IMAGE_EDIT, $merchantId),
            'modelOptions' => $creativeModels->options($merchantId, [AiCapability::IMAGE_EDIT], 'detail_page'),
            'defaultImageChoice' => $defaultImageChoice,
            'detailOutputPresets' => detail_page_output_presets(),
        ]);
    }

    public function update(int $id, ContentSafetyService $contentSafety): Response
    {
        [$merchant, $user] = $this->identity();
        $project = $this->project($id, (int) $merchant['id']);
        if (!$project) {
            return Web::redirect('/workspace/detail-pages', '详情页项目不存在。', 'error');
        }
        $data = [
            'product_name' => mb_strimwidth(trim((string) $this->request->post('product_name', '')), 0, 160, ''),
            'category' => mb_strimwidth(trim((string) $this->request->post('category', '')), 0, 100, ''),
            'materials' => mb_strimwidth(trim((string) $this->request->post('materials', '')), 0, 1000, ''),
            'appearance' => mb_strimwidth(trim((string) $this->request->post('appearance', '')), 0, 1000, ''),
            'target_audience' => mb_strimwidth(trim((string) $this->request->post('target_audience', '')), 0, 1000, ''),
            'product_description' => mb_strimwidth(trim((string) $this->request->post('product_description', '')), 0, 5000, ''),
            'brand_notes' => mb_strimwidth(trim((string) $this->request->post('brand_notes', '')), 0, 2000, ''),
            'creative_brief' => mb_strimwidth(trim((string) $this->request->post('creative_brief', '')), 0, 2000, ''),
        ];
        foreach (['use_scenarios', 'selling_points', 'core_functions', 'uncertain_points', 'prohibited_claims'] as $field) {
            $data[$field] = json_encode($this->lines((string) $this->request->post($field, '')), JSON_UNESCAPED_UNICODE);
        }
        if ($data['product_name'] === '' || $data['category'] === '' || decode_json_list($data['selling_points']) === []) {
            return Web::redirect('/workspace/detail-pages/' . $id, '商品名称、类目和卖点不能为空。', 'error');
        }
        try {
            $contentSafety->enforceText($merchant, $user, implode("\n", array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $data)), 'detail_report_update', $this->request->ip());
            Db::transaction(function () use ($id, $merchant, $project, $data): void {
                Db::name('detail_page_projects')->where('id', $id)->where('merchant_id', $merchant['id'])->update($data + ['updated_at' => Web::now()]);
                // A detail-page plan never owns the SKU product foundation.
            });
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/detail-pages/' . $id, $exception->getMessage(), 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'detail_page_project.update', 'detail_page_project', $id, $this->request->ip());
        return Web::redirect('/workspace/detail-pages/' . $id, '当前方案的商品资料已保存。');
    }

    public function chat(int $id, NewApiClient $newApi, AssetStorage $storage, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety): Response
    {
        [$merchant, $user] = $this->identity();
        $project = $this->project($id, (int) $merchant['id']);
        $message = mb_strimwidth(trim((string) $this->request->post('message', '')), 0, 3000, '');
        if (!$project || mb_strlen($message) < 2) {
            return Response::create(['ok' => false, 'message' => '请输入要补充或修改的内容。'], 'json')->code(422);
        }
        $visionModel = $newApi->visionModel((int) $merchant['id']);
        $pricingSnapshot = $pricing->priceForModel(AiCapability::PRODUCT_VISION, $visionModel, $pricing->productRecognitionCredits());
        $credits = $plans->recognitionCredits((int) $merchant['id'], (int) $pricingSnapshot['credits']);
        $reference = 'DETAIL-CHAT-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $charged = false;
        try {
            $contentSafety->enforceText($merchant, $user, $message, 'detail_report_chat', $this->request->ip());
            if ($credits > 0) {
                $this->chargeRecognition((int) $merchant['id'], $credits, $reference, 'AI 详情页报告修订');
                $charged = true;
            }
            $image = $storage->readImageWithBackup((string) $project['source_image_url'], (string) ($project['source_image_backup_url'] ?? ''));
            $result = $newApi->refineDetailPageReport($this->projectToReport($project), $message, (string) $project['platform'], (string) $project['language'], $image);
            if (!($result['ok'] ?? false)) {
                throw new RuntimeException((string) ($result['message'] ?? '商品资料与详情页脚本修订失败。'));
            }
            $report = $result['report'];
            $now = Web::now();
            Db::transaction(function () use ($id, $merchant, $message, $report, $result, $now): void {
                $reportData = $this->projectData($report);
                Db::name('detail_page_projects')->where('id', $id)->where('merchant_id', $merchant['id'])->update($reportData + ['updated_at' => $now]);
                $project = Db::name('detail_page_projects')->where('id', $id)->find();
                // AI refinements apply only to this detail-page plan.
                $this->insertMessage($id, (int) $merchant['id'], 'user', $message, null, $now);
                $this->insertMessage($id, (int) $merchant['id'], 'assistant', (string) $report['assistant_reply'], (string) ($result['requestId'] ?? ''), $now);
            });
            Audit::log('merchant_user', (int) $user['id'], 'detail_page_project.chat', 'detail_page_project', $id, $this->request->ip(), ['credits' => $credits]);
            return Response::create(['ok' => true, 'message' => $report['assistant_reply'], 'report' => $report, 'creditsCharged' => $credits], 'json');
        } catch (\Throwable $exception) {
            if ($charged) {
                $this->refundRecognition((int) $merchant['id'], $credits, $reference, 'AI 详情页报告修订失败退款');
            }
            return Response::create(['ok' => false, 'message' => $exception->getMessage() . ($charged ? '，已退回识别点数。' : '')], 'json')->code(422);
        }
    }

    public function generate(int $id, NewApiClient $newApi, AssetStorage $storage, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety, GenerationItemQueue $queue, DetailPageSelectionService $detailSelection, CreativeModelService $creativeModels, GenerationSubmissionGuard $submissionGuard): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        try {
            $clientSubmissionId = $submissionGuard->normalize($this->request->post('client_submission_id', ''));
            $submissionKey = $submissionGuard->scopedKey($clientSubmissionId, 'detail_page:' . $id);
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/detail-pages/' . $id, $exception->getMessage(), 'error');
        }
        $project = $this->project($id, $merchantId);
        if (!$project) {
            return Web::redirect('/workspace/detail-pages', '详情页项目不存在。', 'error');
        }
        $screenCount = max(1, min(count(detail_page_screen_options()), (int) $this->request->post('screen_count', 10)));
        try {
            $detailOutput = detail_page_output_dimensions(
                (string) $this->request->post('detail_output_preset', 'portrait_2_3'),
                $this->request->post('detail_output_width'),
                $this->request->post('detail_output_height')
            );
        } catch (\InvalidArgumentException $exception) {
            return Web::redirect('/workspace/detail-pages/' . $id, $exception->getMessage(), 'error');
        }
        $assetTypes = $detailSelection->select($project, $screenCount);
        $batchLimit = $plans->batchImageLimit($merchantId, generation_job_image_limit());
        if (count($assetTypes) > $batchLimit) {
            return Web::redirect('/workspace/detail-pages/' . $id, "当前套餐单次最多生成 {$batchLimit} 张图片。", 'error');
        }
        $jobCapacity = $plans->canStartJobs($merchantId);
        if (!$jobCapacity['allowed']) {
            return Web::redirect('/workspace/jobs', (string) $jobCapacity['message'], 'error');
        }
        try {
            $requestedModelId = max(0, (int) $this->request->post('generation_model_id', 0));
            $modelSelection = $creativeModels->resolveTask(
                AiCapability::IMAGE_EDIT,
                $requestedModelId > 0 ? $requestedModelId : null,
                $merchantId,
                $pricing->imageGenerationCredits(),
                'detail_page'
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/detail-pages/' . $id, $exception->getMessage(), 'error');
        }
        $sku = Db::name('skus')->where('id', (int) ($project['sku_id'] ?? 0))->where('merchant_id', $merchantId)->find();
        if (!$sku) {
            return Web::redirect('/workspace/detail-pages/' . $id, '详情页方案缺少可用的商品档案，本次未创建任务、未预留点数。', 'error');
        }
        $submissionPayloadHash = $submissionGuard->payloadHash('detail_page_submission_v1', [
            'project_id' => $id,
            'sku_id' => (int) ($project['sku_id'] ?? 0),
            'asset_types' => $assetTypes,
            'detail_output' => $detailOutput,
            'requested_model_id' => $requestedModelId,
            'resolved_model_id' => (int) $modelSelection['resolvedModelId'],
        ]);
        $existingSubmission = $submissionGuard->existing($merchantId, $submissionKey);
        if ($existingSubmission !== null) {
            try {
                $submissionGuard->assertMatches($existingSubmission, $submissionPayloadHash);
            } catch (\Throwable $exception) {
                return Web::redirect('/workspace/detail-pages/' . $id, $exception->getMessage(), 'error');
            }
            return Web::redirect('/workspace/generate?mode=detail&sku_id=' . (int) ($project['sku_id'] ?? 0) . '&project_id=' . $id . '&task_center=open&task_tab=latest', '相同详情页生成请求已经提交，本次没有重复创建任务或扣点。');
        }
        $model = $modelSelection['model'];
        $pricingSnapshot = $modelSelection['price'];
        $quote = $plans->generationQuote($merchantId, count($assetTypes), (int) $pricingSnapshot['credits']);
        if (!$quote['allowed']) {
            return Web::redirect('/workspace/plans', (string) $quote['message'], 'error');
        }
        $estimatedCredits = (int) $quote['credits'];
        if ($this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']) < $estimatedCredits) {
            return Web::redirect('/workspace/usage', "点数不足，生成 {$screenCount} 张详情页图片需要 {$estimatedCredits} 点。本次未创建任务，也不会扣点。", 'error');
        }
        try {
            $configured = $newApi->configuration()['configured'];
            $ready = $configured && $newApi->hasAvailableModels(
                $model,
                $modelSelection['resolvedModelId'],
                (string) ($modelSelection['route']['resolvedGateway'] ?? ''),
                AiCapability::IMAGE_EDIT
            );
            $status = $ready ? 'queued' : 'pending_gateway';
            $submittedIp = $this->request->ip();
            $jobResult = Db::transaction(function () use ($merchantId, $user, $submittedIp, $id, $project, $assetTypes, $detailOutput, $status, $ready, $configured, $model, $modelSelection, $pricingSnapshot, $queue, $plans, $submissionGuard, $submissionKey, $submissionPayloadHash): array {
                $locked = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
                if (!$locked || $locked['status'] !== 'active') {
                    throw new RuntimeException('商户账户当前不可用。');
                }
                $existing = $submissionGuard->existing($merchantId, $submissionKey);
                if ($existing !== null) {
                    $submissionGuard->assertMatches($existing, $submissionPayloadHash);
                    return ['id' => (int) $existing['id'], 'reused' => true, 'credits' => max(0, (int) $existing['estimated_credits'])];
                }
                $capacity = $plans->canStartJobs($merchantId);
                if (!$capacity['allowed']) {
                    throw new RuntimeException((string) $capacity['message']);
                }
                $lockedQuote = $plans->generationQuote($merchantId, count($assetTypes), (int) $pricingSnapshot['credits']);
                if (!$lockedQuote['allowed']) {
                    throw new RuntimeException((string) $lockedQuote['message']);
                }
                $lockedCredits = (int) $lockedQuote['credits'];
                if ($this->availableGenerationCredits($merchantId, (int) $locked['credit_balance']) < $lockedCredits) {
                    throw new RuntimeException('可用点数已被其他进行中任务预留，本次未创建任务。');
                }
                $now = Web::now();
                $jobId = (int) Db::name('generation_jobs')->insertGetId([
                    'merchant_id' => $merchantId, 'sku_id' => (int) ($project['sku_id'] ?? 0) ?: null, 'detail_page_project_id' => $id,
                    'job_no' => 'DETAIL-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3))),
                    'platform' => $project['platform'], 'language' => $project['language'], 'asset_types' => json_encode($assetTypes),
                    'task_brief' => trim((string) ($project['creative_brief'] ?? '')) ?: null,
                    'task_context_json' => json_encode([
                        'mode' => 'detail_page', 'project_id' => $id, 'screen_count' => count($assetTypes),
                        'detail_output_preset' => $detailOutput['preset'],
                        'detail_output_width' => $detailOutput['width'],
                        'detail_output_height' => $detailOutput['height'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'submitted_by_user_id' => (int) $user['id'], 'submitted_ip' => $submittedIp,
                    'client_submission_id' => $submissionKey,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'requested_model_id' => $modelSelection['requestedModelId'],
                    'resolved_ai_model_id' => $modelSelection['resolvedModelId'],
                    'route_snapshot_json' => json_encode($modelSelection['route'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'pricing_snapshot_json' => json_encode($pricingSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'safety_status' => 'pending', 'safety_attempts' => 0,
                    'status' => $status, 'estimated_credits' => $lockedCredits, 'actual_credits' => 0,
                    'progress_current' => 0, 'progress_total' => count($assetTypes), 'progress_stage' => $ready ? 'safety_pending' : 'pending_gateway',
                    'progress_message' => $ready ? count($assetTypes) . ' 张详情页图片已提交，等待后台安全检查' : '等待管理员配置可用模型渠道',
                    'model' => $model, 'error_message' => $ready ? null : ($configured ? '模型没有可用渠道' : '等待配置模型网关'),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $queue->createItems($jobId, $merchantId, $assetTypes, $lockedCredits, $status);
                Db::name('detail_page_projects')->where('id', $id)->update(['status' => 'generating', 'updated_at' => $now]);
                return ['id' => $jobId, 'reused' => false, 'credits' => $lockedCredits];
            });
            $jobId = (int) $jobResult['id'];
            if (empty($jobResult['reused'])) {
                Audit::log('merchant_user', (int) $user['id'], 'detail_page_generation.create', 'generation_job', $jobId, $this->request->ip(), ['project_id' => $id, 'credits' => (int) $jobResult['credits'], 'detail_output' => $detailOutput]);
            }
            $message = !empty($jobResult['reused'])
                ? '相同详情页生成请求已经提交，本次没有重复创建任务或扣点。'
                : ($ready ? "{$screenCount} 张详情页图片已提交，生成进度已在右侧打开。" : '任务已保存，可在生成进度中查看并重试。');
            return Web::redirect('/workspace/generate?mode=detail&sku_id=' . (int) ($project['sku_id'] ?? 0) . '&project_id=' . $id . '&task_center=open&task_tab=latest', $message);
        } catch (\Throwable $exception) {
            $existing = $submissionGuard->existing($merchantId, $submissionKey);
            if ($existing !== null) {
                try {
                    $submissionGuard->assertMatches($existing, $submissionPayloadHash);
                    return Web::redirect('/workspace/generate?mode=detail&sku_id=' . (int) ($project['sku_id'] ?? 0) . '&project_id=' . $id . '&task_center=open&task_tab=latest', '相同详情页生成请求已经提交，本次没有重复创建任务或扣点。');
                } catch (\Throwable) {
                }
            }
            return Web::redirect('/workspace/detail-pages/' . $id, $exception->getMessage(), 'error');
        }
    }

    private function createSkuWithoutReport(array $merchant, array $user, mixed $file, UploadedImageNormalizer $normalizer, AssetStorage $storage, PlanService $plans, ContentSafetyService $contentSafety, GenerationPreferenceService $preferences, ProductLaunchTargetService $launchTargets, string $platform, string $language, string $brief): Response
    {
        $merchantId = (int) $merchant['id'];
        $limit = $plans->limit($merchantId, 'skus');
        $count = (int) Db::name('skus')->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->count();
        if ($limit > 0 && $count >= $limit) {
            return Web::redirect('/workspace/plans', "当前套餐最多管理 {$limit} 个 SKU，请升级套餐后继续上新。", 'error');
        }
        try {
            $image = $normalizer->normalize($file);
            $contentSafety->enforceImage($merchant, $user, $image, 'product_launch_image', $this->request->ip());
            $contentSafety->enforceText($merchant, $user, $brief, 'product_launch_text', $this->request->ip());
            $skuCode = 'DRAFT-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $stored = $storage->storeSourceImageRecord((string) $image['content'], (string) $image['mime'], $merchantId, $skuCode);
            $now = Web::now();
            $name = mb_strimwidth(trim((string) $this->request->post('product_name', '')), 0, 160, '');
            $skuId = (int) Db::name('skus')->insertGetId([
                'merchant_id' => $merchantId, 'sku_code' => $skuCode,
                'name' => $name !== '' ? $name : '待完善商品 ' . date('m-d H:i'),
                'category' => '待 AI 建档', 'creative_brief' => null,
                'preferred_image_ratio' => $this->preferredRatio(), 'preferred_image_model' => $this->preferredImageModelFromRequest(),
                'source_image_path' => $stored['url'], 'source_image_backup_path' => $stored['backupUrl'],
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $launchTargets->persistFromLaunch($skuId, $merchantId, [
                'platform' => $platform, 'language' => $language, 'brief' => $brief,
            ]);
            $preferences->remember((int) $user['id'], $platform, $language);
            Audit::log('merchant_user', (int) $user['id'], 'product_launch.sku_only', 'sku', $skuId, $this->request->ip(), ['platform' => $platform, 'language' => $language]);
            return Web::redirect('/workspace/generate?sku_id=' . $skuId, '商品已建立。补充商品资料后，主图与详情页会更贴近实物。');
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/generate', $exception->getMessage(), 'error');
        }
    }

    private function enqueueProductLaunch(
        array $merchant,
        array $user,
        array $files,
        string $platform,
        string $language,
        string $brief,
        NewApiClient $newApi,
        AssetStorage $storage,
        UploadedImageNormalizer $normalizer,
        PricingService $pricing,
        PlanService $plans,
        VisionTaskQueue $visionTasks,
        RecognitionBillingService $recognitionBilling,
        ModelCapabilityRouter $router
    ): Response {
        $merchantId = (int) $merchant['id'];
        $launchPlan = strtolower(trim((string) $this->request->post('launch_plan', 'smart')));
        if (!in_array($launchPlan, ['smart', 'quick', 'bundle', 'detail'], true)) {
            $launchPlan = 'smart';
        }
        $clientSubmissionId = trim((string) $this->request->post('client_submission_id', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $clientSubmissionId)) {
            return Response::create(GenerationOperationResult::failure(
                'invalid_submission_id', 'validation', '提交标识无效，请刷新页面后重试。'
            ), 'json')->code(422);
        }
        $existingTask = $visionTasks->taskByClientSubmission($merchantId, $clientSubmissionId);
        if ($existingTask) {
            $payload = $visionTasks->statusPayload((int) $existingTask['id'], $merchantId) ?: [];
            return Response::create(GenerationOperationResult::success($payload + [
                'stage' => 'confirming',
                'taskCreated' => true,
                'creditsReserved' => (int) ($existingTask['credits'] ?? 0) > 0,
                'deduplicated' => true,
                'taskId' => (int) $existingTask['id'],
                'statusUrl' => '/workspace/vision-tasks/' . (int) $existingTask['id'],
                'message' => '系统已确认这次提交，正在继续原任务，不会重复扣点或重复调用模型。',
            ]), 'json')->code(202);
        }
        $createReport = true;
        $limit = $plans->limit($merchantId, 'skus');
        $count = (int) Db::name('skus')->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->count();
        if ($limit > 0 && $count >= $limit) {
            return Response::create(GenerationOperationResult::failure(
                'sku_limit_reached', 'validation', "当前套餐最多管理 {$limit} 个 SKU，请升级套餐后继续上新。"
            ), 'json')->code(422);
        }

        try {
            $resolution = [];
            if ($createReport) {
                $resolution = $router->resolve(AiCapability::PRODUCT_VISION, null, $merchantId);
                $model = (string) ($resolution['resolvedModel'] ?? $newApi->visionModel($merchantId));
                $fallbackModel = (string) ($resolution['fallbackModel'] ?? '');
            } else {
                $model = $newApi->visionModel($merchantId);
                $fallbackModel = '';
            }
            $plannerResolution = $router->resolve(AiCapability::CONTENT_PLANNER, null, $merchantId);
        } catch (\Throwable $exception) {
            return Response::create(GenerationOperationResult::failure(
                'capability_unavailable', 'validation', localized_job_error($exception->getMessage()),
                false, false, 'not_applicable', true
            ), 'json')->code(422);
        }
        $pricingSnapshot = $pricing->priceForModel(AiCapability::PRODUCT_VISION, $model, $pricing->productRecognitionCredits());
        $credits = $createReport ? $plans->recognitionCredits($merchantId, (int) $pricingSnapshot['credits']) : 0;
        $balance = (int) $merchant['credit_balance'];
        if ($credits > 0 && $recognitionBilling->availableCredits($merchantId, $balance) < $credits) {
            return Response::create(GenerationOperationResult::failure(
                'insufficient_credits', 'validation', "点数不足，商品识别需要 {$credits} 点。本次未创建任务，也不会扣点。",
                false, false, 'not_applicable', false, null, null, ['balance' => $balance]
            ), 'json')->code(422);
        }

        $storedRecords = [];
        $charged = false;
        $reference = 'LAUNCH-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $uploadStarted = microtime(true);
            $images = [];
            $originalInputBytes = 0;
            foreach (array_slice($files, 0, 6) as $file) {
                if (is_object($file) && method_exists($file, 'getSize')) {
                    $originalInputBytes += max(0, (int) $file->getSize());
                }
                $images[] = $normalizer->normalize($file);
            }
            if ($images === []) {
                throw new \RuntimeException('请至少上传一张清晰的商品参考图。');
            }
            $image = $images[0];
            if ($credits > 0) {
                [$ledgerId, $balance] = $recognitionBilling->reserve($merchantId, $credits, $reference);
                $charged = true;
            } else {
                $ledgerId = null;
            }
            foreach ($images as $index => $preparedImage) {
                $storedRecords[] = $storage->storeSourceImageRecord(
                    (string) $preparedImage['content'],
                    (string) $preparedImage['mime'],
                    $merchantId,
                    strtolower($reference) . '-' . ($index + 1)
                );
            }
            $stored = $storedRecords[0];
            $additionalReferences = [];
            foreach (array_slice($storedRecords, 1) as $index => $record) {
                $additionalReferences[] = [
                    'url' => (string) $record['url'],
                    'backup_url' => trim((string) ($record['backupUrl'] ?? '')) ?: null,
                    'hash' => hash('sha256', (string) $images[$index + 1]['content']),
                    'position' => $index + 2,
                ];
            }
            $context = [
                'purpose' => 'product_launch',
                'platform' => $platform,
                'language' => $language,
                'brief' => $brief,
                'launch_plan' => $launchPlan,
                'create_report' => $createReport,
                'image_ratio' => 'auto',
                'image_model' => '',
                'product_name' => mb_strimwidth(trim((string) $this->request->post('product_name', '')), 0, 160, ''),
                'submitted_ip' => $this->request->ip(),
                'merchantId' => $merchantId,
                'reference_images' => $additionalReferences,
                'reference_count' => count($images),
                'resolvedModelId' => max(0, (int) ($resolution['resolvedModelId'] ?? 0)),
                'resolvedGateway' => (string) ($resolution['resolvedGateway'] ?? 'sub2api'),
                'fallbackModelId' => max(0, (int) ($resolution['fallbackModelId'] ?? 0)),
                'fallbackGateway' => (string) ($resolution['fallbackGateway'] ?? ''),
                'vision_route_snapshot' => $resolution,
                'planner_route_snapshot' => $plannerResolution,
                'plannerResolvedModelId' => max(0, (int) ($plannerResolution['resolvedModelId'] ?? 0)),
                'plannerResolvedModel' => (string) ($plannerResolution['resolvedModel'] ?? ''),
                'plannerResolvedGateway' => (string) ($plannerResolution['resolvedGateway'] ?? 'sub2api'),
                'plannerFallbackModelId' => max(0, (int) ($plannerResolution['fallbackModelId'] ?? 0)),
                'plannerFallbackModel' => (string) ($plannerResolution['fallbackModel'] ?? ''),
                'plannerFallbackGateway' => (string) ($plannerResolution['fallbackGateway'] ?? ''),
                'client_submission_id' => $clientSubmissionId,
            ];
            $baseHash = $visionTasks->requestHash($image, $context, $model);
            $task = $visionTasks->enqueue([
                'merchant_id' => $merchantId,
                'user_id' => (int) $user['id'],
                'task_type' => 'product_launch',
                'client_submission_id' => $clientSubmissionId,
                'image_url' => (string) $stored['url'],
                'image_backup_url' => (string) ($stored['backupUrl'] ?? ''),
                'temporary_input' => true,
                'image_hash' => hash('sha256', (string) $image['content']),
                'request_hash' => hash('sha256', $baseHash . "\0" . $reference),
                'context' => $context,
                'model' => $model,
                'fallback_model' => $fallbackModel,
                'pricing_snapshot' => $pricingSnapshot,
                'credits' => $credits,
                'ledger_id' => $ledgerId,
                'billing_reference' => $reference,
                'priority' => 75,
                'progress_percent' => 8,
                'progress_stage' => 'queued',
                'progress_message' => count($images) > 1
                    ? count($images) . ' 张参考图已上传，等待后台' . ($createReport ? '整理商品资料。' : '创建商品。')
                    : '商品图已上传，等待后台' . ($createReport ? '整理商品资料。' : '创建商品。'),
                'vision_route_snapshot' => $resolution,
                'planner_route_snapshot' => $plannerResolution,
                'input_image_count' => count($images),
                'original_input_bytes' => $originalInputBytes,
                'normalized_input_bytes' => array_sum(array_map(static fn (array $item): int => strlen((string) ($item['content'] ?? '')), $images)),
                'stage_metrics' => [
                    'upload_ms' => (int) round((microtime(true) - $uploadStarted) * 1000),
                ],
            ]);
            if (!empty($task['_deduplicated'])) {
                if ($charged) {
                    $recognitionBilling->refund($merchantId, $credits, $reference, '商品上新重复提交退款');
                }
                foreach ($storedRecords as $record) {
                    foreach (array_unique(array_filter([(string) ($record['url'] ?? ''), (string) ($record['backupUrl'] ?? '')])) as $url) {
                        try { $storage->deleteStoredUrl($url); } catch (\Throwable) {}
                    }
                }
                $payload = $visionTasks->statusPayload((int) $task['id'], $merchantId) ?: [];
                return Response::create(GenerationOperationResult::success($payload + [
                    'stage' => 'confirming', 'taskCreated' => true, 'deduplicated' => true,
                    'taskId' => (int) $task['id'], 'statusUrl' => '/workspace/vision-tasks/' . (int) $task['id'],
                    'message' => '系统已确认原提交，未重复扣点或重复调用模型。',
                ]), 'json')->code(202);
            }
            Audit::log('merchant_user', (int) $user['id'], 'product_launch.queued', 'vision_task', (int) $task['id'], $this->request->ip(), [
                'platform' => $platform,
                'language' => $language,
                'credits' => $credits,
                'model' => $model,
                'fallback_model' => $fallbackModel,
                'reference_count' => count($images),
                'launch_plan' => $launchPlan,
            ]);
            return Response::create(GenerationOperationResult::success([
                'stage' => 'received',
                'taskCreated' => true,
                'creditsReserved' => $charged,
                'queued' => true,
                'taskId' => (int) $task['id'],
                'status' => 'queued',
                'statusUrl' => '/workspace/vision-tasks/' . (int) $task['id'],
                'progressPercent' => 8,
                'progressStage' => 'queued',
                'message' => count($images) > 1
                    ? count($images) . ' 张参考图已上传，后续审核与' . ($createReport ? '建档' : '商品创建') . '将在后台自动完成。'
                    : '商品图已上传，后续审核与' . ($createReport ? '建档' : '商品创建') . '将在后台自动完成。',
                'balance' => $balance,
            ]), 'json')->code(202);
        } catch (\Throwable $exception) {
            $refundStatus = 'not_applicable';
            if ($charged) {
                try {
                    $recognitionBilling->refund($merchantId, $credits, $reference, '商品上新建档失败退款');
                    $refundStatus = 'refunded';
                } catch (\Throwable) {
                    $refundStatus = 'refund_failed';
                }
            }
            foreach ($storedRecords as $record) {
                foreach (array_unique(array_filter([(string) ($record['url'] ?? ''), (string) ($record['backupUrl'] ?? '')])) as $url) {
                    try {
                        $storage->deleteStoredUrl($url);
                    } catch (\Throwable) {
                    }
                }
            }
            try {
                Audit::log(
                    'merchant_user',
                    (int) $user['id'],
                    'product_launch.create_failed',
                    'product_launch_submission',
                    $clientSubmissionId,
                    $this->request->ip(),
                    [
                        'internal_error_class' => $exception::class,
                        'internal_error_message' => mb_strimwidth($exception->getMessage(), 0, 1000, ''),
                        'credits_reserved' => $charged,
                        'refund_status' => $refundStatus,
                        'stored_reference_count' => count($storedRecords),
                        'model' => $model,
                        'fallback_model' => $fallbackModel,
                    ]
                );
            } catch (\Throwable) {
                // Failure diagnostics must never replace the original response.
            }
            $message = localized_job_error($exception->getMessage());
            if ($refundStatus === 'refunded') {
                $message .= '，预留点数已退回。';
            } elseif ($refundStatus === 'refund_failed') {
                $message .= '，退款状态待管理员核对。';
            }
            return Response::create(GenerationOperationResult::failure(
                'launch_create_failed', $charged ? 'task_creation' : 'validation', $message,
                false, $charged, $refundStatus, $charged
            ), 'json')->code(422);
        }
    }

    private function productLaunchFiles(array $files): array
    {
        $uploaded = $files['source_images'] ?? $files['source_image'] ?? [];
        $uploaded = is_array($uploaded) ? $uploaded : [$uploaded];
        $uploaded = array_values(array_filter($uploaded, static fn (mixed $file): bool => is_object($file)));
        if (count($uploaded) > 6) {
            throw new \InvalidArgumentException('单次最多上传 6 张商品参考图。');
        }
        return $uploaded;
    }

    private function launchBrief(): string
    {
        $parts = [
            trim((string) $this->request->post('creative_brief', '')),
            trim((string) $this->request->post('target_audience', '')) !== '' ? '目标用户：' . trim((string) $this->request->post('target_audience', '')) : '',
            trim((string) $this->request->post('visual_preference', '')) !== '' ? '画面期待：' . trim((string) $this->request->post('visual_preference', '')) : '',
            trim((string) $this->request->post('highlight_features', '')) !== '' ? '重点突出：' . trim((string) $this->request->post('highlight_features', '')) : '',
        ];
        return mb_strimwidth(implode("\n", array_filter($parts)), 0, 2000, '');
    }

    private function preferredRatio(): string
    {
        $ratio = (string) $this->request->post('image_ratio', 'auto');
        return in_array($ratio, ['auto', '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '3:1', '1:3', '21:9', '9:21', '5:4', '4:5'], true) ? $ratio : 'auto';
    }

    private function preferredImageModel(NewApiClient $newApi): string
    {
        $model = $this->preferredImageModelFromRequest();
        foreach ($newApi->modelCatalog() as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $model && !empty($candidate['imageEdit'])) {
                return $model;
            }
        }
        return $newApi->defaultModel();
    }

    private function preferredImageModelFromRequest(): string
    {
        return mb_strimwidth(trim((string) $this->request->post('image_model', '')), 0, 160, '');
    }

    private function project(int $id, int $merchantId): ?array
    {
        $project = Db::name('detail_page_projects')->where('id', $id)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find();
        return $project ?: null;
    }

    private function projectData(array $report): array
    {
        $data = [];
        foreach (['product_name', 'category', 'materials', 'appearance', 'target_audience', 'product_description', 'brand_notes'] as $field) {
            $data[$field] = $this->reportText($report[$field] ?? '') ?: null;
        }
        foreach (['use_scenarios', 'selling_points', 'core_functions', 'uncertain_points', 'prohibited_claims', 'screen_plan'] as $field) {
            $data[$field] = json_encode(is_array($report[$field] ?? null) ? $report[$field] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $data;
    }

    private function marketProjectData(?array $research): array
    {
        if ($research === null) {
            return [];
        }
        return [
            'market_research_json' => json_encode($research, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'market_research_query' => (string) ($research['query'] ?? ''),
            'market_research_updated_at' => (string) ($research['researchedAt'] ?? Web::now()),
        ];
    }

    private function reportText(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (!is_array($value)) {
            return '';
        }
        $parts = array_map(fn (mixed $item): string => $this->reportText($item), $value);
        return implode('；', array_values(array_unique(array_filter($parts, static fn (string $item): bool => $item !== ''))));
    }

    private function projectToReport(array $project): array
    {
        $report = [];
        foreach (['product_name', 'category', 'materials', 'appearance', 'target_audience', 'product_description', 'brand_notes', 'creative_brief'] as $field) {
            $report[$field] = (string) ($project[$field] ?? '');
        }
        foreach (['use_scenarios', 'selling_points', 'core_functions', 'uncertain_points', 'prohibited_claims', 'screen_plan'] as $field) {
            $decoded = json_decode((string) ($project[$field] ?? '[]'), true);
            $report[$field] = is_array($decoded) ? $decoded : [];
        }
        return $report;
    }

    private function syncSkuFromReport(int $skuId, int $merchantId, array $report, string $brief = '', int $projectId = 0): void
    {
        unset($skuId, $merchantId, $report, $brief, $projectId);
    }

    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,，、]+/u', $value) ?: [])));
    }

    private function insertMessage(int $projectId, int $merchantId, string $role, string $content, ?string $requestId, string $now): void
    {
        Db::name('detail_page_messages')->insert([
            'project_id' => $projectId, 'merchant_id' => $merchantId, 'role' => $role,
            'content' => $content, 'request_id' => $requestId ?: null, 'created_at' => $now,
        ]);
    }

    private function identity(): array
    {
        return [
            Db::name('merchants')->where('id', (int) Session::get('merchant_id'))->find(),
            Db::name('merchant_users')->where('id', (int) Session::get('merchant_user_id'))->find(),
        ];
    }

    private function availableGenerationCredits(int $merchantId, int $balance): int
    {
        $jobs = Db::name('generation_jobs')->where('merchant_id', $merchantId)->whereIn('status', ['queued', 'pending_gateway'])
            ->field('estimated_credits,actual_credits')->select()->toArray();
        $committed = array_reduce($jobs, static fn (int $sum, array $job): int => $sum + max(0, (int) $job['estimated_credits'] - (int) $job['actual_credits']), 0);
        return max(0, $balance - $committed);
    }

    private function chargeRecognition(int $merchantId, int $credits, string $reference, string $note): void
    {
        Db::transaction(function () use ($merchantId, $credits, $reference, $note): void {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant || $merchant['status'] !== 'active' || $this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']) < $credits) {
                throw new RuntimeException("点数不足，本次 AI 分析需要 {$credits} 点。本次未执行，也不会扣点。");
            }
            $balance = (int) $merchant['credit_balance'] - $credits;
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            Db::name('credit_ledger')->insert([
                'merchant_id' => $merchantId, 'type' => 'recognition_consume', 'amount' => -$credits, 'balance_after' => $balance,
                'source' => 'product_recognition', 'reference_type' => 'detail_page_report', 'reference_id' => $reference,
                'note' => $note, 'created_at' => $now,
            ]);
        });
    }

    private function refundRecognition(int $merchantId, int $credits, string $reference, string $note): void
    {
        Db::transaction(function () use ($merchantId, $credits, $reference, $note): void {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant || Db::name('credit_ledger')->where('merchant_id', $merchantId)->where('type', 'recognition_refund')->where('reference_id', $reference)->find()) {
                return;
            }
            $balance = (int) $merchant['credit_balance'] + $credits;
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            Db::name('credit_ledger')->insert([
                'merchant_id' => $merchantId, 'type' => 'recognition_refund', 'amount' => $credits, 'balance_after' => $balance,
                'source' => 'product_recognition', 'reference_type' => 'detail_page_report', 'reference_id' => $reference,
                'note' => $note, 'created_at' => $now,
            ]);
        });
    }
}
