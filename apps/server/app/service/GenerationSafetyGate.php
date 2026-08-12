<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use Throwable;
use think\facade\Db;

final class GenerationSafetyGate
{
    public function __construct(
        private readonly ContentSafetyService $safety,
        private readonly AssetStorage $storage
    ) {
    }

    /**
     * Claims and checks one queued generation job. A null result means no safety work is due.
     */
    public function processNext(): ?array
    {
        $this->recoverStaleChecks();
        $job = $this->claimNext();
        if ($job === null) {
            return null;
        }

        $configuration = $this->safety->configuration();
        if (!$configuration['enabled']) {
            $this->markPassed($job, [
                'schema_version' => 'generation_safety_coverage_v1',
                'mode' => 'disabled',
                'checked_at' => Web::now(),
            ]);
            return ['job' => $job, 'status' => 'passed'];
        }

        $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->find();
        $user = $this->submittedUser($job);
        if (!$merchant || !$user) {
            $message = '任务提交账户不存在，内容安全检查无法继续。';
            $this->markFailed($job, $message, 'failed');
            return ['job' => $job, 'status' => 'failed', 'message' => $message];
        }

        try {
            $input = $this->safetyInput($job, (bool) $configuration['externalEnabled']);
            $ipAddress = trim((string) ($job['submitted_ip'] ?? '')) ?: '127.0.0.1';
            foreach ($input['texts'] as $entry) {
                $this->safety->enforceText($merchant, $user, (string) $entry['content'], (string) $entry['source'], $ipAddress);
            }
            if ($input['images'] !== []) {
                $this->safety->enforceImages(
                    $merchant,
                    $user,
                    array_values(array_map(static fn (array $entry): array => (array) $entry['content'], $input['images'])),
                    'generation_frozen_references',
                    $ipAddress
                );
            }
            $this->markPassed($job, $this->coverage($input));
            return ['job' => $job, 'status' => 'passed'];
        } catch (ContentSafetyViolationException $exception) {
            $this->markFailed($job, $exception->getMessage(), 'blocked');
            return ['job' => $job, 'status' => 'blocked', 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            $attempts = (int) ($job['safety_attempts'] ?? 0) + 1;
            if ($exception instanceof ContentSafetyUnavailableException || $attempts < 3) {
                $this->defer($job, $exception->getMessage(), $attempts);
                return ['job' => $job, 'status' => 'retrying', 'message' => $exception->getMessage()];
            }
            $message = '内容安全检查失败：' . localized_job_error($exception->getMessage());
            $this->markFailed($job, $message, 'failed');
            return ['job' => $job, 'status' => 'failed', 'message' => $message];
        }
    }

    private function claimNext(): ?array
    {
        $now = Web::now();
        $candidateIds = Db::name('generation_jobs')->where('status', 'queued')
            ->whereIn('safety_status', ['pending', 'retrying'])
            ->where(static function ($query) use ($now): void {
                $query->whereNull('safety_next_retry_at')->whereOr('safety_next_retry_at', '<=', $now);
            })
            ->order('id', 'asc')->limit(20)->column('id');

        foreach ($candidateIds as $candidateId) {
            $claimed = Db::transaction(function () use ($candidateId): ?array {
                $job = Db::name('generation_jobs')->where('id', (int) $candidateId)->lock(true)->find();
                if (!$job || $job['status'] !== 'queued' || !in_array((string) ($job['safety_status'] ?? 'pending'), ['pending', 'retrying'], true)) {
                    return null;
                }
                if (!empty($job['safety_next_retry_at']) && (string) $job['safety_next_retry_at'] > Web::now()) {
                    return null;
                }
                Db::name('generation_jobs')->where('id', $job['id'])->update([
                    'safety_status' => 'checking',
                    'progress_stage' => 'safety_check',
                    'progress_message' => '正在后台进行内容安全检查',
                    'updated_at' => Web::now(),
                ]);
                return $job;
            });
            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    private function safetyInput(array $job, bool $includeImages): array
    {
        $texts = [];
        $images = [];
        $platform = (string) $job['platform'];

        if ($platform === 'free') {
            $free = Db::name('free_generation_jobs')->where('job_id', $job['id'])->find();
            if ($free) {
                $texts[] = ['source' => 'free_generation_text', 'content' => (string) $free['prompt']];
                if ($includeImages && !empty($free['reference_image_url'])) {
                    $images[] = ['source' => 'free_generation_image', 'content' => $this->storage->readImageWithBackup((string) $free['reference_image_url'], (string) ($free['reference_image_backup_url'] ?? ''))];
                }
                if ($includeImages) {
                    foreach ($this->decodeList($free['reference_images_json'] ?? null) as $index => $reference) {
                        $url = trim((string) ($reference['url'] ?? ''));
                        if ($url === '') {
                            continue;
                        }
                        $images[] = [
                            'source' => 'free_generation_image_' . ($index + 2),
                            'content' => $this->storage->readImageWithBackup($url, (string) ($reference['backup_url'] ?? '')),
                        ];
                    }
                }
            }
            return compact('texts', 'images');
        }

        if ($platform === 'canvas') {
            $captureAsset = $this->pendingCanvasCaptureAsset($job);
            if ($captureAsset) {
                if ($includeImages) {
                    $images[] = [
                        'source' => 'canvas_' . (string) $captureAsset['asset_type'],
                        'content' => $this->storage->readImageWithBackup(
                            (string) $captureAsset['file_url'],
                            (string) ($captureAsset['backup_file_url'] ?? '')
                        ),
                    ];
                }
                return compact('texts', 'images');
            }
            $edit = Db::name('canvas_edits')->where('job_id', $job['id'])->find();
            if (!$edit || (string) ($edit['operation'] ?? '') === 'resize_only') {
                return compact('texts', 'images');
            }
            $texts[] = ['source' => 'canvas_edit_text', 'content' => trim((string) $edit['preset'] . "\n" . (string) $edit['instruction'])];
            if ($includeImages) {
                $asset = Db::name('assets')->where('id', $edit['source_asset_id'])->where('merchant_id', $job['merchant_id'])->find();
                if (!$asset) {
                    throw new \RuntimeException('画布源素材不存在。');
                }
                $images[] = ['source' => 'canvas_edit_image', 'content' => $this->storage->readImageWithBackup((string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? ''))];
            }
            return compact('texts', 'images');
        }

        if ((string) ($job['output_kind'] ?? 'image') === 'video') {
            $video = Db::name('video_generation_requests')->where('job_id', $job['id'])->find();
            if (!$video) {
                throw new \RuntimeException('商品视频请求参数不存在。');
            }
            $texts[] = [
                'source' => 'video_generation_text',
                'content' => trim((string) $video['prompt']),
            ];
            if ($includeImages && !empty($video['reference_image_url'])) {
                $images[] = [
                    'source' => 'video_generation_image',
                    'content' => $this->storage->readImageWithBackup(
                        (string) $video['reference_image_url'],
                        (string) ($video['reference_image_backup_url'] ?? '')
                    ),
                ];
            }
            return compact('texts', 'images');
        }

        $sku = !empty($job['sku_id'])
            ? Db::name('skus')->where('id', $job['sku_id'])->where('merchant_id', $job['merchant_id'])->find()
            : null;
        $project = !empty($job['detail_page_project_id'])
            ? Db::name('detail_page_projects')->where('id', $job['detail_page_project_id'])->where('merchant_id', $job['merchant_id'])->find()
            : null;
        $source = $project ? 'detail_page_generation_text' : 'generation_text';
        $textPayload = [
            'product' => $sku ?: ($project ?: []),
            'task_brief' => (string) ($job['task_brief'] ?? ''),
            'custom_material_requests' => decode_json_list($job['custom_material_requests'] ?? '[]'),
            'detail_page_plan' => $project ?: null,
        ];
        $texts[] = ['source' => $source, 'content' => json_encode($textPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''];

        if ($includeImages) {
            if ($project && !empty($project['source_image_url'])) {
                $images[] = ['source' => 'detail_page_generation_image', 'content' => $this->storage->readImageWithBackup((string) $project['source_image_url'], (string) ($project['source_image_backup_url'] ?? ''))];
            } elseif ($sku && !empty($sku['source_image_path'])) {
                $images[] = ['source' => 'generation_image', 'content' => $this->storage->readImageWithBackup((string) $sku['source_image_path'], (string) ($sku['source_image_backup_path'] ?? ''))];
                foreach ($this->decodeList($sku['reference_images_json'] ?? null) as $index => $reference) {
                    $url = trim((string) ($reference['url'] ?? $reference['path'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $images[] = [
                        'source' => 'generation_image_' . ($index + 2),
                        'content' => $this->storage->readImageWithBackup($url, (string) ($reference['backup_url'] ?? '')),
                    ];
                }
            }
        }

        return compact('texts', 'images');
    }

    private function coverage(array $input): array
    {
        $texts = array_map(static fn (array $entry): array => [
            'source' => (string) $entry['source'],
            'sha256' => hash('sha256', (string) $entry['content']),
        ], (array) ($input['texts'] ?? []));
        $images = array_map(static fn (array $entry): array => [
            'source' => (string) $entry['source'],
            'sha256' => hash('sha256', (string) ($entry['content']['content'] ?? '')),
        ], (array) ($input['images'] ?? []));
        $payload = ['texts' => $texts, 'images' => $images];
        return [
            'schema_version' => 'generation_safety_coverage_v1',
            'mode' => 'frozen_inputs',
            'text_count' => count($texts),
            'image_count' => count($images),
            'inputs' => $payload,
            'input_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'checked_at' => Web::now(),
        ];
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function submittedUser(array $job): ?array
    {
        $userId = (int) ($job['submitted_by_user_id'] ?? 0);
        if ($userId > 0) {
            $user = Db::name('merchant_users')->where('id', $userId)->where('merchant_id', $job['merchant_id'])->find();
            if ($user) {
                return $user;
            }
        }
        return Db::name('merchant_users')->where('merchant_id', $job['merchant_id'])->where('status', 'active')->order('id', 'asc')->find();
    }

    private function markPassed(array $job, array $coverage = []): void
    {
        $taskContext = json_decode((string) ($job['task_context_json'] ?? ''), true);
        $taskContext = is_array($taskContext) ? $taskContext : [];
        if ($coverage !== []) {
            $taskContext['safety_input_coverage'] = $coverage;
        }
        $taskContextJson = json_encode($taskContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $captureAsset = $this->pendingCanvasCaptureAsset($job);
        if ($captureAsset) {
            Db::transaction(function () use ($job, $captureAsset, $taskContextJson): void {
                $now = Web::now();
                Db::name('assets')->where('id', $captureAsset['id'])->where('status', 'pending')->update([
                    'status' => 'active',
                    'updated_at' => $now,
                ]);
                Db::name('generation_jobs')->where('id', $job['id'])->where('safety_status', 'checking')->update([
                    'status' => 'completed',
                    'safety_status' => 'passed',
                    'safety_next_retry_at' => null,
                    'safety_checked_at' => $now,
                    'progress_current' => 1,
                    'progress_total' => 1,
                    'progress_stage' => 'completed',
                    'progress_message' => '画布素材已通过安全检查并保存到素材库',
                    'error_message' => null,
                    'task_context_json' => $taskContextJson,
                    'updated_at' => $now,
                ]);
            });
            Audit::log('system', 0, 'canvas_asset.safety_passed', 'asset', (int) $captureAsset['id'], '127.0.0.1', [
                'job_id' => (int) $job['id'],
            ]);
            return;
        }
        Db::name('generation_jobs')->where('id', $job['id'])->where('safety_status', 'checking')->update([
            'safety_status' => 'passed',
            'safety_next_retry_at' => null,
            'safety_checked_at' => Web::now(),
            'progress_stage' => 'queued',
            'progress_message' => '安全检查已通过，等待生成',
            'task_context_json' => $taskContextJson,
            'updated_at' => Web::now(),
        ]);
    }

    private function defer(array $job, string $message, int $attempts): void
    {
        $delay = min(300, 10 * (2 ** min(5, max(0, $attempts - 1))));
        $retryAt = date('Y-m-d H:i:s', time() + $delay);
        Db::name('generation_jobs')->where('id', $job['id'])->where('safety_status', 'checking')->update([
            'safety_status' => 'retrying',
            'safety_attempts' => $attempts,
            'safety_next_retry_at' => $retryAt,
            'progress_stage' => 'safety_retrying',
            'progress_message' => "内容安全服务暂时繁忙，{$delay} 秒后自动重试",
            'error_message' => mb_strimwidth($message, 0, 500, '...'),
            'updated_at' => Web::now(),
        ]);
        Audit::log('system', 0, 'generation_safety.retry_scheduled', 'generation_job', (int) $job['id'], '127.0.0.1', [
            'attempts' => $attempts, 'retry_at' => $retryAt, 'message' => $message,
        ]);
    }

    private function markFailed(array $job, string $message, string $safetyStatus): void
    {
        $captureAsset = $this->pendingCanvasCaptureAsset($job);
        Db::transaction(function () use ($job, $message, $safetyStatus, $captureAsset): void {
            $now = Web::now();
            if ($captureAsset) {
                Db::name('assets')->where('id', $captureAsset['id'])->update([
                    'file_url' => '',
                    'backup_file_url' => null,
                    'status' => 'purged',
                    'purged_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Db::name('generation_job_items')->where('job_id', $job['id'])->whereIn('status', ['queued', 'pending_gateway'])->update([
                'status' => 'failed', 'error_message' => mb_strimwidth($message, 0, 500, '...'),
                'completed_at' => $now, 'updated_at' => $now,
            ]);
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => 'failed',
                'safety_status' => $safetyStatus,
                'safety_checked_at' => $now,
                'safety_next_retry_at' => null,
                'progress_stage' => 'failed',
                'progress_message' => mb_strimwidth($message, 0, 500, '...'),
                'error_message' => mb_strimwidth($message, 0, 500, '...'),
                'updated_at' => $now,
            ]);
            if (!empty($job['detail_page_project_id'])) {
                Db::name('detail_page_projects')->where('id', $job['detail_page_project_id'])->update(['status' => 'failed', 'updated_at' => $now]);
            }
        });
        if ($captureAsset) {
            foreach (array_unique(array_filter([
                (string) ($captureAsset['file_url'] ?? ''),
                (string) ($captureAsset['backup_file_url'] ?? ''),
            ])) as $url) {
                try {
                    $this->storage->deleteStoredUrl($url);
                } catch (Throwable) {
                }
            }
        }
        Audit::log('system', 0, 'generation_safety.blocked', 'generation_job', (int) $job['id'], '127.0.0.1', ['message' => $message]);
    }

    private function pendingCanvasCaptureAsset(array $job): ?array
    {
        if ((string) ($job['platform'] ?? '') !== 'canvas') {
            return null;
        }
        $assetTypes = decode_json_list($job['asset_types'] ?? '[]');
        if (count($assetTypes) !== 1 || !in_array((string) $assetTypes[0], ['canvas_upload', 'canvas_design'], true)) {
            return null;
        }
        $asset = Db::name('assets')->where('job_id', $job['id'])
            ->where('merchant_id', $job['merchant_id'])
            ->where('status', 'pending')
            ->whereIn('asset_type', ['canvas_upload', 'canvas_design'])
            ->find();
        return $asset ?: null;
    }

    private function recoverStaleChecks(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - 10 * 60);
        Db::name('generation_jobs')->where('status', 'queued')->where('safety_status', 'checking')->where('updated_at', '<', $cutoff)->update([
            'safety_status' => 'retrying',
            'safety_next_retry_at' => Web::now(),
            'progress_stage' => 'safety_retrying',
            'progress_message' => '安全检查进程中断，已自动重新排队',
            'updated_at' => Web::now(),
        ]);
    }
}
