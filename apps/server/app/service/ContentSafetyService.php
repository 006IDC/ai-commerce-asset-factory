<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class ContentSafetyService
{
    public function __construct(
        private readonly SystemSettings $settings,
        private readonly NewApiClient $newApi,
        private readonly SmtpMailer $mailer
    ) {
    }

    public function configuration(): array
    {
        $configuration = [
            'routingMode' => $this->settings->get('ai_routing_mode') === 'capability' ? 'capability' : 'legacy',
            'enabled' => $this->settings->get('content_safety_enabled') === '1',
            'externalEnabled' => $this->settings->get('content_safety_external_enabled') === '1',
            'failClosed' => $this->settings->get('content_safety_fail_closed') !== '0',
            'model' => trim($this->settings->get('content_safety_model')),
            'fallbackModel' => trim($this->settings->get('content_safety_fallback_model')),
            'penaltyCredits' => max(0, min(1000000, (int) $this->settings->get('content_safety_penalty_credits'))),
            'terms' => $this->settings->get('content_safety_terms'),
        ];
        $configuration += $this->serviceDiagnostic($configuration);
        return $configuration;
    }

    public function enforceText(array $merchant, array $user, string $text, string $source, string $ipAddress): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['model' => 'local-rules', 'requestId' => null, 'cacheHit' => false];
        }
        return $this->enforce($merchant, $user, 'text', $text, null, $source, $ipAddress);
    }

    public function enforceImage(array $merchant, array $user, array $image, string $source, string $ipAddress): array
    {
        return $this->enforce($merchant, $user, 'image', '', $image, $source, $ipAddress);
    }

    /**
     * De-duplicate and process a bounded image batch. The moderation cache is
     * shared with enforceImage(), so repeated references do not call upstream.
     * The small batch boundary prevents one launch from monopolising a worker.
     */
    public function enforceImages(array $merchant, array $user, array $images, string $source, string $ipAddress, int $batchSize = 2): array
    {
        $unique = [];
        $traces = [];
        foreach (array_slice($images, 0, 6) as $image) {
            if (!is_array($image) || empty($image['content'])) {
                continue;
            }
            $unique[hash('sha256', (string) $image['content'])] = $image;
        }
        foreach (array_chunk(array_values($unique), max(1, min(3, $batchSize))) as $batch) {
            $traces = array_merge($traces, $this->enforceImageBatch($merchant, $user, $batch, $source, $ipAddress));
        }
        $models = array_values(array_unique(array_filter(array_map(static fn (array $trace): string => trim((string) ($trace['model'] ?? '')), $traces))));
        $requestIds = array_values(array_unique(array_filter(array_map(static fn (array $trace): string => trim((string) ($trace['requestId'] ?? '')), $traces))));
        return [
            'model' => implode(',', $models) ?: 'local-rules',
            'requestId' => implode(',', $requestIds) ?: null,
            'cacheHit' => $traces !== [] && count(array_filter($traces, static fn (array $trace): bool => !empty($trace['cacheHit']))) === count($traces),
            'checks' => $traces,
        ];
    }

    private function enforceImageBatch(array $merchant, array $user, array $batch, string $source, string $ipAddress): array
    {
        $runner = dirname(__DIR__, 2) . '/scripts/content-safety-image-child.php';
        if (count($batch) < 2 || !function_exists('proc_open') || !is_file($runner)) {
            return array_map(fn (array $image): array => $this->enforceImage($merchant, $user, $image, $source, $ipAddress), $batch);
        }
        $children = [];
        foreach ($batch as $index => $image) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $runner],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 2)
            );
            if (!is_resource($process) || count($pipes) !== 3) {
                if (is_resource($process)) {
                    $children[] = ['process' => $process, 'pipes' => $pipes];
                }
                $this->stopImageSafetyChildren($children);
                return array_map(fn (array $candidate): array => $this->enforceImage($merchant, $user, $candidate, $source, $ipAddress), $batch);
            }
            $children[] = [
                'process' => $process,
                'pipes' => $pipes,
                'index' => $index,
                'input' => serialize([
                    'merchant' => $merchant,
                    'user' => $user,
                    'image' => $image,
                    'source' => $source,
                    'ip_address' => $ipAddress,
                ]),
            ];
        }
        foreach ($children as &$child) {
            $input = (string) $child['input'];
            $written = 0;
            while ($written < strlen($input)) {
                $chunk = fwrite($child['pipes'][0], substr($input, $written));
                if (!is_int($chunk) || $chunk <= 0) {
                    break;
                }
                $written += $chunk;
            }
            fclose($child['pipes'][0]);
            $child['input_complete'] = $written === strlen($input);
            unset($child['input']);
        }
        unset($child);
        $results = [];
        $error = null;
        foreach ($children as $child) {
            $stdout = stream_get_contents($child['pipes'][1]);
            $stderr = stream_get_contents($child['pipes'][2]);
            fclose($child['pipes'][1]);
            fclose($child['pipes'][2]);
            $exitCode = proc_close($child['process']);
            $payload = json_decode(is_string($stdout) ? $stdout : '', true);
            if (empty($child['input_complete']) || $exitCode !== 0 || !is_array($payload) || empty($payload['ok'])) {
                $error ??= is_array($payload) ? $payload : ['type' => 'error', 'message' => '图片安全检查子进程异常退出。'];
                if (!is_array($payload) && is_string($stderr) && trim($stderr) !== '') {
                    $error['message'] = mb_strimwidth(trim($stderr), 0, 500, '');
                }
                continue;
            }
            $results[(int) $child['index']] = (array) ($payload['result'] ?? []);
        }
        if ($error !== null) {
            $message = trim((string) ($error['message'] ?? '图片安全检查失败。'));
            throw match ((string) ($error['type'] ?? 'error')) {
                'violation' => new ContentSafetyViolationException($message),
                'unavailable' => new ContentSafetyUnavailableException($message),
                default => new RuntimeException($message),
            };
        }
        ksort($results);
        return array_values($results);
    }

    private function stopImageSafetyChildren(array $children): void
    {
        foreach ($children as $child) {
            foreach ((array) ($child['pipes'] ?? []) as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($child['process'] ?? null)) {
                proc_terminate($child['process']);
                proc_close($child['process']);
            }
        }
    }

    private function enforce(array $merchant, array $user, string $type, string $text, ?array $image, string $source, string $ipAddress): array
    {
        $configuration = $this->configuration();
        if (!$configuration['enabled']) {
            return ['model' => 'disabled', 'requestId' => null, 'cacheHit' => false];
        }
        $rawHash = hash('sha256', $type . "\0" . ($type === 'image' ? (string) $image['content'] : $text));
        $policyHash = hash('sha256', json_encode([
            $this->newApi->capabilityRoutingEnabledFor((int) $merchant['id']) ? 'capability' : 'legacy',
            $configuration['externalEnabled'],
            $configuration['model'],
            $configuration['fallbackModel'],
            $configuration['terms'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $checkHash = hash('sha256', $rawHash . "\0" . $policyHash);
        $cached = Db::name('content_moderation_checks')->where('content_hash', $checkHash)
            ->whereIn('status', ['safe', 'blocked'])->order('id', 'desc')->find();
        if ($cached) {
            Db::name('content_moderation_checks')->where('id', $cached['id'])
                ->inc('cache_hit_count')->update(['last_cache_hit_at' => Web::now()]);
            if ($cached['status'] === 'blocked') {
                $categories = decode_json_list($cached['categories']);
                $this->reject($merchant, $user, $type, $rawHash, $source, $categories, (string) $cached['reason'], $text, $ipAddress);
            }
            return ['model' => (string) ($cached['model'] ?? 'cache'), 'requestId' => $cached['request_id'] ?? null, 'cacheHit' => true];
        }

        $localMatch = $type === 'text' ? $this->matchForbiddenTerm($text, (string) $configuration['terms']) : null;
        if ($localMatch !== null) {
            $categories = ['prohibited_terms'];
            $this->recordCheck($merchant, $user, $type, $checkHash, $source, 'blocked', $categories, '命中平台违禁词规则。', 'local-rules', null);
            $this->reject($merchant, $user, $type, $rawHash, $source, $categories, '命中平台违禁词规则。', $text, $ipAddress);
        }

        if (!$configuration['externalEnabled']) {
            return ['model' => 'local-rules', 'requestId' => null, 'cacheHit' => false];
        }
        $result = $this->newApi->moderateContent(
            $type === 'text' ? $text : null,
            $image,
            $configuration['model'],
            $configuration['fallbackModel'],
            (int) $merchant['id']
        );
        if (!$result['ok']) {
            $this->recordCheck($merchant, $user, $type, $checkHash, $source, 'error', [], (string) $result['reason'], (string) $result['model'], null);
            if ($configuration['failClosed']) {
                throw new ContentSafetyUnavailableException('内容安全服务暂时不可用，本次未进入生成；这不是内容违规，请稍后在原任务手动重试。');
            }
            Audit::log('system', 0, 'content_safety.degraded', 'content_moderation_check', null, $ipAddress, [
                'merchant_id' => (int) $merchant['id'],
                'user_id' => (int) $user['id'],
                'content_type' => $type,
                'source' => $source,
                'reason' => (string) $result['reason'],
                'model' => (string) $result['model'],
            ]);
            return ['model' => (string) ($result['model'] ?? ''), 'requestId' => $result['requestId'] ?? null, 'cacheHit' => false];
        }
        $categories = array_values(array_unique(array_map('strval', $result['categories'] ?? [])));
        $status = $result['flagged'] ? 'blocked' : 'safe';
        $this->recordCheck($merchant, $user, $type, $checkHash, $source, $status, $categories, (string) ($result['reason'] ?? ''), (string) ($result['model'] ?? ''), $result['requestId'] ?? null);
        if ($status === 'blocked') {
            $this->reject($merchant, $user, $type, $rawHash, $source, $categories, (string) ($result['reason'] ?? ''), $text, $ipAddress);
        }
        return ['model' => (string) ($result['model'] ?? ''), 'requestId' => $result['requestId'] ?? null, 'cacheHit' => false];
    }

    private function reject(array $merchant, array $user, string $type, string $contentHash, string $source, array $categories, string $reason, string $text, string $ipAddress): never
    {
        $violation = $this->registerViolation($merchant, $user, $type, $contentHash, $source, $categories, $reason, $text, $ipAddress);
        $labels = $this->categoryLabels($categories);
        $categoryText = $labels === [] ? '违反平台内容安全规则' : implode('、', $labels);
        $penaltyText = (int) $violation['penalty_credits'] > 0 ? '，已扣除 ' . (int) $violation['penalty_credits'] . ' 点数' : '';
        throw new ContentSafetyViolationException('内容安全检测未通过：' . $categoryText . $penaltyText . '，请求已驳回。');
    }

    private function registerViolation(array $merchant, array $user, string $type, string $contentHash, string $source, array $categories, string $reason, string $text, string $ipAddress): array
    {
        $configuration = $this->configuration();
        $result = Db::transaction(function () use ($merchant, $user, $type, $contentHash, $source, $categories, $reason, $text, $ipAddress, $configuration): array {
            $existing = Db::name('content_violations')->where('merchant_id', $merchant['id'])
                ->where('content_type', $type)->where('content_hash', $contentHash)->find();
            if ($existing) {
                return ['record' => $existing, 'created' => false];
            }
            $lockedMerchant = Db::name('merchants')->where('id', $merchant['id'])->lock(true)->find();
            if (!$lockedMerchant) {
                throw new RuntimeException('商户账户不存在。');
            }
            $deduction = min((int) $lockedMerchant['credit_balance'], (int) $configuration['penaltyCredits']);
            $now = Web::now();
            $violationId = Db::name('content_violations')->insertGetId([
                'merchant_id' => $merchant['id'],
                'user_id' => $user['id'],
                'content_type' => $type,
                'content_hash' => $contentHash,
                'source' => $source,
                'categories' => json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'reason' => mb_strimwidth($reason, 0, 500, ''),
                'content_excerpt' => $type === 'text' ? mb_strimwidth(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 240, '') : null,
                'penalty_credits' => $deduction,
                'warning_email_status' => 'pending',
                'warning_error' => null,
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);
            if ($deduction > 0) {
                $balance = (int) $lockedMerchant['credit_balance'] - $deduction;
                Db::name('merchants')->where('id', $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                Db::name('credit_ledger')->insert([
                    'merchant_id' => $merchant['id'],
                    'type' => 'content_violation_penalty',
                    'amount' => -$deduction,
                    'balance_after' => $balance,
                    'source' => 'content_moderation',
                    'reference_type' => 'content_violation',
                    'reference_id' => (string) $violationId,
                    'note' => '内容安全违规处罚',
                    'created_at' => $now,
                ]);
            }
            $record = Db::name('content_violations')->where('id', $violationId)->find();
            return ['record' => $record, 'created' => true];
        });
        $record = $result['record'];
        if ($result['created']) {
            Audit::log('merchant_user', (int) $user['id'], 'content_safety.violation', 'content_violation', (int) $record['id'], $ipAddress, [
                'content_type' => $type,
                'content_hash' => $contentHash,
                'source' => $source,
                'categories' => $categories,
                'penalty_credits' => (int) $record['penalty_credits'],
            ]);
            try {
                $this->mailer->sendContentViolationWarning((string) $user['email'], $this->categoryLabels($categories), (int) $record['penalty_credits'], $this->sourceLabel($source));
                Db::name('content_violations')->where('id', $record['id'])->update(['warning_email_status' => 'sent', 'warning_error' => null]);
            } catch (\Throwable $exception) {
                Db::name('content_violations')->where('id', $record['id'])->update([
                    'warning_email_status' => 'failed',
                    'warning_error' => mb_strimwidth($exception->getMessage(), 0, 500, ''),
                ]);
            }
        }

        return $record;
    }

    private function recordCheck(array $merchant, array $user, string $type, string $hash, string $source, string $status, array $categories, string $reason, string $model, ?string $requestId): void
    {
        Db::name('content_moderation_checks')->insert([
            'merchant_id' => $merchant['id'],
            'user_id' => $user['id'],
            'content_type' => $type,
            'content_hash' => $hash,
            'source' => $source,
            'status' => $status,
            'categories' => json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reason' => mb_strimwidth($reason, 0, 500, ''),
            'model' => $model,
            'request_id' => $requestId,
            'cache_hit_count' => 0,
            'last_cache_hit_at' => null,
            'created_at' => Web::now(),
        ]);
    }

    private function serviceDiagnostic(array $configuration): array
    {
        $default = [
            'serviceState' => !$configuration['enabled'] ? 'disabled' : (!$configuration['externalEnabled'] ? 'local_only' : 'ready'),
            'lastServiceError' => '',
            'serviceNotice' => '',
            'lastCheckedAt' => '',
        ];
        if (!$configuration['enabled'] || !$configuration['externalEnabled']) {
            return $default;
        }
        try {
            $latest = Db::name('content_moderation_checks')->whereIn('status', ['safe', 'blocked', 'error'])
                ->order('id', 'desc')->field('status,reason,created_at')->find();
            if (!$latest) {
                return $default;
            }
            $reason = (string) $latest['reason'];
            return [
                'serviceState' => (string) $latest['status'] === 'error' ? 'degraded' : 'ready',
                'lastServiceError' => (string) $latest['status'] === 'error' ? (string) $latest['reason'] : '',
                'serviceNotice' => (string) $latest['status'] !== 'error' && str_contains($reason, '已自动切换至备用视觉审核') ? $reason : '',
                'lastCheckedAt' => (string) $latest['created_at'],
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    private function matchForbiddenTerm(string $text, string $terms): ?string
    {
        $normalized = $this->normalize($text);
        foreach (preg_split('/\r\n|\n|\r/', $terms) ?: [] as $term) {
            $term = trim($term);
            if ($term !== '' && str_contains($normalized, $this->normalize($term))) {
                return $term;
            }
        }
        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_convert_kana(mb_strtolower($text), 'asKV', 'UTF-8');
        return preg_replace('/[\p{Z}\p{P}\p{S}_]+/u', '', $text) ?? $text;
    }

    public function categoryLabels(array $categories): array
    {
        $labels = [];
        foreach ($categories as $category) {
            $value = mb_strtolower((string) $category);
            $label = match (true) {
                str_contains($value, 'minor') || str_contains($value, '未成年') => '未成年人性内容',
                str_contains($value, 'sexual') || str_contains($value, '色情') || str_contains($value, '裸露') => '色情或裸露',
                str_contains($value, 'violence') || str_contains($value, '暴力') || str_contains($value, '血腥') => '血腥暴力',
                str_contains($value, 'illicit') || str_contains($value, '违法') || str_contains($value, '犯罪') || str_contains($value, '毒品') || str_contains($value, '武器') => '违法犯罪',
                str_contains($value, 'hate') || str_contains($value, 'extrem') || str_contains($value, '仇恨') || str_contains($value, '恐怖') => '仇恨或极端主义',
                str_contains($value, 'self-harm') || str_contains($value, '自残') => '自残自伤',
                str_contains($value, 'prohibited_terms') => '平台违禁词',
                default => '其他违规内容',
            };
            $labels[$label] = true;
        }
        return array_keys($labels);
    }

    public function sourceLabel(string $source): string
    {
        return [
            'sku_create_text' => '新建 SKU 文本',
            'sku_create_image' => '新建 SKU 图片',
            'sku_update_text' => '编辑 SKU 文本',
            'sku_update_image' => '编辑 SKU 图片',
            'sku_analyze_text' => 'AI 商品分析文本',
            'sku_analyze_image' => 'AI 商品分析图片',
            'generation_text' => '提交生图文本',
            'generation_image' => '提交生图参考图',
            'free_generation_text' => '自由创作提示词',
            'free_generation_image' => '自由创作参考图',
            'free_prompt_idea' => 'AI 提示词润色',
            'canvas_edit_text' => '画布编辑指令',
            'canvas_edit_image' => '画布编辑源图',
            'detail_report_text' => 'AI 详情页补充内容',
            'detail_report_image' => 'AI 详情页商品实拍图',
            'sku_detail_report_text' => 'SKU 产品报告补充内容',
            'sku_detail_report_image' => 'SKU 产品报告商品图',
            'detail_page_generation_text' => '十屏详情页报告内容',
            'detail_page_generation_image' => '十屏详情页商品图',
        ][$source] ?? '商户内容提交';
    }
}
