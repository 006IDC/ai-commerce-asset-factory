<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AiCapability;
use app\service\AiMediaExecutionService;
use app\service\ComfyWorkflowToken;
use app\support\Audit;
use RuntimeException;
use Throwable;
use think\Response;
use think\facade\Db;

final class InternalAiWorkflow extends BaseController
{
    public function image(
        ComfyWorkflowToken $tokens,
        AiMediaExecutionService $media
    ): Response {
        try {
            $this->assertLoopbackRequest();
            $prompt = mb_strimwidth(trim((string) $this->request->post('prompt', '')), 0, 12000, '');
            $size = strtolower(trim((string) $this->request->post('size', '1024x1024')));
            $seed = max(1, min(2147483647, (int) $this->request->post('seed', 1)));
            if ($prompt === '' || !in_array($size, ['1024x1024', '1536x1024', '1024x1536'], true)) {
                throw new RuntimeException('工作流图片请求缺少有效提示词或尺寸。');
            }
            $authorization = trim((string) $this->request->header('Authorization', ''));
            $token = str_starts_with($authorization, 'Bearer ')
                ? trim(substr($authorization, 7))
                : '';
            $files = $this->imageFiles();
            $claims = $tokens->verify(
                $token,
                $prompt,
                $size,
                $seed,
                $this->referenceFingerprint($files)
            );
            $modelId = max(0, (int) ($claims['model_id'] ?? 0));
            $capability = (string) ($claims['capability'] ?? '');
            if ($modelId <= 0 || !in_array($capability, [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)) {
                throw new RuntimeException('工作流任务没有冻结可用的图片模型。');
            }

            $job = null;
            $jobId = max(0, (int) ($claims['job_id'] ?? 0));
            $itemId = max(0, (int) ($claims['item_id'] ?? 0));
            $scope = (string) ($claims['scope'] ?? 'job');
            if ($scope === 'job') {
                $job = $this->activeJob($jobId, $itemId, $modelId, $capability);
            } elseif ($scope !== 'system_test') {
                throw new RuntimeException('工作流回调范围无效。');
            }

            if ($capability === AiCapability::IMAGE_EDIT && $files === []) {
                throw new RuntimeException('当前图片编辑工作流缺少参考图。');
            }
            if ($capability === AiCapability::IMAGE_GENERATION && $files !== []) {
                throw new RuntimeException('当前文生图工作流不接受参考图。');
            }
            $context = [
                'merchant_id' => (int) ($job['merchant_id'] ?? 0),
                'job_id' => $jobId ?: null,
                'call_key' => 'comfyui:' . $scope . ':' . $jobId . ':' . $itemId . ':' . $seed,
                'workflow_scope' => $scope,
            ];
            if ($files === []) {
                $result = $media->requestJson(
                    $capability,
                    $modelId,
                    'POST',
                    '/v1/images/generations',
                    [
                        'prompt' => $prompt,
                        'n' => 1,
                        'size' => $size,
                        'seed' => $seed,
                        'response_format' => 'b64_json',
                    ],
                    480,
                    $context
                );
            } else {
                $payload = [
                    'prompt' => $prompt,
                    'n' => '1',
                    'size' => $size,
                    'seed' => (string) $seed,
                    'response_format' => 'b64_json',
                ];
                foreach ($files as $index => $file) {
                    $payload['image[' . $index . ']'] = curl_file_create(
                        (string) $file->getPathname(),
                        (string) $file->getMime(),
                        $this->safeFilename((string) $file->getOriginalName(), $index)
                    );
                }
                $result = $media->requestMultipart(
                    $capability,
                    $modelId,
                    '/v1/images/edits',
                    $payload,
                    480,
                    $context
                );
            }
            if (empty($result['handled']) || empty($result['ok'])) {
                $status = max(400, min(599, (int) ($result['status'] ?? 503) ?: 503));
                return $this->jsonError(
                    (string) ($result['message'] ?? '当前模型尚未在 Sub2API 中发布或暂时不可用。'),
                    $status
                );
            }
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            if (!is_array($data['data'] ?? null) || !is_array($data['data'][0] ?? null)) {
                return $this->jsonError('上游模型没有返回可用图片。', 502);
            }
            return Response::create($data, 'json')
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (Throwable $exception) {
            Audit::log('system', 0, 'comfyui.callback.rejected', 'generation_job', null, $this->request->ip(), [
                'message' => mb_strimwidth($exception->getMessage(), 0, 500, ''),
            ]);
            return $this->jsonError($exception->getMessage(), 401);
        }
    }

    private function activeJob(int $jobId, int $itemId, int $modelId, string $capability): array
    {
        if ($jobId <= 0 || $itemId <= 0) {
            throw new RuntimeException('工作流任务标识不完整。');
        }
        $job = Db::name('generation_jobs')->where('id', $jobId)->find();
        $item = Db::name('generation_job_items')->where('id', $itemId)->where('job_id', $jobId)->find();
        if (!$job || !$item
            || !in_array((string) ($job['status'] ?? ''), ['queued', 'running', 'manual_review'], true)
            || !in_array((string) ($item['status'] ?? ''), ['processing', 'workflow_processing', 'manual_review'], true)) {
            throw new RuntimeException('工作流任务已取消、结束或不再允许回调。');
        }
        $route = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $route = is_array($route) ? $route : [];
        if ((int) ($route['resolvedModelId'] ?? $job['resolved_ai_model_id'] ?? 0) !== $modelId
            || (string) ($route['capability'] ?? '') !== $capability) {
            throw new RuntimeException('工作流模型与任务冻结路由不一致。');
        }
        return $job;
    }

    private function imageFiles(): array
    {
        $uploaded = $this->request->file();
        $files = is_array($uploaded) ? ($uploaded['image'] ?? []) : [];
        $files = is_array($files) ? array_values($files) : ($files ? [$files] : []);
        if (count($files) > 6) {
            throw new RuntimeException('工作流单次最多处理 6 张参考图。');
        }
        foreach ($files as $file) {
            $mime = strtolower((string) $file->getMime());
            $size = (int) $file->getSize();
            if ($size <= 0 || $size > 24 * 1024 * 1024
                || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new RuntimeException('工作流参考图仅支持 JPG、PNG、WebP，单张不能超过 24MB。');
            }
        }
        return $files;
    }

    private function assertLoopbackRequest(): void
    {
        $ip = trim((string) $this->request->ip());
        if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('工作流回调只允许来自本机执行器。');
        }
    }

    private function referenceFingerprint(array $files): string
    {
        $hashes = [];
        foreach ($files as $file) {
            $hash = hash_file('sha256', (string) $file->getPathname());
            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException('无法校验工作流参考图。');
            }
            $hashes[] = $hash;
        }
        return $hashes === [] ? '' : hash('sha256', implode("\n", $hashes));
    }

    private function safeFilename(string $name, int $index): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name)) ?: '';
        return mb_strimwidth($name !== '' ? $name : 'reference-' . ($index + 1) . '.png', 0, 120, '');
    }

    private function jsonError(string $message, int $status): Response
    {
        return Response::create([
            'error' => [
                'type' => 'workflow_callback_error',
                'message' => $message,
            ],
        ], 'json')->code($status)->header(['Cache-Control' => 'no-store']);
    }
}
