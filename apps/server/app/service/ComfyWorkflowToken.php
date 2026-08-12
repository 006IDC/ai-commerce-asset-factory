<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class ComfyWorkflowToken
{
    private const CONTEXT = 'comfy-workflow-callback-v1';

    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    public function issue(
        int $jobId,
        int $itemId,
        int $modelId,
        string $capability,
        string $prompt,
        string $size,
        int $seed,
        int $expiresAt,
        string $scope = 'job',
        string $referencesHash = ''
    ): string {
        $claims = [
            'v' => 1,
            'scope' => $scope,
            'job_id' => max(0, $jobId),
            'item_id' => max(0, $itemId),
            'model_id' => max(0, $modelId),
            'capability' => $capability,
            'prompt_hash' => hash('sha256', $prompt),
            'size' => $size,
            'seed' => max(1, $seed),
            'references_hash' => strtolower(trim($referencesHash)),
            'exp' => max(time() + 30, $expiresAt),
        ];
        $payload = $this->encode(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $payload . '.' . $this->cipher->fingerprint($payload, self::CONTEXT);
    }

    public function verify(
        string $token,
        string $prompt,
        string $size,
        int $seed,
        string $referencesHash = ''
    ): array {
        [$payload, $signature] = array_pad(explode('.', trim($token), 2), 2, '');
        if ($payload === '' || $signature === '') {
            throw new RuntimeException('工作流回调令牌无效。');
        }
        $expected = $this->cipher->fingerprint($payload, self::CONTEXT);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('工作流回调令牌校验失败。');
        }
        $decoded = json_decode($this->decode($payload), true);
        if (!is_array($decoded) || (int) ($decoded['v'] ?? 0) !== 1) {
            throw new RuntimeException('工作流回调令牌格式无效。');
        }
        if ((int) ($decoded['exp'] ?? 0) < time()) {
            throw new RuntimeException('工作流回调令牌已过期。');
        }
        if (!hash_equals((string) ($decoded['prompt_hash'] ?? ''), hash('sha256', $prompt))
            || (string) ($decoded['size'] ?? '') !== $size
            || (int) ($decoded['seed'] ?? 0) !== max(1, $seed)
            || !hash_equals(
                (string) ($decoded['references_hash'] ?? ''),
                strtolower(trim($referencesHash))
            )) {
            throw new RuntimeException('工作流回调参数与任务快照不一致。');
        }
        return $decoded;
    }

    private function encode(string|false $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('无法创建工作流回调令牌。');
        }
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('工作流回调令牌无法解码。');
        }
        return $decoded;
    }
}
