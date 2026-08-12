<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class VideoJobService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly PlanService $plans,
        private readonly CreativeModelService $models,
        private readonly MediaWorkflowEngine $workflowEngine
    ) {
    }

    public function queue(array $merchant, array $user, array $input): array
    {
        $merchantId = (int) ($merchant['id'] ?? 0);
        $skuId = max(0, (int) ($input['sku_id'] ?? 0));
        $capability = (string) ($input['capability'] ?? AiCapability::VIDEO_IMAGE_TO_VIDEO);
        if (!in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)) {
            throw new \RuntimeException('视频任务能力无效。');
        }

        $capacity = $this->plans->canStartJobs($merchantId);
        if (!$capacity['allowed']) {
            throw new \RuntimeException((string) $capacity['message']);
        }

        $modelSelection = $this->models->resolveTask(
            $capability,
            max(0, (int) ($input['requested_model_id'] ?? 0)),
            $merchantId,
            $this->pricing->videoGenerationCreditsPerSecond()
        );
        $pricingSnapshot = $modelSelection['price'];
        $requestedDuration = (int) ($input['duration_seconds'] ?? 5);
        $duration = in_array($requestedDuration, [5, 10], true)
            ? $requestedDuration
            : 5;
        $estimatedCredits = $this->pricing->creditsForUnits($pricingSnapshot, $duration);
        $availableCredits = $this->availableCredits($merchantId, (int) ($merchant['credit_balance'] ?? 0));
        if ($availableCredits < $estimatedCredits) {
            throw new \RuntimeException("可用点数不足，当前可用 {$availableCredits} 点，{$duration} 秒视频预计需要 {$estimatedCredits} 点。本次未创建任务。");
        }

        $model = (string) $modelSelection['model'];
        $workflowSnapshot = $this->workflowEngine->snapshotForVideo(
            $capability,
            isset($modelSelection['resolvedModelId']) ? (int) $modelSelection['resolvedModelId'] : null,
            (string) ($modelSelection['route']['resolvedGateway'] ?? 'sub2api')
        );
        $workflowPublicSnapshot = $this->workflowEngine->publicSnapshot($workflowSnapshot);
        $workflowConfiguration = $this->workflowEngine->configuration();
        $usesComfy = (string) ($workflowSnapshot['workflow_engine'] ?? 'direct') === 'comfyui';
        $gatewayConfigured = (bool) ($usesComfy
            ? ($workflowConfiguration['comfyui']['configured'] ?? false)
            : ($workflowConfiguration['direct']['sub2ApiConfigured'] ?? false));
        $gatewayReady = $this->workflowEngine->ready($workflowSnapshot, $model);
        $status = $gatewayReady ? 'queued' : 'pending_gateway';
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];
        $context['duration_seconds'] = $duration;
        $context['capability'] = $capability;
        $context['workflow'] = $workflowPublicSnapshot;
        $routeSnapshot = $modelSelection['route'];
        $routeSnapshot['workflow'] = $workflowPublicSnapshot;
        $now = Web::now();
        $assetType = trim((string) ($input['asset_type'] ?? 'product_video')) ?: 'product_video';
        $promptText = trim((string) ($input['prompt'] ?? ''));
        if ($promptText === '') {
            $promptText = trim((string) ($input['creative_brief'] ?? ''));
        }
        if ($promptText === '') {
            $promptText = '基于已确认商品事实生成商品展示视频，保持商品身份与外观一致。';
        }
        $jobId = Db::transaction(function () use (
            $merchantId,
            $merchant,
            $user,
            $input,
            $modelSelection,
            $pricingSnapshot,
            $estimatedCredits,
            $model,
            $status,
            $gatewayReady,
            $gatewayConfigured,
            $context,
            $routeSnapshot,
            $workflowSnapshot,
            $workflowPublicSnapshot,
            $duration,
            $now,
            $promptText
        ): int {
            $lockedMerchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$lockedMerchant || (string) $lockedMerchant['status'] !== 'active') {
                throw new \RuntimeException('商户账户当前不可用。');
            }
            $capacity = $this->plans->canStartJobs($merchantId);
            if (!$capacity['allowed']) {
                throw new \RuntimeException((string) $capacity['message']);
            }
            $availableCredits = $this->availableCredits($merchantId, (int) $lockedMerchant['credit_balance']);
            if ($availableCredits < $estimatedCredits) {
                throw new \RuntimeException("可用点数不足，当前可用 {$availableCredits} 点，视频预计需要 {$estimatedCredits} 点。本次未创建任务。");
            }

            $jobNo = 'VIDEO-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $skuId = max(0, (int) ($input['sku_id'] ?? 0));
            $assetType = trim((string) ($input['asset_type'] ?? 'product_video')) ?: 'product_video';
            $jobId = (int) Db::name('generation_jobs')->insertGetId([
                'merchant_id' => $merchantId,
                'sku_id' => $skuId > 0 ? $skuId : null,
                'detail_page_project_id' => null,
                'bundle_no' => null,
                'bundle_part' => null,
                'job_no' => $jobNo,
                'output_kind' => 'video',
                'platform' => (string) ($input['platform'] ?? 'douyin'),
                'language' => (string) ($input['language'] ?? 'zh-CN'),
                'asset_types' => json_encode([$assetType], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'task_brief' => trim((string) ($input['creative_brief'] ?? '')) ?: null,
                'task_context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'requested_model_id' => $modelSelection['requestedModelId'],
                'resolved_ai_model_id' => $modelSelection['resolvedModelId'],
                'route_snapshot_json' => json_encode($routeSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pricing_snapshot_json' => json_encode($pricingSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'submitted_by_user_id' => max(0, (int) ($user['id'] ?? 0)) ?: null,
                'submitted_ip' => trim((string) ($input['submitted_ip'] ?? '')) ?: null,
                'safety_status' => 'pending',
                'safety_attempts' => 0,
                'status' => $status,
                'estimated_credits' => $estimatedCredits,
                'actual_credits' => 0,
                'progress_current' => 0,
                'progress_total' => 100,
                'progress_stage' => $gatewayReady ? 'safety_pending' : 'pending_gateway',
                'progress_message' => $gatewayReady ? '视频任务已提交，等待后台安全检查' : '等待管理员配置可用视频模型',
                'model' => $model,
                'error_message' => $gatewayReady
                    ? null
                    : ((string) ($workflowSnapshot['workflow_engine'] ?? 'direct') === 'comfyui'
                        ? ($gatewayConfigured ? 'ComfyUI 工作流模板尚未通过测试' : '等待管理员配置 ComfyUI 工作流引擎')
                        : ($gatewayConfigured ? '视频模型尚无可用渠道' : '等待管理员配置模型网关')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('video_generation_requests')->insert([
                'job_id' => $jobId,
                'merchant_id' => $merchantId,
                'sku_id' => $skuId > 0 ? $skuId : null,
                'source_asset_id' => max(0, (int) ($input['source_asset_id'] ?? 0)) ?: null,
                'reference_image_url' => trim((string) ($input['reference_image_url'] ?? '')) ?: null,
                'reference_image_backup_url' => trim((string) ($input['reference_image_backup_url'] ?? '')) ?: null,
                'prompt' => $promptText,
                'motion_preset' => (string) ($input['motion_preset'] ?? 'product_showcase'),
                'ratio' => (string) ($input['ratio'] ?? '9:16'),
                'resolution' => (string) ($input['resolution'] ?? '720p'),
                'duration_seconds' => $duration,
                'generate_audio' => !empty($input['generate_audio']) ? 1 : 0,
                'provider_status' => 'queued',
                'provider_progress' => 0,
                'next_poll_at' => null,
                'request_payload_json' => json_encode([
                    'workflow' => $workflowSnapshot,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $jobId;
        });

        return [
            'jobId' => $jobId,
            'status' => $status,
            'gatewayReady' => $gatewayReady,
            'estimatedCredits' => $estimatedCredits,
            'durationSeconds' => $duration,
            'model' => $model,
            'modelSelection' => $modelSelection,
            'pricingSnapshot' => $pricingSnapshot,
            'workflowSnapshot' => $workflowPublicSnapshot,
        ];
    }

    private function availableCredits(int $merchantId, int $balance): int
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

}
