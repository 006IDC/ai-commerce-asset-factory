<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use Throwable;
use think\facade\Db;

final class AiModelCallRecorder
{
    private array $costProfiles = [];

    public function record(string $capability, ?int $modelId, array $result, int $durationMs, string $status, array $context = []): void
    {
        if (!AiCapability::exists($capability)) {
            return;
        }

        try {
            $inputUnits = $this->usageValue($result, 'prompt_tokens', 'input_tokens');
            $outputUnits = $this->usageValue($result, 'completion_tokens', 'output_tokens');
            Db::name('ai_model_calls')->insert([
                'merchant_id' => $this->positiveId($context['merchant_id'] ?? null),
                'job_id' => $this->positiveId($context['job_id'] ?? null),
                'capability_code' => $capability,
                'ai_model_id' => $modelId && $modelId > 0 ? $modelId : null,
                'request_id' => $this->trim((string) ($result['requestId'] ?? ($result['data']['id'] ?? '')), 190),
                'status' => in_array($status, ['success', 'failed', 'fallback', 'review'], true) ? $status : 'failed',
                'attempt_no' => isset($context['attempt_no']) ? max(1, (int) $context['attempt_no']) : null,
                'route_role' => $this->trim((string) ($context['route_role'] ?? ''), 30),
                'protocol' => $this->trim((string) ($context['protocol'] ?? ''), 40),
                'http_status' => isset($result['status']) && is_numeric($result['status'])
                    ? max(0, (int) $result['status'])
                    : null,
                'duration_ms' => max(0, $durationMs),
                'input_units' => $inputUnits,
                'output_units' => $outputUnits,
                'estimated_cost' => $this->estimatedCost($modelId, $result, $inputUnits, $outputUnits),
                'actual_cost' => isset($context['actual_cost']) && is_numeric($context['actual_cost'])
                    ? max(0, (float) $context['actual_cost'])
                    : null,
                'error_code' => $this->errorCode($result),
                'error_message' => !empty($result['ok']) ? null : $this->trim((string) ($result['message'] ?? '模型调用失败。'), 1000),
                'prompt_hash' => $this->promptHash($context['payload'] ?? null),
                'started_at' => $this->trim((string) ($context['started_at'] ?? ''), 30),
                'finished_at' => $this->trim((string) ($context['finished_at'] ?? ''), 30),
                'created_at' => Web::now(),
            ]);
        } catch (Throwable) {
            // Metrics must never turn a successful model call into a failed user request.
        }
    }

    private function positiveId(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function usageValue(array $result, string $primary, string $alternate): ?float
    {
        $usage = $result['data']['usage'] ?? null;
        if (!is_array($usage)) {
            return null;
        }
        $value = $usage[$primary] ?? $usage[$alternate] ?? null;
        return is_numeric($value) ? (float) $value : null;
    }

    private function estimatedCost(?int $modelId, array $result, ?float $inputUnits, ?float $outputUnits): ?float
    {
        if ($modelId === null || $modelId <= 0 || empty($result['ok'])) {
            return null;
        }
        if (!array_key_exists($modelId, $this->costProfiles)) {
            $limits = Db::name('ai_models')->where('id', $modelId)->value('limits_json');
            $decoded = json_decode((string) ($limits ?? '{}'), true);
            $profile = is_array($decoded) && is_array($decoded['upstream_cost'] ?? null)
                ? $decoded['upstream_cost']
                : [];
            $this->costProfiles[$modelId] = $profile;
        }
        $profile = $this->costProfiles[$modelId];
        $mode = (string) ($profile['mode'] ?? 'unconfigured');
        if (!in_array($mode, ['free', 'resource_pack', 'paid'], true)) {
            return null;
        }
        if ($mode !== 'paid') {
            return 0.0;
        }

        $cost = max(0.0, (float) ($profile['fixed_per_request'] ?? 0));
        $cost += max(0.0, (float) ($inputUnits ?? 0)) / 1000000
            * max(0.0, (float) ($profile['input_per_million'] ?? 0));
        $cost += max(0.0, (float) ($outputUnits ?? 0)) / 1000000
            * max(0.0, (float) ($profile['output_per_million'] ?? 0));
        return round($cost, 6);
    }

    private function errorCode(array $result): ?string
    {
        if (!empty($result['ok'])) {
            return null;
        }
        $code = $result['data']['error']['code'] ?? $result['status'] ?? null;
        return $code === null || $code === '' ? null : $this->trim((string) $code, 80);
    }

    private function promptHash(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }
        $parts = [];
        $this->collectText($payload, $parts);
        $text = trim(implode("\n", $parts));
        return $text === '' ? null : hash('sha256', $text);
    }

    private function collectText(mixed $value, array &$parts): void
    {
        if (is_string($value)) {
            if (!str_starts_with($value, 'data:image/')) {
                $parts[] = $value;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['image', 'image_url', 'mask', 'b64_json'], true)) {
                continue;
            }
            $this->collectText($item, $parts);
        }
    }

    private function trim(string $value, int $width): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_strimwidth($value, 0, $width, '');
    }
}
