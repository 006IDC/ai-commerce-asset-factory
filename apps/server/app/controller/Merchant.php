<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AiCapability;
use app\service\AssetStorage;
use app\service\AssetTrashService;
use app\service\ContentSafetyService;
use app\service\CreativeModelService;
use app\service\CreativeProjectService;
use app\service\DetailPageSelectionService;
use app\service\FreeCreationIntentService;
use app\service\GenerationItemQueue;
use app\service\GenerationJobView;
use app\service\GenerationPreferenceService;
use app\service\GenerationOperationResult;
use app\service\GenerationSubmissionGuard;
use app\service\ModelCapabilityRouter;
use app\service\MerchantWorkspaceService;
use app\service\NewApiClient;
use app\service\PlanService;
use app\service\PricingService;
use app\service\ProductCreativeReadinessService;
use app\service\ProductLaunchTargetService;
use app\service\PromptTemplateService;
use app\service\RecognitionBillingService;
use app\service\SkuProductReport;
use app\service\UploadedImageNormalizer;
use app\service\VideoGenerationQueue;
use app\service\VideoJobService;
use app\service\VisionTaskQueue;
use app\support\Audit;
use app\support\TemporaryDownloadResponse;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class Merchant extends BaseController
{
    private const ASSET_EXPORT_MAX_ITEMS = 100;
    private const ASSET_EXPORT_MAX_BYTES = 500 * 1024 * 1024;

    public function dashboard(): Response
    {
        return Web::redirect('/workspace/generate');
    }

    public function updateWorkspaceProject(string $type, int $id, MerchantWorkspaceService $workspace): Response
    {
        [$merchant, $user] = $this->identity();
        $action = trim((string) $this->request->post('action', ''));
        $name = trim((string) $this->request->post('name', ''));
        $returnTo = trim((string) $this->request->post('return_to', '/workspace/generate'));
        if (!str_starts_with($returnTo, '/workspace')) {
            $returnTo = '/workspace/generate';
        }
        try {
            $result = $workspace->updatePreference((int) $merchant['id'], $type, $id, $action, $name);
            Audit::log('merchant_user', (int) $user['id'], 'workspace_project.' . $action, 'workspace_project', $type . ':' . $id, $this->request->ip(), $result);
            $message = match ($action) {
                'rename' => '项目名称已更新。',
                'pin' => '项目已置顶。',
                'unpin' => '项目已取消置顶。',
                'hide' => '项目已从最近项目中移除，商品、任务和素材仍然保留。',
                default => '项目已更新。',
            };
            return Web::redirect($returnTo, $message);
        } catch (\Throwable $exception) {
            return Web::redirect($returnTo, $exception->getMessage(), 'error');
        }
    }

    public function generatePage(NewApiClient $newApi, PricingService $pricing, PlanService $plans, PromptTemplateService $prompts, SkuProductReport $profiles, GenerationPreferenceService $preferences, CreativeModelService $creativeModels, GenerationJobView $jobView, ProductCreativeReadinessService $readiness, ProductLaunchTargetService $targets): Response
    {
        if ($this->request->get('mode', '') === 'free') {
            return $this->freeCreatePage($newApi, $pricing, $plans, $prompts, $creativeModels, $jobView);
        }
        [$merchant, $user] = $this->identity();
        $generationPreference = $preferences->forUser((int) $user['id']);
        $requestedMode = (string) $this->request->get('mode', '');
        $generationMode = in_array($requestedMode, ['assets', 'detail', 'video'], true) ? $requestedMode : 'bundle';
        $selectedSkuId = (int) $this->request->get('sku_id', 0);
        if ($requestedMode === '' && $selectedSkuId <= 0) {
            return $this->render('merchant/product_launch', compact('merchant', 'user') + [
                'pageTitle' => '商品上新',
                'platforms' => generation_platform_options(),
                'languages' => generation_language_options(),
                'preferredPlatform' => $generationPreference['platform'],
                'preferredLanguage' => $generationPreference['language'],
                'recognitionCredits' => $plans->recognitionCredits((int) $merchant['id'], $pricing->productRecognitionCredits()),
                'promptOptimizationCredits' => $plans->recognitionCredits((int) $merchant['id'], $pricing->promptOptimizationCredits()),
            ]);
        }
        $skus = Db::name('skus')->where('merchant_id', $merchant['id'])->where('status', 'active')->order('id', 'desc')->select()->toArray();
        $skuIds = array_map(static fn (array $sku): int => (int) $sku['id'], $skus);
        $reports = [];
        $detailProjects = [];
        if ($skuIds !== []) {
            $detailProjects = Db::name('detail_page_projects')->where('merchant_id', $merchant['id'])->whereIn('sku_id', $skuIds)
                ->where('status', '<>', 'deleted')->order('updated_at', 'desc')->order('id', 'desc')->select()->toArray();
            foreach ($detailProjects as $report) {
                $reports[(int) $report['sku_id']] ??= $report;
            }
        }
        foreach ($skus as &$sku) {
            $sku = $profiles->ensureFoundation((int) $sku['id'], (int) $merchant['id']) ?? $sku;
            $report = $reports[(int) $sku['id']] ?? null;
            $sku['detail_report_id'] = (int) ($report['id'] ?? 0);
            $sku['detail_report_language'] = (string) ($report['language'] ?? '');
            $sku['detail_report_updated_at'] = (string) ($report['updated_at'] ?? '');
            $sku['creative_readiness'] = $generationMode === 'assets'
                ? $readiness->forVisualSku((int) $sku['id'], (int) $merchant['id'], 'marketing_creative')
                : $readiness->forSku((int) $sku['id'], (int) $merchant['id'], (int) ($report['id'] ?? 0) ?: null);
            if ($generationMode === 'assets') {
                $sku['catalog_readiness'] = $readiness->forVisualSku((int) $sku['id'], (int) $merchant['id'], 'catalog_base');
            }
        }
        unset($sku);

        $standardGenerationPrice = $pricing->imageGenerationCredits();
        try {
            $smartSelection = $creativeModels->resolveTask(AiCapability::IMAGE_EDIT, null, (int) $merchant['id'], $standardGenerationPrice);
            $smartUnitPrice = (int) $smartSelection['price']['credits'];
            $defaultImageChoice = [
                'id' => max(0, (int) ($smartSelection['resolvedModelId'] ?? 0)),
                'modelId' => (string) $smartSelection['model'],
                'name' => (string) ($smartSelection['route']['displayName'] ?? $smartSelection['model']),
                'price' => $smartUnitPrice,
            ];
        } catch (\Throwable) {
            $smartUnitPrice = $standardGenerationPrice;
            $defaultImageChoice = ['id' => 0, 'modelId' => $newApi->defaultModel(), 'name' => $newApi->defaultModel(), 'price' => $smartUnitPrice];
        }
        $launchTaskBrief = '';
        $launchTaskId = max(0, (int) $this->request->get('launch_task_id', 0));
        if ($launchTaskId > 0 && $selectedSkuId > 0) {
            $launchTask = Db::name('vision_tasks')
                ->where('id', $launchTaskId)
                ->where('merchant_id', (int) $merchant['id'])
                ->where('sku_id', $selectedSkuId)
                ->where('status', 'completed')
                ->find();
            if ($launchTask) {
                $launchContext = json_decode((string) ($launchTask['context_json'] ?? '{}'), true);
                $launchTaskBrief = mb_strimwidth(trim((string) ($launchContext['brief'] ?? '')), 0, 1200, '');
            }
        }
        $generationQuote = $plans->generationQuote((int) $merchant['id'], 1, $smartUnitPrice);
        $batchImageLimit = $plans->batchImageLimit((int) $merchant['id'], generation_job_image_limit());
        $modelOptions = $creativeModels->options((int) $merchant['id'], [AiCapability::IMAGE_EDIT]);
        $imageEditEnabled = $creativeModels->canResolve(AiCapability::IMAGE_EDIT, (int) $merchant['id']);
        $videoModelOptions = $creativeModels->options(
            (int) $merchant['id'],
            [AiCapability::VIDEO_IMAGE_TO_VIDEO],
            'product_video'
        );
        $videoEnabled = $creativeModels->canResolve(AiCapability::VIDEO_IMAGE_TO_VIDEO, (int) $merchant['id']);
        $videoBasePrice = $pricing->videoGenerationCreditsPerSecond();
        try {
            $defaultVideoChoice = $creativeModels->defaultChoice(
                AiCapability::VIDEO_IMAGE_TO_VIDEO,
                (int) $merchant['id'],
                $videoBasePrice
            );
        } catch (\Throwable) {
            $defaultVideoChoice = [
                'id' => 0,
                'modelId' => '',
                'name' => '暂未配置可用视频模型',
                'price' => $videoBasePrice,
            ];
        }
        $selectedSku = null;
        foreach ($skus as $sku) {
            if ((int) $sku['id'] === $selectedSkuId) {
                $selectedSku = $sku;
                break;
            }
        }
        $preferredPlatform = $generationPreference['platform'];
        $preferredLanguage = $generationPreference['language'];
        if ($selectedSku !== null) {
            $savedTargets = $targets->forSku($selectedSku, null, array_values(array_filter(
                $detailProjects,
                static fn (array $project): bool => (int) ($project['sku_id'] ?? 0) === $selectedSkuId
            )));
            $primaryTarget = $savedTargets['targets'][0] ?? null;
            if (is_array($primaryTarget)) {
                $preferredPlatform = (string) ($primaryTarget['platform'] ?? $preferredPlatform);
                $preferredLanguage = (string) ($primaryTarget['language'] ?? $preferredLanguage);
            }
            if ($launchTaskBrief === '') {
                $launchTaskBrief = (string) ($savedTargets['launch_direction'] ?? '');
            }
        }
        $projectAssets = [];
        $projectJobs = [];
        if ($selectedSku !== null) {
            $projectAssets = Db::name('assets')->alias('a')
                ->leftJoin('generation_jobs j', 'j.id=a.job_id')
                ->field('a.id,a.asset_type,a.media_type,a.file_url,a.backup_file_url,a.created_at,j.job_no,j.platform')
                ->where('a.merchant_id', (int) $merchant['id'])
                ->where('a.sku_id', $selectedSkuId)
                ->where('a.status', 'active')
                ->where('a.media_type', 'image')
                ->where('a.file_url', '<>', '')
                ->order('a.id', 'desc')
                ->limit(18)
                ->select()
                ->toArray();
            $projectJobs = $jobView->enrich(Db::name('generation_jobs')
                ->where('merchant_id', (int) $merchant['id'])
                ->where('sku_id', $selectedSkuId)
                ->whereNull('merchant_hidden_at')
                ->order('id', 'desc')
                ->limit(8)
                ->select()
                ->toArray());
        }
        return $this->render('merchant/generate', compact('merchant', 'user', 'skus', 'detailProjects', 'generationQuote', 'standardGenerationPrice') + [
            'pageTitle' => '商品上新工作台',
            'newApiConfigured' => $newApi->configuration()['configured'],
            'defaultModel' => $newApi->defaultModel(),
            'imageEditEnabled' => $imageEditEnabled,
            'modelOptions' => $modelOptions,
            'defaultImageChoice' => $defaultImageChoice,
            'videoModelOptions' => $videoModelOptions,
            'defaultVideoChoice' => $defaultVideoChoice,
            'videoEnabled' => $videoEnabled,
            'videoBasePrice' => $videoBasePrice,
            'launchTaskBrief' => $launchTaskBrief,
            'generationPrice' => (int) $generationQuote['credits'],
            'selectedSkuId' => $selectedSkuId,
            'selectedSku' => $selectedSku,
            'projectAssets' => $projectAssets,
            'projectJobs' => $projectJobs,
            'selectedDetailProjectId' => (int) $this->request->get('project_id', 0),
            'generationMode' => $generationMode,
            'batchImageLimit' => $batchImageLimit,
            'preferredPlatform' => $preferredPlatform,
            'preferredLanguage' => $preferredLanguage,
            'detailOutputPresets' => detail_page_output_presets(),
        ]);
    }

    public function createVideoGenerationJob(
        PromptTemplateService $prompts,
        SkuProductReport $profiles,
        VideoJobService $videoJobs,
        ProductCreativeReadinessService $readiness
    ): Response {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $skuId = max(0, (int) $this->request->post('sku_id', 0));
        $sourceAssetId = max(0, (int) $this->request->post('source_asset_id', 0));
        $platform = trim((string) $this->request->post('platform', 'douyin'));
        $language = trim((string) $this->request->post('language', 'zh-CN'));
        $motionPreset = trim((string) $this->request->post('motion_preset', 'product_showcase'));
        $ratio = trim((string) $this->request->post('ratio', '9:16'));
        $resolution = trim((string) $this->request->post('resolution', '720p'));
        $duration = (int) $this->request->post('duration_seconds', 5);
        $creativeBrief = mb_strimwidth(trim((string) $this->request->post('task_brief', '')), 0, 1200, '');
        $generateAudio = $this->request->post('generate_audio', '') === '1';

        $sku = $profiles->ensureFoundation($skuId, $merchantId);
        if (!$sku) {
            return Web::redirect('/workspace/generate?mode=video', '请选择有效商品后再创建视频。', 'error');
        }
        $creativeReadiness = $readiness->forSku($skuId, $merchantId);
        if (empty($creativeReadiness['ready'])) {
            return $this->creativeReadinessFailure(
                $readiness,
                $creativeReadiness,
                '/workspace/generate?mode=video&sku_id=' . $skuId
            );
        }
        if (!array_key_exists($platform, generation_platform_options())
            || !array_key_exists($language, generation_language_options())) {
            return Web::redirect('/workspace/generate?mode=video&sku_id=' . $skuId, '平台或画面语言无效。', 'error');
        }
        if (!array_key_exists($motionPreset, $prompts->videoMotionPresets())) {
            $motionPreset = 'product_showcase';
        }
        if (!in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4'], true)) {
            $ratio = '9:16';
        }
        if (!in_array($resolution, ['720p', '1080p'], true)) {
            $resolution = '720p';
        }
        if (!in_array($duration, [5, 10], true)) {
            $duration = 5;
        }

        $sourceAsset = null;
        if ($sourceAssetId > 0) {
            $sourceAsset = Db::name('assets')
                ->where('id', $sourceAssetId)
                ->where('merchant_id', $merchantId)
                ->where('sku_id', $skuId)
                ->where('status', 'active')
                ->where('media_type', 'image')
                ->find();
            if (!$sourceAsset) {
                return Web::redirect('/workspace/generate?mode=video&sku_id=' . $skuId, '选择的视频参考图不存在或不属于当前商品。', 'error');
            }
        }
        $referenceUrl = trim((string) ($sourceAsset['file_url'] ?? $sku['source_image_path'] ?? ''));
        $referenceBackupUrl = trim((string) ($sourceAsset['backup_file_url'] ?? $sku['source_image_backup_path'] ?? ''));
        if ($referenceUrl === '' && $referenceBackupUrl === '') {
            return Web::redirect('/workspace/generate?mode=video&sku_id=' . $skuId, '商品尚无参考图，请先补充商品图片。', 'error');
        }

        $prompt = $prompts->buildVideoPrompt(
            $sku,
            $platform,
            $motionPreset,
            $creativeBrief,
            $ratio,
            $resolution,
            $duration
        );
        $submittedIp = $this->request->ip();

        try {
            $queued = $videoJobs->queue($merchant, $user, [
                'sku_id' => $skuId,
                'source_asset_id' => $sourceAssetId,
                'reference_image_url' => $referenceUrl !== '' ? $referenceUrl : $referenceBackupUrl,
                'reference_image_backup_url' => $referenceBackupUrl,
                'platform' => $platform,
                'language' => $language,
                'motion_preset' => $motionPreset,
                'ratio' => $ratio,
                'resolution' => $resolution,
                'duration_seconds' => $duration,
                'generate_audio' => $generateAudio,
                'creative_brief' => $creativeBrief,
                'prompt' => $prompt,
                'capability' => AiCapability::VIDEO_IMAGE_TO_VIDEO,
                'requested_model_id' => $this->requestedGenerationModelId(),
                'product_report' => $profiles->reportContext($sku),
                'submitted_ip' => $submittedIp,
                'context' => [
                    'mode' => 'product_video',
                    'motion_preset' => $motionPreset,
                    'ratio' => $ratio,
                    'resolution' => $resolution,
                    'generate_audio' => $generateAudio,
                ],
            ]);
            $jobId = (int) $queued['jobId'];
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/generate?mode=video&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }

        Audit::log('merchant_user', (int) $user['id'], 'video_generation.create', 'generation_job', $jobId, $submittedIp, [
            'sku_id' => $skuId,
            'source_asset_id' => $sourceAssetId ?: null,
            'duration_seconds' => $duration,
            'ratio' => $ratio,
            'resolution' => $resolution,
            'motion_preset' => $motionPreset,
            'estimated_credits' => (int) $queued['estimatedCredits'],
        ]);
        $message = !empty($queued['gatewayReady'])
            ? '商品视频任务已提交，安全检查和生成会在后台自动完成。'
            : '视频任务已保存；管理员开通并验证视频模型后即可重试。';
        return Web::redirect('/workspace/generate?mode=video&sku_id=' . $skuId . '&task_center=open&task_tab=latest', $message);
    }

    public function freeCreatePage(NewApiClient $newApi, PricingService $pricing, PlanService $plans, PromptTemplateService $prompts, CreativeModelService $creativeModels, GenerationJobView $jobView): Response
    {
        [$merchant, $user] = $this->identity();
        $standardGenerationPrice = $pricing->imageGenerationCredits();
        try {
            $smartGeneration = $creativeModels->resolveTask(AiCapability::IMAGE_GENERATION, null, (int) $merchant['id'], $standardGenerationPrice);
            $smartEdit = $creativeModels->resolveTask(AiCapability::IMAGE_EDIT, null, (int) $merchant['id'], $standardGenerationPrice);
            $smartGenerationPrice = (int) $plans->generationQuote((int) $merchant['id'], 1, (int) $smartGeneration['price']['credits'])['credits'];
            $smartEditPrice = (int) $plans->generationQuote((int) $merchant['id'], 1, (int) $smartEdit['price']['credits'])['credits'];
            $defaultGenerationChoice = [
                'id' => max(0, (int) ($smartGeneration['resolvedModelId'] ?? 0)),
                'modelId' => (string) $smartGeneration['model'],
                'name' => (string) ($smartGeneration['route']['displayName'] ?? $smartGeneration['model']),
            ];
            $defaultEditChoice = [
                'id' => max(0, (int) ($smartEdit['resolvedModelId'] ?? 0)),
                'modelId' => (string) $smartEdit['model'],
                'name' => (string) ($smartEdit['route']['displayName'] ?? $smartEdit['model']),
            ];
        } catch (\Throwable) {
            $smartGenerationPrice = (int) $plans->generationQuote((int) $merchant['id'], 1, $standardGenerationPrice)['credits'];
            $smartEditPrice = $smartGenerationPrice;
            $defaultGenerationChoice = ['id' => 0, 'modelId' => $newApi->defaultModel(), 'name' => $newApi->defaultModel()];
            $defaultEditChoice = $defaultGenerationChoice;
        }
        $generationQuote = $plans->generationQuote((int) $merchant['id'], 1, $smartGenerationPrice);
        $freeQuantityLimit = max(1, min(
            generation_asset_quantity_limit(),
            $plans->batchImageLimit((int) $merchant['id'], generation_job_image_limit())
        ));
        $promptResultId = max(0, (int) $this->request->get('prompt_result_id', 0));
        $projectId = max(0, (int) $this->request->get('project_id', 0));
        $todayStartedAt = date('Y-m-d 00:00:00');
        $initialPrompt = '';
        $freeProject = null;
        $projectAssets = [];
        $freeConversationRows = Db::name('generation_jobs')->alias('j')
            ->leftJoin('free_generation_jobs f', 'f.job_id=j.id')
            ->field('j.*,f.prompt,f.output_preset,f.output_width,f.output_height,f.reference_image_url,f.reference_image_backup_url,f.reference_images_json')
            ->where('j.merchant_id', (int) $merchant['id'])
            ->where('j.platform', 'free')
            ->where('j.created_at', '>=', $todayStartedAt)
            ->whereNull('j.merchant_hidden_at')
            ->order('j.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        if ($projectId > 0 && !array_filter(
            $freeConversationRows,
            static fn (array $row): bool => (int) ($row['id'] ?? 0) === $projectId
        )) {
            $selectedRow = Db::name('generation_jobs')->alias('j')
                ->leftJoin('free_generation_jobs f', 'f.job_id=j.id')
                ->field('j.*,f.prompt,f.output_preset,f.output_width,f.output_height,f.reference_image_url,f.reference_image_backup_url,f.reference_images_json')
                ->where('j.id', $projectId)
                ->where('j.merchant_id', (int) $merchant['id'])
                ->where('j.platform', 'free')
                ->whereNull('j.merchant_hidden_at')
                ->find();
            if ($selectedRow) {
                $freeConversationRows[] = $selectedRow;
            }
        }
        $freeConversationRows = $jobView->enrich($freeConversationRows);
        $conversationJobIds = array_values(array_filter(array_map(
            static fn (array $row): int => max(0, (int) ($row['id'] ?? 0)),
            $freeConversationRows
        )));
        $conversationAssets = [];
        if ($conversationJobIds !== []) {
            $assetRows = Db::name('assets')
                ->where('merchant_id', (int) $merchant['id'])
                ->whereIn('job_id', $conversationJobIds)
                ->where('status', 'active')
                ->where('file_url', '<>', '')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            $itemIds = array_values(array_unique(array_filter(array_map(
                static fn (array $asset): int => max(0, (int) ($asset['generation_item_id'] ?? 0)),
                $assetRows
            ))));
            $qualityByItem = [];
            if ($itemIds !== []) {
                foreach (Db::name('generation_job_items')->whereIn('id', $itemIds)
                    ->field('id,status,charged_credits,technical_refund_ledger_id,quality_refund_ledger_id,completed_at')
                    ->select()->toArray() as $qualityItem) {
                    $qualityByItem[(int) $qualityItem['id']] = $qualityItem;
                }
            }
            foreach ($assetRows as $asset) {
                $qualityItem = $qualityByItem[(int) ($asset['generation_item_id'] ?? 0)] ?? null;
                $asset['quality_appeal_eligible'] = $qualityItem
                    && (string) $qualityItem['status'] === 'completed'
                    && (int) ($qualityItem['charged_credits'] ?? 0) > 0
                    && (int) ($qualityItem['technical_refund_ledger_id'] ?? 0) <= 0
                    && (int) ($qualityItem['quality_refund_ledger_id'] ?? 0) <= 0
                    && strtotime((string) ($qualityItem['completed_at'] ?? '')) >= time() - 7 * 86400;
                $conversationAssets[(int) $asset['job_id']][] = $asset;
            }
        }
        $freeConversation = [];
        foreach ($freeConversationRows as $row) {
            $jobId = (int) $row['id'];
            $references = [];
            $primaryReference = trim((string) ($row['reference_image_url'] ?? ''));
            $primaryBackup = trim((string) ($row['reference_image_backup_url'] ?? ''));
            if ($primaryReference !== '' || $primaryBackup !== '') {
                $references[] = ['url' => $primaryReference, 'backup_url' => $primaryBackup];
            }
            $additionalReferences = json_decode((string) ($row['reference_images_json'] ?? ''), true);
            foreach (is_array($additionalReferences) ? $additionalReferences : [] as $reference) {
                if (!is_array($reference)) {
                    continue;
                }
                $url = trim((string) ($reference['url'] ?? ''));
                $backupUrl = trim((string) ($reference['backup_url'] ?? ''));
                if ($url !== '' || $backupUrl !== '') {
                    $references[] = ['url' => $url, 'backup_url' => $backupUrl];
                }
            }
            $row['reference_images'] = $references;
            $row['assets'] = $conversationAssets[$jobId] ?? [];
            $freeConversation[] = $row;
            if ($projectId > 0 && $jobId === $projectId) {
                $freeProject = $row;
                $projectAssets = $row['assets'];
            }
        }
        usort($freeConversation, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        if ($promptResultId > 0) {
            $promptResult = Db::name('image_tool_results')
                ->where('id', $promptResultId)
                ->where('merchant_id', (int) $merchant['id'])
                ->where('result_type', 'reverse_prompt')
                ->find();
            $initialPrompt = mb_strimwidth(trim((string) ($promptResult['content'] ?? '')), 0, 4000, '');
        }
        return $this->render('merchant/free_create', compact('merchant', 'user', 'generationQuote', 'standardGenerationPrice', 'initialPrompt', 'promptResultId') + [
            'pageTitle' => '自由创作',
            'newApiConfigured' => $newApi->configuration()['configured'],
            'defaultModel' => $newApi->defaultModel(),
            'generationPrice' => (int) $generationQuote['credits'],
            'modelOptions' => $creativeModels->options(
                (int) $merchant['id'],
                [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT],
                'free_create'
            ),
            'smartGenerationPrice' => $smartGenerationPrice,
            'smartEditPrice' => $smartEditPrice,
            'defaultGenerationChoice' => $defaultGenerationChoice,
            'defaultEditChoice' => $defaultEditChoice,
            'outputPresets' => canvas_output_presets(),
            'promptOptimizationCredits' => $plans->recognitionCredits((int) $merchant['id'], $pricing->promptOptimizationCredits()),
            'freePromptTemplates' => $prompts->publicFreeTemplates(),
            'freePromptCategories' => $prompts->freeCategories(),
            'imagePromptAssets' => Db::name('assets')->where('merchant_id', (int) $merchant['id'])
                ->where('status', 'active')->where('file_url', '<>', '')
                ->order('id', 'desc')->limit(24)->select()->toArray(),
            'freeProject' => $freeProject,
            'projectAssets' => $projectAssets,
            'freeConversation' => $freeConversation,
            'freeQuantityLimit' => $freeQuantityLimit,
            'selectedOutputPreset' => (string) ($freeProject['output_preset'] ?? 'square_1_1'),
            'preferredLanguage' => isset(generation_language_options()[(string) ($freeProject['language'] ?? '')])
                ? (string) $freeProject['language']
                : (isset(generation_language_options()[(string) ($user['preferred_generation_language'] ?? '')])
                    ? (string) $user['preferred_generation_language']
                    : 'zh-CN'),
        ]);
    }

    public function createGenerationJob(NewApiClient $newApi, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety, AssetStorage $storage, GenerationItemQueue $itemQueue, SkuProductReport $profiles, CreativeModelService $creativeModels, GenerationSubmissionGuard $submissionGuard): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $skuId = (int) $this->request->post('sku_id', 0);
        $platform = (string) $this->request->post('platform', '');
        $language = (string) $this->request->post('language', 'zh-CN');
        $selectedAssetTypes = $this->request->post('asset_types', []);
        $assetQuantities = $this->request->post('asset_quantities', []);
        $customMaterialRequests = $this->customMaterialRequests((string) $this->request->post('custom_material_requests', ''));
        $allowedPlatforms = array_keys(generation_platform_options());
        $allowedTypes = array_keys(asset_type_options());
        $selectedAssetTypes = array_values(array_intersect($allowedTypes, is_array($selectedAssetTypes) ? $selectedAssetTypes : []));
        $assetQuantities = is_array($assetQuantities) ? $assetQuantities : [];
        $assetTypes = [];
        foreach ($selectedAssetTypes as $assetType) {
            $quantity = max(1, min(generation_asset_quantity_limit(), (int) ($assetQuantities[$assetType] ?? 1)));
            for ($index = 0; $index < $quantity; $index++) {
                $assetTypes[] = $assetType;
            }
        }
        $assetTypes = [...$assetTypes, ...custom_material_asset_types($customMaterialRequests)];
        $launchAction = (string) $this->request->post('launch_action', 'full') === 'preview' ? 'preview' : 'full';
        if ($launchAction === 'preview' && $assetTypes !== []) {
            $assetTypes = [$assetTypes[0]];
            $customMaterialRequests = [];
        }
        try {
            $clientSubmissionId = $submissionGuard->normalize($this->request->post('client_submission_id', ''));
            $submissionKey = $submissionGuard->scopedKey($clientSubmissionId, 'product_image');
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/generate?mode=assets&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }

        $sku = $profiles->ensureFoundation($skuId, $merchantId);
        if (!$sku || !in_array($platform, $allowedPlatforms, true) || !isset(generation_language_options()[$language]) || $assetTypes === []) {
            return Web::redirect('/workspace/generate', '请选择有效 SKU、平台和素材类型。', 'error');
        }
        $batchLimit = $plans->batchImageLimit($merchantId, generation_job_image_limit());
        if (count($assetTypes) > $batchLimit) {
            return Web::redirect('/workspace/generate?sku_id=' . $skuId, '当前套餐单次最多生成 ' . $batchLimit . ' 张图片，请减少素材数量。', 'error');
        }
        $jobCapacity = $plans->canStartJobs($merchantId);
        if (!$jobCapacity['allowed']) {
            return Web::redirect('/workspace/jobs', (string) $jobCapacity['message'], 'error');
        }

        $taskBrief = mb_strimwidth(trim((string) $this->request->post('task_brief', '')), 0, 1200, '');
        $taskContext = [
            'mode' => $launchAction === 'preview' ? 'first_image_preview' : 'normal',
            'launch_action' => $launchAction,
            'custom_material_requests' => $customMaterialRequests,
            'submitted_at' => Web::now(),
        ];
        $gatewayConfigured = $newApi->configuration()['configured'];
        $usesReference = !empty($sku['source_image_path']);
        $capability = $usesReference ? AiCapability::IMAGE_EDIT : AiCapability::IMAGE_GENERATION;
        $requestedModelId = $this->requestedGenerationModelId();
        try {
            $modelSelection = $creativeModels->resolveTask(
                $capability,
                $requestedModelId,
                $merchantId,
                $pricing->imageGenerationCredits()
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/generate?mode=assets&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }
        $submissionPayloadHash = $submissionGuard->payloadHash('product_image_submission_v1', [
            'sku_id' => $skuId,
            'platform' => $platform,
            'language' => $language,
            'asset_types' => $assetTypes,
            'custom_material_requests' => $customMaterialRequests,
            'task_brief' => $taskBrief,
            'launch_action' => $launchAction,
            'requested_model_id' => $requestedModelId,
            'resolved_model_id' => (int) $modelSelection['resolvedModelId'],
        ]);
        $existingSubmission = $submissionGuard->existing($merchantId, $submissionKey);
        if ($existingSubmission !== null) {
            try {
                $submissionGuard->assertMatches($existingSubmission, $submissionPayloadHash);
            } catch (\Throwable $exception) {
                return Web::redirect('/workspace/generate?mode=assets&sku_id=' . $skuId, $exception->getMessage(), 'error');
            }
            return Web::redirect('/workspace/generate?mode=assets&sku_id=' . $skuId . '&task_center=open&task_tab=latest', '相同生成请求已经提交，本次没有重复创建任务或扣点。');
        }
        $model = $modelSelection['model'];
        $pricingSnapshot = $modelSelection['price'];
        $generationQuote = $plans->generationQuote($merchantId, count($assetTypes), (int) $pricingSnapshot['credits']);
        if (!$generationQuote['allowed']) {
            return Web::redirect('/workspace/plans', (string) $generationQuote['message'], 'error');
        }
        $estimatedCredits = (int) $generationQuote['credits'];
        $availableCredits = $this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']);
        if ($availableCredits < $estimatedCredits) {
            return Web::redirect('/workspace/usage', "可用点数不足，当前可用 {$availableCredits} 点，该任务预计需要 {$estimatedCredits} 点。本次未创建任务。", 'error');
        }

        $gatewayReady = $gatewayConfigured && $newApi->hasAvailableModels(
            $model,
            $modelSelection['resolvedModelId'],
            (string) ($modelSelection['route']['resolvedGateway'] ?? ''),
            $capability
        );
        $status = $gatewayReady ? 'queued' : 'pending_gateway';
        try {
            $submittedIp = $this->request->ip();
            $jobResult = Db::transaction(function () use ($merchantId, $user, $submittedIp, $skuId, $platform, $language, $assetTypes, $customMaterialRequests, $taskBrief, $taskContext, $status, $model, $modelSelection, $pricingSnapshot, $gatewayReady, $gatewayConfigured, $plans, $itemQueue, $submissionGuard, $submissionKey, $submissionPayloadHash): array {
                $lockedMerchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
                if (!$lockedMerchant || $lockedMerchant['status'] !== 'active') {
                    throw new \RuntimeException('商户账户当前不可用。');
                }
                $existing = $submissionGuard->existing($merchantId, $submissionKey);
                if ($existing !== null) {
                    $submissionGuard->assertMatches($existing, $submissionPayloadHash);
                    return ['id' => (int) $existing['id'], 'reused' => true];
                }
                $generationQuote = $plans->generationQuote($merchantId, count($assetTypes), (int) $pricingSnapshot['credits']);
                if (!$generationQuote['allowed']) {
                    throw new \RuntimeException((string) $generationQuote['message']);
                }
                $jobCapacity = $plans->canStartJobs($merchantId);
                if (!$jobCapacity['allowed']) {
                    throw new \RuntimeException((string) $jobCapacity['message']);
                }
                $estimatedCredits = (int) $generationQuote['credits'];
                $availableCredits = $this->availableGenerationCredits($merchantId, (int) $lockedMerchant['credit_balance']);
                if ($availableCredits < $estimatedCredits) {
                    throw new \RuntimeException("可用点数不足，当前可用 {$availableCredits} 点，该任务预计需要 {$estimatedCredits} 点。本次未创建任务。");
                }
                $now = Web::now();
                $jobId = (int) Db::name('generation_jobs')->insertGetId([
                    'merchant_id' => $merchantId,
                    'sku_id' => $skuId,
                    'job_no' => 'JOB-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3))),
                    'platform' => $platform,
                    'language' => $language,
                    'asset_types' => json_encode($assetTypes),
                    'custom_material_requests' => $customMaterialRequests === [] ? null : json_encode($customMaterialRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'task_brief' => $taskBrief !== '' ? $taskBrief : null,
                    'task_context_json' => json_encode($taskContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'requested_model_id' => $modelSelection['requestedModelId'],
                    'resolved_ai_model_id' => $modelSelection['resolvedModelId'],
                    'route_snapshot_json' => json_encode($modelSelection['route'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'pricing_snapshot_json' => json_encode($pricingSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'submitted_by_user_id' => (int) $user['id'],
                    'submitted_ip' => $submittedIp,
                    'client_submission_id' => $submissionKey,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'safety_status' => 'pending',
                    'safety_attempts' => 0,
                    'status' => $status,
                    'estimated_credits' => $estimatedCredits,
                    'actual_credits' => 0,
                    'progress_current' => 0,
                    'progress_total' => count($assetTypes),
                    'progress_stage' => $gatewayReady ? 'safety_pending' : 'pending_gateway',
                    'progress_message' => $gatewayReady ? '任务已提交，等待后台安全检查' : '等待管理员配置可用模型渠道',
                    'model' => $model,
                    'error_message' => $gatewayReady ? null : ($gatewayConfigured ? 'AI 服务已连接，但尚未配置可用模型渠道' : '等待管理员配置 AI 模型网关'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $itemQueue->createItems($jobId, $merchantId, $assetTypes, $estimatedCredits, $status);
                return ['id' => $jobId, 'reused' => false];
            });
        } catch (\Throwable $exception) {
            $existing = $submissionGuard->existing($merchantId, $submissionKey);
            if ($existing !== null) {
                try {
                    $submissionGuard->assertMatches($existing, $submissionPayloadHash);
                    return Web::redirect('/workspace/generate?mode=assets&sku_id=' . $skuId . '&task_center=open&task_tab=latest', '相同生成请求已经提交，本次没有重复创建任务或扣点。');
                } catch (\Throwable) {
                }
            }
            return Web::redirect('/workspace/usage', $exception->getMessage(), 'error');
        }
        $jobId = (int) $jobResult['id'];
        if (empty($jobResult['reused'])) {
            Audit::log('merchant_user', (int) $user['id'], 'generation_job.create', 'generation_job', $jobId, $this->request->ip(), [
            'sku_id' => $skuId,
            'platform' => $platform,
            'language' => $language,
            'asset_types' => $assetTypes,
            'custom_material_requests' => $customMaterialRequests,
            'asset_type_counts' => array_count_values($assetTypes),
            'generation_mode' => $usesReference ? 'reference_image' : 'text',
            'launch_action' => $launchAction,
            ]);
        }

        $message = !empty($jobResult['reused'])
            ? '相同生成请求已经提交，本次没有重复创建任务或扣点。'
            : ($gatewayReady
            ? ($launchAction === 'preview' ? '首张预览已提交，安全检查与生图将在后台自动完成。' : '任务已提交，安全检查与生图将在后台自动完成。')
            : ($gatewayConfigured ? '任务已保存；管理员配置可用模型渠道后即可重试。' : '任务已保存；配置模型网关后即可进入生成队列。'));
        return Web::redirect('/workspace/generate?mode=assets&sku_id=' . $skuId . '&task_center=open&task_tab=latest', $message . ' 生成进度已在右侧打开。');
    }

    public function createListingBundle(NewApiClient $newApi, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety, AssetStorage $storage, GenerationItemQueue $itemQueue, DetailPageSelectionService $detailSelection, SkuProductReport $profiles, CreativeModelService $creativeModels, GenerationSubmissionGuard $submissionGuard): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $skuId = (int) $this->request->post('sku_id', 0);
        $projectId = (int) $this->request->post('detail_project_id', 0);
        $selectedAssetTypes = $this->request->post('asset_types', []);
        $assetQuantities = $this->request->post('asset_quantities', []);
        $customMaterialRequests = $this->customMaterialRequests((string) $this->request->post('custom_material_requests', ''));
        $allowedTypes = array_keys(asset_type_options());
        $selectedAssetTypes = array_values(array_intersect($allowedTypes, is_array($selectedAssetTypes) ? $selectedAssetTypes : []));
        $assetQuantities = is_array($assetQuantities) ? $assetQuantities : [];
        $assetTypes = [];
        foreach ($selectedAssetTypes as $assetType) {
            $quantity = max(1, min(generation_asset_quantity_limit(), (int) ($assetQuantities[$assetType] ?? 1)));
            for ($index = 0; $index < $quantity; $index++) {
                $assetTypes[] = $assetType;
            }
        }
        $assetTypes = [...$assetTypes, ...custom_material_asset_types($customMaterialRequests)];
        $launchAction = (string) $this->request->post('launch_action', 'full') === 'preview' ? 'preview' : 'full';
        try {
            $clientSubmissionId = $submissionGuard->normalize($this->request->post('client_submission_id', ''));
            $assetSubmissionKey = $submissionGuard->scopedKey($clientSubmissionId, 'listing_bundle_assets');
            $detailSubmissionKey = $submissionGuard->scopedKey($clientSubmissionId, 'listing_bundle_detail');
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }
        $detailCount = max(1, min(count(detail_page_screen_options()), (int) $this->request->post('detail_screen_count', 10)));
        try {
            $detailOutput = detail_page_output_dimensions(
                (string) $this->request->post('detail_output_preset', 'portrait_2_3'),
                $this->request->post('detail_output_width'),
                $this->request->post('detail_output_height')
            );
        } catch (\InvalidArgumentException $exception) {
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }
        $sku = $profiles->ensureFoundation($skuId, $merchantId);
        $project = Db::name('detail_page_projects')->where('id', $projectId)->where('merchant_id', $merchantId)
            ->where('sku_id', $skuId)->where('status', '<>', 'deleted')->find();
        if (!$sku || !$project || $assetTypes === []) {
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, '请选择有效商品、详情页方案和主图素材。', 'error');
        }
        $taskBrief = mb_strimwidth(trim((string) $this->request->post('task_brief', '')), 0, 1200, '');
        if ($taskBrief === '') {
            $taskBrief = mb_strimwidth(trim((string) ($project['creative_brief'] ?? '')), 0, 1200, '');
        }
        $detailTypes = $detailSelection->select($project, $detailCount);
        if ($launchAction === 'preview') {
            $assetTypes = [$assetTypes[0]];
            $customMaterialRequests = [];
            $detailTypes = [];
        }
        try {
            $requestedModelId = $this->requestedGenerationModelId();
            $modelSelection = $creativeModels->resolveTask(
                AiCapability::IMAGE_EDIT,
                $requestedModelId,
                $merchantId,
                $pricing->imageGenerationCredits(),
                'listing_bundle'
            );
            $detailModelSelection = $launchAction === 'preview' ? $modelSelection : $creativeModels->resolveTask(
                AiCapability::IMAGE_EDIT,
                $requestedModelId,
                $merchantId,
                $pricing->imageGenerationCredits(),
                'detail_page'
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }
        $submissionPayloadHash = $submissionGuard->payloadHash('listing_bundle_submission_v1', [
            'sku_id' => $skuId,
            'project_id' => $projectId,
            'asset_types' => $assetTypes,
            'detail_types' => $detailTypes,
            'custom_material_requests' => $customMaterialRequests,
            'task_brief' => $taskBrief,
            'launch_action' => $launchAction,
            'detail_output' => $detailOutput,
            'requested_model_id' => $requestedModelId,
            'asset_model_id' => (int) $modelSelection['resolvedModelId'],
            'detail_model_id' => (int) $detailModelSelection['resolvedModelId'],
        ]);
        $existingAssetSubmission = $submissionGuard->existing($merchantId, $assetSubmissionKey);
        if ($existingAssetSubmission !== null) {
            try {
                $submissionGuard->assertMatches($existingAssetSubmission, $submissionPayloadHash);
                if ($launchAction !== 'preview') {
                    $existingDetailSubmission = $submissionGuard->existing($merchantId, $detailSubmissionKey);
                    if ($existingDetailSubmission === null) {
                        throw new \RuntimeException('相同整套任务提交记录不完整，请联系管理员核对任务。');
                    }
                    $submissionGuard->assertMatches($existingDetailSubmission, $submissionPayloadHash);
                }
            } catch (\Throwable $exception) {
                return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, $exception->getMessage(), 'error');
            }
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId . '&project_id=' . $projectId . '&task_center=open&task_tab=latest', '相同整套生成请求已经提交，本次没有重复创建任务或扣点。');
        }
        $model = $modelSelection['model'];
        $totalImages = count($assetTypes) + count($detailTypes);
        $batchLimit = $plans->batchImageLimit($merchantId, generation_job_image_limit());
        if ($totalImages > $batchLimit) {
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, "当前套餐单次最多生成 {$batchLimit} 张图片，当前整套共 {$totalImages} 张。", 'error');
        }
        $requiredJobSlots = $launchAction === 'preview' ? 1 : 2;
        $jobCapacity = $plans->canStartJobs($merchantId, $requiredJobSlots);
        if (!$jobCapacity['allowed']) {
            return Web::redirect('/workspace/jobs', (string) $jobCapacity['message'], 'error');
        }
        $pricingSnapshot = $modelSelection['price'];
        $quote = $plans->generationQuote($merchantId, $totalImages, (int) $pricingSnapshot['credits']);
        if (!$quote['allowed']) {
            return Web::redirect('/workspace/plans', (string) $quote['message'], 'error');
        }
        $estimatedCredits = (int) $quote['credits'];
        $availableCredits = $this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']);
        if ($availableCredits < $estimatedCredits) {
            $quoteLabel = $launchAction === 'preview' ? '首张预览' : '整套素材';
            return Web::redirect('/workspace/usage', "可用点数不足，当前可用 {$availableCredits} 点，{$quoteLabel}预计需要 {$estimatedCredits} 点。本次未创建任务。", 'error');
        }
        $configured = $newApi->configuration()['configured'];
        $ready = $configured && $newApi->hasAvailableModels(
            $model,
            $modelSelection['resolvedModelId'],
            (string) ($modelSelection['route']['resolvedGateway'] ?? ''),
            AiCapability::IMAGE_EDIT
        );
        $status = $ready ? 'queued' : 'pending_gateway';
        $bundleNo = ($launchAction === 'preview' ? 'PREVIEW-' : 'BUNDLE-') . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $submittedIp = $this->request->ip();
            $jobIds = Db::transaction(function () use ($merchantId, $user, $submittedIp, $skuId, $projectId, $project, $taskBrief, $assetTypes, $customMaterialRequests, $detailTypes, $detailOutput, $totalImages, $status, $ready, $configured, $model, $modelSelection, $detailModelSelection, $pricingSnapshot, $plans, $itemQueue, $bundleNo, $launchAction, $requiredJobSlots, $submissionGuard, $assetSubmissionKey, $detailSubmissionKey, $submissionPayloadHash): array {
                $lockedMerchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
                if (!$lockedMerchant || $lockedMerchant['status'] !== 'active') {
                    throw new \RuntimeException('商户账户当前不可用。');
                }
                $existingAsset = $submissionGuard->existing($merchantId, $assetSubmissionKey);
                if ($existingAsset !== null) {
                    $submissionGuard->assertMatches($existingAsset, $submissionPayloadHash);
                    $existingDetailId = 0;
                    if ($launchAction !== 'preview') {
                        $existingDetail = $submissionGuard->existing($merchantId, $detailSubmissionKey);
                        if ($existingDetail === null) {
                            throw new \RuntimeException('相同整套任务提交记录不完整，请联系管理员核对任务。');
                        }
                        $submissionGuard->assertMatches($existingDetail, $submissionPayloadHash);
                        $existingDetailId = (int) $existingDetail['id'];
                    }
                    return [
                        (int) $existingAsset['id'],
                        $existingDetailId,
                        max(0, (int) $existingAsset['estimated_credits']) + ($existingDetailId > 0 ? max(0, (int) $existingDetail['estimated_credits']) : 0),
                        true,
                        (string) ($existingAsset['bundle_no'] ?? $bundleNo),
                    ];
                }
                $jobCapacity = $plans->canStartJobs($merchantId, $requiredJobSlots);
                if (!$jobCapacity['allowed']) {
                    throw new \RuntimeException((string) $jobCapacity['message']);
                }
                $quote = $plans->generationQuote($merchantId, $totalImages, (int) $pricingSnapshot['credits']);
                if (!$quote['allowed']) {
                    throw new \RuntimeException((string) $quote['message']);
                }
                $totalCredits = (int) $quote['credits'];
                $availableCredits = $this->availableGenerationCredits($merchantId, (int) $lockedMerchant['credit_balance']);
                if ($availableCredits < $totalCredits) {
                    $quoteLabel = $launchAction === 'preview' ? '首张预览' : '整套素材';
                    throw new \RuntimeException("可用点数不足，当前可用 {$availableCredits} 点，{$quoteLabel}预计需要 {$totalCredits} 点。本次未创建任务。");
                }
                $unitCredits = $totalCredits > 0 ? (int) $pricingSnapshot['credits'] : 0;
                $assetCredits = count($assetTypes) * $unitCredits;
                $detailCredits = count($detailTypes) * $unitCredits;
                $now = Web::now();
                $common = [
                    'merchant_id' => $merchantId, 'sku_id' => $skuId, 'bundle_no' => $bundleNo,
                    'platform' => (string) $project['platform'], 'language' => (string) $project['language'],
                    'task_brief' => $taskBrief !== '' ? $taskBrief : null,
                    'task_context_json' => json_encode([
                        'mode' => 'listing_bundle', 'detail_project_id' => $projectId,
                        'launch_action' => $launchAction,
                        'detail_output_preset' => $detailOutput['preset'],
                        'detail_output_width' => $detailOutput['width'],
                        'detail_output_height' => $detailOutput['height'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'pricing_snapshot_json' => json_encode($pricingSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'submitted_by_user_id' => (int) $user['id'], 'submitted_ip' => $submittedIp,
                    'safety_status' => 'pending', 'safety_attempts' => 0,
                    'status' => $status, 'actual_credits' => 0, 'progress_current' => 0,
                    'progress_stage' => $ready ? 'safety_pending' : 'pending_gateway',
                    'model' => $model,
                    'error_message' => $ready ? null : ($configured ? '模型没有可用渠道' : '等待配置模型网关'),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $assetJobId = (int) Db::name('generation_jobs')->insertGetId($common + [
                    'detail_page_project_id' => null, 'bundle_part' => 'assets',
                    'client_submission_id' => $assetSubmissionKey,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'requested_model_id' => $modelSelection['requestedModelId'],
                    'resolved_ai_model_id' => $modelSelection['resolvedModelId'],
                    'route_snapshot_json' => json_encode($modelSelection['route'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'job_no' => $bundleNo . ($launchAction === 'preview' ? '-FIRST' : '-ASSET'), 'asset_types' => json_encode($assetTypes),
                    'custom_material_requests' => $customMaterialRequests === [] ? null : json_encode($customMaterialRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'estimated_credits' => $assetCredits, 'progress_total' => count($assetTypes),
                    'progress_message' => $ready ? ($launchAction === 'preview' ? '首张预览等待后台安全检查' : '整套上架素材：商品主图等待后台安全检查') : '等待管理员配置可用模型渠道',
                ]);
                $itemQueue->createItems($assetJobId, $merchantId, $assetTypes, $assetCredits, $status);
                if ($launchAction === 'preview') {
                    return [$assetJobId, 0, $totalCredits, false, $bundleNo];
                }
                $detailJobId = (int) Db::name('generation_jobs')->insertGetId($common + [
                    'detail_page_project_id' => $projectId, 'bundle_part' => 'detail',
                    'client_submission_id' => $detailSubmissionKey,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'requested_model_id' => $detailModelSelection['requestedModelId'],
                    'resolved_ai_model_id' => $detailModelSelection['resolvedModelId'],
                    'route_snapshot_json' => json_encode($detailModelSelection['route'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'job_no' => $bundleNo . '-DETAIL', 'asset_types' => json_encode($detailTypes),
                    'estimated_credits' => $detailCredits, 'progress_total' => count($detailTypes),
                    'progress_message' => $ready ? '整套上架素材：详情页等待后台安全检查' : '等待管理员配置可用模型渠道',
                ]);
                $itemQueue->createItems($detailJobId, $merchantId, $detailTypes, $detailCredits, $status);
                Db::name('detail_page_projects')->where('id', $projectId)->update(['status' => 'generating', 'updated_at' => $now]);
                return [$assetJobId, $detailJobId, $totalCredits, false, $bundleNo];
            });
        } catch (\Throwable $exception) {
            $existing = $submissionGuard->existing($merchantId, $assetSubmissionKey);
            if ($existing !== null) {
                try {
                    $submissionGuard->assertMatches($existing, $submissionPayloadHash);
                    return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId . '&project_id=' . $projectId . '&task_center=open&task_tab=latest', '相同整套生成请求已经提交，本次没有重复创建任务或扣点。');
                } catch (\Throwable) {
                }
            }
            return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId, $exception->getMessage(), 'error');
        }
        if (empty($jobIds[3])) {
            Audit::log('merchant_user', (int) $user['id'], $launchAction === 'preview' ? 'listing_bundle.preview' : 'listing_bundle.create', 'generation_job', (int) $jobIds[0], $this->request->ip(), [
            'bundle_no' => (string) $jobIds[4], 'sku_id' => $skuId, 'project_id' => $projectId,
            'asset_job_id' => (int) $jobIds[0], 'detail_job_id' => (int) $jobIds[1],
            'asset_count' => count($assetTypes), 'detail_count' => count($detailTypes), 'credits' => (int) $jobIds[2],
            'custom_material_requests' => $customMaterialRequests,
            'detail_output' => $detailOutput,
            'launch_action' => $launchAction,
            ]);
        }
        $message = !empty($jobIds[3])
            ? '相同整套生成请求已经提交，本次没有重复创建任务或扣点。'
            : ($launchAction === 'preview'
            ? ($ready ? '首张预览已提交，生成进度已在右侧打开；确认结果后再生成整套。' : '首张预览任务已保存，可在生成进度中查看并重试。')
            : ($ready ? '整套素材已拆分为后台任务，生成进度已在右侧打开。' : '整套任务已保存，可在生成进度中查看并重试。'));
        return Web::redirect('/workspace/generate?mode=bundle&sku_id=' . $skuId . '&project_id=' . $projectId . '&task_center=open&task_tab=latest', $message);
    }

    public function createFreeGenerationJob(NewApiClient $newApi, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety, AssetStorage $storage, PromptTemplateService $prompts, GenerationItemQueue $itemQueue, CreativeModelService $creativeModels, FreeCreationIntentService $intentService): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $clientSubmissionId = trim((string) $this->request->post('client_submission_id', ''));
        if ($clientSubmissionId === '') {
            return $this->freeCreateError('本次发送标识缺失，请刷新页面后重试。', 409);
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $clientSubmissionId) !== 1) {
            return $this->freeCreateError('本次发送标识无效，请刷新页面后重试。');
        }
        $prompt = mb_strimwidth(trim((string) $this->request->post('prompt', '')), 0, 4000, '');
        $promptTemplateId = max(0, (int) $this->request->post('prompt_template_id', 0));
        $promptTemplate = $prompts->activeFreeTemplate($promptTemplateId);
        $requestedQuantity = max(1, (int) $this->request->post('quantity', 1));
        $batchLimit = min(generation_asset_quantity_limit(), $plans->batchImageLimit($merchantId, generation_job_image_limit()));
        if ($requestedQuantity > $batchLimit) {
            return $this->freeCreateError("当前套餐单次最多生成 {$batchLimit} 张图片。");
        }
        $quantity = $requestedQuantity;
        $outputPreset = (string) $this->request->post('output_preset', 'square_1_1');
        $language = (string) $this->request->post('language', 'zh-CN');
        $outputPresets = canvas_output_presets();
        if (mb_strlen($prompt) < 4 || !isset($outputPresets[$outputPreset]) || !isset(generation_language_options()[$language])) {
            return $this->freeCreateError('请填写至少 4 个字的图片描述，并选择有效图片比例与画面语言。');
        }
        if ($promptTemplateId > 0 && $promptTemplate === null) {
            return $this->freeCreateError('所选灵感模板已下架，请重新选择或直接填写画面描述。');
        }
        try {
            $intent = $intentService->compile(
                $prompt,
                $promptTemplate,
                (string) $this->request->post('subject_text', ''),
                (string) $this->request->post('user_addition', '')
            );
            $prompt = (string) $intent['prompt'];
        } catch (\Throwable $exception) {
            return $this->freeCreateError($exception->getMessage());
        }
        $jobCapacity = $plans->canStartJobs($merchantId);
        if (!$jobCapacity['allowed']) {
            return $this->freeCreateError((string) $jobCapacity['message']);
        }
        $referenceImages = [];
        $referenceFingerprints = [];
        try {
            $referenceAssetId = max(0, (int) $this->request->post('reference_asset_id', 0));
            if ($referenceAssetId > 0) {
                $referenceAsset = Db::name('assets')->where('id', $referenceAssetId)
                    ->where('merchant_id', $merchantId)->where('status', 'active')->find();
                if (!$referenceAsset) {
                    return $this->freeCreateError('所选素材库参考图已不存在，请重新选择。');
                }
                $referenceImage = $storage->readImageWithBackup(
                    (string) $referenceAsset['file_url'],
                    (string) ($referenceAsset['backup_file_url'] ?? '')
                );
                $referenceImages[] = $referenceImage;
                $referenceFingerprints[] = hash('sha256', (string) $referenceImage['content']);
            }
            $uploadedFiles = $this->request->file();
            $referenceFiles = is_array($uploadedFiles)
                ? ($uploadedFiles['reference_images'] ?? $uploadedFiles['reference_image'] ?? [])
                : [];
            $referenceFiles = is_array($referenceFiles) ? array_values($referenceFiles) : [$referenceFiles];
            if (count($referenceFiles) + count($referenceImages) > 6) {
                return $this->freeCreateError('自由创作单次最多上传 6 张参考图。');
            }
            foreach ($referenceFiles as $referenceFile) {
                if ($referenceFile) {
                    $normalized = $this->normalizedUploadedProductImage($referenceFile);
                    $referenceImages[] = $normalized;
                    $referenceFingerprints[] = hash('sha256', (string) $normalized['content']);
                }
            }
        } catch (\Throwable $exception) {
            return $this->freeCreateError($exception->getMessage());
        }

        $capability = $referenceImages !== [] ? AiCapability::IMAGE_EDIT : AiCapability::IMAGE_GENERATION;
        $requestedModelId = $this->requestedGenerationModelId();
        $submissionPayloadHash = $this->freeSubmissionPayloadHash([
            'prompt' => $prompt,
            'template_id' => max(0, (int) ($intent['prompt_template_id'] ?? 0)),
            'template_code' => (string) ($intent['prompt_template_code'] ?? ''),
            'template_content_sha256' => hash('sha256', (string) ($intent['prompt_template_content'] ?? '')),
            'subject_text' => (string) ($intent['subject_text'] ?? ''),
            'user_addition' => (string) ($intent['user_addition'] ?? ''),
            'reference_fingerprints' => $referenceFingerprints,
            'quantity' => $quantity,
            'output_preset' => $outputPreset,
            'language' => $language,
            'requested_model_id' => $requestedModelId,
            'capability' => $capability,
        ]);
        $existingJob = Db::name('generation_jobs')
            ->where('merchant_id', $merchantId)
            ->where('client_submission_id', $clientSubmissionId)
            ->find();
        if ($existingJob) {
            if (!$this->freeSubmissionMatches($existingJob, $submissionPayloadHash)) {
                return $this->freeCreateError('同一发送标识已用于另一条创作需求，请刷新后重新发送。', 409);
            }
            return $this->freeCreateSuccess($existingJob, true);
        }
        try {
            $modelSelection = $creativeModels->resolveTask(
                $capability,
                $requestedModelId,
                $merchantId,
                $pricing->imageGenerationCredits(),
                'free'
            );
        } catch (\Throwable $exception) {
            return $this->freeCreateError($exception->getMessage());
        }
        $model = $modelSelection['model'];
        $pricingSnapshot = $modelSelection['price'];
        $quote = $plans->generationQuote($merchantId, $quantity, (int) $pricingSnapshot['credits']);
        if (!$quote['allowed']) {
            return $this->freeCreateError((string) $quote['message']);
        }
        $estimatedCredits = (int) $quote['credits'];
        $availableCredits = $this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']);
        if ($availableCredits < $estimatedCredits) {
            return $this->freeCreateError("可用点数不足，当前可用 {$availableCredits} 点，该任务预计需要 {$estimatedCredits} 点。本次未创建任务。");
        }

        $gatewayConfigured = $newApi->configuration()['configured'];
        $gatewayReady = $gatewayConfigured && $newApi->hasAvailableModels(
            $model,
            $modelSelection['resolvedModelId'],
            (string) ($modelSelection['route']['resolvedGateway'] ?? ''),
            $capability
        );
        $status = $gatewayReady ? 'queued' : 'pending_gateway';
        $jobNo = 'FREE-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $storedReferences = [];
        if ($referenceImages !== []) {
            try {
                foreach ($referenceImages as $index => $referenceImage) {
                    $stored = $storage->storeSourceImageRecord(
                        (string) $referenceImage['content'],
                        (string) $referenceImage['mime'],
                        $merchantId,
                        $jobNo . '-REF-' . ($index + 1)
                    );
                    $storedReferences[] = [
                        'url' => (string) $stored['url'],
                        'backup_url' => (string) ($stored['backupUrl'] ?? ''),
                    ];
                }
            } catch (\Throwable $exception) {
                return $this->freeCreateError($exception->getMessage());
            }
        }
        $assetTypes = array_fill(0, $quantity, 'free');
        $width = (int) $outputPresets[$outputPreset]['width'];
        $height = (int) $outputPresets[$outputPreset]['height'];
        try {
            $submittedIp = $this->request->ip();
            $jobId = Db::transaction(function () use ($merchantId, $user, $submittedIp, $assetTypes, $status, $model, $modelSelection, $pricingSnapshot, $gatewayReady, $gatewayConfigured, $plans, $jobNo, $prompt, $intent, $clientSubmissionId, $submissionPayloadHash, $storedReferences, $referenceFingerprints, $outputPreset, $width, $height, $language, $itemQueue): int {
                $lockedMerchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
                if (!$lockedMerchant || $lockedMerchant['status'] !== 'active') {
                    throw new \RuntimeException('商户账户当前不可用。');
                }
                $generationQuote = $plans->generationQuote($merchantId, count($assetTypes), (int) $pricingSnapshot['credits']);
                if (!$generationQuote['allowed']) {
                    throw new \RuntimeException((string) $generationQuote['message']);
                }
                $jobCapacity = $plans->canStartJobs($merchantId);
                if (!$jobCapacity['allowed']) {
                    throw new \RuntimeException((string) $jobCapacity['message']);
                }
                $estimatedCredits = (int) $generationQuote['credits'];
                $availableCredits = $this->availableGenerationCredits($merchantId, (int) $lockedMerchant['credit_balance']);
                if ($availableCredits < $estimatedCredits) {
                    throw new \RuntimeException("可用点数不足，当前可用 {$availableCredits} 点，该任务预计需要 {$estimatedCredits} 点。本次未创建任务。");
                }
                $now = Web::now();
                $jobId = (int) Db::name('generation_jobs')->insertGetId([
                    'merchant_id' => $merchantId,
                    'sku_id' => null,
                    'job_no' => $jobNo,
                    'platform' => 'free',
                    'language' => $language,
                    'asset_types' => json_encode($assetTypes),
                    'submitted_by_user_id' => (int) $user['id'],
                    'submitted_ip' => $submittedIp,
                    'client_submission_id' => $clientSubmissionId,
                    'submission_payload_hash' => $submissionPayloadHash,
                    'task_context_json' => null,
                    'requested_model_id' => $modelSelection['requestedModelId'],
                    'resolved_ai_model_id' => $modelSelection['resolvedModelId'],
                    'route_snapshot_json' => json_encode($modelSelection['route'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'pricing_snapshot_json' => json_encode($pricingSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'safety_status' => 'pending',
                    'safety_attempts' => 0,
                    'status' => $status,
                    'estimated_credits' => $estimatedCredits,
                    'actual_credits' => 0,
                    'progress_current' => 0,
                    'progress_total' => count($assetTypes),
                    'progress_stage' => $gatewayReady ? 'safety_pending' : 'pending_gateway',
                    'progress_message' => $gatewayReady ? '自由创作已提交，等待后台安全检查' : '等待管理员配置可用模型渠道',
                    'model' => $model,
                    'error_message' => $gatewayReady ? null : ($gatewayConfigured ? 'AI 服务已连接，但尚未配置可用模型渠道' : '等待管理员配置 AI 模型网关'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::name('free_generation_jobs')->insert([
                    'job_id' => $jobId,
                    'prompt' => $prompt,
                    'prompt_template_id' => $intent['prompt_template_id'],
                    'prompt_template_code' => $intent['prompt_template_code'],
                    'prompt_template_name' => $intent['prompt_template_name'],
                    'prompt_template_content' => $intent['prompt_template_content'],
                    'subject_text' => $intent['subject_text'],
                    'user_addition' => $intent['user_addition'],
                    'reference_image_url' => $storedReferences[0]['url'] ?? null,
                    'reference_image_backup_url' => $storedReferences[0]['backup_url'] ?? null,
                    'reference_images_json' => json_encode(array_slice($storedReferences, 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'reference_count' => count($storedReferences),
                    'reference_fingerprints_json' => json_encode($referenceFingerprints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'output_preset' => $outputPreset,
                    'output_width' => $width,
                    'output_height' => $height,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $itemQueue->createItems($jobId, $merchantId, $assetTypes, $estimatedCredits, $status);
                return $jobId;
            });
        } catch (\Throwable $exception) {
            $existingJob = Db::name('generation_jobs')
                ->where('merchant_id', $merchantId)
                ->where('client_submission_id', $clientSubmissionId)
                ->find();
            $this->cleanupUnclaimedFreeReferences($storage, $storedReferences);
            if ($existingJob) {
                if (!$this->freeSubmissionMatches($existingJob, $submissionPayloadHash)) {
                    return $this->freeCreateError('同一发送标识已用于另一条创作需求，请刷新后重新发送。', 409);
                }
                return $this->freeCreateSuccess($existingJob, true);
            }
            return $this->freeCreateError($exception->getMessage());
        }

        Audit::log('merchant_user', (int) $user['id'], 'free_generation.create', 'generation_job', $jobId, $this->request->ip(), [
            'quantity' => $quantity,
            'output_preset' => $outputPreset,
            'output_size' => $width . 'x' . $height,
            'language' => $language,
            'has_reference' => $storedReferences !== [],
            'reference_count' => count($storedReferences),
            'prompt_template_id' => $promptTemplateId ?: null,
            'prompt_template_name' => $promptTemplate['name'] ?? null,
            'prompt_template_category' => $promptTemplate['asset_type'] ?? null,
        ]);
        $createdJob = Db::name('generation_jobs')->where('id', $jobId)->find() ?: [
            'id' => $jobId,
            'job_no' => $jobNo,
            'status' => $status,
            'progress_current' => 0,
            'progress_total' => $quantity,
            'progress_message' => $gatewayReady ? '自由创作已提交，等待后台安全检查' : '等待管理员配置可用模型渠道',
            'model' => $model,
            'created_at' => Web::now(),
        ];
        return $this->freeCreateSuccess($createdJob);
    }

    public function optimizeFreePrompt(NewApiClient $newApi, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety): Response
    {
        [$merchant, $user] = $this->identity();
        $idea = mb_strimwidth(trim((string) $this->request->post('idea', '')), 0, 2000, '');
        $outputPreset = (string) $this->request->post('output_preset', 'square_1_1');
        $presets = canvas_output_presets();
        if (mb_strlen($idea) < 4 || !isset($presets[$outputPreset])) {
            return Response::create(['ok' => false, 'message' => '请先填写至少 4 个字的初步想法并选择图片比例。'], 'json')->code(422);
        }
        $model = $newApi->visionModel((int) $merchant['id']);
        $pricingSnapshot = $pricing->priceForModel(AiCapability::CONTENT_PLANNER, $model, $pricing->promptOptimizationCredits());
        $credits = $plans->recognitionCredits((int) $merchant['id'], (int) $pricingSnapshot['credits']);
        $reference = 'PROMPT-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $charged = false;
        $ledgerId = null;
        $balance = (int) $merchant['credit_balance'];
        try {
            $contentSafety->enforceText($merchant, $user, $idea, 'free_prompt_idea', $this->request->ip());
            if ($credits > 0) {
                [$ledgerId, $balance] = $this->reservePromptCredits((int) $merchant['id'], $credits, $reference);
                $charged = true;
            }
            $preset = $presets[$outputPreset];
            $result = $newApi->optimizeImagePrompt(
                $idea,
                (string) $preset['label'] . ' ' . (int) $preset['width'] . 'x' . (int) $preset['height'],
                $this->request->post('has_reference', '') === '1',
                (int) $merchant['id']
            );
            if (!$result['ok']) {
                if ($charged) {
                    $balance = $this->refundPromptCredits((int) $merchant['id'], $credits, $reference);
                    $charged = false;
                }
                return Response::create(['ok' => false, 'message' => (string) $result['message'] . ($credits > 0 ? '，已退回预留点数。' : ''), 'balance' => $balance], 'json')->code(422);
            }
            $requestId = trim((string) ($result['requestId'] ?? ''));
            if ($ledgerId !== null && $requestId !== '') {
                Db::name('credit_ledger')->where('id', $ledgerId)->update(['new_api_request_id' => $requestId]);
            }
            Audit::log('merchant_user', (int) $user['id'], 'free_prompt.optimized', 'merchant', (int) $merchant['id'], $this->request->ip(), [
                'credits' => $credits,
                'model' => (string) ($result['model'] ?? $newApi->visionModel((int) $merchant['id'])),
                'request_id' => $requestId,
            ]);
            $result['creditsCharged'] = $credits;
            $result['balance'] = $balance;
            $result['pricingSnapshot'] = $pricingSnapshot;
            return Response::create($result, 'json');
        } catch (\Throwable $exception) {
            if ($charged) {
                try {
                    $this->refundPromptCredits((int) $merchant['id'], $credits, $reference);
                } catch (\Throwable) {
                }
            }
            return Response::create(['ok' => false, 'message' => $exception->getMessage()], 'json')->code(422);
        }
    }

    public function skus(): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $keyword = trim((string) $this->request->get('q', ''));
        $category = trim((string) $this->request->get('category', ''));
        $imageState = (string) $this->request->get('image', '');
        $sort = (string) $this->request->get('sort', 'latest');
        $archived = $this->request->get('view', '') === 'archived';
        if (!in_array($imageState, ['', 'with', 'without'], true)) {
            $imageState = '';
        }
        if (!in_array($sort, ['latest', 'oldest', 'name', 'assets', 'jobs'], true)) {
            $sort = 'latest';
        }
        $query = Db::name('skus')->where('merchant_id', $merchantId)->where('status', $archived ? 'archived' : 'active');
        if ($keyword !== '') {
            $query->whereLike('name|sku_code|category|search_terms|selling_points|product_description|creative_brief|report_materials|report_appearance|report_target_audience', '%' . $keyword . '%');
        }
        if ($category !== '') {
            $query->where('category', $category);
        }
        if ($imageState === 'with') {
            $query->whereNotNull('source_image_path')->where('source_image_path', '<>', '');
        } elseif ($imageState === 'without') {
            $query->where(function ($nested): void {
                $nested->whereNull('source_image_path')->whereOr('source_image_path', '=', '');
            });
        }
        if ($sort === 'oldest') {
            $query->order('id', 'asc');
        } elseif ($sort === 'name') {
            $query->order('name', 'asc');
        } else {
            $query->order('id', 'desc');
        }
        $skus = $query->limit(300)->select()->toArray();

        $skuIds = array_map(static fn (array $sku): int => (int) $sku['id'], $skus);
        $assetStats = [];
        $jobStats = [];
        $reportStats = [];
        if ($skuIds !== []) {
            foreach (Db::name('assets')->where('merchant_id', $merchantId)->where('status', 'active')->whereIn('sku_id', $skuIds)
                ->field('sku_id,COUNT(*) as asset_count,SUM(is_favorite) as favorite_count')->group('sku_id')->select()->toArray() as $row) {
                $assetStats[(int) $row['sku_id']] = $row;
            }
            foreach (Db::name('generation_jobs')->where('merchant_id', $merchantId)->whereIn('sku_id', $skuIds)
                ->field('sku_id,COUNT(*) as job_count,MAX(created_at) as last_job_at')->group('sku_id')->select()->toArray() as $row) {
                $jobStats[(int) $row['sku_id']] = $row;
            }
            foreach (Db::name('detail_page_projects')->where('merchant_id', $merchantId)->whereIn('sku_id', $skuIds)
                ->field('sku_id,COUNT(*) as channel_count,MAX(id) as report_id,MAX(updated_at) as report_updated_at')->group('sku_id')->select()->toArray() as $row) {
                $reportStats[(int) $row['sku_id']] = $row;
            }
        }
        foreach ($skus as &$sku) {
            $id = (int) $sku['id'];
            $sku['asset_count'] = (int) ($assetStats[$id]['asset_count'] ?? 0);
            $sku['favorite_count'] = (int) ($assetStats[$id]['favorite_count'] ?? 0);
            $sku['job_count'] = (int) ($jobStats[$id]['job_count'] ?? 0);
            $sku['last_job_at'] = (string) ($jobStats[$id]['last_job_at'] ?? '');
            $sku['report_id'] = (int) ($sku['report_source_project_id'] ?? 0) ?: (int) ($reportStats[$id]['report_id'] ?? 0);
            $sku['report_updated_at'] = (string) ($sku['report_updated_at'] ?? '') ?: (string) ($reportStats[$id]['report_updated_at'] ?? '');
            $sku['channel_count'] = (int) ($reportStats[$id]['channel_count'] ?? 0);
            $profileCompleteness = sku_profile_completeness($sku);
            $sku['profile_completeness'] = (int) $profileCompleteness['score'];
            $sku['profile_missing_items'] = $profileCompleteness['missing'];
        }
        unset($sku);
        if (in_array($sort, ['assets', 'jobs'], true)) {
            $field = $sort === 'assets' ? 'asset_count' : 'job_count';
            usort($skus, static fn (array $left, array $right): int => [$right[$field], $right['id']] <=> [$left[$field], $left['id']]);
        }

        $categories = Db::name('skus')->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')
            ->where('category', '<>', '')->distinct(true)->order('category', 'asc')->column('category');
        $stats = [
            'active' => (int) Db::name('skus')->where('merchant_id', $merchantId)->where('status', 'active')->count(),
            'archived' => (int) Db::name('skus')->where('merchant_id', $merchantId)->where('status', 'archived')->count(),
            'withImage' => (int) Db::name('skus')->where('merchant_id', $merchantId)->where('status', 'active')->whereNotNull('source_image_path')->where('source_image_path', '<>', '')->count(),
            'assets' => (int) Db::name('assets')->where('merchant_id', $merchantId)->where('status', 'active')->count(),
            'reports' => (int) Db::name('skus')->where('merchant_id', $merchantId)->where('report_status', 'ready')->count(),
        ];
        $returnTo = '/workspace/skus?' . http_build_query(array_filter([
            'q' => $keyword,
            'category' => $category,
            'image' => $imageState,
            'sort' => $sort !== 'latest' ? $sort : null,
            'view' => $archived ? 'archived' : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $this->render('merchant/skus', compact(
            'merchant', 'user', 'skus', 'keyword', 'category', 'imageState', 'sort', 'archived', 'categories', 'stats', 'returnTo'
        ) + ['pageTitle' => '商品档案']);
    }

    public function toggleSkuArchive(int $id): Response
    {
        [$merchant, $user] = $this->identity();
        $sku = Db::name('skus')->where('id', $id)->where('merchant_id', $merchant['id'])->whereIn('status', ['active', 'archived'])->find();
        if (!$sku) {
            return Web::redirect('/workspace/skus', 'SKU 不存在。', 'error');
        }
        $restore = (string) $this->request->post('action', '') === 'restore';
        if (!$restore) {
            $activeJobs = (int) Db::name('generation_jobs')->where('sku_id', $id)->where('merchant_id', $merchant['id'])
                ->whereIn('status', ['pending_gateway', 'queued', 'running', 'manual_review'])->count();
            if ($activeJobs > 0) {
                return Web::redirect($this->skuReturnPath(), '该 SKU 仍有等待或生成中的任务，暂时不能归档。', 'error');
            }
        }
        $status = $restore ? 'active' : 'archived';
        Db::name('skus')->where('id', $id)->update(['status' => $status, 'updated_at' => Web::now()]);
        Audit::log('merchant_user', (int) $user['id'], 'sku.' . ($restore ? 'restore' : 'archive'), 'sku', $id, $this->request->ip());

        return Web::redirect($this->skuReturnPath($restore ? '/workspace/skus?view=archived' : '/workspace/skus'), $restore ? 'SKU 已恢复。' : 'SKU 已归档，历史素材仍然保留。');
    }

    public function deleteSku(int $id, AssetTrashService $trashService): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $sku = Db::name('skus')->where('id', $id)->where('merchant_id', $merchantId)->where('status', 'archived')->find();
        if (!$sku) {
            return Web::redirect('/workspace/skus?view=archived', '只有已归档的 SKU 可以删除。', 'error');
        }
        $activeJobs = (int) Db::name('generation_jobs')->where('sku_id', $id)->where('merchant_id', $merchantId)
            ->whereIn('status', ['pending_gateway', 'queued', 'running', 'manual_review'])->count();
        if ($activeJobs > 0) {
            return Web::redirect('/workspace/skus?view=archived', '该 SKU 仍有进行中的任务，暂时不能删除。', 'error');
        }

        $now = Web::now();
        $assetIds = array_map('intval', Db::name('assets')->where('merchant_id', $merchantId)->where('sku_id', $id)
            ->where('status', '<>', 'purged')->order('id', 'asc')->column('id'));
        if ($assetIds !== []) {
            Db::name('assets')->where('merchant_id', $merchantId)->where('sku_id', $id)->where('status', '<>', 'purged')->update([
                'status' => 'deleted', 'deleted_at' => $now, 'purged_at' => null, 'updated_at' => $now,
            ]);
        }
        $purged = 0;
        $failed = 0;
        $warnings = [];
        foreach (array_chunk($assetIds, 200) as $chunk) {
            $result = $trashService->purgeForMerchant($merchantId, $chunk);
            $purged += (int) $result['purged'];
            $failed += (int) $result['failed'];
            array_push($warnings, ...(array) $result['warnings']);
        }
        Db::transaction(function () use ($merchantId, $id, $now): void {
            Db::name('skus')->where('id', $id)->where('merchant_id', $merchantId)->update(['status' => 'deleted', 'updated_at' => $now]);
            Db::name('detail_page_projects')->where('sku_id', $id)->where('merchant_id', $merchantId)->update(['status' => 'deleted', 'updated_at' => $now]);
            Db::name('generation_jobs')->where('sku_id', $id)->where('merchant_id', $merchantId)->update(['merchant_hidden_at' => $now, 'updated_at' => $now]);
        });
        Audit::log('merchant_user', (int) $user['id'], 'sku.delete', 'sku', $id, $this->request->ip(), [
            'asset_count' => count($assetIds), 'purged' => $purged, 'failed' => $failed, 'warnings' => array_values(array_unique($warnings)),
        ]);
        $message = "商品档案已删除，并清理 {$purged} 张生成图片；任务与点数记录仍保留在管理后台审计中。";
        if ($failed > 0) {
            $message .= " {$failed} 张文件清理失败，已记录到审计日志。";
        }
        return Web::redirect('/workspace/skus?view=archived', $message, $failed > 0 ? 'warning' : 'success');
    }

    public function newSku(PricingService $pricing, PlanService $plans): Response
    {
        [$merchant, $user] = $this->identity();
        return $this->render('merchant/sku_new', compact('merchant', 'user') + [
            'pageTitle' => '新建 SKU',
            'recognitionPrice' => $plans->recognitionCredits((int) $merchant['id'], $pricing->productRecognitionCredits()),
        ]);
    }

    public function createSku(AssetStorage $storage, ContentSafetyService $contentSafety, PlanService $plans, SkuProductReport $profiles): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $skuLimit = $plans->limit($merchantId, 'skus');
        $skuCount = (int) Db::name('skus')->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->count();
        if ($skuLimit > 0 && $skuCount >= $skuLimit) {
            return Web::redirect('/workspace/plans', "当前套餐最多管理 {$skuLimit} 个 SKU，请归档删除无用商品或升级套餐。", 'error');
        }
        $skuCode = strtoupper(trim((string) $this->request->post('sku_code', '')));
        if ($skuCode === '') {
            $skuCode = 'SKU-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        }
        $name = trim((string) $this->request->post('name', ''));
        $category = trim((string) $this->request->post('category', ''));
        $sellingPoints = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，、]+/u', (string) $this->request->post('selling_points', '')) ?: [])));
        $creativeBrief = trim((string) $this->request->post('creative_brief', ''));
        $productDescription = trim((string) $this->request->post('product_description', ''));
        $searchTerms = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，、]+/u', (string) $this->request->post('search_terms', '')) ?: [])));

        if ($skuCode === '' || mb_strlen($skuCode) > 80 || mb_strlen($name) < 2 || mb_strlen($name) > 160 || $category === ''
            || mb_strlen($creativeBrief) > 1000 || mb_strlen($productDescription) > 4000) {
            return Web::redirect('/workspace/skus/new', '请完整填写 SKU 编码、商品名称和类目。', 'error');
        }
        if (Db::name('skus')->where('merchant_id', $merchantId)->where('sku_code', $skuCode)->find()) {
            return Web::redirect('/workspace/skus/new', 'SKU 编码已存在。', 'error');
        }

        try {
            $preparedSourceImage = $this->prepareUploadedSourceImage($contentSafety, $merchant, $user, 'sku_create_image');
            $contentSafety->enforceText($merchant, $user, $this->skuSafetyText([
                'name' => $name,
                'category' => $category,
                'selling_points' => json_encode($sellingPoints, JSON_UNESCAPED_UNICODE),
                'creative_brief' => $creativeBrief,
                'product_description' => $productDescription,
            ]), 'sku_create_text', $this->request->ip());
            $sourceImage = $preparedSourceImage === null ? null : $storage->storeSourceImageRecord(
                (string) $preparedSourceImage['content'],
                (string) $preparedSourceImage['mime'],
                $merchantId,
                $skuCode
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/skus/new', $exception->getMessage(), 'error');
        }

        $now = Web::now();
        $skuId = Db::name('skus')->insertGetId([
            'merchant_id' => $merchantId,
            'sku_code' => $skuCode,
            'name' => $name,
            'category' => $category,
            'selling_points' => json_encode($sellingPoints, JSON_UNESCAPED_UNICODE),
            'creative_brief' => $creativeBrief !== '' ? $creativeBrief : null,
            'product_description' => $productDescription !== '' ? $productDescription : null,
            'search_terms' => json_encode(array_slice($searchTerms, 0, 10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_image_path' => $sourceImage['url'] ?? null,
            'source_image_backup_path' => $sourceImage['backupUrl'] ?? null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foundation = json_decode((string) $this->request->post('foundation_payload', ''), true);
        if (is_array($foundation)) {
            $profiles->applyAnalysis((int) $skuId, $merchantId, $foundation);
        }
        Audit::log('merchant_user', (int) $user['id'], 'sku.create', 'sku', $skuId, $this->request->ip());

        return Web::redirect('/workspace/skus/' . $skuId . '?section=overview&created=1', 'SKU 已创建，请按下一步完成商品建档。');
    }

    public function analyzeSkuImage(
        NewApiClient $newApi,
        AssetStorage $storage,
        PricingService $pricing,
        PlanService $plans,
        ContentSafetyService $contentSafety,
        SkuProductReport $profiles,
        VisionTaskQueue $visionTasks,
        RecognitionBillingService $recognitionBilling,
        ModelCapabilityRouter $router
    ): Response
    {
        [$merchant, $user] = $this->identity();
        $charged = false;
        $billingReference = 'VISION-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $resolution = $router->resolve(AiCapability::PRODUCT_VISION, null, (int) $merchant['id']);
        $model = (string) ($resolution['resolvedModel'] ?? $newApi->visionModel((int) $merchant['id']));
        $fallbackModel = (string) ($resolution['fallbackModel'] ?? '');
        $pricingSnapshot = $pricing->priceForModel(AiCapability::PRODUCT_VISION, $model, $pricing->productRecognitionCredits());
        $credits = $plans->recognitionCredits((int) $merchant['id'], (int) $pricingSnapshot['credits']);
        $ledgerId = null;
        $balance = (int) $merchant['credit_balance'];
        $temporaryRecord = null;
        if ($credits > 0 && $recognitionBilling->availableCredits((int) $merchant['id'], $balance) < $credits) {
            return Response::create(['ok' => false, 'message' => "点数不足，商品识别需要 {$credits} 点。本次未执行，也不会扣点。", 'balance' => $balance], 'json')->code(422);
        }
        try {
            $uploadedFiles = $this->request->file();
            $file = is_array($uploadedFiles) ? ($uploadedFiles['source_image'] ?? null) : null;
            $sku = null;
            if ($file) {
                $image = $this->normalizedUploadedProductImage($file);
            } else {
                $skuId = (int) $this->request->post('sku_id', 0);
                $sku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchant['id'])->where('status', '<>', 'deleted')->find();
                if (!$sku || empty($sku['source_image_path'])) {
                    throw new \RuntimeException('请先选择一张商品图片。');
                }
                $image = $storage->readImageWithBackup((string) $sku['source_image_path'], (string) ($sku['source_image_backup_path'] ?? ''));
            }
            $safetyText = implode("\n", [
                (string) $this->request->post('creative_brief', ''),
                (string) $this->request->post('name', ''),
                (string) $this->request->post('category', ''),
                (string) $this->request->post('selling_points', ''),
                (string) $this->request->post('product_description', ''),
            ]);
            $enforceSafety = function () use ($contentSafety, $merchant, $user, $image, $safetyText): void {
                $contentSafety->enforceImage($merchant, $user, $image, 'sku_analyze_image', $this->request->ip());
                $contentSafety->enforceText($merchant, $user, $safetyText, 'sku_analyze_text', $this->request->ip());
            };
            $context = [
                'name' => (string) $this->request->post('name', ''),
                'category' => (string) $this->request->post('category', ''),
                'sellingPoints' => (string) $this->request->post('selling_points', ''),
                'productDescription' => (string) $this->request->post('product_description', ''),
                'purpose' => (string) $this->request->post('purpose', 'product_profile'),
                'merchantId' => (int) $merchant['id'],
                'resolvedModelId' => max(0, (int) ($resolution['resolvedModelId'] ?? 0)),
                'resolvedGateway' => (string) ($resolution['resolvedGateway'] ?? 'sub2api'),
                'fallbackModelId' => max(0, (int) ($resolution['fallbackModelId'] ?? 0)),
                'fallbackGateway' => (string) ($resolution['fallbackGateway'] ?? ''),
            ];
            $requestHash = $visionTasks->requestHash($image, $context, $model);
            $cached = $visionTasks->cachedResult($requestHash);
            if ($cached) {
                $enforceSafety();
                if ($sku) {
                    $profiles->applyAnalysis((int) $sku['id'], (int) $merchant['id'], (array) $cached['product']);
                }
                $cached['message'] = '已复用相同商品图的识别结果，本次未扣点数。';
                $cached['creditsCharged'] = 0;
                $cached['balance'] = $balance;
                $cached['pricingSnapshot'] = $pricingSnapshot;
                return Response::create($cached, 'json');
            }
            $activeTask = $visionTasks->activeTask((int) $merchant['id'], $requestHash);
            if ($activeTask) {
                $enforceSafety();
                return Response::create([
                    'ok' => true,
                    'queued' => true,
                    'taskId' => (int) $activeTask['id'],
                    'statusUrl' => '/workspace/vision-tasks/' . (int) $activeTask['id'],
                    'message' => '相同商品图正在识别，已接续现有任务。',
                    'balance' => $balance,
                ], 'json')->code(202);
            }
            $enforceSafety();
            if ($credits > 0) {
                [$ledgerId, $balance] = $recognitionBilling->reserve((int) $merchant['id'], $credits, $billingReference);
                $charged = true;
            }
            if ($file) {
                $temporaryRecord = $storage->storeSourceImageRecord(
                    (string) $image['content'],
                    (string) $image['mime'],
                    (int) $merchant['id'],
                    strtolower($billingReference)
                );
                $imageUrl = (string) $temporaryRecord['url'];
                $imageBackupUrl = (string) ($temporaryRecord['backupUrl'] ?? '');
            } else {
                $imageUrl = (string) $sku['source_image_path'];
                $imageBackupUrl = (string) ($sku['source_image_backup_path'] ?? '');
            }
            $task = $visionTasks->enqueue([
                'merchant_id' => (int) $merchant['id'],
                'user_id' => (int) $user['id'],
                'sku_id' => (int) ($sku['id'] ?? 0),
                'image_url' => $imageUrl,
                'image_backup_url' => $imageBackupUrl,
                'temporary_input' => $file !== null,
                'image_hash' => hash('sha256', (string) $image['content']),
                'request_hash' => $requestHash,
                'context' => $context,
                'model' => $model,
                'fallback_model' => $fallbackModel,
                'pricing_snapshot' => $pricingSnapshot,
                'credits' => $credits,
                'ledger_id' => $ledgerId,
                'billing_reference' => $billingReference,
            ]);
            Audit::log('merchant_user', (int) $user['id'], 'product_recognition.queued', 'vision_task', (int) $task['id'], $this->request->ip(), [
                'credits' => $credits,
                'model' => $model,
                'fallback_model' => $fallbackModel,
                'request_hash' => $requestHash,
            ]);
            return Response::create([
                'ok' => true,
                'queued' => true,
                'taskId' => (int) $task['id'],
                'statusUrl' => '/workspace/vision-tasks/' . (int) $task['id'],
                'message' => '已进入视觉任务队列，可以继续浏览其他页面。',
                'balance' => $balance,
                'pricingSnapshot' => $pricingSnapshot,
            ], 'json')->code(202);
        } catch (\Throwable $exception) {
            if ($charged) {
                try {
                    $recognitionBilling->refund((int) $merchant['id'], $credits, $billingReference);
                } catch (\Throwable) {
                }
            }
            if (is_array($temporaryRecord)) {
                foreach (array_unique(array_filter([(string) ($temporaryRecord['url'] ?? ''), (string) ($temporaryRecord['backupUrl'] ?? '')])) as $url) {
                    try {
                        $storage->deleteStoredUrl($url);
                    } catch (\Throwable) {
                    }
                }
            }
            return Response::create(['ok' => false, 'message' => $exception->getMessage()], 'json')->code(422);
        }
    }

    public function visionTaskStatus(int $id, VisionTaskQueue $visionTasks): Response
    {
        [$merchant] = $this->identity();
        $payload = $visionTasks->statusPayload($id, (int) $merchant['id']);
        if ($payload === null) {
            return Response::create(['ok' => false, 'message' => '视觉任务不存在。'], 'json')->code(404);
        }
        return Response::create($payload, 'json');
    }

    public function confirmVisionTaskSubmission(VisionTaskQueue $visionTasks): Response
    {
        [$merchant] = $this->identity();
        $clientSubmissionId = trim((string) $this->request->get('client_submission_id', ''));
        $task = $visionTasks->taskByClientSubmission((int) $merchant['id'], $clientSubmissionId);
        if (!$task) {
            return Response::create([
                'ok' => false, 'errorCode' => 'submission_not_found', 'stage' => 'confirming',
                'message' => '暂未查到这次提交，系统仍在确认是否已接收。',
            ], 'json')->code(404);
        }
        $payload = $visionTasks->statusPayload((int) $task['id'], (int) $merchant['id']) ?: [];
        return Response::create(GenerationOperationResult::success($payload + [
            'stage' => 'confirmed', 'taskCreated' => true, 'taskId' => (int) $task['id'],
            'statusUrl' => '/workspace/vision-tasks/' . (int) $task['id'],
            'message' => '已确认任务进入后台，不会重复提交。',
        ]), 'json');
    }

    public function retryVisionTask(int $id, VisionTaskQueue $visionTasks): Response
    {
        [$merchant] = $this->identity();
        try {
            $accepted = $visionTasks->retryProductLaunch($id, (int) $merchant['id']);
        } catch (\Throwable $exception) {
            return Response::create(GenerationOperationResult::failure(
                'vision_task_retry_reservation_failed', 'retry', localized_job_error($exception->getMessage())
            ), 'json')->code(409);
        }
        if (!$accepted) {
            return Response::create(GenerationOperationResult::failure(
                'vision_task_not_retryable', 'retry', '该商品上新任务当前没有可重试的失败节点。'
            ), 'json')->code(409);
        }
        return Response::create(GenerationOperationResult::success([
            'stage' => 'retry_accepted', 'taskCreated' => true, 'taskId' => $id,
            'statusUrl' => '/workspace/vision-tasks/' . $id,
            'message' => '失败节点已重新排队，已完成阶段不会重复执行。',
        ]), 'json')->code(202);
    }

    public function updateSku(
        int $id,
        AssetStorage $storage,
        ContentSafetyService $contentSafety,
        VisionTaskQueue $visionTasks,
        SkuProductReport $profiles
    ): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $sku = Db::name('skus')->where('id', $id)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find();
        if (!$sku) {
            return Web::redirect('/workspace/skus', 'SKU 不存在。', 'error');
        }

        $name = trim((string) $this->request->post('name', ''));
        $category = trim((string) $this->request->post('category', ''));
        $sellingPoints = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，、]+/u', (string) $this->request->post('selling_points', '')) ?: [])));
        $creativeBrief = trim((string) $this->request->post('creative_brief', ''));
        $productDescription = trim((string) $this->request->post('product_description', ''));
        $searchTerms = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，、]+/u', (string) $this->request->post('search_terms', '')) ?: [])));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160 || $category === '' || mb_strlen($category) > 100
            || mb_strlen($creativeBrief) > 1000 || mb_strlen($productDescription) > 4000) {
            return Web::redirect('/workspace/skus/' . $id . '?section=profile', '请完整填写商品名称和类目。', 'error');
        }

        try {
            $preparedSourceImage = $this->prepareUploadedSourceImage($contentSafety, $merchant, $user, 'sku_update_image');
            $contentSafety->enforceText($merchant, $user, $this->skuSafetyText([
                'name' => $name,
                'category' => $category,
                'selling_points' => json_encode($sellingPoints, JSON_UNESCAPED_UNICODE),
                'creative_brief' => $creativeBrief,
                'product_description' => $productDescription,
            ]), 'sku_update_text', $this->request->ip());
            $sourceImage = $preparedSourceImage === null ? null : $storage->storeSourceImageRecord(
                (string) $preparedSourceImage['content'],
                (string) $preparedSourceImage['mime'],
                $merchantId,
                (string) $sku['sku_code']
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/skus/' . $id . '?section=profile', $exception->getMessage(), 'error');
        }
        Db::name('skus')->where('id', $id)->update([
            'name' => $name,
            'category' => $category,
            'selling_points' => json_encode($sellingPoints, JSON_UNESCAPED_UNICODE),
            'creative_brief' => $creativeBrief !== '' ? $creativeBrief : null,
            'product_description' => $productDescription !== '' ? $productDescription : null,
            'search_terms' => json_encode(array_slice($searchTerms, 0, 10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_image_path' => $sourceImage['url'] ?? $sku['source_image_path'],
            'source_image_backup_path' => $sourceImage['backupUrl'] ?? $sku['source_image_backup_path'],
            'reference_images_json' => $sourceImage !== null ? '[]' : (string) ($sku['reference_images_json'] ?? '[]'),
            'updated_at' => Web::now(),
        ]);
        $profiles->confirmBasicFields($id, $merchantId);
        Audit::log('merchant_user', (int) $user['id'], 'sku.update', 'sku', $id, $this->request->ip(), ['source_image_updated' => $sourceImage !== null]);
        $visionTasks->resumeAfterSkuCorrection($id, $merchantId);

        return Web::redirect('/workspace/skus/' . $id . '?section=profile', '商品资料已保存。');
    }

    public function canvasPage(
        PricingService $pricing,
        PlanService $plans,
        CreativeModelService $creativeModels,
        CreativeProjectService $projects
    ): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $startNewWorkspace = (string) $this->request->get('new', '') === '1';
        $requestedProjectId = max(0, (int) $this->request->get('project_id', 0));
        $selectedId = max(0, (int) $this->request->get('asset_id', 0));
        try {
            $creativeProject = $projects->openCanvasProject(
                $merchantId,
                (int) $user['id'],
                $requestedProjectId,
                $startNewWorkspace,
                $selectedId
            );
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/canvas', $exception->getMessage(), 'error');
        }
        $assets = Db::name('assets')->alias('a')
            ->leftJoin('skus s', 's.id = a.sku_id')
            ->leftJoin('canvas_edits ce', 'ce.job_id = a.job_id')
            ->field('a.id,a.sku_id,a.asset_type,a.media_type,a.mime_type,a.width,a.height,a.file_url,a.backup_file_url,a.status,a.created_at,s.name as sku_name,s.sku_code,ce.source_asset_id as parent_asset_id')
            ->where('a.merchant_id', $merchantId)->whereIn('a.status', ['active', 'pending'])
            ->where('a.media_type', 'image')
            ->order('a.id', 'desc')->limit(200)->select()->toArray();
        $selectedAsset = null;
        foreach ($assets as $asset) {
            if ((int) $asset['id'] === $selectedId && (string) ($asset['status'] ?? '') === 'active') {
                $selectedAsset = $asset;
                break;
            }
        }
        $selectedId = (int) ($selectedAsset['id'] ?? 0);
        $quote = $plans->generationQuote($merchantId, 1, $pricing->canvasEditCredits());
        $outputPresets = canvas_output_presets();
        $projectDocument = is_array($creativeProject['document'] ?? null)
            ? $creativeProject['document']
            : $projects->defaultDocument('design');
        $workspaceGraph = is_array($projectDocument['board'] ?? null)
            ? $projectDocument['board']
            : ['nodes' => [], 'connections' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1], 'cleared' => true];
        $canvasProjects = Db::name('creative_projects')
            ->field('id,title,project_type,status,cover_asset_id,document_version,current_revision_no,updated_at')
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->limit(80)
            ->select()
            ->toArray();
        try {
            $storyboardRuntime = $projects->storyboardRuntime($merchantId, (int) $creativeProject['id']);
        } catch (\Throwable) {
            $storyboardRuntime = [
                'projectId' => (int) $creativeProject['id'],
                'scenes' => [],
                'summary' => ['total' => 0, 'draft' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'progress' => 0, 'estimatedCredits' => 0, 'actualCredits' => 0],
            ];
        }
        $videoBasePrice = $pricing->videoGenerationCreditsPerSecond();
        $videoEnabled = $creativeModels->canResolve(AiCapability::VIDEO_IMAGE_TO_VIDEO, $merchantId)
            || $creativeModels->canResolve(AiCapability::VIDEO_TEXT_TO_VIDEO, $merchantId);
        try {
            $videoChoice = $creativeModels->defaultChoice(
                AiCapability::VIDEO_IMAGE_TO_VIDEO,
                $merchantId,
                $videoBasePrice
            );
        } catch (\Throwable) {
            try {
                $videoChoice = $creativeModels->defaultChoice(
                    AiCapability::VIDEO_TEXT_TO_VIDEO,
                    $merchantId,
                    $videoBasePrice
                );
            } catch (\Throwable) {
                $videoChoice = ['id' => 0, 'name' => '暂未配置可用视频模型', 'price' => $videoBasePrice];
            }
        }
        $storyboardSku = null;
        if ((int) ($creativeProject['sku_id'] ?? 0) > 0) {
            $storyboardSku = Db::name('skus')
                ->field('id,sku_code,name,category')
                ->where('id', (int) $creativeProject['sku_id'])
                ->where('merchant_id', $merchantId)
                ->where('status', '<>', 'deleted')
                ->find();
        }

        $requestedTool = (string) $this->request->get('tool', '');
        if (!in_array($requestedTool, ['cutout', 'portrait_clear', 'head_cutout', 'erase', 'watermark_remove', 'translate', 'colorize'], true)) {
            $requestedTool = '';
        }

        return $this->render('merchant/canvas', compact('merchant', 'user', 'assets', 'selectedAsset', 'selectedId', 'quote', 'outputPresets', 'workspaceGraph', 'projectDocument', 'creativeProject', 'canvasProjects', 'requestedTool', 'storyboardRuntime', 'storyboardSku') + [
            'pageTitle' => '创作画布',
            'workspaceProjectTitle' => (string) $creativeProject['title'],
            'workspaceSaveState' => '版本 ' . (int) $creativeProject['current_revision_no'] . ' · 已自动保存',
            'storyboardVideoEnabled' => $videoEnabled,
            'storyboardVideoUnitPrice' => max(0, (int) ($videoChoice['price'] ?? $videoBasePrice)),
            'storyboardVideoModelName' => (string) ($videoChoice['name'] ?? '自动选择可用视频模型'),
            'storyboardPlannerCredits' => $plans->recognitionCredits($merchantId, $pricing->promptOptimizationCredits()),
        ]);
    }

    public function createCanvasAsset(
        AssetStorage $storage,
        UploadedImageNormalizer $normalizer,
        ContentSafetyService $contentSafety,
        CreativeProjectService $projects
    ): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $sourceType = (string) $this->request->post('source_type', 'upload');
        $projectId = max(0, (int) $this->request->post('project_id', 0));
        $designId = trim((string) $this->request->post('design_id', ''));
        $nodeUuid = trim((string) $this->request->post('node_uuid', ''));
        if (!in_array($sourceType, ['upload', 'design'], true)) {
            return Response::create(['ok' => false, 'message' => '画布素材来源无效。'], 'json')->code(422);
        }
        if ($projectId > 0 && !$projects->findOwnedProject($merchantId, $projectId)) {
            return Response::create(['ok' => false, 'message' => '创作项目不存在或无权访问。'], 'json')->code(404);
        }

        $stored = null;
        try {
            $files = $this->request->file();
            $file = is_array($files) ? ($files['image'] ?? null) : null;
            if ($sourceType === 'upload' || is_object($file)) {
                if (!is_object($file)) {
                    throw new \RuntimeException('请选择一张本地图片。');
                }
                $image = $normalizer->normalize($file);
            } else {
                $payload = (string) $this->request->post('image_data', '');
                if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $payload, $matches)) {
                    throw new \RuntimeException('空白画板内容无法识别，请重新保存。');
                }
                $content = base64_decode(str_replace(["\r", "\n"], '', $matches[1]), true);
                if (!is_string($content) || $content === '' || strlen($content) > 24 * 1024 * 1024) {
                    throw new \RuntimeException('画板图片为空或超过 24MB。');
                }
                $dimensions = getimagesizefromstring($content);
                if (!$dimensions || (int) $dimensions[0] <= 0 || (int) $dimensions[1] <= 0
                    || (int) $dimensions[0] > 8192 || (int) $dimensions[1] > 8192
                    || (int) $dimensions[0] * (int) $dimensions[1] > 32000000) {
                    throw new \RuntimeException('画板尺寸无效或超过保存上限。');
                }
                $image = ['content' => $content, 'mime' => 'image/png', 'filename' => 'canvas-design.png'];
            }

            $safetyEnabled = (bool) ($contentSafety->configuration()['enabled'] ?? false);
            $jobNo = 'CANVAS-' . strtoupper($sourceType) . '-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $assetType = $sourceType === 'upload' ? 'canvas_upload' : 'canvas_design';
            $stored = $storage->storeSourceImageRecord(
                (string) $image['content'],
                (string) $image['mime'],
                $merchantId,
                strtolower($jobNo)
            );
            $now = Web::now();
            $created = Db::transaction(function () use ($merchant, $user, $jobNo, $assetType, $stored, $now, $safetyEnabled): array {
                $jobId = (int) Db::name('generation_jobs')->insertGetId([
                    'merchant_id' => (int) $merchant['id'],
                    'sku_id' => null,
                    'job_no' => $jobNo,
                    'platform' => 'canvas',
                    'language' => 'zh-CN',
                    'asset_types' => json_encode([$assetType], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'submitted_by_user_id' => (int) $user['id'],
                    'submitted_ip' => $this->request->ip(),
                    'safety_status' => $safetyEnabled ? 'pending' : 'passed',
                    'safety_attempts' => 0,
                    'safety_checked_at' => $safetyEnabled ? null : $now,
                    'status' => $safetyEnabled ? 'queued' : 'completed',
                    'estimated_credits' => 0,
                    'actual_credits' => 0,
                    'progress_current' => $safetyEnabled ? 0 : 1,
                    'progress_total' => 1,
                    'progress_stage' => $safetyEnabled ? 'safety_pending' : 'completed',
                    'progress_message' => $safetyEnabled ? '画布素材已保存，正在后台进行内容安全检查' : '画布素材已保存到素材库',
                    'model' => null,
                    'error_message' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $assetId = (int) Db::name('assets')->insertGetId([
                    'merchant_id' => (int) $merchant['id'],
                    'sku_id' => null,
                    'job_id' => $jobId,
                    'generation_item_id' => null,
                    'asset_type' => $assetType,
                    'file_url' => (string) $stored['url'],
                    'backup_file_url' => trim((string) ($stored['backupUrl'] ?? '')) ?: null,
                    'score' => null,
                    'is_favorite' => 0,
                    'status' => $safetyEnabled ? 'pending' : 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return ['jobId' => $jobId, 'assetId' => $assetId];
            });
            Audit::log('merchant_user', (int) $user['id'], 'canvas_asset.create', 'asset', (int) $created['assetId'], $this->request->ip(), [
                'source_type' => $sourceType,
                'job_id' => (int) $created['jobId'],
                'project_id' => $projectId ?: null,
                'design_id' => $designId !== '' ? $designId : null,
            ]);
            if ($projectId > 0) {
                $projects->attachAsset(
                    $merchantId,
                    $projectId,
                    (int) $created['assetId'],
                    $nodeUuid !== '' ? $nodeUuid : ($sourceType === 'design' && $designId !== '' ? $designId . '-output' : 'asset-' . (int) $created['assetId']),
                    $sourceType === 'design' ? 'output' : 'source'
                );
            }

            return Response::create([
                'ok' => true,
                'message' => $safetyEnabled
                    ? '画布内容已保存，正在后台进行安全检查；通过后会自动归档到素材库。'
                    : ($sourceType === 'upload' ? '本地图片已加入画布并保存到素材库。' : '画板已保存为未归类画布素材。'),
                'pendingReview' => $safetyEnabled,
                'jobNo' => $jobNo,
                'jobId' => (int) $created['jobId'],
                'statusUrl' => '/workspace/canvas/jobs/' . (int) $created['jobId'],
                'projectId' => $projectId,
                'designId' => $designId,
                'asset' => [
                    'id' => (int) $created['assetId'],
                    'skuId' => 0,
                    'skuName' => '',
                    'skuCode' => '',
                    'assetType' => $assetType,
                    'assetTypeLabel' => asset_type_label($assetType),
                    'fileUrl' => (string) $stored['url'],
                    'backupFileUrl' => (string) ($stored['backupUrl'] ?? ''),
                    'createdAt' => $now,
                    'parentAssetId' => 0,
                    'status' => $safetyEnabled ? 'pending' : 'active',
                ],
            ], 'json')->code($safetyEnabled ? 202 : 201);
        } catch (\Throwable $exception) {
            if (is_array($stored)) {
                foreach (array_unique(array_filter([(string) ($stored['url'] ?? ''), (string) ($stored['backupUrl'] ?? '')])) as $url) {
                    try {
                        $storage->deleteStoredUrl($url);
                    } catch (\Throwable) {
                    }
                }
            }
            return Response::create(['ok' => false, 'message' => localized_job_error($exception->getMessage())], 'json')->code(422);
        }
    }

    public function toolsPage(PricingService $pricing, PlanService $plans, NewApiClient $newApi): Response
    {
        [$merchant, $user] = $this->identity();
        $assets = Db::name('assets')->alias('a')->leftJoin('skus s', 's.id = a.sku_id')
            ->field('a.id,a.file_url,a.backup_file_url,a.asset_type,a.created_at,s.name as sku_name,s.sku_code')
            ->where('a.merchant_id', $merchant['id'])->where('a.status', 'active')
            ->order('a.id', 'desc')->limit(200)->select()->toArray();
        $textResults = Db::name('image_tool_results')->alias('r')
            ->leftJoin('assets a', 'a.id = r.asset_id')
            ->leftJoin('skus s', 's.id = a.sku_id')
            ->field('r.id,r.asset_id,r.content,r.model,r.created_at,a.asset_type,s.name as sku_name,s.sku_code')
            ->where('r.merchant_id', (int) $merchant['id'])
            ->where('r.result_type', 'reverse_prompt')
            ->order('r.id', 'desc')->limit(12)->select()->toArray();

        return $this->render('merchant/tools', compact('merchant', 'user', 'assets', 'textResults') + [
            'pageTitle' => '图片工具',
            'promptCredits' => $plans->recognitionCredits((int) $merchant['id'], $pricing->promptOptimizationCredits()),
            'visionReady' => $newApi->visionModel((int) $merchant['id']) !== '',
        ]);
    }

    public function reverseImagePrompt(NewApiClient $newApi, PricingService $pricing, PlanService $plans, ContentSafetyService $contentSafety, AssetStorage $storage): Response
    {
        [$merchant, $user] = $this->identity();
        $assetId = (int) $this->request->post('asset_id', 0);
        $asset = $assetId > 0
            ? Db::name('assets')->where('id', $assetId)->where('merchant_id', $merchant['id'])->where('status', 'active')->find()
            : null;
        $files = $this->request->file();
        $uploaded = is_array($files) ? ($files['source_image'] ?? null) : null;
        if (!$asset && !$uploaded) {
            return Response::create(GenerationOperationResult::failure(
                'image_required', 'validation', '请选择当前参考图或素材库中的图片。'
            ), 'json')->code(422);
        }

        $model = $newApi->visionModel((int) $merchant['id']);
        $pricingSnapshot = $pricing->priceForModel(AiCapability::PRODUCT_VISION, $model, $pricing->promptOptimizationCredits());
        $credits = $plans->recognitionCredits((int) $merchant['id'], (int) $pricingSnapshot['credits']);
        $reference = 'REVERSE-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $charged = false;
        $ledgerId = null;
        $balance = (int) $merchant['credit_balance'];
        try {
            $image = $asset
                ? $storage->readImageWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''))
                : $this->normalizedUploadedProductImage($uploaded);
            $contentSafety->enforceImage($merchant, $user, $image, 'reverse_image_prompt', $this->request->ip());
            if ($credits > 0) {
                [$ledgerId, $balance] = $this->reservePromptCredits((int) $merchant['id'], $credits, $reference);
                $charged = true;
            }
            $result = $newApi->reverseImagePrompt($image, (int) $merchant['id']);
            if (!$result['ok']) {
                $refundStatus = 'not_applicable';
                if ($charged) {
                    $balance = $this->refundPromptCredits((int) $merchant['id'], $credits, $reference);
                    $refundStatus = 'refunded';
                }
                return Response::create(GenerationOperationResult::failure(
                    'provider_failed', 'prompt_extraction',
                    localized_job_error((string) $result['message']) . ($refundStatus === 'refunded' ? '，预留点数已退回。' : ''),
                    false, $charged, $refundStatus, true
                ), 'json')->code(422);
            }
            if ($ledgerId !== null && !empty($result['requestId'])) {
                Db::name('credit_ledger')->where('id', $ledgerId)->update(['new_api_request_id' => (string) $result['requestId']]);
            }
            $prompt = mb_strimwidth(trim((string) $result['prompt']), 0, 4000, '');
            $createdAt = Web::now();
            $resultId = 0;
            if ($asset) {
                $resultId = (int) Db::name('image_tool_results')->insertGetId([
                    'merchant_id' => (int) $merchant['id'],
                    'user_id' => (int) $user['id'],
                    'asset_id' => $assetId,
                    'result_type' => 'reverse_prompt',
                    'content' => $prompt,
                    'model' => (string) ($result['model'] ?? $newApi->visionModel((int) $merchant['id'])),
                    'request_id' => $result['requestId'] ?? null,
                    'created_at' => $createdAt,
                ]);
            }
            Audit::log('merchant_user', (int) $user['id'], 'image_tools.reverse_prompt', 'image_tool_result', $resultId, $this->request->ip(), [
                'asset_id' => $assetId,
                'credits' => $credits,
                'model' => (string) ($result['model'] ?? $newApi->visionModel((int) $merchant['id'])),
            ]);
            return Response::create(GenerationOperationResult::success([
                'stage' => 'completed',
                'message' => '已根据图片整理出可编辑的生成提示词。',
                'prompt' => $prompt,
                'resultId' => $resultId,
                'assetId' => $assetId,
                'createdAt' => $createdAt,
                'balance' => $balance,
                'useUrl' => '/workspace/free-create?prompt_result_id=' . $resultId,
                'pricingSnapshot' => $pricingSnapshot,
            ]), 'json');
        } catch (\Throwable $exception) {
            $refundStatus = 'not_applicable';
            if ($charged) {
                try {
                    $this->refundPromptCredits((int) $merchant['id'], $credits, $reference);
                    $refundStatus = 'refunded';
                } catch (\Throwable) {
                    $refundStatus = 'refund_failed';
                }
            }
            $message = localized_job_error($exception->getMessage());
            if ($refundStatus === 'refunded') {
                $message .= '，预留点数已退回。';
            } elseif ($refundStatus === 'refund_failed') {
                $message .= '，退款状态待管理员核对。';
            }
            return Response::create(GenerationOperationResult::failure(
                'prompt_extraction_failed', $charged ? 'provider' : 'validation', $message,
                false, $charged, $refundStatus, $charged
            ), 'json')->code(422);
        }
    }

    public function csrfToken(): Response
    {
        return Response::create([
            'ok' => true,
            'token' => Web::csrfToken(),
        ], 'json')->header(['Cache-Control' => 'no-store']);
    }

    public function saveCanvasWorkspace(CreativeProjectService $projects): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $projectId = max(0, (int) $this->request->post('project_id', 0));
        $expectedVersion = max(0, (int) $this->request->post('document_version', 0));
        $rawDocument = trim((string) $this->request->post('document_json', ''));
        $rawGraph = trim((string) $this->request->post('graph_json', ''));
        try {
            if ($projectId > 0) {
                $project = $projects->findOwnedProject($merchantId, $projectId);
                if (!$project) {
                    throw new \RuntimeException('创作项目不存在或无权访问。');
                }
                $document = json_decode((string) $project['document_json'], true);
                if (!is_array($document)) {
                    $document = $projects->defaultDocument('design');
                }
            } else {
                $project = $projects->openCanvasProject($merchantId, (int) $user['id']);
                $projectId = (int) $project['id'];
                $expectedVersion = (int) $project['document_version'];
                $document = is_array($project['document'] ?? null) ? $project['document'] : $projects->defaultDocument('design');
            }

            if ($rawDocument !== '') {
                if (strlen($rawDocument) > 2_000_000) {
                    throw new \RuntimeException('项目内容超过 2MB 保存上限。');
                }
                $decoded = json_decode($rawDocument, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('项目内容无法识别，请刷新后重试。');
                }
                $document = $decoded;
            } elseif ($rawGraph !== '') {
                if (strlen($rawGraph) > 500000) {
                    throw new \RuntimeException('画布数据超过保存上限。');
                }
                $graph = json_decode($rawGraph, true);
                if (!is_array($graph)) {
                    throw new \RuntimeException('画布数据无法识别。');
                }
                $legacyDocument = $projects->documentFromLegacyGraph($graph);
                $document['board'] = $legacyDocument['board'];
                $document['activeView'] = 'board';
            } else {
                throw new \RuntimeException('没有可保存的画布内容。');
            }

            $saved = $projects->saveDocument(
                $merchantId,
                (int) $user['id'],
                $projectId,
                $expectedVersion,
                $document,
                (string) $this->request->post('save_source', 'auto')
            );
            Audit::log('merchant_user', (int) $user['id'], 'creative_project.save', 'creative_project', $projectId, $this->request->ip(), [
                'document_version' => (int) $saved['project']['document_version'],
                'revision_no' => (int) $saved['project']['current_revision_no'],
                'unchanged' => (bool) $saved['unchanged'],
            ]);
            return Response::create([
                'ok' => true,
                'message' => $saved['unchanged'] ? '项目已是最新版本' : '项目已保存',
                'projectId' => $projectId,
                'documentVersion' => (int) $saved['project']['document_version'],
                'revisionNo' => (int) $saved['project']['current_revision_no'],
                'updatedAt' => (string) $saved['project']['updated_at'],
                'unchanged' => (bool) $saved['unchanged'],
            ], 'json');
        } catch (\Throwable $exception) {
            $status = $exception->getCode() === 409 ? 409 : 422;
            return Response::create(['ok' => false, 'message' => $exception->getMessage()], 'json')->code($status);
        }
    }

    public function planCanvasStoryboard(
        NewApiClient $newApi,
        PricingService $pricing,
        PlanService $plans,
        ContentSafetyService $contentSafety,
        CreativeProjectService $projects
    ): Response {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $projectId = max(0, (int) $this->request->post('project_id', 0));
        $brief = mb_strimwidth(trim((string) $this->request->post('brief', '')), 0, 1200, '');
        $sceneCount = max(2, min(10, (int) $this->request->post('scene_count', 5)));
        $project = $projects->findOwnedProject($merchantId, $projectId);
        if (!$project) {
            return Response::create(['ok' => false, 'message' => '创作项目不存在或无权访问。'], 'json')->code(404);
        }
        $document = json_decode((string) $project['document_json'], true);
        $storyboard = is_array($document['storyboard'] ?? null) ? $document['storyboard'] : [];
        if ($brief === '') {
            $brief = mb_strimwidth(trim((string) ($storyboard['brief'] ?? '')), 0, 1200, '');
        }
        if (mb_strlen($brief) < 4) {
            return Response::create(['ok' => false, 'message' => '请先写至少 4 个字的视频目标，例如“突出商品质感并展示使用场景”。'], 'json')->code(422);
        }
        $platform = (string) ($storyboard['platform'] ?? 'douyin');
        $language = (string) ($storyboard['language'] ?? 'zh-CN');
        $ratio = (string) ($storyboard['ratio'] ?? '9:16');
        if (!array_key_exists($platform, generation_platform_options())
            || !array_key_exists($language, generation_language_options())
            || !in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4'], true)) {
            return Response::create(['ok' => false, 'message' => '故事板的平台、语言或比例无效，请刷新后重试。'], 'json')->code(422);
        }
        $sku = null;
        if ((int) ($project['sku_id'] ?? 0) > 0) {
            $sku = Db::name('skus')
                ->where('id', (int) $project['sku_id'])
                ->where('merchant_id', $merchantId)
                ->where('status', '<>', 'deleted')
                ->find();
        }

        $model = $newApi->contentPlannerModel($merchantId);
        if ($model === '') {
            return Response::create(['ok' => false, 'message' => '管理员尚未配置可用的视频分镜策划模型。'], 'json')->code(503);
        }
        $pricingSnapshot = $pricing->priceForModel(
            AiCapability::CONTENT_PLANNER,
            $model,
            $pricing->promptOptimizationCredits()
        );
        $credits = $plans->recognitionCredits($merchantId, (int) $pricingSnapshot['credits']);
        $reference = 'STORYBOARD-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $charged = false;
        $ledgerId = null;
        $balance = (int) $merchant['credit_balance'];
        try {
            $contentSafety->enforceText($merchant, $user, $brief, 'video_storyboard_plan', $this->request->ip());
            if ($credits > 0) {
                [$ledgerId, $balance] = $this->reservePromptCredits(
                    $merchantId,
                    $credits,
                    $reference,
                    'AI 视频分镜策划'
                );
                $charged = true;
            }
            $result = $newApi->planVideoStoryboard(
                (string) $project['title'],
                $sku ?: null,
                $brief,
                $platform,
                $language,
                $ratio,
                $sceneCount,
                $merchantId
            );
            if (!$result['ok']) {
                if ($charged) {
                    $balance = $this->refundPromptCredits($merchantId, $credits, $reference, 'AI 视频分镜策划');
                    $charged = false;
                }
                return Response::create([
                    'ok' => false,
                    'message' => (string) $result['message'] . ($credits > 0 ? '，已退回预扣点数。' : ''),
                    'balance' => $balance,
                ], 'json')->code(422);
            }
            $requestId = trim((string) ($result['requestId'] ?? ''));
            if ($ledgerId !== null && $requestId !== '') {
                Db::name('credit_ledger')->where('id', $ledgerId)->update(['new_api_request_id' => $requestId]);
            }
            Audit::log('merchant_user', (int) $user['id'], 'video_storyboard.planned', 'creative_project', $projectId, $this->request->ip(), [
                'scene_count' => count((array) ($result['scenes'] ?? [])),
                'credits' => $credits,
                'model' => (string) ($result['model'] ?? $model),
                'request_id' => $requestId,
            ]);
            return Response::create([
                'ok' => true,
                'message' => (string) $result['message'],
                'scenes' => array_values((array) $result['scenes']),
                'creditsCharged' => $credits,
                'balance' => $balance,
                'pricingSnapshot' => $pricingSnapshot,
            ], 'json');
        } catch (\Throwable $exception) {
            if ($charged) {
                try {
                    $this->refundPromptCredits($merchantId, $credits, $reference, 'AI 视频分镜策划');
                } catch (\Throwable) {
                }
            }
            return Response::create(['ok' => false, 'message' => localized_job_error($exception->getMessage())], 'json')->code(422);
        }
    }

    public function createCanvasStoryboardScene(
        PromptTemplateService $prompts,
        SkuProductReport $profiles,
        VideoJobService $videoJobs,
        CreativeProjectService $projects,
        ProductCreativeReadinessService $readiness
    ): Response {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $projectId = max(0, (int) $this->request->post('project_id', 0));
        $sceneId = trim((string) $this->request->post('scene_id', ''));
        $project = $projects->findOwnedProject($merchantId, $projectId);
        if (!$project || $sceneId === '') {
            return Response::create(['ok' => false, 'message' => '创作项目或视频镜头不存在。'], 'json')->code(404);
        }
        $document = json_decode((string) $project['document_json'], true);
        $storyboard = is_array($document['storyboard'] ?? null) ? $document['storyboard'] : [];
        $scenes = is_array($storyboard['scenes'] ?? null) ? array_values($storyboard['scenes']) : [];
        $scene = null;
        $sceneIndex = 0;
        foreach ($scenes as $index => $candidate) {
            if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $sceneId) {
                $scene = $candidate;
                $sceneIndex = $index + 1;
                break;
            }
        }
        if (!$scene) {
            return Response::create(['ok' => false, 'message' => '当前镜头不在该创作项目中，请刷新后重试。'], 'json')->code(404);
        }
        if (mb_strlen(trim((string) ($scene['prompt'] ?? ''))) < 4) {
            return Response::create(['ok' => false, 'message' => '请先为当前镜头填写至少 4 个字的画面与动作要求。'], 'json')->code(422);
        }

        $platform = (string) ($storyboard['platform'] ?? 'douyin');
        $language = (string) ($storyboard['language'] ?? 'zh-CN');
        $ratio = (string) ($storyboard['ratio'] ?? '9:16');
        $resolution = (string) ($storyboard['resolution'] ?? '720p');
        if (!array_key_exists($platform, generation_platform_options())
            || !array_key_exists($language, generation_language_options())
            || !in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4'], true)
            || !in_array($resolution, ['720p', '1080p'], true)) {
            return Response::create(['ok' => false, 'message' => '故事板生成参数无效，请刷新后重新选择。'], 'json')->code(422);
        }

        $sourceAsset = null;
        $referenceUrl = '';
        $referenceBackupUrl = '';
        $sourceType = (string) ($scene['sourceType'] ?? 'asset');
        $sourceAssetId = max(0, (int) ($scene['sourceAssetId'] ?? 0));
        $previousJobId = 0;
        if ($sourceType === 'asset' && $sourceAssetId > 0) {
            $sourceAsset = Db::name('assets')
                ->where('id', $sourceAssetId)
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->where('media_type', 'image')
                ->find();
            if (!$sourceAsset) {
                return Response::create(['ok' => false, 'message' => '当前镜头选择的首帧图片不存在或已删除。'], 'json')->code(422);
            }
            $referenceUrl = trim((string) ($sourceAsset['file_url'] ?? ''));
            $referenceBackupUrl = trim((string) ($sourceAsset['backup_file_url'] ?? ''));
        } elseif ($sourceType === 'previous_tail') {
            $previousSceneId = trim((string) ($scene['previousSceneId'] ?? ''));
            $previousRun = Db::name('creative_project_runs')->alias('r')
                ->join('generation_jobs j', 'j.id=r.job_id')
                ->join('video_generation_requests v', 'v.job_id=j.id')
                ->field('r.job_id,v.last_frame_url')
                ->where('r.project_id', $projectId)
                ->where('r.node_uuid', $previousSceneId)
                ->where('r.run_type', 'video_scene')
                ->where('j.status', 'completed')
                ->order('r.id', 'desc')
                ->find();
            $previousJobId = (int) ($previousRun['job_id'] ?? 0);
            $referenceUrl = trim((string) ($previousRun['last_frame_url'] ?? ''));
            if ($previousSceneId === '' || $previousJobId <= 0 || $referenceUrl === '') {
                return Response::create([
                    'ok' => false,
                    'message' => '上一镜头还没有可用尾帧。请先完成上一镜头，或改用素材图片作为当前首帧。',
                ], 'json')->code(422);
            }
            $sourceAssetId = 0;
        } else {
            $sourceAssetId = 0;
        }

        $sku = null;
        $foundation = [];
        $skuId = max(0, (int) ($project['sku_id'] ?? 0));
        if ($skuId > 0) {
            $sku = Db::name('skus')
                ->where('id', $skuId)
                ->where('merchant_id', $merchantId)
                ->where('status', '<>', 'deleted')
                ->find();
            if ($sku) {
                $creativeReadiness = $readiness->forSku($skuId, $merchantId);
                if (empty($creativeReadiness['ready'])) {
                    return $this->creativeReadinessFailure(
                        $readiness,
                        $creativeReadiness,
                        '/workspace/creative-projects/' . $projectId
                    );
                }
                $foundation = $profiles->reportContext($sku);
            } else {
                $skuId = 0;
            }
        }
        $scene['index'] = $sceneIndex;
        $hasReference = $referenceUrl !== '' || $referenceBackupUrl !== '';
        $prompt = $prompts->buildStoryboardScenePrompt(
            (string) $project['title'],
            $sku ?: null,
            $scene,
            $platform,
            $ratio,
            $resolution,
            $hasReference
        );
        try {
            $queued = $videoJobs->queue($merchant, $user, [
                'sku_id' => $skuId,
                'source_asset_id' => $sourceAssetId,
                'reference_image_url' => $referenceUrl !== '' ? $referenceUrl : $referenceBackupUrl,
                'reference_image_backup_url' => $referenceBackupUrl,
                'platform' => $platform,
                'language' => $language,
                'motion_preset' => (string) ($scene['motionPreset'] ?? 'product_showcase'),
                'ratio' => $ratio,
                'resolution' => $resolution,
                'duration_seconds' => (int) ($scene['durationSeconds'] ?? 5),
                'generate_audio' => !empty($storyboard['generateAudio']),
                'creative_brief' => (string) ($scene['prompt'] ?? ''),
                'prompt' => $prompt,
                'capability' => $hasReference ? AiCapability::VIDEO_IMAGE_TO_VIDEO : AiCapability::VIDEO_TEXT_TO_VIDEO,
                'requested_model_id' => 0,
                'product_report' => $foundation,
                'submitted_ip' => $this->request->ip(),
                'asset_type' => 'product_video',
                'context' => [
                    'mode' => 'storyboard_scene',
                    'creative_project_id' => $projectId,
                    'scene_id' => $sceneId,
                    'scene_index' => $sceneIndex,
                    'scene_title' => (string) ($scene['title'] ?? ''),
                    'source_type' => $sourceType,
                    'previous_scene_job_id' => $previousJobId ?: null,
                    'transition' => (string) ($scene['transition'] ?? 'cut'),
                    'subtitle' => (string) ($scene['subtitle'] ?? ''),
                ],
            ]);
            $jobId = (int) $queued['jobId'];
            $projects->registerRun($merchantId, $projectId, $jobId, 'video_scene', $sceneId, [
                'scene' => $scene,
                'storyboard' => [
                    'platform' => $platform,
                    'language' => $language,
                    'ratio' => $ratio,
                    'resolution' => $resolution,
                    'generate_audio' => !empty($storyboard['generateAudio']),
                ],
                'source_asset_id' => $sourceAssetId ?: null,
                'previous_scene_job_id' => $previousJobId ?: null,
                'model' => (string) $queued['model'],
                'pricing' => $queued['pricingSnapshot'],
                'workflow' => $queued['workflowSnapshot'],
            ]);
            Audit::log('merchant_user', (int) $user['id'], 'video_storyboard.scene_queued', 'generation_job', $jobId, $this->request->ip(), [
                'creative_project_id' => $projectId,
                'scene_id' => $sceneId,
                'scene_index' => $sceneIndex,
                'source_type' => $sourceType,
                'estimated_credits' => (int) $queued['estimatedCredits'],
            ]);
            return Response::create([
                'ok' => true,
                'message' => !empty($queued['gatewayReady'])
                    ? '当前镜头已进入后台队列，可以继续编辑或提交其他镜头。'
                    : '镜头任务已保存，待管理员配置可用视频模型后可重试。',
                'jobId' => $jobId,
                'sceneId' => $sceneId,
                'status' => (string) $queued['status'],
                'estimatedCredits' => (int) $queued['estimatedCredits'],
                'durationSeconds' => (int) $queued['durationSeconds'],
                'statusUrl' => '/workspace/canvas/storyboard/status/' . $projectId,
            ], 'json')->code(202);
        } catch (\Throwable $exception) {
            return Response::create(['ok' => false, 'message' => localized_job_error($exception->getMessage())], 'json')->code(422);
        }
    }

    public function canvasStoryboardStatus(int $id, CreativeProjectService $projects): Response
    {
        [$merchant] = $this->identity();
        try {
            $runtime = $projects->storyboardRuntime((int) $merchant['id'], $id);
            return Response::create(['ok' => true] + $runtime, 'json')->header(['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            return Response::create(['ok' => false, 'message' => $exception->getMessage()], 'json')->code(404);
        }
    }

    public function canvasEdit(
        NewApiClient $newApi,
        PricingService $pricing,
        PlanService $plans,
        ContentSafetyService $contentSafety,
        AssetStorage $storage,
        CreativeProjectService $projects,
        CreativeModelService $creativeModels
    ): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $assetId = (int) $this->request->post('asset_id', 0);
        $projectId = max(0, (int) $this->request->post('project_id', 0));
        $sourceNodeUuid = trim((string) $this->request->post('source_node_uuid', ''));
        $instruction = mb_strimwidth(trim((string) $this->request->post('instruction', '')), 0, 1200, '');
        $operation = (string) $this->request->post('operation', 'ai_edit');
        $mode = (string) $this->request->post('mode', 'global');
        $preset = (string) $this->request->post('preset', 'custom');
        $outputPreset = (string) $this->request->post('output_preset', 'square_1_1');
        $maskPayload = (string) $this->request->post('mask_image', '');
        $operations = ['resize_only', 'ai_edit'];
        $modes = ['global', 'regional'];
        $presets = ['custom', 'background', 'scene', 'cleanup', 'composition', 'style', 'lighting', 'cutout', 'enhance'];
        $outputPresets = canvas_output_presets();
        if (!in_array($operation, $operations, true) || !isset($outputPresets[$outputPreset])
            || ($operation === 'ai_edit' && (!in_array($mode, $modes, true) || !in_array($preset, $presets, true)))) {
            return Response::create(['ok' => false, 'message' => '画布编辑模式或快捷方案无效，请刷新页面后重试。'], 'json')->code(422);
        }
        if ($assetId <= 0 || ($operation === 'ai_edit' && $preset === 'custom' && mb_strlen($instruction) < 4)) {
            return Response::create(['ok' => false, 'message' => '请选择素材并填写至少 4 个字的修改要求，或选择一个快捷方案。'], 'json')->code(422);
        }
        if ($projectId > 0 && !$projects->findOwnedProject($merchantId, $projectId)) {
            return Response::create(['ok' => false, 'message' => '创作项目不存在或无权访问。'], 'json')->code(404);
        }
        if ($operation === 'resize_only') {
            $mode = 'global';
            $preset = 'resize_only';
            $instruction = '';
        }
        $asset = Db::name('assets')->where('id', $assetId)->where('merchant_id', $merchantId)->where('status', 'active')->find();
        if (!$asset) {
            return Response::create(['ok' => false, 'message' => '要编辑的素材不存在或无权访问。'], 'json')->code(404);
        }
        $maskFile = null;
        $modelSelection = null;
        $model = null;
        $pricingSnapshot = null;
        if ($operation === 'ai_edit') {
            try {
                $modelSelection = $creativeModels->resolveTask(
                    AiCapability::IMAGE_EDIT,
                    null,
                    $merchantId,
                    $pricing->canvasEditCredits(),
                    'image_edit'
                );
            } catch (\Throwable $exception) {
                return Response::create([
                    'ok' => false,
                    'message' => localized_job_error($exception->getMessage()),
                ], 'json')->code(503);
            }
            $model = (string) $modelSelection['model'];
            $pricingSnapshot = $modelSelection['price'];
            if (!$newApi->configuration()['configured'] || !$newApi->hasAvailableModels(
                $model,
                $modelSelection['resolvedModelId'],
                (string) ($modelSelection['route']['resolvedGateway'] ?? ''),
                AiCapability::IMAGE_EDIT
            )) {
                return Response::create([
                    'ok' => false,
                    'message' => '当前图片编辑模型渠道不可用，请稍后重试或联系管理员。',
                ], 'json')->code(503);
            }
        }
        try {
            $image = $storage->readImageWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''));
            $mask = $operation === 'ai_edit' && $mode === 'regional' ? $this->canvasMask($maskPayload, $image) : null;
            $credits = 0;
            if ($operation === 'ai_edit') {
                $quote = $plans->generationQuote($merchantId, 1, (int) $pricingSnapshot['credits']);
                if (!$quote['allowed']) {
                    throw new \RuntimeException((string) $quote['message']);
                }
                $credits = (int) $quote['credits'];
                $available = $this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']);
                if ($available < $credits) {
                    throw new \RuntimeException("可用点数不足，本次画布编辑需要 {$credits} 点。本次未创建任务，也不会扣点。");
                }
            }

            $jobNo = 'CANVAS-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $outputWidth = (int) $outputPresets[$outputPreset]['width'];
            $outputHeight = (int) $outputPresets[$outputPreset]['height'];
            $resizeMode = $operation === 'resize_only' ? 'crop' : 'extend';
            if ($mask !== null) {
                $maskFile = $this->storeCanvasMask($jobNo, $mask);
            }
            $now = Web::now();
            $submittedIp = $this->request->ip();
            $jobId = Db::transaction(function () use ($merchantId, $user, $submittedIp, $asset, $credits, $jobNo, $operation, $mode, $preset, $instruction, $maskFile, $outputPreset, $outputWidth, $outputHeight, $resizeMode, $model, $modelSelection, $pricingSnapshot, $now): int {
                $jobId = (int) Db::name('generation_jobs')->insertGetId([
                    'merchant_id' => $merchantId,
                    'sku_id' => $asset['sku_id'] ?: null,
                    'job_no' => $jobNo,
                    'platform' => 'canvas',
                    'asset_types' => json_encode(['canvas_edit']),
                    'submitted_by_user_id' => (int) $user['id'],
                    'submitted_ip' => $submittedIp,
                    'requested_model_id' => $modelSelection['requestedModelId'] ?? null,
                    'resolved_ai_model_id' => $modelSelection['resolvedModelId'] ?? null,
                    'route_snapshot_json' => $modelSelection === null ? null : json_encode(
                        $modelSelection['route'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'pricing_snapshot_json' => $pricingSnapshot === null ? null : json_encode($pricingSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'safety_status' => $operation === 'resize_only' ? 'passed' : 'pending',
                    'safety_attempts' => 0,
                    'safety_checked_at' => $operation === 'resize_only' ? $now : null,
                    'status' => 'queued',
                    'estimated_credits' => $credits,
                    'actual_credits' => 0,
                    'progress_current' => 0,
                    'progress_total' => 1,
                    'progress_stage' => $operation === 'resize_only' ? 'queued' : 'safety_pending',
                    'progress_message' => $operation === 'resize_only' ? '尺寸调整已排队，等待后台处理' : 'AI 编辑已提交，等待后台安全检查',
                    'model' => $model,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::name('canvas_edits')->insert([
                    'job_id' => $jobId,
                    'source_asset_id' => $asset['id'],
                    'mode' => $mode,
                    'preset' => $preset,
                    'instruction' => $instruction,
                    'mask_file' => $maskFile,
                    'output_preset' => $outputPreset,
                    'output_width' => $outputWidth,
                    'output_height' => $outputHeight,
                    'resize_mode' => $resizeMode,
                    'operation' => $operation,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return $jobId;
            });
            Audit::log('merchant_user', (int) $user['id'], 'canvas_edit.queued', 'generation_job', $jobId, $this->request->ip(), [
                'source_asset_id' => $assetId,
                'project_id' => $projectId ?: null,
                'source_node_uuid' => $sourceNodeUuid !== '' ? $sourceNodeUuid : null,
                'operation' => $operation,
                'mode' => $mode,
                'preset' => $preset,
                'output_preset' => $outputPreset,
                'output_size' => $outputWidth . 'x' . $outputHeight,
                'resize_mode' => $resizeMode,
            ]);
            if ($projectId > 0) {
                $projects->registerRun($merchantId, $projectId, $jobId, 'image_edit', $sourceNodeUuid !== '' ? $sourceNodeUuid : null, [
                    'source_asset_id' => $assetId,
                    'operation' => $operation,
                    'mode' => $mode,
                    'preset' => $preset,
                    'instruction' => $instruction,
                    'output_preset' => $outputPreset,
                    'output_width' => $outputWidth,
                    'output_height' => $outputHeight,
                    'model' => $model,
                    'pricing' => $pricingSnapshot,
                ]);
            }

            return Response::create([
                'ok' => true,
                'message' => $operation === 'resize_only' ? '尺寸调整任务已提交，正在后台处理。' : 'AI 编辑任务已提交，正在后台处理。',
                'jobId' => $jobId,
                'statusUrl' => '/workspace/canvas/jobs/' . $jobId,
                'projectId' => $projectId,
            ], 'json')->code(202);
        } catch (\Throwable $exception) {
            if ($maskFile !== null) {
                $this->deleteCanvasMask($maskFile);
            }
            Audit::log('merchant_user', (int) $user['id'], 'canvas_edit.failed', 'asset', $assetId, $this->request->ip(), ['message' => $exception->getMessage()]);
            return Response::create(['ok' => false, 'message' => localized_job_error($exception->getMessage())], 'json')->code(422);
        }
    }

    public function canvasEditStatus(int $id, CreativeProjectService $projects): Response
    {
        [$merchant] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $job = Db::name('generation_jobs')->where('id', $id)->where('merchant_id', $merchantId)->where('platform', 'canvas')->find();
        if (!$job) {
            return Response::create(['ok' => false, 'message' => '画布编辑任务不存在或无权访问。'], 'json')->code(404);
        }
        $edit = Db::name('canvas_edits')->where('job_id', $id)->find();
        $run = Db::name('creative_project_runs')->alias('r')
            ->leftJoin('creative_projects p', 'p.id=r.project_id')
            ->field('r.*,p.merchant_id')
            ->where('r.job_id', $id)
            ->where('p.merchant_id', $merchantId)
            ->find();
        $asset = null;
        if ($job['status'] === 'completed') {
            $asset = Db::name('assets')->alias('a')->leftJoin('skus s', 's.id = a.sku_id')
                ->field('a.*,s.name as sku_name,s.sku_code')->where('a.job_id', $id)->where('a.status', 'active')->order('a.id', 'desc')->find();
        }
        $runStatus = (string) $job['status'];
        if ($runStatus === 'completed' && !$asset) {
            $runStatus = 'result_missing';
        }
        if ($run) {
            $projects->updateRunStatus($id, $runStatus);
            if ($asset) {
                $projects->attachAsset(
                    $merchantId,
                    (int) $run['project_id'],
                    (int) $asset['id'],
                    'run-result-' . $id,
                    'output'
                );
            }
        }

        return Response::create([
            'ok' => true,
            'job' => [
                'id' => (int) $job['id'],
                'status' => (string) $job['status'],
                'statusLabel' => job_status_label((string) $job['status']),
                'stage' => (string) ($job['progress_stage'] ?? ''),
                'message' => (string) ($job['progress_message'] ?: job_status_label((string) $job['status'])),
                'error' => localized_job_error((string) ($job['error_message'] ?? '')),
                'imageUrl' => (string) ($asset['file_url'] ?? ''),
                'assetId' => (int) ($asset['id'] ?? 0),
                'outputWidth' => (int) ($edit['output_width'] ?? 1024),
                'outputHeight' => (int) ($edit['output_height'] ?? 1024),
                'projectId' => (int) ($run['project_id'] ?? 0),
                'sourceNodeUuid' => (string) ($run['node_uuid'] ?? ''),
                'runStatus' => $runStatus,
                'asset' => $asset ? [
                    'id' => (int) $asset['id'],
                    'skuId' => (int) ($asset['sku_id'] ?? 0),
                    'skuName' => (string) ($asset['sku_name'] ?? ''),
                    'skuCode' => (string) ($asset['sku_code'] ?? ''),
                    'assetType' => (string) ($asset['asset_type'] ?? 'canvas_edit'),
                    'assetTypeLabel' => asset_type_label((string) ($asset['asset_type'] ?? 'canvas_edit')),
                    'fileUrl' => (string) ($asset['file_url'] ?? ''),
                    'backupFileUrl' => (string) ($asset['backup_file_url'] ?? ''),
                    'createdAt' => (string) ($asset['created_at'] ?? ''),
                    'parentAssetId' => (int) ($edit['source_asset_id'] ?? 0),
                ] : null,
            ],
        ], 'json');
    }

    public function assets(AssetTrashService $trashService): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $keyword = trim((string) $this->request->get('q', ''));
        $category = trim((string) $this->request->get('category', ''));
        $skuId = max(0, (int) $this->request->get('sku_id', 0));
        $detailProjectId = max(0, (int) $this->request->get('detail_project_id', 0));
        $assetType = trim((string) $this->request->get('type', ''));
        $source = trim((string) $this->request->get('source', ''));
        $platform = trim((string) $this->request->get('platform', ''));
        $folder = (string) $this->request->get('folder', 'all');
        $usage = trim((string) $this->request->get('usage', ''));
        $favoriteOnly = $this->request->get('favorite', '') === '1';
        $sort = (string) $this->request->get('sort', 'latest');
        $layout = (string) $this->request->get('layout', 'grid');
        $trash = $this->request->get('trash', '') === '1';
        if (!array_key_exists($assetType, asset_library_type_options())) {
            $assetType = '';
        }
        if (!in_array($sort, ['latest', 'oldest', 'favorite'], true)) {
            $sort = 'latest';
        }
        if (!in_array($layout, ['grid', 'compact'], true)) {
            $layout = 'grid';
        }
        if (!in_array($folder, ['all', 'projects', 'singles', 'canvas'], true)) {
            $folder = 'all';
        }
        $usageOptions = [
            '' => ['label' => '全部素材', 'icon' => 'layout-grid'],
            'main' => ['label' => '主图与展示', 'icon' => 'image'],
            'detail' => ['label' => '详情与卖点', 'icon' => 'panels-top-left'],
            'scene' => ['label' => '场景与内容', 'icon' => 'gallery-horizontal'],
            'canvas' => ['label' => '画布衍生', 'icon' => 'paintbrush'],
        ];
        if (!array_key_exists($usage, $usageOptions)) {
            $usage = '';
        }
        $sourceOptions = ['sku' => 'SKU 素材', 'detail' => 'SKU 详情页相册', 'free' => '自由创作', 'canvas' => '画布编辑', 'unclassified' => '未归类素材'];
        if (!array_key_exists($source, $sourceOptions)) {
            $source = '';
        }
        $platformOptions = generation_platform_options();
        if (!array_key_exists($platform, $platformOptions)) {
            $platform = '';
        }
        $skuCategories = Db::name('skus')->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')
            ->where('category', '<>', '')->distinct(true)->column('category');
        $detailCategories = Db::name('detail_page_projects')->where('merchant_id', $merchantId)
            ->where('category', '<>', '')->distinct(true)->column('category');
        $categories = array_values(array_unique(array_filter(array_map('strval', array_merge($skuCategories, $detailCategories)))));
        sort($categories, SORT_NATURAL);
        if ($category !== '' && $category !== '__free' && !in_array($category, array_map('strval', $categories), true)) {
            $category = '';
        }
        if ($detailProjectId > 0 && !Db::name('detail_page_projects')->where('id', $detailProjectId)->where('merchant_id', $merchantId)->find()) {
            $detailProjectId = 0;
        }
        $selectedSku = null;
        if ($skuId > 0) {
            $selectedSku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->where('status', 'active')
                ->field('id,sku_code,name,category,source_image_path,source_image_backup_path')->find();
            if (!$selectedSku) {
                $skuId = 0;
            }
        }
        if ($detailProjectId > 0) {
            $source = 'detail';
            $skuId = 0;
        } elseif ($skuId > 0) {
            $source = 'sku';
        }
        if ($source === 'sku') {
            $detailProjectId = 0;
            if ($category !== '' && !in_array($category, array_map('strval', $skuCategories), true)) {
                $category = '';
            }
        } elseif ($source === 'detail') {
            $skuId = 0;
            if ($category !== '' && !in_array($category, array_map('strval', $detailCategories), true)) {
                $category = '';
            }
        } elseif ($source === 'free') {
            $skuId = 0;
            $detailProjectId = 0;
            $category = '__free';
        } elseif ($source === 'canvas') {
            if ($folder !== 'canvas') {
                $skuId = 0;
            }
            $detailProjectId = 0;
            $category = '';
        } elseif ($source === 'unclassified') {
            $skuId = 0;
            $detailProjectId = 0;
            $category = '';
        }

        $assetFields = 'a.*,s.name as sku_name,s.sku_code,s.category as sku_category,s.source_image_path,s.source_image_backup_path,j.job_no,j.model as job_model,j.platform as job_platform,j.bundle_no,j.bundle_part,j.detail_page_project_id,j.status as job_status,j.created_at as job_created_at,dp.product_name as detail_project_name,dp.category as detail_project_category,dp.platform as detail_project_platform,dp.source_image_url as detail_source_image';
        $hasFilters = $trash || $keyword !== '' || $category !== '' || $skuId > 0 || $detailProjectId > 0
            || $assetType !== '' || $source !== '' || $platform !== '' || $usage !== '' || $favoriteOnly || $folder !== 'all';

        $query = Db::name('assets')->alias('a')
            ->leftJoin('skus s', 's.id = a.sku_id')
            ->leftJoin('generation_jobs j', 'j.id = a.job_id')
            ->leftJoin('detail_page_projects dp', 'dp.id = j.detail_page_project_id')
            ->field($assetFields)
            ->where('a.merchant_id', $merchantId)
            ->where('a.status', $trash ? 'deleted' : 'active');
        if ($skuId > 0) {
            $query->where('a.sku_id', $skuId);
        }
        if ($detailProjectId > 0) {
            $query->where('j.detail_page_project_id', $detailProjectId);
        }
        if ($source === 'detail') {
            $query->whereNotNull('j.detail_page_project_id');
        } elseif ($source === 'canvas') {
            $query->where('j.platform', 'canvas');
        } elseif ($source === 'free') {
            $query->where('j.platform', 'free');
        } elseif ($source === 'sku') {
            $query->whereNotNull('a.sku_id');
        } elseif ($source === 'unclassified') {
            $query->whereNull('a.sku_id')->where('j.platform', '<>', 'free');
        }
        if ($folder === 'projects') {
            $query->whereNotNull('j.bundle_no')->where('j.bundle_no', '<>', '');
        } elseif ($folder === 'singles') {
            $query->where(function ($nested): void {
                $nested->whereNull('j.bundle_no')->whereOr('j.bundle_no', '=', '');
            })->where('j.platform', '<>', 'canvas');
        } elseif ($folder === 'canvas') {
            $query->where('j.platform', 'canvas');
        }
        if ($platform !== '') {
            $query->where('j.platform', $platform);
        }
        if ($category === '__free') {
            $query->whereNull('a.sku_id')->whereNull('j.detail_page_project_id');
        } elseif ($category !== '') {
            $query->where(function ($nested) use ($category): void {
                $nested->where('s.category', $category)->whereOr('dp.category', $category);
            });
        }
        if ($assetType !== '') {
            $query->where('a.asset_type', $assetType);
        }
        if ($usage === 'main') {
            $query->whereIn('a.asset_type', ['main', 'secondary', 'multi_angle', 'package']);
        } elseif ($usage === 'detail') {
            $detailUsageTypes = array_merge(['detail', 'feature'], array_keys(detail_page_screen_options()));
            $query->whereIn('a.asset_type', array_values(array_unique($detailUsageTypes)));
        } elseif ($usage === 'scene') {
            $query->whereIn('a.asset_type', ['scene', 'lifestyle', 'model', 'cover']);
        } elseif ($usage === 'canvas') {
            $query->where('j.platform', 'canvas');
        }
        if ($favoriteOnly && !$trash) {
            $query->where('a.is_favorite', 1);
        }
        if ($keyword !== '') {
            $tokens = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [$keyword];
            foreach ($tokens as $token) {
                $like = '%' . $token . '%';
                $matchedTypes = [];
                foreach (asset_library_type_options() as $type => $option) {
                    if (str_contains((string) $option['label'], $token) || str_contains($token, (string) $option['label'])) {
                        $matchedTypes[] = $type;
                    }
                }
                $clauses = [
                    "LOWER(COALESCE(s.name, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(s.sku_code, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(s.category, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(s.product_description, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(dp.product_name, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(dp.category, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(dp.product_description, '')) LIKE LOWER(?)",
                    "LOWER(COALESCE(j.job_no, '')) LIKE LOWER(?)",
                ];
                $bindings = array_fill(0, count($clauses), $like);
                if ($matchedTypes !== []) {
                    $clauses[] = 'a.asset_type IN (' . implode(',', array_fill(0, count($matchedTypes), '?')) . ')';
                    array_push($bindings, ...$matchedTypes);
                }
                if (in_array($token, ['详情', '详情页', '十屏'], true)) {
                    $clauses[] = 'j.detail_page_project_id IS NOT NULL';
                }
                if (in_array($token, ['自由', '自由创作'], true)) {
                    $clauses[] = "j.platform = 'free'";
                }
                $query->whereRaw('(' . implode(' OR ', $clauses) . ')', $bindings);
            }
        }
        if ($sort === 'oldest') {
            $query->order('a.id', 'asc');
        } elseif ($sort === 'favorite') {
            $query->order('a.is_favorite', 'desc')->order('a.id', 'desc');
        } else {
            $query->order('a.id', 'desc');
        }
        $assets = $query->limit(300)->select()->toArray();
        if ($trash) {
            foreach ($assets as &$asset) {
                $asset['purge_deadline'] = $trashService->purgeDeadline((string) ($asset['deleted_at'] ?: $asset['updated_at']));
            }
            unset($asset);
        }

        $batchLabels = Db::name('asset_batch_labels')->where('merchant_id', $merchantId)->column('label', 'batch_key');
        $assetGroups = [];
        foreach ($assets as $asset) {
            $skuGroupId = (int) ($asset['sku_id'] ?? 0);
            $assetDetailProjectId = (int) ($asset['detail_page_project_id'] ?? 0);
            $jobPlatform = (string) ($asset['job_platform'] ?? '');
            $unclassified = $skuGroupId <= 0 && $assetDetailProjectId <= 0 && !in_array($jobPlatform, ['free', 'canvas'], true);
            $groupId = $skuGroupId > 0 ? 'sku:' . $skuGroupId : ($assetDetailProjectId > 0 ? 'detail:' . $assetDetailProjectId : ($jobPlatform === 'canvas' ? 'canvas' : ($unclassified ? 'unclassified' : 'free')));
            if (!isset($assetGroups[$groupId])) {
                $assetGroups[$groupId] = [
                    'skuId' => $skuGroupId,
                    'detailProjectId' => $skuGroupId > 0 ? 0 : $assetDetailProjectId,
                    'skuName' => (string) ($skuGroupId > 0 ? ($asset['sku_name'] ?: '未命名 SKU') : ($assetDetailProjectId > 0 ? ($asset['detail_project_name'] ?: '未关联 SKU 的详情页') : ($jobPlatform === 'canvas' ? '画布编辑素材' : ($unclassified ? '未归类素材' : '自由创作素材')))),
                    'skuCode' => (string) ($skuGroupId > 0 ? ($asset['sku_code'] ?: 'SKU') : ($assetDetailProjectId > 0 ? 'DETAIL-' . $assetDetailProjectId : ($jobPlatform === 'canvas' ? 'CANVAS' : ($unclassified ? 'UNFILED' : 'FREE')))),
                    'category' => (string) ($skuGroupId > 0 ? ($asset['sku_category'] ?: '') : ($assetDetailProjectId > 0 ? ($asset['detail_project_category'] ?: '') : '')),
                    'platform' => $skuGroupId > 0 ? '' : $jobPlatform,
                    'sourceLabel' => $skuGroupId > 0 ? 'SKU 素材' : ($assetDetailProjectId > 0 ? '未关联 SKU 的详情页' : ($jobPlatform === 'canvas' ? '画布编辑' : ($unclassified ? '未归类' : '自由创作'))),
                    'sourceImage' => (string) ($asset['source_image_path'] ?: ($asset['detail_source_image'] ?: '')),
                    'sourceImageBackup' => (string) ($asset['source_image_backup_path'] ?? ''),
                    'batches' => [],
                    'assetCount' => 0,
                ];
            }
            $assetGroups[$groupId]['assetCount']++;
            $bundleNo = trim((string) ($asset['bundle_no'] ?? ''));
            $batchKey = $bundleNo !== '' ? 'bundle:' . $bundleNo : 'job:' . (int) $asset['job_id'];
            if (!isset($assetGroups[$groupId]['batches'][$batchKey])) {
                $assetGroups[$groupId]['batches'][$batchKey] = [
                    'key' => $batchKey,
                    'label' => trim((string) ($batchLabels[$batchKey] ?? '')),
                    'defaultLabel' => '',
                    'jobId' => (int) $asset['job_id'],
                    'jobNo' => (string) ($asset['job_no'] ?? ''),
                    'bundleNo' => $bundleNo,
                    'platform' => $jobPlatform,
                    'status' => (string) ($asset['job_status'] ?? ''),
                    'detailProjectId' => $assetDetailProjectId,
                    'detailProjectName' => (string) ($asset['detail_project_name'] ?? ''),
                    'createdAt' => (string) ($asset['job_created_at'] ?: $asset['created_at']),
                    'assets' => [],
                    'typeLabels' => [],
                ];
            }
            $assetGroups[$groupId]['batches'][$batchKey]['assets'][] = $asset;
            $assetGroups[$groupId]['batches'][$batchKey]['typeLabels'][(string) $asset['asset_type']] = asset_type_label((string) $asset['asset_type']);
        }
        $assetDisplayOrder = array_flip(array_keys(asset_library_type_options()));
        foreach ($assetGroups as &$assetGroup) {
            foreach ($assetGroup['batches'] as &$batch) {
                usort($batch['assets'], static function (array $left, array $right) use ($assetDisplayOrder): int {
                    $typeOrder = ($assetDisplayOrder[(string) $left['asset_type']] ?? 999) <=> ($assetDisplayOrder[(string) $right['asset_type']] ?? 999);
                    return $typeOrder !== 0 ? $typeOrder : ((int) $left['id'] <=> (int) $right['id']);
                });
                if ($batch['bundleNo'] !== '') {
                    $batch['defaultLabel'] = '整套上架素材';
                } elseif ($batch['platform'] === 'canvas') {
                    $batch['defaultLabel'] = '画布衍生';
                } elseif ($batch['platform'] === 'free') {
                    $batch['defaultLabel'] = '自由创作';
                } elseif ((int) $batch['detailProjectId'] > 0) {
                    $batch['defaultLabel'] = '详情页生成';
                } else {
                    $typeLabels = array_values($batch['typeLabels']);
                    $batch['defaultLabel'] = count($typeLabels) <= 2 ? implode(' + ', $typeLabels) : '商品素材生成';
                }
                $batch['displayLabel'] = $batch['label'] !== '' ? $batch['label'] : $batch['defaultLabel'];
            }
            unset($batch);
        }
        unset($assetGroup);

        $skuQuery = Db::name('skus')->where('merchant_id', $merchantId)->where('status', 'active');
        if ($category !== '' && $category !== '__free') {
            $skuQuery->where('category', $category);
        }
        $skus = $skuQuery->field('id,sku_code,name,category,source_image_path,source_image_backup_path')->order('id', 'desc')->limit(300)->select()->toArray();
        $assetTree = [];
        $treeStats = [];
        foreach (Db::name('assets')->alias('a')->leftJoin('generation_jobs j', 'j.id=a.job_id')
            ->where('a.merchant_id', $merchantId)->where('a.status', 'active')->whereNotNull('a.sku_id')
            ->field("a.sku_id,COUNT(*) AS total,SUM(CASE WHEN j.bundle_no IS NOT NULL AND j.bundle_no <> '' THEN 1 ELSE 0 END) AS projects,SUM(CASE WHEN j.platform = 'canvas' THEN 1 ELSE 0 END) AS canvas")
            ->group('a.sku_id')->select()->toArray() as $row) {
            $treeStats[(int) $row['sku_id']] = $row;
        }
        foreach (Db::name('skus')->where('merchant_id', $merchantId)->where('status', 'active')->field('id,sku_code,name,category,source_image_path,source_image_backup_path')->order('id', 'desc')->limit(300)->select()->toArray() as $treeSku) {
            $treeId = (int) $treeSku['id'];
            $total = (int) ($treeStats[$treeId]['total'] ?? 0);
            $projects = (int) ($treeStats[$treeId]['projects'] ?? 0);
            $canvas = (int) ($treeStats[$treeId]['canvas'] ?? 0);
            $assetTree[] = $treeSku + ['total' => $total, 'projects' => $projects, 'canvas' => $canvas, 'singles' => max(0, $total - $projects - $canvas)];
        }
        $allBatchKeys = [];
        foreach (Db::name('assets')->alias('a')->leftJoin('generation_jobs j', 'j.id=a.job_id')
            ->where('a.merchant_id', $merchantId)->where('a.status', 'active')->field('a.job_id,j.bundle_no')->select()->toArray() as $batchRow) {
            $bundleNo = trim((string) ($batchRow['bundle_no'] ?? ''));
            $allBatchKeys[$bundleNo !== '' ? 'bundle:' . $bundleNo : 'job:' . (int) $batchRow['job_id']] = true;
        }
        $detailProjectQuery = Db::name('detail_page_projects')->where('merchant_id', $merchantId);
        if ($source === 'detail' && $category !== '') {
            $detailProjectQuery->where('category', $category);
        }
        $detailProjects = $detailProjectQuery->field('id,product_name,category,platform')->order('id', 'desc')->limit(300)->select()->toArray();
        $stats = [
            'active' => (int) Db::name('assets')->where('merchant_id', $merchantId)->where('status', 'active')->count(),
            'favorites' => (int) Db::name('assets')->where('merchant_id', $merchantId)->where('status', 'active')->where('is_favorite', 1)->count(),
            'trash' => (int) Db::name('assets')->where('merchant_id', $merchantId)->where('status', 'deleted')->count(),
            'groups' => count($assetGroups),
            'batches' => count($allBatchKeys),
            'free' => (int) Db::name('assets')->alias('a')->leftJoin('generation_jobs j', 'j.id=a.job_id')
                ->where('a.merchant_id', $merchantId)->where('a.status', 'active')->where('j.platform', 'free')->count(),
            'unclassified' => (int) Db::name('assets')->alias('a')->leftJoin('generation_jobs j', 'j.id=a.job_id')
                ->where('a.merchant_id', $merchantId)->where('a.status', 'active')->whereNull('a.sku_id')->whereNull('j.detail_page_project_id')
                ->whereNotIn('j.platform', ['free', 'canvas'])->count(),
            'detail' => (int) Db::name('assets')->alias('a')->leftJoin('generation_jobs j', 'j.id=a.job_id')
                ->where('a.merchant_id', $merchantId)->where('a.status', 'active')->whereNotNull('j.detail_page_project_id')->count(),
        ];
        $returnTo = '/workspace/assets?' . http_build_query(array_filter([
            'q' => $keyword,
            'category' => $category,
            'sku_id' => $skuId ?: null,
            'detail_project_id' => $detailProjectId ?: null,
            'type' => $assetType,
            'source' => $source,
            'platform' => $platform,
            'usage' => $usage,
            'folder' => $folder !== 'all' ? $folder : null,
            'favorite' => $favoriteOnly ? 1 : null,
            'sort' => $sort !== 'latest' ? $sort : null,
            'layout' => $layout !== 'grid' ? $layout : null,
            'trash' => $trash ? 1 : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $this->render('merchant/assets', compact(
            'merchant', 'user', 'assets', 'assetGroups', 'assetTree', 'skus', 'detailProjects', 'categories', 'skuCategories', 'detailCategories', 'stats', 'keyword', 'category', 'skuId',
            'detailProjectId', 'assetType', 'source', 'sourceOptions', 'platform', 'platformOptions', 'folder', 'usage', 'usageOptions', 'favoriteOnly', 'sort', 'layout', 'trash', 'hasFilters', 'returnTo', 'selectedSku'
        ) + ['pageTitle' => '素材库']);
    }

    public function saveAssetBatchLabel(): Response
    {
        [$merchant, $user] = $this->identity();
        $merchantId = (int) $merchant['id'];
        $batchKey = trim((string) $this->request->post('batch_key', ''));
        $label = trim((string) $this->request->post('label', ''));
        if (!preg_match('/^(job:[1-9]\d*|bundle:[A-Za-z0-9._:-]{1,160})$/', $batchKey) || mb_strlen($label) > 120) {
            return Web::redirect($this->assetReturnPath(), '批次名称或批次标识无效。', 'error');
        }

        $owned = false;
        if (str_starts_with($batchKey, 'job:')) {
            $owned = Db::name('generation_jobs')->where('id', (int) substr($batchKey, 4))->where('merchant_id', $merchantId)->find() !== null;
        } else {
            $owned = Db::name('generation_jobs')->where('bundle_no', substr($batchKey, 7))->where('merchant_id', $merchantId)->find() !== null;
        }
        if (!$owned) {
            return Web::redirect($this->assetReturnPath(), '素材批次不存在。', 'error');
        }

        $existing = Db::name('asset_batch_labels')->where('merchant_id', $merchantId)->where('batch_key', $batchKey)->find();
        if ($label === '') {
            if ($existing) {
                Db::name('asset_batch_labels')->where('id', $existing['id'])->delete();
            }
        } elseif ($existing) {
            Db::name('asset_batch_labels')->where('id', $existing['id'])->update(['label' => $label, 'updated_at' => Web::now()]);
        } else {
            Db::name('asset_batch_labels')->insert([
                'merchant_id' => $merchantId,
                'batch_key' => $batchKey,
                'label' => $label,
                'created_at' => Web::now(),
                'updated_at' => Web::now(),
            ]);
        }
        Audit::log('merchant_user', (int) $user['id'], 'asset.batch_label', 'asset_batch', 0, $this->request->ip(), [
            'batch_key' => $batchKey,
            'label' => $label,
        ]);

        return Web::redirect($this->assetReturnPath(), $label === '' ? '已恢复默认批次名称。' : '批次名称已保存。');
    }

    public function toggleFavorite(int $id): Response
    {
        [$merchant, $user] = $this->identity();
        $asset = Db::name('assets')->where('id', $id)->where('merchant_id', $merchant['id'])->where('status', 'active')->find();
        if (!$asset) {
            return Web::redirect('/workspace/assets', '素材不存在。', 'error');
        }
        $favorite = (int) $asset['is_favorite'] === 1 ? 0 : 1;
        Db::name('assets')->where('id', $id)->update(['is_favorite' => $favorite, 'updated_at' => Web::now()]);
        Audit::log('merchant_user', (int) $user['id'], 'asset.favorite', 'asset', $id, $this->request->ip(), ['favorite' => $favorite]);

        return Web::redirect($this->assetReturnPath(), $favorite ? '已收藏素材。' : '已取消收藏。');
    }

    public function deleteAsset(int $id): Response
    {
        [$merchant, $user] = $this->identity();
        $now = Web::now();
        $updated = Db::name('assets')->where('id', $id)->where('merchant_id', $merchant['id'])->where('status', 'active')->update([
            'status' => 'deleted',
            'deleted_at' => $now,
            'purged_at' => null,
            'updated_at' => $now,
        ]);
        if (!$updated) {
            return Web::redirect('/workspace/assets', '素材不存在或已删除。', 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'asset.delete', 'asset', $id, $this->request->ip());

        return Web::redirect($this->assetReturnPath(), '素材已移入回收站，保留 7 天后自动彻底删除。');
    }

    public function restoreAsset(int $id): Response
    {
        [$merchant, $user] = $this->identity();
        $updated = Db::name('assets')->where('id', $id)->where('merchant_id', $merchant['id'])->where('status', 'deleted')->update([
            'status' => 'active',
            'deleted_at' => null,
            'updated_at' => Web::now(),
        ]);
        if (!$updated) {
            return Web::redirect('/workspace/assets?trash=1', '素材不存在或已经恢复。', 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'asset.restore', 'asset', $id, $this->request->ip());

        return Web::redirect($this->assetReturnPath('/workspace/assets?trash=1'), '素材已恢复。');
    }

    public function purgeAsset(int $id, AssetTrashService $trashService): Response
    {
        [$merchant, $user] = $this->identity();
        $result = $trashService->purgeForMerchant((int) $merchant['id'], [$id]);
        if ((int) $result['purged'] < 1) {
            return Web::redirect('/workspace/assets?trash=1', $result['warnings'][0] ?? '素材不存在、尚未进入回收站或文件删除失败。', 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'asset.purge', 'asset', $id, $this->request->ip(), $result);
        $message = '素材已彻底删除，无法恢复。';
        if ($result['warnings'] !== []) {
            $message .= ' ' . $result['warnings'][0];
        }

        return Web::redirect($this->assetReturnPath('/workspace/assets?trash=1'), $message, $result['warnings'] === [] ? 'success' : 'warning');
    }

    public function bulkAssetAction(AssetTrashService $trashService): Response
    {
        [$merchant, $user] = $this->identity();
        $ids = $this->request->post('asset_ids', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn (int $id): bool => $id > 0)));
        $ids = array_slice($ids, 0, 100);
        $action = (string) $this->request->post('bulk_action', '');
        if ($ids === [] || !in_array($action, ['favorite', 'unfavorite', 'delete', 'restore', 'purge', 'assign_sku'], true)) {
            return Web::redirect($this->assetReturnPath(), '请选择素材和有效的批量操作。', 'error');
        }

        $query = Db::name('assets')->where('merchant_id', $merchant['id'])->whereIn('id', $ids);
        $now = Web::now();
        $details = [];
        if ($action === 'assign_sku') {
            $targetSkuId = max(0, (int) $this->request->post('target_sku_id', 0));
            $targetSku = Db::name('skus')->where('id', $targetSkuId)->where('merchant_id', $merchant['id'])->where('status', 'active')
                ->field('id,name,sku_code')->find();
            if (!$targetSku) {
                return Web::redirect($this->assetReturnPath(), '请选择有效的目标 SKU。', 'error');
            }
            $updated = $query->where('status', 'active')->update(['sku_id' => $targetSkuId, 'updated_at' => $now]);
            $message = '已将 ' . (int) $updated . ' 张素材归档到 ' . (string) $targetSku['name'] . '。';
            $details = ['target_sku_id' => $targetSkuId, 'target_sku_code' => (string) $targetSku['sku_code']];
        } elseif ($action === 'purge') {
            $result = $trashService->purgeForMerchant((int) $merchant['id'], $ids);
            $updated = (int) $result['purged'];
            $details = $result;
            $message = '已彻底删除 ' . $updated . ' 张素材。';
            if ((int) $result['failed'] > 0) {
                $message .= ' ' . (int) $result['failed'] . ' 张文件删除失败，可稍后重试。';
            }
        } elseif ($action === 'restore') {
            $updated = $query->where('status', 'deleted')->update(['status' => 'active', 'deleted_at' => null, 'updated_at' => $now]);
            $message = '已恢复 ' . (int) $updated . ' 张素材。';
        } elseif ($action === 'delete') {
            $updated = $query->where('status', 'active')->update(['status' => 'deleted', 'deleted_at' => $now, 'purged_at' => null, 'updated_at' => $now]);
            $message = '已将 ' . (int) $updated . ' 张素材移入回收站。';
        } else {
            $favorite = $action === 'favorite' ? 1 : 0;
            $updated = $query->where('status', 'active')->update(['is_favorite' => $favorite, 'updated_at' => $now]);
            $message = $favorite ? '已批量收藏 ' . (int) $updated . ' 张素材。' : '已批量取消收藏 ' . (int) $updated . ' 张素材。';
        }
        Audit::log('merchant_user', (int) $user['id'], 'asset.bulk_' . $action, 'asset', 0, $this->request->ip(), [
            'ids' => $ids,
            'updated' => (int) $updated,
            'details' => $details,
        ]);

        return Web::redirect($this->assetReturnPath(), $message);
    }

    public function downloadAsset(int $id, AssetStorage $storage): Response
    {
        [$merchant] = $this->identity();
        $asset = Db::name('assets')->where('id', $id)->where('merchant_id', $merchant['id'])->where('status', 'active')->find();
        if (!$asset) {
            return Web::redirect('/workspace/assets', '素材不存在。', 'error');
        }
        try {
            $file = (string) ($asset['media_type'] ?? 'image') === 'video'
                ? $storage->readVideoWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''))
                : $storage->readImageWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''));
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/assets', $exception->getMessage(), 'error');
        }
        return Response::create($file['content'])->header([
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="asset-' . $id . '.' . pathinfo($file['filename'], PATHINFO_EXTENSION) . '"',
            'Content-Length' => (string) strlen($file['content']),
        ]);
    }

    public function downloadAssetsZip(AssetStorage $storage): Response
    {
        [$merchant, $user] = $this->identity();
        $ids = $this->request->post('asset_ids', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return Response::create(['ok' => false, 'message' => '请至少选择一张素材。'], 'json')->code(422);
        }
        if (count($ids) > self::ASSET_EXPORT_MAX_ITEMS) {
            return Response::create(['ok' => false, 'message' => '同步导出一次最多 100 张素材，请缩小选择范围后重试。'], 'json')->code(422);
        }
        if (!class_exists(\ZipArchive::class)) {
            return Response::create(['ok' => false, 'message' => '服务器尚未安装 PHP Zip 扩展，请联系管理员安装 php-zip。'], 'json')->code(503);
        }
        $organization = (string) $this->request->post('organization', 'structured');
        if (!in_array($organization, ['structured', 'flat'], true)) {
            $organization = 'structured';
        }
        $includeManifest = $this->request->post('include_manifest', '') === '1';

        $assets = Db::name('assets')->alias('a')
            ->leftJoin('skus s', 's.id = a.sku_id')
            ->leftJoin('generation_jobs j', 'j.id = a.job_id')
            ->leftJoin('detail_page_projects dp', 'dp.id = j.detail_page_project_id')
            ->field('a.id,a.sku_id,a.asset_type,a.media_type,a.file_url,a.backup_file_url,a.created_at,s.sku_code,s.name as sku_name,j.job_no,j.platform as job_platform,j.detail_page_project_id,dp.product_name as detail_project_name')
            ->where('a.merchant_id', $merchant['id'])->where('a.status', 'active')->whereIn('a.id', $ids)
            ->order('a.sku_id', 'asc')->order('a.id', 'asc')->select()->toArray();
        if ($assets === []) {
            return Response::create(['ok' => false, 'message' => '选中的素材不存在或已经删除。'], 'json')->code(404);
        }

        $skuIds = array_values(array_unique(array_map(static fn (array $asset): int => (int) ($asset['sku_id'] ?? 0), $assets)));
        $singleSku = count($skuIds) === 1 && $skuIds[0] > 0;
        $archiveBaseName = $singleSku
            ? $this->safeArchiveName(trim((string) ($assets[0]['sku_code'] ?: '') . '-' . (string) ($assets[0]['sku_name'] ?: '未命名商品')) . '-素材包-' . date('Ymd-His'))
            : $this->safeArchiveName((string) $merchant['name'] . '-素材包-' . date('Ymd-His'));
        $zipPath = tempnam(sys_get_temp_dir(), 'asset-archive-');
        if ($zipPath === false) {
            return Response::create(['ok' => false, 'message' => '服务器无法创建临时压缩包。'], 'json')->code(500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return Response::create(['ok' => false, 'message' => '创建素材压缩包失败。'], 'json')->code(500);
        }
        @set_time_limit(0);
        $added = 0;
        $failed = [];
        $folderIndexes = [];
        $manifestRows = [];
        $totalBytes = 0;
        $limitExceeded = false;
        try {
            foreach ($assets as $asset) {
                try {
                    $file = (string) ($asset['media_type'] ?? 'image') === 'video'
                        ? $storage->readVideoWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''))
                        : $storage->readImageWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''));
                    $fileBytes = strlen((string) $file['content']);
                    if ($totalBytes + $fileBytes > self::ASSET_EXPORT_MAX_BYTES) {
                        $limitExceeded = true;
                        break;
                    }
                    $folder = $organization === 'structured' ? $this->assetArchiveFolder($asset) : '';
                    $folderIndexes[$folder] = ($folderIndexes[$folder] ?? 0) + 1;
                    $extension = pathinfo((string) $file['filename'], PATHINFO_EXTENSION) ?: 'png';
                    $entryName = sprintf('%02d-%s-A%d.%s', $folderIndexes[$folder], $this->safeArchiveName(asset_type_label($asset['asset_type'])), (int) $asset['id'], $extension);
                    $entryPath = ($folder !== '' ? $folder . '/' : '') . $entryName;
                    if (!$zip->addFromString($entryPath, (string) $file['content'])) {
                        throw new \RuntimeException('写入 ZIP 失败');
                    }
                    $added++;
                    $totalBytes += $fileBytes;
                    $manifestRows[] = [
                        (int) $asset['id'],
                        (string) ($asset['sku_code'] ?? ''),
                        (string) ($asset['sku_name'] ?? ''),
                        platform_label((string) ($asset['job_platform'] ?? '')),
                        (string) ($asset['detail_project_name'] ?? ''),
                        asset_type_label((string) $asset['asset_type']),
                        (string) ($asset['job_no'] ?? ''),
                        (string) ($asset['created_at'] ?? ''),
                        $entryPath,
                    ];
                } catch (\Throwable $exception) {
                    $failed[] = '素材 #' . (int) $asset['id'] . '：' . $exception->getMessage();
                }
            }
            if (!$limitExceeded && $includeManifest) {
                $zip->addFromString('素材清单.csv', $this->assetManifestCsv($manifestRows));
            }
            if (!$limitExceeded && $failed !== []) {
                $zip->addFromString('打包失败说明.txt', "以下素材未能加入压缩包：\r\n" . implode("\r\n", $failed));
            }
            $zip->setArchiveComment('AI 电商素材工厂 · ' . date('Y-m-d H:i:s'));
        } finally {
            $zip->close();
        }
        if ($limitExceeded) {
            @unlink($zipPath);
            return Response::create(['ok' => false, 'message' => '本次素材原文件合计超过 500 MB，请缩小选择范围后重试。'], 'json')->code(413);
        }
        if ($added === 0) {
            @unlink($zipPath);
            return Response::create(['ok' => false, 'message' => '选中的素材均无法读取，未生成压缩包。'], 'json')->code(422);
        }

        Audit::log('merchant_user', (int) $user['id'], 'asset.download_zip', 'asset', 0, $this->request->ip(), [
            'selected' => count($ids),
            'added' => $added,
            'failed' => count($failed),
            'single_sku' => $singleSku,
            'organization' => $organization,
            'manifest' => $includeManifest,
            'source_bytes' => $totalBytes,
        ]);

        return new TemporaryDownloadResponse($zipPath, $archiveBaseName . '.zip', 'application/zip');
    }

    public function jobs(NewApiClient $newApi, GenerationJobView $jobView): Response
    {
        [$merchant, $user] = $this->identity();
        $skuId = max(0, (int) $this->request->get('sku_id', 0));
        $status = (string) $this->request->get('status', '');
        $keyword = trim((string) $this->request->get('q', ''));
        $type = trim((string) $this->request->get('type', ''));
        $period = trim((string) $this->request->get('period', ''));
        $page = max(1, (int) $this->request->get('page', 1));
        if (!in_array($status, ['pending_gateway', 'queued', 'running', 'manual_review', 'completed', 'partial', 'failed', 'result_missing', 'cancelled'], true)) {
            $status = '';
        }
        if (!in_array($type, ['', 'bundle', 'detail', 'product', 'free', 'canvas', 'video'], true)) {
            $type = '';
        }
        if (!in_array($period, ['', 'today', '7d', '30d'], true)) {
            $period = '';
        }
        $query = Db::name('generation_jobs')->alias('j')
            ->leftJoin('skus s', 's.id = j.sku_id')
            ->leftJoin('free_generation_jobs f', 'f.job_id = j.id')
            ->leftJoin('canvas_edits ce', 'ce.job_id = j.id')
            ->leftJoin('detail_page_projects dp', 'dp.id = j.detail_page_project_id')
            ->field('j.*,s.name as sku_name,s.sku_code,s.source_image_path,f.reference_image_url as free_reference_image,ce.source_asset_id as canvas_source_asset_id,dp.product_name as detail_project_name,dp.source_image_url as detail_source_image')
            ->where('j.merchant_id', $merchant['id'])
            ->whereNull('j.merchant_hidden_at');
        if ($skuId > 0) {
            $query->where('j.sku_id', $skuId);
        }
        if ($status === 'queued') {
            $query->whereIn('j.status', ['queued', 'pending_gateway']);
        } elseif ($status === 'failed') {
            $query->whereIn('j.status', ['failed', 'result_missing']);
        } elseif ($status !== '') {
            $query->where('j.status', $status);
        }
        if ($keyword !== '') {
            $query->where(function ($nested) use ($keyword): void {
                $nested->whereLike('j.job_no|j.bundle_no|s.name|s.sku_code|dp.product_name', '%' . $keyword . '%');
            });
        }
        if ($type === 'bundle') {
            $query->whereNotNull('j.bundle_no')->where('j.bundle_no', '<>', '');
        } elseif ($type === 'detail') {
            $query->whereNotNull('j.detail_page_project_id');
        } elseif ($type === 'free') {
            $query->where('j.platform', 'free');
        } elseif ($type === 'canvas') {
            $query->where('j.platform', 'canvas');
        } elseif ($type === 'video') {
            $query->where('j.output_kind', 'video');
        } elseif ($type === 'product') {
            $query->where('j.output_kind', 'image')->whereNotIn('j.platform', ['free', 'canvas'])->whereNull('j.detail_page_project_id')
                ->where(function ($nested): void {
                    $nested->whereNull('j.bundle_no')->whereOr('j.bundle_no', '=', '');
                });
        }
        if ($period !== '') {
            $startAt = match ($period) {
                'today' => date('Y-m-d 00:00:00'),
                '7d' => date('Y-m-d H:i:s', time() - 7 * 86400),
                '30d' => date('Y-m-d H:i:s', time() - 30 * 86400),
                default => '',
            };
            if ($startAt !== '') {
                $query->where('j.created_at', '>=', $startAt);
            }
        }
        $pageSize = 30;
        $totalJobs = (int) (clone $query)->count();
        $totalPages = max(1, (int) ceil($totalJobs / $pageSize));
        $page = min($page, $totalPages);
        $jobs = $jobView->enrich($query->order('j.id', 'desc')->page($page, $pageSize)->select()->toArray());
        $filteredSku = $skuId > 0
            ? Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchant['id'])->field('id,sku_code,name')->find()
            : null;
        $skuOptions = Db::name('skus')->where('merchant_id', $merchant['id'])->where('status', '<>', 'deleted')
            ->field('id,sku_code,name')->order('updated_at', 'desc')->limit(300)->select()->toArray();
        $statusCounts = ['running' => 0, 'queued' => 0, 'failed' => 0, 'partial' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach (Db::name('generation_jobs')->where('merchant_id', $merchant['id'])->whereNull('merchant_hidden_at')
            ->field('status,COUNT(*) AS total')->group('status')->select()->toArray() as $row) {
            $key = (string) $row['status'];
            if (in_array($key, ['queued', 'pending_gateway'], true)) {
                $statusCounts['queued'] += (int) $row['total'];
            } elseif ($key === 'result_missing') {
                $statusCounts['failed'] += (int) $row['total'];
            } elseif (array_key_exists($key, $statusCounts)) {
                $statusCounts[$key] = (int) $row['total'];
            }
        }

        return $this->render('merchant/jobs', compact(
            'merchant', 'user', 'jobs', 'skuId', 'status', 'keyword', 'type', 'period', 'page',
            'pageSize', 'totalJobs', 'totalPages', 'filteredSku', 'skuOptions', 'statusCounts'
        ) + [
            'pageTitle' => '任务记录',
            'imageEditEnabled' => $newApi->supportsImageEdit($newApi->defaultModel()),
        ]);
    }

    public function jobStatuses(GenerationJobView $jobView): Response
    {
        [$merchant] = $this->identity();
        $jobs = Db::name('generation_jobs')->alias('j')
            ->leftJoin('skus s', 's.id=j.sku_id')
            ->leftJoin('free_generation_jobs f', 'f.job_id=j.id')
            ->field('j.id,j.job_no,j.output_kind,j.sku_id,j.platform,j.bundle_part,j.detail_page_project_id,j.status,j.safety_status,j.asset_types,j.progress_current,j.progress_total,j.progress_stage,j.progress_message,j.error_message,j.actual_credits,j.estimated_credits,j.model,j.requested_model_id,j.resolved_ai_model_id,j.route_snapshot_json,j.new_api_request_id,j.created_at,j.updated_at,s.name as sku_name,s.sku_code,s.source_image_path as sku_thumbnail,s.source_image_backup_path as sku_thumbnail_backup,f.prompt as free_prompt,f.reference_image_url as free_thumbnail,f.reference_image_backup_url as free_thumbnail_backup')
            ->where('j.merchant_id', $merchant['id'])
            ->whereNull('j.merchant_hidden_at')
            ->order('j.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $jobs = $jobView->enrich($jobs);

        $items = array_map(static function (array $job): array {
            $isVideo = (string) ($job['output_kind'] ?? 'image') === 'video';
            $total = $isVideo ? 100 : max((int) ($job['progress_total'] ?? 0), count(decode_json_list($job['asset_types'] ?? '[]')));
            $current = min(max(0, (int) ($job['progress_current'] ?? 0)), $total);
            $assetTypeLabels = array_values(array_unique(array_map(static fn (string $type): string => asset_type_label($type), decode_json_list($job['asset_types'] ?? '[]'))));
            $taskTitle = match (true) {
                $isVideo => '商品视频生成',
                (string) $job['platform'] === 'canvas' => '画布编辑',
                (string) $job['platform'] === 'free' => '自由创作',
                (int) ($job['detail_page_project_id'] ?? 0) > 0 => '详情页生成',
                (string) ($job['bundle_part'] ?? '') === 'assets' => '整套素材 · 主图',
                default => (generation_platform_options()[(string) $job['platform']] ?? (string) $job['platform']) . '素材生成',
            };
            $skuId = (int) ($job['sku_id'] ?? 0);
            $freePrompt = trim((string) ($job['free_prompt'] ?? ''));
            $projectTitle = match ((string) $job['platform']) {
                'canvas' => '画布工作区',
                'free' => $freePrompt !== '' ? mb_strimwidth($freePrompt, 0, 30, '...') : '自由创作',
                default => trim((string) ($job['sku_name'] ?? '')) ?: ((string) ($job['sku_code'] ?? '') ?: $taskTitle),
            };
            $detailUrl = match ((string) $job['platform']) {
                'canvas' => '/workspace/canvas',
                'free' => '/workspace/free-create?project_id=' . (int) $job['id'],
                default => $skuId > 0
                    ? '/workspace/generate?' . ($isVideo ? 'mode=video&' : '') . 'sku_id=' . $skuId
                    : '/workspace/jobs',
            };
            $previewUrls = array_values(array_filter((array) ($job['result_preview_urls'] ?? [])));
            if ($previewUrls === []) {
                $fallbackPreview = match ((string) $job['platform']) {
                    'free' => (string) ($job['free_thumbnail'] ?: $job['free_thumbnail_backup']),
                    default => (string) ($job['sku_thumbnail'] ?: $job['sku_thumbnail_backup']),
                };
                if ($fallbackPreview !== '') {
                    $previewUrls[] = $fallbackPreview;
                }
            }
            return [
                'id' => (int) $job['id'],
                'jobNo' => (string) $job['job_no'],
                'title' => $taskTitle,
                'modelName' => (string) ($job['display_model_name'] ?? $job['model'] ?? '系统默认模型'),
                'outputKind' => $isVideo ? 'video' : 'image',
                'projectTitle' => $projectTitle,
                'assetTypeLabels' => array_slice($assetTypeLabels, 0, 3),
                'status' => (string) $job['status'],
                'statusLabel' => job_status_label($job['status']),
                'progressCurrent' => $current,
                'progressTotal' => $total,
                'progressStage' => (string) ($job['progress_stage'] ?? ''),
                'progressMessage' => (string) ($job['progress_message'] ?? ''),
                'errorMessage' => trim((string) ($job['error_message'] ?? '')),
                'resultStatus' => (string) ($job['result_status'] ?? 'none'),
                'resultStatusLabel' => (string) ($job['result_status_label'] ?? '暂无结果'),
                'availableResults' => (int) ($job['available_result_count'] ?? 0),
                'expectedResults' => (int) ($job['expected_result_count'] ?? $total),
                'actualCredits' => (int) $job['actual_credits'],
                'estimatedCredits' => (int) $job['estimated_credits'],
                'canRetry' => (in_array($job['status'], ['failed', 'partial', 'pending_gateway'], true) || (string) ($job['result_status'] ?? '') === 'missing')
                    && (string) ($job['safety_status'] ?? '') !== 'blocked',
                'canHide' => $job['status'] === 'failed',
                'canCancel' => !empty($job['can_cancel']),
                'updatedAt' => (string) $job['updated_at'],
                'createdAt' => (string) $job['created_at'],
                'previewUrls' => $previewUrls,
                'detailUrl' => $detailUrl,
            ];
        }, $jobs);

        $visionTasks = Db::name('vision_tasks')
            ->field('id,task_no,sku_id,task_type,status,progress_percent,progress_stage,progress_message,result_json,error_message,attempts,next_retry_at,created_at,updated_at')
            ->where('merchant_id', $merchant['id'])
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();
        foreach ($visionTasks as $task) {
            $result = json_decode((string) ($task['result_json'] ?? ''), true);
            $result = is_array($result) ? $result : [];
            $status = (string) $task['status'];
            $taskType = (string) ($task['task_type'] ?? 'product_profile');
            $skuId = (int) ($task['sku_id'] ?? 0);
            $sku = $skuId > 0
                ? Db::name('skus')->field('name,sku_code,source_image_path,source_image_backup_path')->where('id', $skuId)->where('merchant_id', $merchant['id'])->find()
                : null;
            $detailUrl = trim((string) ($result['redirectUrl'] ?? ''));
            if ($detailUrl === '') {
                $detailUrl = $skuId > 0 ? '/workspace/skus/' . $skuId . '?section=overview' : '/workspace/generate';
            }
            $taskErrorMessage = trim((string) ($task['error_message'] ?? ''));
            $items[] = [
                'id' => 'vision-' . (int) $task['id'],
                'jobNo' => (string) $task['task_no'],
                'title' => $taskType === 'product_launch' ? '商品上新建档' : '商品图片识别',
                'projectTitle' => trim((string) ($sku['name'] ?? '')) ?: ($taskType === 'product_launch' ? '新商品识别' : '商品资料识别'),
                'assetTypeLabels' => [],
                'status' => $status,
                'statusLabel' => match ($status) {
                    'queued' => '等待处理',
                    'running' => '处理中',
                    'retrying' => '自动重试',
                    'completed' => '已完成',
                    'failed' => '失败',
                    default => $status,
                },
                'progressCurrent' => max(0, min(100, (int) ($task['progress_percent'] ?? 0))),
                'progressTotal' => 100,
                'progressPercent' => max(0, min(100, (int) ($task['progress_percent'] ?? 0))),
                'progressStage' => (string) ($task['progress_stage'] ?? ''),
                'progressMessage' => (string) ($task['progress_message'] ?? ''),
                'attempt' => max(0, (int) ($task['attempts'] ?? 0)),
                'maxAttempts' => 2,
                'nextRetryAt' => (string) ($task['next_retry_at'] ?? ''),
                'errorMessage' => $taskErrorMessage === ''
                    ? ''
                    : localized_job_error($taskErrorMessage),
                'resultStatus' => $status === 'completed' ? 'available' : ($status === 'failed' ? 'missing' : 'pending'),
                'resultStatusLabel' => $status === 'completed' ? '结果可用' : ($status === 'failed' ? '结果缺失' : '等待结果'),
                'availableResults' => $status === 'completed' ? 1 : 0,
                'expectedResults' => 1,
                'actualCredits' => (int) ($result['creditsCharged'] ?? 0),
                'estimatedCredits' => (int) ($result['creditsCharged'] ?? 0),
                'canRetry' => $status === 'failed' && $taskType === 'product_launch',
                'canHide' => false,
                'canCancel' => false,
                'retryUrl' => $status === 'failed' && $taskType === 'product_launch'
                    ? '/workspace/vision-tasks/' . (int) $task['id'] . '/retry' : '',
                'updatedAt' => (string) $task['updated_at'],
                'createdAt' => (string) $task['created_at'],
                'previewUrls' => array_values(array_filter([
                    (string) ($sku['source_image_path'] ?? ''),
                    (string) ($sku['source_image_backup_path'] ?? ''),
                ])),
                'detailUrl' => $detailUrl,
            ];
        }
        usort($items, static fn (array $left, array $right): int => strcmp((string) $right['createdAt'], (string) $left['createdAt']));

        $failedCount = Db::name('generation_jobs')
            ->where('merchant_id', $merchant['id'])
            ->whereIn('status', ['failed', 'partial', 'result_missing'])
            ->whereNull('merchant_hidden_at')
            ->count();
        $failedCount += Db::name('vision_tasks')->where('merchant_id', $merchant['id'])->where('status', 'failed')->count();

        return Response::create(['ok' => true, 'jobs' => $items, 'failedCount' => $failedCount], 'json');
    }

    public function retryJob(int $id, NewApiClient $newApi, GenerationItemQueue $itemQueue, VideoGenerationQueue $videoQueue): Response
    {
        [$merchant, $user] = $this->identity();
        $job = Db::name('generation_jobs')
            ->where('id', $id)
            ->where('merchant_id', $merchant['id'])
            ->find();
        $expected = $job ? max((int) ($job['progress_total'] ?? 0), count(decode_json_list($job['asset_types'] ?? '[]'))) : 0;
        $available = $job ? (int) Db::name('assets')->where('job_id', $id)->where('status', 'active')->where('file_url', '<>', '')->count() : 0;
        $resultMissing = $job && in_array((string) $job['status'], ['completed', 'partial'], true) && $expected > $available;
        if (!$job || (!in_array($job['status'], ['failed', 'partial', 'result_missing', 'pending_gateway'], true) && !$resultMissing)
            || (string) ($job['safety_status'] ?? '') === 'blocked') {
            return Web::redirect('/workspace/jobs', '该任务当前不可重试。', 'error');
        }
        $model = trim((string) ($job['model'] ?: $newApi->defaultModel()));
        $routeSnapshot = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $routeSnapshot = is_array($routeSnapshot) ? $routeSnapshot : [];
        if (!$newApi->hasAvailableModels(
            $model,
            max(0, (int) ($routeSnapshot['resolvedModelId'] ?? $job['resolved_ai_model_id'] ?? 0)) ?: null,
            trim((string) ($routeSnapshot['resolvedGateway'] ?? '')) ?: null,
            in_array((string) ($routeSnapshot['capability'] ?? ''), [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)
                ? (string) $routeSnapshot['capability']
                : null
        )) {
            $hasItems = $itemQueue->hasItems($id);
            $reactivatedItems = $hasItems ? $itemQueue->retryItems($id, 'pending_gateway') : 0;
            if ($hasItems && $reactivatedItems === 0) {
                return Web::redirect('/workspace/jobs', '没有可重新生成的失败图片，任务状态未改变。', 'error');
            }
            Db::name('generation_jobs')->where('id', $id)->update([
                'status' => 'pending_gateway',
                'error_message' => '当前任务模型尚未在 Sub2API 发布或暂时不可用，请管理员检查模型服务后再试',
                'updated_at' => Web::now(),
            ]);
            return Web::redirect('/workspace/jobs', '当前模型渠道仍不可用，任务已保留，请稍后重试。', 'error');
        }
        if ((string) ($job['output_kind'] ?? 'image') === 'video') {
            if (!$videoQueue->requeue($id, (int) $merchant['id'])) {
                return Web::redirect('/workspace/jobs', '该视频任务当前不可重试。', 'error');
            }
            Audit::log('merchant_user', (int) $user['id'], 'video_generation.retry', 'generation_job', $id, $this->request->ip(), [
                'model' => $model,
            ]);
            return Web::redirect('/workspace/jobs', '视频任务已重新进入独立队列，失败时仍会自动退款。');
        }

        $completedCount = (int) Db::name('assets')->where('job_id', $id)->where('status', 'active')->count();
        $total = max((int) ($job['progress_total'] ?? 0), count(decode_json_list($job['asset_types'])));
        $hasItems = $itemQueue->hasItems($id);
        $reactivatedItems = $hasItems ? $itemQueue->activatePendingItems($id) : 0;
        if ($hasItems && $reactivatedItems === 0) {
            return Web::redirect('/workspace/jobs', '没有可重新生成的失败图片，任务状态未改变。', 'error');
        }
        $safetyReset = (string) ($job['safety_status'] ?? '') === 'failed' ? [
            'safety_status' => 'pending',
            'safety_attempts' => 0,
            'safety_next_retry_at' => null,
            'safety_checked_at' => null,
        ] : [];
        Db::name('generation_jobs')->where('id', $id)->update($safetyReset + [
            'status' => 'queued',
            'error_message' => null,
            'progress_current' => min($completedCount, $total),
            'progress_total' => $total,
            'progress_stage' => 'queued',
            'progress_message' => $completedCount > 0
                ? "已重新排队，将从 {$completedCount}/{$total} 继续生成"
                : '已重新排队，等待后台处理',
            'updated_at' => Web::now(),
        ]);
        Audit::log('merchant_user', (int) $user['id'], 'generation_job.retry', 'generation_job', $id, $this->request->ip(), [
            'model' => $model,
        ]);

        return Web::redirect('/workspace/jobs', '任务已重新进入队列，点数充足时会自动继续处理。');
    }

    public function cancelJob(int $id, GenerationItemQueue $itemQueue): Response
    {
        [$merchant, $user] = $this->identity();
        $result = $itemQueue->cancel($id, (int) $merchant['id']);
        if ($result === null) {
            return Web::redirect('/workspace/jobs', '任务已提交上游或已经结束，当前不能取消；生成失败后可手动重新生成。', 'error');
        }
        $job = Db::name('generation_jobs')->where('id', $id)->where('merchant_id', $merchant['id'])->find();
        if ((int) ($job['detail_page_project_id'] ?? 0) > 0) {
            Db::name('detail_page_projects')->where('id', (int) $job['detail_page_project_id'])
                ->where('merchant_id', $merchant['id'])->update(['status' => 'draft', 'updated_at' => Web::now()]);
        }
        Audit::log('merchant_user', (int) $user['id'], 'generation_job.cancel', 'generation_job', $id, $this->request->ip(), $result);
        $isVideo = (string) ($job['output_kind'] ?? 'image') === 'video';
        return Web::redirect('/workspace/jobs', (int) $result['completed'] > 0
            ? '任务已取消，已完成的图片已保留，未使用点数已退回。'
            : ($isVideo ? '视频任务已取消，预扣点数会由视频队列自动退回。' : '任务已取消，未使用点数已退回。'));
    }

    public function hideFailedJob(int $id): Response
    {
        [$merchant, $user] = $this->identity();
        $job = Db::name('generation_jobs')->where('id', $id)->where('merchant_id', $merchant['id'])->find();
        if (!$job || $job['status'] !== 'failed' || !empty($job['merchant_hidden_at'])) {
            return Web::redirect('/workspace/jobs', '只有失败任务可以从列表中删除。', 'error');
        }
        $hiddenAt = Web::now();
        Db::name('generation_jobs')->where('id', $id)->update(['merchant_hidden_at' => $hiddenAt, 'updated_at' => $hiddenAt]);
        Audit::log('merchant_user', (int) $user['id'], 'generation_job.hide_failed', 'generation_job', $id, $this->request->ip(), [
            'job_no' => (string) $job['job_no'], 'status' => (string) $job['status'],
        ]);
        return Web::redirect('/workspace/jobs', '失败任务已从你的列表中删除，后台审计记录仍然保留。');
    }

    public function usage(PlanService $plans): Response
    {
        [$merchant, $user] = $this->identity();
        $ledger = Db::name('credit_ledger')->where('merchant_id', $merchant['id'])->order('id', 'desc')->limit(100)->select()->toArray();
        $usedCredits = abs((int) Db::name('credit_ledger')->where('merchant_id', $merchant['id'])
            ->whereIn('type', ['consume', 'recognition_consume', 'content_violation_penalty'])->sum('amount'));

        $subscription = $plans->currentSubscription((int) $merchant['id']);
        return $this->render('merchant/usage', compact('merchant', 'user', 'ledger', 'usedCredits', 'subscription') + ['pageTitle' => '点数余额与用量']);
    }

    public function plans(PlanService $plans): Response
    {
        [$merchant, $user] = $this->identity();
        $catalog = $plans->catalogForMerchant((int) $merchant['id']);
        $availablePlans = $catalog['plans'];
        $subscription = $catalog['subscription'];
        $generationUsageToday = $subscription ? $plans->generationUsageToday((int) $merchant['id']) : 0;

        return $this->render('merchant/plans', compact('merchant', 'user', 'availablePlans', 'subscription', 'generationUsageToday') + ['pageTitle' => '套餐购买']);
    }

    public function purchasePlan(int $id, PlanService $plans): Response
    {
        [$merchant, $user] = $this->identity();
        try {
            $purchase = $plans->purchase($id, (int) $merchant['id'], (int) $user['id']);
        } catch (\Throwable $exception) {
            return Web::redirect('/workspace/plans', $exception->getMessage(), 'error');
        }
        Audit::log('merchant_user', (int) $user['id'], 'plan.purchase', 'merchant_subscription', (int) $purchase['id'], $this->request->ip(), [
            'plan_name' => $purchase['planName'],
            'price_credits' => $purchase['priceCredits'],
            'expires_at' => $purchase['expiresAt'],
            'purchase_mode' => $purchase['purchaseMode'],
            'upgrade_credit' => $purchase['upgradeCredit'],
        ]);

        $message = $purchase['purchaseMode'] === 'upgrade' ? '套餐升级成功' : '套餐购买成功';
        return Web::redirect('/workspace/plans', $message . '，有效期至 ' . $purchase['expiresAt'] . '。');
    }

    public function account(PlanService $plans): Response
    {
        [$merchant, $user] = $this->identity();
        if ($this->request->get('reset_email') === '1') {
            Session::delete('pending_account_email');
        }
        $members = Db::name('merchant_users')->where('merchant_id', $merchant['id'])->order('id', 'asc')->select()->toArray();
        $subscription = $plans->currentSubscription((int) $merchant['id']);

        return $this->render('merchant/account', compact('merchant', 'user', 'members', 'subscription') + [
            'pageTitle' => '账户设置',
            'pendingAccountEmail' => (string) Session::get('pending_account_email', ''),
        ]);
    }

    private function canvasMask(string $payload, array $image): array
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $payload, $matches)) {
            throw new \RuntimeException('请先在图片上涂抹需要修改的区域。');
        }
        $content = base64_decode($matches[1], true);
        if ($content === false || $content === '' || strlen($content) > 8 * 1024 * 1024) {
            throw new \RuntimeException('画布遮罩无效或超过 8MB。');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        if ($mime !== 'image/png') {
            throw new \RuntimeException('画布遮罩必须是 PNG 图片。');
        }
        $maskSize = getimagesizefromstring($content);
        $imageSize = getimagesizefromstring((string) $image['content']);
        if (!$maskSize || !$imageSize || $maskSize[0] !== $imageSize[0] || $maskSize[1] !== $imageSize[1]) {
            throw new \RuntimeException('画布遮罩尺寸必须与原素材完全一致。');
        }

        return ['content' => $content, 'mime' => 'image/png', 'filename' => 'canvas-mask.png'];
    }

    private function storeCanvasMask(string $jobNo, array $mask): string
    {
        $directory = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'canvas-masks';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('服务器无法创建画布遮罩目录。');
        }
        $filename = strtolower($jobNo) . '.png';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, (string) $mask['content'], LOCK_EX) === false) {
            throw new \RuntimeException('服务器无法保存画布遮罩。');
        }

        return $filename;
    }

    private function deleteCanvasMask(string $filename): void
    {
        $safeName = basename($filename);
        if ($safeName !== $filename || $safeName === '') {
            return;
        }
        $path = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'canvas-masks' . DIRECTORY_SEPARATOR . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function assetReturnPath(string $fallback = '/workspace/assets'): string
    {
        $returnTo = trim((string) $this->request->post('return_to', ''));
        if ($returnTo === '' || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
            return $fallback;
        }
        $parts = parse_url($returnTo);
        if ($parts === false || ($parts['path'] ?? '') !== '/workspace/assets' || isset($parts['host']) || isset($parts['scheme'])) {
            return $fallback;
        }

        return '/workspace/assets' . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    private function skuReturnPath(string $fallback = '/workspace/skus'): string
    {
        $returnTo = trim((string) $this->request->post('return_to', ''));
        if ($returnTo === '' || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
            return $fallback;
        }
        $parts = parse_url($returnTo);
        if ($parts === false || ($parts['path'] ?? '') !== '/workspace/skus' || isset($parts['host']) || isset($parts['scheme'])) {
            return $fallback;
        }

        return '/workspace/skus' . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    private function assetArchiveFolder(array $asset): string
    {
        $folders = [];
        $skuId = (int) ($asset['sku_id'] ?? 0);
        $detailProjectId = (int) ($asset['detail_page_project_id'] ?? 0);
        $platform = platform_label((string) ($asset['job_platform'] ?? '')) ?: '其他平台';
        if ($skuId > 0) {
            $folders[] = $this->safeArchiveName(trim((string) ($asset['sku_code'] ?: 'SKU') . '-' . (string) ($asset['sku_name'] ?: '未命名商品')));
            $folders[] = $this->safeArchiveName($platform);
        } else {
            $folders[] = $this->safeArchiveName($platform);
        }
        if ($detailProjectId > 0) {
            $folders[] = $this->safeArchiveName('详情页-DETAIL-' . $detailProjectId . '-' . (string) ($asset['detail_project_name'] ?: '未命名方案'));
        } else {
            $folders[] = '常规素材';
        }

        return implode('/', $folders);
    }

    private function assetManifestCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('无法创建素材清单');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['素材编号', 'SKU 编码', '商品名称', '平台', '详情页方案', '素材类型', '任务编号', '生成时间', '压缩包路径'], ',', '"', '\\', "\r\n");
            foreach ($rows as $row) {
                fputcsv($stream, $row, ',', '"', '\\', "\r\n");
            }
            rewind($stream);
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function safeArchiveName(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', trim($name)) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name, " .\t\n\r\0\x0B");
        return mb_substr($name !== '' ? $name : '素材包', 0, 80);
    }

    private function creativeReadinessFailure(
        ProductCreativeReadinessService $readiness,
        array $state,
        string $fallback
    ): Response {
        if ($this->request->isAjax()
            || str_contains(strtolower((string) $this->request->header('accept', '')), 'application/json')) {
            return Response::create($readiness->responsePayload($state), 'json')
                ->code(409)
                ->header(['Cache-Control' => 'no-store']);
        }
        return Web::redirect(
            $readiness->recoveryUrl($state, $fallback),
            $readiness->humanMessage($state),
            'error'
        );
    }

    private function freeCreateWantsJson(): bool
    {
        return $this->request->isAjax()
            || str_contains(strtolower((string) $this->request->header('accept', '')), 'application/json');
    }

    private function freeCreateError(
        string $message,
        int $status = 422,
        string $errorCode = 'validation_failed',
        string $stage = 'validation',
        bool $jobCreated = false,
        bool $creditsReserved = false,
        string $refundStatus = 'not_applicable',
        bool $retryable = false,
        ?int $jobId = null
    ): Response
    {
        if ($this->freeCreateWantsJson()) {
            return Response::create(GenerationOperationResult::failure(
                $errorCode,
                $stage,
                localized_job_error($message),
                $jobCreated,
                $creditsReserved,
                $refundStatus,
                $retryable,
                null,
                $jobId
            ), 'json')->code($status)->header(['Cache-Control' => 'no-store']);
        }
        return Web::redirect('/workspace/free-create', localized_job_error($message), 'error');
    }

    private function freeCreateSuccess(array $job, bool $duplicate = false): Response
    {
        $jobId = max(0, (int) ($job['id'] ?? 0));
        $redirectUrl = '/workspace/free-create?project_id=' . $jobId;
        $message = $duplicate
            ? '这条创作需求已经发送，已恢复原任务进度。'
            : ((string) ($job['status'] ?? '') === 'pending_gateway'
                ? '创作任务已保存，可继续输入；服务恢复后可在生成进度中重试。'
                : '消息已发送给所选模型，可继续输入下一条创作需求。');
        $route = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $route = is_array($route) ? $route : [];
        $modelName = trim((string) ($route['displayName'] ?? '')) ?: (string) ($job['model'] ?? '');
        if ($this->freeCreateWantsJson()) {
            return Response::create(GenerationOperationResult::success([
                'stage' => 'queued',
                'taskCreated' => true,
                'jobCreated' => true,
                'jobId' => $jobId,
                'duplicate' => $duplicate,
                'message' => $message,
                'jobNo' => (string) ($job['job_no'] ?? ''),
                'status' => (string) ($job['status'] ?? 'queued'),
                'progressCurrent' => max(0, (int) ($job['progress_current'] ?? 0)),
                'progressTotal' => max(1, (int) ($job['progress_total'] ?? 1)),
                'progressMessage' => (string) ($job['progress_message'] ?? ''),
                'modelName' => $modelName,
                'createdAt' => (string) ($job['created_at'] ?? ''),
                'redirectUrl' => $redirectUrl,
            ]), 'json')->header(['Cache-Control' => 'no-store']);
        }
        return Web::redirect($redirectUrl, $message);
    }

    private function freeSubmissionPayloadHash(array $payload): string
    {
        $encoded = json_encode(
            ['schema_version' => 'free_submission_v1'] + $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('本次创作需求无法生成稳定提交指纹。');
        }
        return hash('sha256', $encoded);
    }

    private function freeSubmissionMatches(array $job, string $payloadHash): bool
    {
        $stored = trim((string) ($job['submission_payload_hash'] ?? ''));
        // Rows created before 0.50.0 have no payload hash; preserve their
        // existing idempotent recovery semantics without rewriting history.
        return $stored === '' || hash_equals($stored, $payloadHash);
    }

    private function cleanupUnclaimedFreeReferences(AssetStorage $storage, array $references): void
    {
        $seen = [];
        foreach ($references as $reference) {
            foreach (['url', 'backup_url'] as $key) {
                $url = trim((string) ($reference[$key] ?? ''));
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                try {
                    $storage->deleteStoredUrl($url);
                } catch (\Throwable) {
                    // The database transaction is already rolled back. Storage
                    // cleanup is best-effort and must not hide the original error.
                }
            }
        }
    }

    private function identity(): array
    {
        $merchantId = (int) Session::get('merchant_id');
        $userId = (int) Session::get('merchant_user_id');
        return [
            Db::name('merchants')->where('id', $merchantId)->find(),
            Db::name('merchant_users')->where('id', $userId)->find(),
        ];
    }

    private function availableGenerationCredits(int $merchantId, int $balance): int
    {
        $pendingJobs = Db::name('generation_jobs')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['queued', 'pending_gateway'])
            ->field('estimated_credits,actual_credits')
            ->select()
            ->toArray();
        $committed = array_reduce($pendingJobs, static function (int $total, array $job): int {
            return $total + max(0, (int) $job['estimated_credits'] - (int) $job['actual_credits']);
        }, 0);

        return max(0, $balance - $committed);
    }

    private function reservePromptCredits(int $merchantId, int $credits, string $reference, string $note = 'AI 图片提示词润色'): array
    {
        return Db::transaction(function () use ($merchantId, $credits, $reference, $note): array {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant || $merchant['status'] !== 'active') {
                throw new \RuntimeException('商户账户当前不可用。');
            }
            $availableCredits = $this->availableGenerationCredits($merchantId, (int) $merchant['credit_balance']);
            if ($availableCredits < $credits) {
                throw new \RuntimeException("点数不足，AI 提示词润色需要 {$credits} 点。本次未执行，也不会扣点。");
            }
            $balance = (int) $merchant['credit_balance'] - $credits;
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            $ledgerId = Db::name('credit_ledger')->insertGetId([
                'merchant_id' => $merchantId,
                'type' => 'prompt_optimization_consume',
                'amount' => -$credits,
                'balance_after' => $balance,
                'source' => 'prompt_optimization',
                'reference_type' => 'prompt_optimization',
                'reference_id' => $reference,
                'note' => mb_strimwidth($note, 0, 120, ''),
                'created_at' => $now,
            ]);
            return [(int) $ledgerId, $balance];
        });
    }

    private function refundPromptCredits(int $merchantId, int $credits, string $reference, string $note = 'AI 图片提示词润色'): int
    {
        return Db::transaction(function () use ($merchantId, $credits, $reference, $note): int {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant) {
                throw new \RuntimeException('商户账户不存在，无法退回提示词润色点数。');
            }
            $existingRefund = Db::name('credit_ledger')->where('merchant_id', $merchantId)
                ->where('type', 'prompt_optimization_refund')->where('reference_id', $reference)->find();
            if ($existingRefund) {
                return (int) $merchant['credit_balance'];
            }
            $balance = (int) $merchant['credit_balance'] + $credits;
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            Db::name('credit_ledger')->insert([
                'merchant_id' => $merchantId,
                'type' => 'prompt_optimization_refund',
                'amount' => $credits,
                'balance_after' => $balance,
                'source' => 'prompt_optimization',
                'reference_type' => 'prompt_optimization',
                'reference_id' => $reference,
                'note' => mb_strimwidth($note . '失败退款', 0, 160, ''),
                'created_at' => $now,
            ]);
            return $balance;
        });
    }

    private function prepareUploadedSourceImage(
        ContentSafetyService $contentSafety,
        array $merchant,
        array $user,
        string $source
    ): ?array
    {
        $uploadedFiles = $this->request->file();
        $file = is_array($uploadedFiles) ? ($uploadedFiles['source_image'] ?? null) : null;
        if (!$file) {
            return null;
        }
        $image = $this->normalizedUploadedProductImage($file);

        $contentSafety->enforceImage($merchant, $user, [
            'content' => $image['content'],
            'mime' => $image['mime'],
            'filename' => 'source-image',
        ], $source, $this->request->ip());

        return $image;
    }

    private function normalizedUploadedProductImage(object $file): array
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = (string) $file->getMime();
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > 24 * 1024 * 1024 || !in_array($mime, $allowedMimes, true)) {
            throw new \RuntimeException('商品图仅支持 JPG、PNG、WebP，原文件不能超过 24MB。');
        }
        $path = (string) $file->getPathname();
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new \RuntimeException('读取上传的商品图失败。');
        }
        $dimensions = getimagesizefromstring($content);
        if (!$dimensions || (int) $dimensions[0] <= 0 || (int) $dimensions[1] <= 0) {
            throw new \RuntimeException('上传的商品图无法识别。');
        }
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        if ($size <= 8 * 1024 * 1024 && max($width, $height) <= 2400) {
            return ['content' => $content, 'mime' => $mime, 'filename' => 'product-image'];
        }
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('服务器缺少 GD 扩展，无法优化手机大图。');
        }
        $source = @imagecreatefromstring($content);
        if ($source === false) {
            throw new \RuntimeException('上传的商品图无法解码。');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $rotated = match ($orientation) {
                3 => imagerotate($source, 180, 0),
                6 => imagerotate($source, -90, 0),
                8 => imagerotate($source, 90, 0),
                default => false,
            };
            if ($rotated !== false) {
                $source = $rotated;
                $width = imagesx($source);
                $height = imagesy($source);
            }
        }
        $scale = min(1, 2400 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            throw new \RuntimeException('服务器无法创建商品图优化画布。');
        }
        $outputMime = $mime === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
        if ($outputMime === 'image/webp') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
        } else {
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start();
        $encoded = $outputMime === 'image/jpeg' ? imagejpeg($target, null, 86) : imagewebp($target, null, 86);
        $optimized = (string) ob_get_clean();
        unset($target, $source);
        if (!$encoded || $optimized === '' || strlen($optimized) > 10 * 1024 * 1024) {
            throw new \RuntimeException('商品图自动优化失败，请先将图片压缩到 10MB 以内。');
        }

        return ['content' => $optimized, 'mime' => $outputMime, 'filename' => 'product-image'];
    }

    private function skuSafetyText(array $sku): string
    {
        return implode("\n", [
            (string) ($sku['name'] ?? ''),
            (string) ($sku['category'] ?? ''),
            implode("\n", decode_json_list($sku['selling_points'] ?? [])),
            (string) ($sku['creative_brief'] ?? ''),
            (string) ($sku['product_description'] ?? ''),
        ]);
    }

    private function skuImageModel(NewApiClient $newApi, array $sku): string
    {
        $model = trim((string) ($sku['preferred_image_model'] ?? ''));
        if ($model === '') {
            return $newApi->defaultModel();
        }
        foreach ($newApi->modelCatalog() as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $model && !empty($candidate['imageGeneration']) && !empty($candidate['imageEdit'])) {
                return $model;
            }
        }
        return $newApi->defaultModel();
    }

    private function requestedGenerationModelId(): ?int
    {
        $id = max(0, (int) $this->request->post('generation_model_id', 0));
        return $id > 0 ? $id : null;
    }

    private function customMaterialRequests(string $value): array
    {
        $requests = preg_split('/\r\n|\r|\n/u', $value) ?: [];
        $requests = array_values(array_filter(array_map(static fn (string $request): string => mb_strimwidth(trim($request), 0, 300, ''), $requests)));
        if (count($requests) > 4) {
            throw new \RuntimeException('一次最多添加 4 条补充上新素材需求。');
        }

        return $requests;
    }
}
