<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class GeetestService
{
    private const REGISTER_URL = 'https://api.geetest.com/register.php';
    private const VALIDATE_URL = 'https://api.geetest.com/validate.php';

    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function configuration(): array
    {
        $captchaId = trim($this->settings->get('geetest_captcha_id'));
        $privateKey = trim($this->settings->get('geetest_private_key'));

        return [
            'configured' => $captchaId !== '' && $privateKey !== '',
            'captchaId' => $captchaId,
            'privateKeyConfigured' => $privateKey !== '',
            'privateKeyStoredInDatabase' => $this->settings->hasDatabaseValue('geetest_private_key'),
        ];
    }

    public function register(string $ipAddress): array
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            throw new RuntimeException('安全验证尚未配置。');
        }

        $result = $this->request(self::REGISTER_URL, [
            'gt' => $configuration['captchaId'],
            'client_type' => 'web',
            'ip_address' => $ipAddress,
            'json_format' => 1,
        ], false);
        $challenge = trim((string) ($result['challenge'] ?? ''));
        if ($challenge === '' || $challenge === '0') {
            throw new RuntimeException('安全验证暂时不可用，请稍后重试。');
        }

        return [
            'gt' => $configuration['captchaId'],
            'challenge' => md5($challenge . $this->settings->get('geetest_private_key')),
            'new_captcha' => true,
            'success' => 1,
        ];
    }

    public function verify(string $challenge, string $validate, string $seccode, string $ipAddress): void
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            return;
        }
        if ($challenge === '' || $validate === '' || $seccode === '') {
            throw new RuntimeException('请先完成安全验证。');
        }
        if (mb_strlen($challenge) > 256 || mb_strlen($validate) > 512 || mb_strlen($seccode) > 512) {
            throw new RuntimeException('安全验证数据格式不正确，请刷新页面后重试。');
        }
        $privateKey = $this->settings->get('geetest_private_key');
        $expectedValidate = md5($privateKey . 'geetest' . $challenge);
        if (strlen($validate) !== 32 || !hash_equals($expectedValidate, $validate)) {
            throw new RuntimeException('安全验证已失效，请刷新页面后重试。');
        }

        $result = $this->request(self::VALIDATE_URL, [
            'seccode' => $seccode,
            'challenge' => $challenge,
            'captchaid' => $configuration['captchaId'],
            'client_type' => 'web',
            'ip_address' => $ipAddress,
            'json_format' => 1,
            'sdk' => 'php_3.3.0',
        ], true);
        $returnedSeccode = trim((string) ($result['seccode'] ?? ''));
        $expectedSeccode = md5($seccode);
        if ($returnedSeccode === '' || $returnedSeccode === 'false' || !hash_equals($expectedSeccode, $returnedSeccode)) {
            throw new RuntimeException('安全验证未通过，请刷新页面后重试。');
        }
    }

    private function request(string $url, array $fields, bool $post): array
    {
        $curl = curl_init($post ? $url : $url . '?' . http_build_query($fields));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        if ($post) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('安全验证服务暂时不可用，请稍后重试。');
        }
        $result = json_decode((string) $body, true);
        if (!is_array($result)) {
            throw new RuntimeException('安全验证服务返回异常，请稍后重试。');
        }

        return $result;
    }
}
