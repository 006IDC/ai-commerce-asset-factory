<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Stable submission identities for paid generation entry points.
 *
 * The browser token identifies one deliberate form submission. A scoped,
 * irreversible database key prevents the same token from colliding across
 * product-image, bundle and detail-page endpoints.
 */
final class GenerationSubmissionGuard
{
    public function normalize(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value) !== 1) {
            throw new RuntimeException('本次发送标识缺失或无效，请刷新页面后重试。');
        }
        return $value;
    }

    public function scopedKey(string $clientSubmissionId, string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '') {
            throw new RuntimeException('生成任务提交范围不能为空。');
        }
        return 'gs-' . substr(hash('sha256', $scope . "\0" . $clientSubmissionId), 0, 48);
    }

    public function payloadHash(string $schemaVersion, array $payload): string
    {
        $encoded = json_encode(
            [
                'schema_version' => trim($schemaVersion),
                'payload' => $this->canonicalize($payload),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            throw new RuntimeException('本次生成需求无法建立稳定提交指纹。');
        }
        return hash('sha256', $encoded);
    }

    public function existing(int $merchantId, string $submissionKey): ?array
    {
        $row = Db::name('generation_jobs')
            ->where('merchant_id', $merchantId)
            ->where('client_submission_id', $submissionKey)
            ->find();
        return $row ?: null;
    }

    public function assertMatches(array $job, string $payloadHash): void
    {
        $stored = trim((string) ($job['submission_payload_hash'] ?? ''));
        if ($stored !== '' && !hash_equals($stored, $payloadHash)) {
            throw new RuntimeException('同一发送标识已用于另一条生成需求，请刷新页面后重新发送。');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
