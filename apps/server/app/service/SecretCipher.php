<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class SecretCipher
{
    public function encrypt(string $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('敏感数据加密失败。');
        }

        return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'enc:v1:')) {
            return $value;
        }
        $decoded = base64_decode(substr($value, 7), true);
        if ($decoded === false || strlen($decoded) < 28) {
            throw new RuntimeException('敏感数据格式无效。');
        }
        $plain = openssl_decrypt(
            substr($decoded, 28),
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($decoded, 0, 12),
            substr($decoded, 12, 16)
        );
        if ($plain === false) {
            throw new RuntimeException('敏感数据解密失败，请检查 APP_KEY。');
        }

        return $plain;
    }

    public function fingerprint(string $value, string $context): string
    {
        return hash_hmac('sha256', $context . "\0" . $value, $this->key());
    }

    private function key(): string
    {
        $appKey = trim((string) env('APP_KEY', ''));
        if ($appKey === '') {
            throw new RuntimeException('尚未配置 APP_KEY，不能安全处理敏感数据。');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                $appKey = $decoded;
            }
        }

        return hash('sha256', $appKey, true);
    }
}
