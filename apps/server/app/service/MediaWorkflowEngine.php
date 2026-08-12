<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class MediaWorkflowEngine
{
    public function __construct(
        private readonly NewApiClient $direct,
        private readonly ComfyUiClient $comfy,
        private readonly MediaWorkflowTemplateService $templates,
        private readonly AiMediaExecutionService $mediaExecution,
        private readonly ComfyWorkflowToken $workflowTokens,
        private readonly WorkflowReleaseService $workflowReleases,
        private readonly GenerationExecutionAttemptService $executionAttempts
    ) {
    }

    public function configuration(): array
    {
        return [
            'mode' => 'canvas_only',
            'direct' => $this->direct->configuration(),
            'comfyui' => $this->comfy->configuration(),
            'templates' => $this->templates->templates(),
        ];
    }

    public function snapshotForImage(
        string $capability,
        int $merchantId,
        string $model,
        string $profile = 'auto',
        ?int $modelId = null,
        string $gateway = ''
    ): array
    {
        $profile = strtolower(trim($profile)) ?: 'auto';
        $canvas = in_array($profile, ['image_edit', 'canvas'], true);
        $productCode = $canvas ? 'canvas_edit' : match ($profile) {
            'free', 'free_create' => 'free_create',
            'detail_page', 'detail' => 'detail_page',
            'listing_bundle', 'assets' => 'listing_bundle',
            default => 'product_image',
        };
        $releasedWorkflow = $canvas ? $this->workflowReleases->snapshotForProduct('canvas_edit') : null;
        $outputMap = is_array($releasedWorkflow['workflow_output_map'] ?? null)
            ? $releasedWorkflow['workflow_output_map']
            : [];
        $providerOwner = $canvas
            ? (trim((string) ($outputMap['provider_owner'] ?? 'sub2api')) ?: 'sub2api')
            : 'thinkphp';
        $providerVersion = $providerOwner === 'sub2api' ? 'v2' : 'v1';
        $supportedModels = array_values(array_filter(array_map('strval', (array) ($outputMap['supported_models'] ?? []))));
        $supportedAssetTypes = array_values(array_filter(array_map('strval', (array) ($outputMap['asset_types'] ?? []))));
        $supportedAssetTypePrefixes = array_values(array_filter(array_map('strval', (array) ($outputMap['asset_type_prefixes'] ?? []))));
        $modelSupported = $supportedModels === [] || in_array(trim($model), $supportedModels, true);
        $sub2ApiReady = $this->mediaExecution->hasCapabilityRoute($modelId, $capability);
        $comfyReady = $canvas
            && $sub2ApiReady
            && $this->comfy->configuration()['configured']
            && $releasedWorkflow !== null
            && in_array((string) ($releasedWorkflow['workflow_release_status'] ?? ''), ['published', 'tested'], true)
            && $modelSupported;
        $engine = $canvas ? 'comfyui' : 'direct';
        $executionLane = $engine === 'comfyui'
            ? ($providerVersion === 'v2' ? 'comfyui_sub2api_v2' : 'comfyui_workflow')
            : 'direct_sub2api';
        $snapshot = [
            'routing_policy_version' => 1,
            'product_code' => $productCode,
            'workflow_engine_mode' => 'product_release',
            'workflow_engine' => $engine,
            'execution_lane' => $executionLane,
            'direct_transport' => 'sub2api',
            'workflow_template_code' => $engine === 'direct'
                ? 'direct_image_v1'
                : (string) ($releasedWorkflow['workflow_template_code'] ?? ''),
            'workflow_template_version' => $engine === 'direct'
                ? 1
                : max(0, (int) ($releasedWorkflow['workflow_template_version'] ?? 0)),
            'workflow_template_hash' => $engine === 'direct'
                ? hash('sha256', 'direct_image_v1')
                : (string) ($releasedWorkflow['workflow_template_hash'] ?? ''),
            'workflow_capability' => $capability,
            'workflow_profile' => $profile,
            'workflow_ready' => $engine === 'comfyui' ? $comfyReady : $sub2ApiReady,
            'workflow_provider_owner' => $providerOwner,
            'workflow_provider_version' => $providerVersion,
            'workflow_comfy_eligible' => $canvas,
            'workflow_sub2api_ready' => $sub2ApiReady,
            'workflow_supported_models' => $supportedModels,
            'workflow_asset_types' => $supportedAssetTypes,
            'workflow_asset_type_prefixes' => $supportedAssetTypePrefixes,
            'workflow_requires_reference' => !empty($outputMap['requires_reference']),
            'workflow_fallback_engine' => 'none',
            'workflow_direct_gateway' => 'sub2api',
            'workflow_direct_model_id' => $modelId,
            'captured_at' => date('Y-m-d H:i:s'),
        ];
        if ($engine === 'comfyui' && $releasedWorkflow !== null) {
            $snapshot += $releasedWorkflow;
        }
        return $snapshot;
    }

    public function snapshotForVideo(
        string $capability,
        ?int $modelId = null,
        string $gateway = ''
    ): array
    {
        $sub2ApiReady = $this->mediaExecution->hasCapabilityRoute($modelId, $capability);
        $snapshot = [
            'routing_policy_version' => 1,
            'product_code' => 'product_video',
            'workflow_engine_mode' => 'direct_only',
            'workflow_engine' => 'direct',
            'execution_lane' => 'direct_sub2api',
            'direct_transport' => 'sub2api',
            'workflow_template_code' => 'direct_video_v1',
            'workflow_template_version' => 1,
            'workflow_template_hash' => hash('sha256', 'direct_video_v1'),
            'workflow_capability' => $capability,
            'workflow_ready' => $sub2ApiReady,
            'workflow_provider_owner' => 'thinkphp',
            'workflow_provider_version' => 'v1',
            'workflow_sub2api_ready' => $sub2ApiReady,
            'workflow_fallback_engine' => 'none',
            'workflow_direct_gateway' => 'sub2api',
            'workflow_direct_model_id' => $modelId,
            'captured_at' => date('Y-m-d H:i:s'),
        ];
        return $snapshot;
    }

    public function publicSnapshot(array $snapshot): array
    {
        unset(
            $snapshot['workflow_definition'],
            $snapshot['workflow_input_map'],
            $snapshot['workflow_output_map']
        );
        return $snapshot;
    }

    public function ready(array $snapshot, string $model): bool
    {
        if ((string) ($snapshot['workflow_engine'] ?? 'direct') === 'comfyui') {
            if ((string) ($snapshot['workflow_provider_owner'] ?? '') === 'sub2api') {
                return !empty($snapshot['workflow_ready'])
                    && $this->comfy->configuration()['configured'];
            }
            return !empty($snapshot['workflow_ready'])
                && $this->comfy->configuration()['configured']
                && $this->direct->hasAvailableModels(
                    $model,
                    max(0, (int) ($snapshot['workflow_direct_model_id'] ?? 0)) ?: null,
                    'sub2api',
                    $this->imageCapabilityFromSnapshot($snapshot)
                );
        }
        return $this->direct->hasAvailableModels(
            $model,
            max(0, (int) ($snapshot['workflow_direct_model_id'] ?? 0)) ?: null,
            trim((string) ($snapshot['workflow_direct_gateway'] ?? '')) ?: null,
            $this->imageCapabilityFromSnapshot($snapshot)
        );
    }

    public function submitVideo(array $job, array $request, string $referenceUrl): array
    {
        $snapshot = $this->requestSnapshot($request);
        $result = $this->direct->createVideo(
            (string) $job['model'],
            (string) $request['prompt'],
            $referenceUrl !== '' ? [$referenceUrl] : [],
            (string) $request['ratio'],
            (string) $request['resolution'],
            (int) $request['duration_seconds'],
            (int) $request['generate_audio'] === 1,
            'video-job-' . (int) $job['id'],
            max(0, (int) ($snapshot['workflow_direct_model_id'] ?? $job['resolved_ai_model_id'] ?? 0)) ?: null,
            trim((string) ($snapshot['workflow_direct_gateway'] ?? '')) ?: null,
            [
                'job_id' => (int) $job['id'],
                'call_key' => 'video-submit:' . (int) $job['id'],
                'require_sub2api' => true,
            ]
        );
        $result['workflowSnapshot'] = $snapshot + ['workflow_engine' => 'direct'];
        return $result;
    }

    public function videoStatus(array $request): array
    {
        $snapshot = $this->requestSnapshot($request);
        $requestId = trim((string) ($request['provider_request_id'] ?? ''));
        $payload = json_decode((string) ($request['request_payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $capability = empty($request['reference_image_url'])
            ? AiCapability::VIDEO_TEXT_TO_VIDEO
            : AiCapability::VIDEO_IMAGE_TO_VIDEO;
        return $this->direct->videoStatus(
            $requestId,
            trim((string) ($snapshot['workflow_direct_gateway'] ?? '')) ?: null,
            max(0, (int) ($snapshot['workflow_direct_model_id'] ?? 0)) ?: null,
            trim((string) ($payload['model'] ?? $request['model'] ?? '')) ?: null,
            $capability,
            [
                'job_id' => (int) ($request['job_id'] ?? 0),
                'call_key' => 'video-poll:' . (int) ($request['job_id'] ?? 0) . ':' . $requestId,
                'require_sub2api' => true,
            ]
        );
    }

    /**
     * Return null only for a task that was frozen to the Direct lane.
     * A ComfyUI task never falls back to Direct, before or after submission.
     */
    public function requestImage(
        array $job,
        string $prompt,
        ?array $referenceImage,
        ?array $mask,
        string $size,
        array $context = []
    ): ?array {
        $snapshot = $this->imageSnapshotFromJob($job);
        if ((string) ($snapshot['workflow_engine'] ?? 'direct') !== 'comfyui') {
            return null;
        }
        if (!$this->shouldUseImageWorkflow($snapshot, $job, $referenceImage, $mask, $context)) {
            return $this->workflowFailure(
                $job,
                'preflight',
                '当前任务冻结的 ComfyUI 工作流不满足执行条件，已在提交上游前终止。'
            );
        }

        $existingRequestId = trim((string) ($job['_generation_request_id'] ?? ''));
        $promptId = str_starts_with($existingRequestId, 'comfyui:')
            ? substr($existingRequestId, strlen('comfyui:'))
            : '';
        if ($promptId === '') {
            $references = $this->uploadReferences($job, $referenceImage);
            if (!$references['ok']) {
                return $this->workflowFailure(
                    $job,
                    'reference_upload',
                    (string) $references['message']
                );
            }
            $uploadedMask = $this->uploadMask($job, $mask);
            if (!$uploadedMask['ok']) {
                return $this->workflowFailure(
                    $job,
                    'mask_upload',
                    (string) $uploadedMask['message']
                );
            }
            $seed = $this->deterministicSeed($job, $context);
            $modelId = max(0, (int) (
                $snapshot['workflow_direct_model_id']
                ?? $job['resolved_ai_model_id']
                ?? 0
            ));
            $capability = $this->imageCapabilityFromJob($job, $referenceImage);
            $callbackToken = $this->workflowTokens->issue(
                (int) $job['id'],
                (int) ($job['_generation_item_id'] ?? 0),
                $modelId,
                $capability,
                $prompt,
                $size,
                $seed,
                time() + (int) $this->comfy->configuration()['workflowTimeoutSeconds'] + 180,
                'job',
                $this->referenceFingerprint($referenceImage)
            );
            $variables = [
                'prompt' => $prompt,
                'negative_prompt' => '',
                'reference_images_json' => json_encode($references['references'], JSON_UNESCAPED_SLASHES),
                'mask_image' => (string) ($uploadedMask['reference'] ?? ''),
                'model' => (string) $job['model'],
                'model_id' => $modelId,
                'size' => $size,
                'seed' => $seed,
                'job_id' => (int) $job['id'],
                'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
                'callback_token' => $callbackToken,
                'output_prefix' => $this->outputPrefix($job, $context),
                'job_no' => (string) ($job['job_no'] ?? $job['id']),
                'workflow_code' => (string) ($snapshot['workflow_template_code'] ?? ''),
                'workflow_version' => max(1, (int) ($snapshot['workflow_template_version'] ?? 1)),
                'idempotency_key' => implode(':', [
                    'comfyui-image',
                    (string) ($job['job_no'] ?? $job['id']),
                    (string) ($job['_generation_item_id'] ?? 0),
                    (string) ($snapshot['workflow_template_code'] ?? ''),
                    (string) max(1, (int) ($snapshot['workflow_template_version'] ?? 1)),
                    'manual-retry',
                    (string) max(0, (int) ($job['_manual_retry_no'] ?? 0)),
                ]),
            ];
            try {
                $compiled = is_array($snapshot['workflow_definition'] ?? null)
                    ? $this->templates->compileCaptured($snapshot, $variables)
                    : $this->templates->compile((string) $snapshot['workflow_template_code'], $variables);
            } catch (Throwable $exception) {
                return $this->workflowFailure(
                    $job,
                    'template_compile',
                    $exception->getMessage()
                );
            }
            $submission = $this->comfy->submitWorkflow(
                $compiled['workflow'],
                'asset-factory-image-' . (int) $job['id'] . '-' . (int) ($context['position'] ?? 1),
                ['asset_factory' => [
                    'job_id' => (int) $job['id'],
                    'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
                    'asset_type' => (string) ($context['asset_type'] ?? ''),
                ]]
            );
            if (empty($submission['ok'])) {
                return $this->workflowFailure(
                    $job,
                    'workflow_submit',
                    (string) ($submission['message'] ?? 'ComfyUI 提交失败')
                );
            }
            $promptId = trim((string) ($submission['data']['id'] ?? ''));
            if ($promptId === '') {
                return $this->workflowFailure(
                    $job,
                    'workflow_submit',
                    'ComfyUI 没有返回 prompt_id'
                );
            }
            $this->recordImageRequest($job, $promptId);
            Audit::log('system', 0, 'ai_workflow.image.submitted', 'generation_job', $job['id'], '127.0.0.1', [
                'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
                'asset_type' => (string) ($context['asset_type'] ?? ''),
                'template_code' => (string) ($snapshot['workflow_template_code'] ?? ''),
                'template_version' => (int) ($snapshot['workflow_template_version'] ?? 0),
                'template_hash' => (string) ($snapshot['workflow_template_hash'] ?? ''),
                'model' => (string) $job['model'],
                'provider_request_id' => $promptId,
            ]);
        }

        $result = $this->pollImage($job, $promptId);
        if (empty($result['ok'])) {
            if (!empty($result['lost']) && empty($context['_lost_prompt_recovery'])) {
                $recoveryJob = $job;
                $recoveryJob['_generation_request_id'] = '';
                Audit::log('system', 0, 'ai_workflow.image.recovered_after_runtime_restart', 'generation_job', $job['id'], '127.0.0.1', [
                    'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
                    'lost_prompt_id' => $promptId,
                    'recovery_mode' => 'same_paid_idempotency_identity',
                ]);
                return $this->requestImage(
                    $recoveryJob,
                    $prompt,
                    $referenceImage,
                    $mask,
                    $size,
                    $context + ['_lost_prompt_recovery' => true]
                );
            }
            if (!empty($result['pending'])) {
                return $result + ['retryable' => false, 'workflowAccepted' => true];
            }
            Audit::log('system', 0, 'ai_workflow.image.failed', 'generation_job', $job['id'], '127.0.0.1', [
                'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
                'provider_request_id' => $promptId,
                'message' => (string) ($result['message'] ?? ''),
            ]);
            return $result + ['retryable' => false, 'workflowAccepted' => true];
        }
        Audit::log('system', 0, 'ai_workflow.image.completed', 'generation_job', $job['id'], '127.0.0.1', [
            'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
            'provider_request_id' => $promptId,
            'template_code' => (string) ($snapshot['workflow_template_code'] ?? ''),
        ]);
        return $result;
    }

    public function testImageTemplate(int $templateId, ?int $requestedModelId = null): array
    {
        $template = $this->templates->byId($templateId);
        if ($template === null || (string) ($template['media_type'] ?? '') !== 'image') {
            return ['ok' => false, 'message' => '当前模板不是可测试的图片工作流。'];
        }
        if (!in_array((string) ($template['last_test_status'] ?? ''), ['dependencies_ready', 'ready'], true)) {
            return ['ok' => false, 'message' => '请先完成节点依赖检查，再执行真实图片测试。'];
        }
        $outputMap = is_array($template['outputMap'] ?? null) ? $template['outputMap'] : [];
        $requiresReference = !empty($outputMap['requires_reference']);
        $capability = $requiresReference ? AiCapability::IMAGE_EDIT : AiCapability::IMAGE_GENERATION;
        $testModel = $this->mediaExecution->testModel(
            $capability,
            (array) ($outputMap['supported_models'] ?? []),
            $requestedModelId
        );
        if ($testModel === null) {
            return ['ok' => false, 'message' => '请先在 Sub2API 发布可用的图片模型，再执行真实工作流测试。'];
        }
        $model = (string) $testModel['model_id'];
        $modelId = (int) $testModel['id'];
        $startedAt = microtime(true);
        $promptId = '';
        $inputSnapshot = [
            'workflow_code' => (string) $template['code'],
            'workflow_version' => max(1, (int) ($template['version'] ?? 1)),
            'model' => $model,
            'capability' => $capability,
            'size' => '1024x1024',
            'requires_reference' => $requiresReference,
            'reference_count' => 0,
        ];

        try {
            $references = [];
            if ($requiresReference) {
                $reference = $this->systemTestImage();
                $uploaded = $this->comfy->uploadImage(
                    $reference,
                    'system-product-reference.png',
                    'asset-factory/system-tests'
                );
                if (empty($uploaded['ok'])) {
                    throw new RuntimeException((string) ($uploaded['message'] ?? '测试参考图上传失败。'));
                }
                $references[] = (string) $uploaded['reference'];
            }
            $prompt = $this->workflowTestPrompt((string) $template['code'], $requiresReference);
            $seed = random_int(1, 2147483647);
            $size = '1024x1024';
            $inputSnapshot += [
                'seed' => $seed,
                'prompt_summary' => mb_strimwidth($prompt, 0, 1000, ''),
            ];
            $inputSnapshot['reference_count'] = count($references);
            $compiled = $this->templates->compileForTest($templateId, [
                'prompt' => $prompt,
                'negative_prompt' => '',
                'reference_images_json' => json_encode($references, JSON_UNESCAPED_SLASHES),
                'mask_image' => '',
                'model' => $model,
                'model_id' => $modelId,
                'size' => $size,
                'seed' => $seed,
                'job_id' => 0,
                'generation_item_id' => 0,
                'job_no' => 'system-test-' . bin2hex(random_bytes(8)),
                'workflow_code' => (string) $template['code'],
                'workflow_version' => max(1, (int) ($template['version'] ?? 1)),
                'idempotency_key' => 'comfyui-system-test-' . bin2hex(random_bytes(16)),
                'callback_token' => $this->workflowTokens->issue(
                    0,
                    0,
                    $modelId,
                    $capability,
                    $prompt,
                    $size,
                    $seed,
                    time() + (int) $this->comfy->configuration()['workflowTimeoutSeconds'] + 180,
                    'system_test',
                    $requiresReference ? $this->referenceFingerprint($reference) : ''
                ),
                'output_prefix' => 'asset-factory/system-tests/' . $this->safePathSegment((string) $template['code']),
            ]);
            $submission = $this->comfy->submitWorkflow(
                $compiled['workflow'],
                'asset-factory-system-test-' . bin2hex(random_bytes(8)),
                ['asset_factory' => ['system_test' => true, 'template_id' => $templateId]]
            );
            if (empty($submission['ok'])) {
                throw new RuntimeException((string) ($submission['message'] ?? '工作流真实测试提交失败。'));
            }
            $promptId = trim((string) ($submission['data']['id'] ?? ''));
            if ($promptId === '') {
                throw new RuntimeException('工作流真实测试没有返回 prompt_id。');
            }
            $result = $this->waitForImagePrompt($promptId);
            if (empty($result['ok'])) {
                $failedResult = [
                    'ok' => false,
                    'message' => mb_strimwidth(
                        (string) ($result['message'] ?? '工作流没有返回有效图片。'),
                        0,
                        1000,
                        ''
                    ),
                    'promptId' => $promptId,
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                    'artifactUrl' => null,
                    'artifactError' => null,
                    'workflowVersion' => (int) $inputSnapshot['workflow_version'],
                    'inputSnapshot' => $inputSnapshot,
                    'outputSummary' => [],
                    'failedNodeId' => trim((string) ($result['failedNodeId'] ?? '')),
                    'failedNodeType' => trim((string) ($result['failedNodeType'] ?? '')),
                    'exceptionType' => trim((string) ($result['exceptionType'] ?? '')),
                ];
                $this->templates->recordTest($templateId, $failedResult);
                return $failedResult;
            }
            $result['message'] = '真实图片测试通过，模板已具备全量发布条件。';
            $result['promptId'] = $promptId;
            $result['durationMs'] = (int) round((microtime(true) - $startedAt) * 1000);
            $result['workflowVersion'] = (int) $inputSnapshot['workflow_version'];
            $result['inputSnapshot'] = $inputSnapshot;
            $result['failedNodeId'] = '';
            $result['failedNodeType'] = '';
            $result['exceptionType'] = '';
            try {
                $artifact = $this->storeWorkflowTestArtifact(
                    $result,
                    (string) $template['code']
                );
                $result['artifactUrl'] = (string) $artifact['url'];
                $result['outputSummary'] = $artifact;
                $result['artifactError'] = null;
            } catch (Throwable $artifactException) {
                // A paid upstream result remains a successful test even if the
                // optional local preview cannot be persisted.
                $result['artifactUrl'] = null;
                $result['outputSummary'] = [];
                $result['artifactError'] = mb_strimwidth($artifactException->getMessage(), 0, 500, '');
                $result['message'] .= ' 测试图保存失败，请查看审计详情。';
            }
            $this->templates->recordTest($templateId, $result);
            return $result;
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'message' => mb_strimwidth($exception->getMessage(), 0, 1000, ''),
                'promptId' => $promptId !== '' ? $promptId : null,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'artifactUrl' => null,
                'artifactError' => null,
                'workflowVersion' => (int) $inputSnapshot['workflow_version'],
                'inputSnapshot' => $inputSnapshot,
                'outputSummary' => [],
                'failedNodeId' => '',
                'failedNodeType' => '',
                'exceptionType' => '',
            ];
            $this->templates->recordTest($templateId, $result);
            return $result;
        }
    }

    private function storeWorkflowTestArtifact(array $result, string $templateCode): array
    {
        $encoded = (string) ($result['data']['data'][0]['b64_json'] ?? '');
        if ($encoded === '') {
            throw new RuntimeException('真实测试已完成，但返回图片内容为空。');
        }
        $content = base64_decode($encoded, true);
        if (!is_string($content) || $content === '' || strlen($content) > 30 * 1024 * 1024) {
            throw new RuntimeException('真实测试返回了无效或过大的图片。');
        }
        $imageInfo = getimagesizefromstring($content);
        $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => throw new RuntimeException('真实测试返回了不支持的图片格式。'),
        };
        $directory = dirname(__DIR__, 2) . '/public/storage/workflow-tests';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建工作流测试产物目录。');
        }
        $filename = $this->safePathSegment($templateCode)
            . '-' . date('Ymd-His')
            . '-' . bin2hex(random_bytes(4))
            . '.' . $extension;
        $path = $directory . '/' . $filename;
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('无法保存工作流测试产物。');
        }
        @chmod($path, 0644);
        return [
            'url' => '/storage/workflow-tests/' . $filename,
            'mime' => $mime,
            'width' => max(0, (int) ($imageInfo[0] ?? 0)),
            'height' => max(0, (int) ($imageInfo[1] ?? 0)),
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }

    private function workflowTestPrompt(string $templateCode, bool $requiresReference): string
    {
        if ($templateCode !== MediaWorkflowTemplateService::CANVAS_EDIT_TEMPLATE || !$requiresReference) {
            throw new RuntimeException('真实工作流测试仅支持画布图片编辑模板。');
        }
        return '系统真实测试：保留参考图中蓝色瓶身、白色瓶盖、标签位置和商品轮廓，将背景改为干净的浅灰摄影棚，商品完整居中，不添加文字。';
    }

    public function requestSnapshot(array $request): array
    {
        $decoded = json_decode((string) ($request['request_payload_json'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $snapshot = is_array($decoded['workflow'] ?? null) ? $decoded['workflow'] : [];
        if ($snapshot === []) {
            $snapshot = [
                'workflow_engine_mode' => 'direct',
                'workflow_engine' => 'direct',
                'workflow_ready' => true,
                'captured_at' => (string) ($request['created_at'] ?? ''),
            ];
        }
        return $snapshot;
    }

    private function imageSnapshotFromJob(array $job): array
    {
        $route = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        if (!is_array($route)) {
            return [];
        }
        $snapshot = $route['mediaWorkflow'] ?? $route['media_workflow'] ?? [];
        return is_array($snapshot) ? $snapshot : [];
    }

    private function shouldUseImageWorkflow(
        array $snapshot,
        array $job,
        ?array $referenceImage,
        ?array $mask,
        array $context
    ): bool {
        if ((string) ($snapshot['workflow_engine'] ?? 'direct') !== 'comfyui'
            || (string) ($snapshot['product_code'] ?? '') !== 'canvas_edit'
            || !str_starts_with((string) ($snapshot['workflow_template_code'] ?? ''), 'canvas_edit_')
            || empty($snapshot['workflow_ready'])
            || !$this->comfy->configuration()['configured']) {
            return false;
        }
        if ($mask !== null && !array_key_exists('mask_image', (array) ($snapshot['workflow_input_map'] ?? []))) {
            return false;
        }
        if (!empty($snapshot['workflow_requires_reference']) && $referenceImage === null) {
            return false;
        }
        $models = array_values(array_filter(array_map('strval', (array) ($snapshot['workflow_supported_models'] ?? []))));
        if ($models !== [] && !in_array((string) $job['model'], $models, true)) {
            return false;
        }
        $assetTypes = array_values(array_filter(array_map('strval', (array) ($snapshot['workflow_asset_types'] ?? []))));
        $assetTypePrefixes = array_values(array_filter(array_map('strval', (array) ($snapshot['workflow_asset_type_prefixes'] ?? []))));
        $assetType = trim((string) ($context['asset_type'] ?? ''));
        if ($assetTypes === [] && $assetTypePrefixes === []) {
            return true;
        }
        if (in_array($assetType, $assetTypes, true)) {
            return true;
        }
        foreach ($assetTypePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($assetType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function uploadReferences(array $job, ?array $referenceImage): array
    {
        if ($referenceImage === null) {
            return ['ok' => true, 'references' => []];
        }
        $images = is_array($referenceImage['_reference_originals'] ?? null)
            ? $referenceImage['_reference_originals']
            : [$referenceImage];
        $references = [];
        $subfolder = 'asset-factory/' . $this->safePathSegment((string) $job['job_no']);
        foreach (array_slice($images, 0, 6) as $index => $image) {
            if (!is_array($image) || !is_string($image['content'] ?? null) || (string) $image['content'] === '') {
                return ['ok' => false, 'message' => '商品参考图内容无效。', 'references' => []];
            }
            $extension = match (strtolower((string) ($image['mime'] ?? ''))) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
            $filename = 'reference-' . ($index + 1) . '-' . substr(hash('sha256', (string) $image['content']), 0, 12) . '.' . $extension;
            $uploaded = $this->comfy->uploadImage($image, $filename, $subfolder);
            if (empty($uploaded['ok'])) {
                return ['ok' => false, 'message' => (string) ($uploaded['message'] ?? 'ComfyUI 参考图上传失败。'), 'references' => []];
            }
            $references[] = (string) $uploaded['reference'];
        }
        return ['ok' => true, 'references' => $references];
    }

    private function uploadMask(array $job, ?array $mask): array
    {
        if ($mask === null) {
            return ['ok' => true, 'reference' => ''];
        }
        $subfolder = 'asset-factory/' . $this->safePathSegment((string) $job['job_no']);
        $uploaded = $this->comfy->uploadImage(
            $mask,
            'mask-' . substr(hash('sha256', (string) ($mask['content'] ?? '')), 0, 12) . '.png',
            $subfolder
        );
        if (empty($uploaded['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($uploaded['message'] ?? 'ComfyUI 画布遮罩上传失败。'),
                'reference' => '',
            ];
        }
        return ['ok' => true, 'reference' => (string) ($uploaded['reference'] ?? '')];
    }

    private function waitForImage(array $job, string $promptId): array
    {
        // Once ComfyUI has accepted the prompt it may already have dispatched a
        // paid provider request. Always collect the terminal result; a merchant
        // cancellation must not turn an accepted upstream call into a loss.
        $result = $this->waitForImagePrompt($promptId);
        $metadata = is_array($result['providerMetadata'] ?? null) ? $result['providerMetadata'] : [];
        $itemId = max(0, (int) ($job['_generation_item_id'] ?? 0));
        if ($itemId > 0 && $metadata !== []) {
            $this->executionAttempts->markProviderMetadata($itemId, $promptId, $metadata);
        }
        return $result;
    }

    /**
     * Performs one idempotent status check. Once a prompt_id exists the caller
     * must persist a recoverable queue state and come back with the same ID;
     * it must never hold a Worker for the former 600-second wait window.
     */
    private function pollImage(array $job, string $promptId): array
    {
        $status = $this->comfy->workflowStatus($promptId);
        $startedAt = strtotime((string) ($job['_generation_started_at'] ?? '')) ?: time();
        $elapsed = max(0, time() - $startedAt);
        $softTimeout = (int) $this->comfy->configuration()['workflowTimeoutSeconds'];
        $hardReconciliationDeadline = 86400;
        if (empty($status['ok'])) {
            return [
                'ok' => false,
                'pending' => true,
                'manualReview' => $elapsed >= $hardReconciliationDeadline,
                'message' => $elapsed >= $hardReconciliationDeadline
                    ? '工作流长期没有终态，已进入待人工核对；系统仍保留原 prompt_id，不会重发或自动退款。'
                    : '工作流状态暂时不可读，将继续使用原 prompt_id 对账：'
                        . (string) ($status['message'] ?? '状态接口暂不可用'),
                'status' => 202,
                'requestId' => 'comfyui:' . $promptId,
                'workflowEngine' => 'comfyui',
            ];
        }
        $data = is_array($status['data'] ?? null) ? $status['data'] : [];
        $state = strtolower(trim((string) ($data['status'] ?? 'running')));
        if ($state === 'lost') {
            if ($elapsed < 30) {
                return [
                    'ok' => false,
                    'pending' => true,
                    'manualReview' => false,
                    'message' => '工作流刚提交，正在确认执行槽队列状态。',
                    'status' => 202,
                    'requestId' => 'comfyui:' . $promptId,
                    'workflowEngine' => 'comfyui',
                ];
            }
            return [
                'ok' => false,
                'lost' => true,
                'message' => 'ComfyUI 执行器重启后未找到原任务；系统将使用相同付费幂等身份安全恢复，不会重复扣费。',
                'status' => 409,
                'requestId' => 'comfyui:' . $promptId,
                'workflowEngine' => 'comfyui',
            ];
        }
        if ($state === 'completed') {
            $url = trim((string) ($data['image_url'] ?? ''));
            if ($url === '') {
                return [
                    'ok' => false,
                    'message' => 'ComfyUI 已明确结束，但没有返回可用图片。',
                    'status' => 422,
                    'requestId' => 'comfyui:' . $promptId,
                ];
            }
            $download = $this->comfy->downloadOutput($url);
            if (empty($download['ok'])) {
                return [
                    'ok' => false,
                    'pending' => true,
                    'manualReview' => $elapsed >= $hardReconciliationDeadline,
                    'message' => '工作流已完成，结果文件暂时无法读取；将继续从原任务收取迟到结果。',
                    'status' => 202,
                    'requestId' => 'comfyui:' . $promptId,
                    'workflowEngine' => 'comfyui',
                ];
            }
            $metadata = is_array($data['provider_metadata'] ?? null) ? $data['provider_metadata'] : [];
            $itemId = max(0, (int) ($job['_generation_item_id'] ?? 0));
            if ($itemId > 0 && $metadata !== []) {
                $this->executionAttempts->markProviderMetadata($itemId, $promptId, $metadata);
            }
            return [
                'ok' => true,
                'message' => 'ComfyUI 图片工作流已完成。',
                'status' => 200,
                'requestId' => 'comfyui:' . $promptId,
                'data' => [
                    'id' => 'comfyui:' . $promptId,
                    'data' => [['b64_json' => base64_encode((string) $download['content'])]],
                ],
                'workflowEngine' => 'comfyui',
                'providerMetadata' => $metadata,
            ];
        }
        if (in_array($state, ['failed', 'error', 'cancelled', 'canceled', 'result_missing'], true)) {
            return [
                'ok' => false,
                'message' => trim((string) ($data['message'] ?? $status['message'] ?? 'ComfyUI 图片工作流明确失败。')),
                'status' => 422,
                'requestId' => 'comfyui:' . $promptId,
                'failedNodeId' => trim((string) ($data['failed_node_id'] ?? '')),
                'failedNodeType' => trim((string) ($data['failed_node_type'] ?? '')),
                'exceptionType' => trim((string) ($data['exception_type'] ?? '')),
            ];
        }
        $manualReview = $elapsed >= $hardReconciliationDeadline;
        $slow = $elapsed >= $softTimeout;
        return [
            'ok' => false,
            'pending' => true,
            'manualReview' => $manualReview,
            'message' => $manualReview
                ? '工作流长期没有终态，已进入待人工核对；不会重新创建付费请求或自动退款。'
                : ($slow
                    ? '模型处理时间较长，系统会继续使用原 prompt_id 收取结果。'
                    : '模型正在处理中，系统会继续使用原 prompt_id 轮询。'),
            'status' => 202,
            'requestId' => 'comfyui:' . $promptId,
            'workflowEngine' => 'comfyui',
        ];
    }

    private function waitForImagePrompt(string $promptId, ?callable $activeCheck = null): array
    {
        $timeout = (int) $this->comfy->configuration()['workflowTimeoutSeconds'];
        $deadline = time() + $timeout;
        $lastMessage = '';
        do {
            if ($activeCheck !== null && !$activeCheck()) {
                return ['ok' => false, 'message' => '任务已取消，ComfyUI 返回结果将被丢弃。', 'status' => 409];
            }
            $status = $this->comfy->workflowStatus($promptId);
            if (empty($status['ok'])) {
                $lastMessage = (string) ($status['message'] ?? '读取 ComfyUI 状态失败');
                sleep(2);
                continue;
            }
            $data = is_array($status['data'] ?? null) ? $status['data'] : [];
            $state = strtolower(trim((string) ($data['status'] ?? 'running')));
            if ($state === 'completed') {
                $url = trim((string) ($data['image_url'] ?? ''));
                if ($url === '') {
                    return ['ok' => false, 'message' => 'ComfyUI 工作流已结束，但没有返回可用图片。', 'status' => 422];
                }
                $download = $this->comfy->downloadOutput($url);
                if (empty($download['ok'])) {
                    return ['ok' => false, 'message' => (string) ($download['message'] ?? '读取 ComfyUI 图片失败。'), 'status' => (int) ($download['status'] ?? 502)];
                }
                return [
                    'ok' => true,
                    'message' => 'ComfyUI 图片工作流已完成。',
                    'status' => 200,
                    'requestId' => 'comfyui:' . $promptId,
                    'data' => [
                        'id' => 'comfyui:' . $promptId,
                        'data' => [['b64_json' => base64_encode((string) $download['content'])]],
                    ],
                    'workflowEngine' => 'comfyui',
                    'providerMetadata' => is_array($data['provider_metadata'] ?? null)
                        ? $data['provider_metadata']
                        : [],
                ];
            }
            if (in_array($state, ['failed', 'error', 'cancelled', 'canceled', 'result_missing'], true)) {
                return [
                    'ok' => false,
                    'message' => trim((string) ($data['message'] ?? $status['message'] ?? 'ComfyUI 图片工作流执行失败。')),
                    'status' => 422,
                    'failedNodeId' => trim((string) ($data['failed_node_id'] ?? '')),
                    'failedNodeType' => trim((string) ($data['failed_node_type'] ?? '')),
                    'exceptionType' => trim((string) ($data['exception_type'] ?? '')),
                ];
            }
            sleep(2);
        } while (time() < $deadline);

        return [
            'ok' => false,
            'message' => $lastMessage !== ''
                ? 'ComfyUI 工作流等待超时，最后一次状态：' . $lastMessage
                : "ComfyUI 工作流超过 {$timeout} 秒仍未结束。",
            'status' => 504,
        ];
    }

    private function systemTestImage(): array
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('服务器缺少 GD 扩展，无法创建工作流真实测试参考图。');
        }
        $canvas = imagecreatetruecolor(512, 512);
        if ($canvas === false) {
            throw new RuntimeException('无法创建工作流真实测试参考图。');
        }
        $background = imagecolorallocate($canvas, 244, 247, 250);
        $blue = imagecolorallocate($canvas, 32, 112, 176);
        $darkBlue = imagecolorallocate($canvas, 18, 72, 118);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $border = imagecolorallocate($canvas, 186, 201, 216);
        imagefill($canvas, 0, 0, $background);
        imagefilledrectangle($canvas, 190, 105, 322, 150, $white);
        imagerectangle($canvas, 190, 105, 322, 150, $border);
        imagefilledrectangle($canvas, 168, 145, 344, 420, $blue);
        imagefilledrectangle($canvas, 168, 145, 344, 170, $darkBlue);
        imagefilledrectangle($canvas, 194, 235, 318, 325, $white);
        imagerectangle($canvas, 194, 235, 318, 325, $border);
        ob_start();
        $encoded = imagepng($canvas, null, 6);
        $content = (string) ob_get_clean();
        unset($canvas);
        if (!$encoded || $content === '') {
            throw new RuntimeException('工作流真实测试参考图编码失败。');
        }
        return ['content' => $content, 'mime' => 'image/png', 'filename' => 'system-product-reference.png'];
    }

    private function imageJobStillActive(array $job): bool
    {
        $itemId = (int) ($job['_generation_item_id'] ?? 0);
        if ($itemId > 0) {
            return (string) Db::name('generation_job_items')->where('id', $itemId)->value('status') === 'processing';
        }
        return in_array((string) Db::name('generation_jobs')->where('id', (int) $job['id'])->value('status'), ['running', 'queued'], true);
    }

    private function recordImageRequest(array $job, string $promptId): void
    {
        $requestId = 'comfyui:' . $promptId;
        $now = Web::now();
        $itemId = (int) ($job['_generation_item_id'] ?? 0);
        if ($itemId > 0) {
            $stageValue = Db::name('generation_job_items')->where('id', $itemId)->value('stage_timestamps_json');
            $stageTimestamps = is_string($stageValue) ? json_decode($stageValue, true) : [];
            $stageTimestamps = is_array($stageTimestamps) ? $stageTimestamps : [];
            $stageTimestamps['provider_submitted_at'] = $now;
            Db::name('generation_job_items')->where('id', $itemId)->where('status', 'processing')->update([
                'request_id' => $requestId,
                'stage_timestamps_json' => json_encode($stageTimestamps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
            $this->executionAttempts->markSubmitted($itemId, $requestId);
        }
        Db::name('generation_jobs')->where('id', (int) $job['id'])->update([
            'new_api_request_id' => $requestId,
            'progress_stage' => 'workflow_running',
            'progress_message' => '媒体工作流已提交，正在保持商品一致性并生成画面',
            'updated_at' => $now,
        ]);
    }

    private function workflowFailure(array $job, string $stage, string $message): array
    {
        $message = trim($message) ?: 'ComfyUI 工作流执行失败。';
        Audit::log('system', 0, 'ai_workflow.image.failed_before_accept', 'generation_job', $job['id'], '127.0.0.1', [
            'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
            'stage' => $stage,
            'message' => mb_strimwidth($message, 0, 500, '...'),
            'model' => (string) ($job['model'] ?? ''),
            'fallback' => 'none',
        ]);
        return [
            'ok' => false,
            'message' => $message,
            'status' => 422,
            'retryable' => false,
            'workflowAccepted' => false,
            'workflowEngine' => 'comfyui',
            'providerOwner' => 'frozen_workflow',
        ];
    }

    private function deterministicSeed(array $job, array $context): int
    {
        $source = implode('|', [
            (string) ($job['job_no'] ?? $job['id'] ?? ''),
            (string) ($context['asset_type'] ?? ''),
            (string) ($context['position'] ?? ''),
            (string) ($context['occurrence'] ?? ''),
            'manual-retry:' . max(0, (int) ($job['_manual_retry_no'] ?? 0)),
        ]);
        return ((int) hexdec(substr(hash('sha256', $source), 0, 8)) % 2147483647) + 1;
    }

    private function imageDimensions(string $size): array
    {
        if (preg_match('/^(\d{3,4})x(\d{3,4})$/', trim($size), $matches) !== 1) {
            return [1024, 1024];
        }
        return [
            max(320, min(4096, (int) $matches[1])),
            max(320, min(4096, (int) $matches[2])),
        ];
    }

    private function outputPrefix(array $job, array $context): string
    {
        $assetType = $this->safePathSegment((string) ($context['asset_type'] ?? 'image'));
        $occurrence = max(1, (int) ($context['occurrence'] ?? $context['position'] ?? 1));
        return 'asset-factory/' . $this->safePathSegment((string) ($job['job_no'] ?? $job['id'])) . '/' . $assetType . '-' . $occurrence;
    }

    private function safePathSegment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?: 'job';
        return substr(trim($value, '.-_'), 0, 80) ?: 'job';
    }

    private function imageCapabilityFromSnapshot(array $snapshot): string
    {
        $capability = trim((string) ($snapshot['workflow_capability'] ?? ''));
        return in_array($capability, [
            AiCapability::IMAGE_GENERATION,
            AiCapability::IMAGE_EDIT,
            AiCapability::VIDEO_IMAGE_TO_VIDEO,
            AiCapability::VIDEO_TEXT_TO_VIDEO,
        ], true)
            ? $capability
            : AiCapability::IMAGE_EDIT;
    }

    private function imageCapabilityFromJob(array $job, ?array $referenceImage): string
    {
        $route = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $capability = is_array($route) ? trim((string) ($route['capability'] ?? '')) : '';
        if (in_array($capability, [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)) {
            return $capability;
        }
        return $referenceImage === null ? AiCapability::IMAGE_GENERATION : AiCapability::IMAGE_EDIT;
    }

    private function referenceFingerprint(?array $referenceImage): string
    {
        if ($referenceImage === null) {
            return '';
        }
        $images = is_array($referenceImage['_reference_originals'] ?? null)
            ? $referenceImage['_reference_originals']
            : [$referenceImage];
        $hashes = [];
        foreach (array_slice($images, 0, 6) as $image) {
            if (is_array($image) && is_string($image['content'] ?? null)) {
                $hashes[] = hash('sha256', (string) $image['content']);
            }
        }
        return $hashes === [] ? '' : hash('sha256', implode("\n", $hashes));
    }
}
