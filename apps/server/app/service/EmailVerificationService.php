<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use app\support\Web;
use think\facade\Db;

final class EmailVerificationService
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly SmtpMailer $mailer
    ) {
    }

    public function send(string $email, string $purpose, ?int $userId, string $ipAddress): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('请输入有效邮箱。');
        }
        if (!in_array($purpose, ['register', 'change_email', 'reset_password'], true)) {
            throw new RuntimeException('验证码用途不正确。');
        }

        $lastMinute = date('Y-m-d H:i:s', time() - 60);
        if (Db::name('email_verification_codes')->where('email', $email)->where('purpose', $purpose)
            ->where('created_at', '>=', $lastMinute)->find()) {
            throw new RuntimeException('验证码发送过于频繁，请 60 秒后重试。');
        }
        $lastHour = date('Y-m-d H:i:s', time() - 3600);
        $emailCount = Db::name('email_verification_codes')->where('email', $email)->where('created_at', '>=', $lastHour)->count();
        $ipCount = Db::name('email_verification_codes')->where('requested_ip', $ipAddress)->where('created_at', '>=', $lastHour)->count();
        if ($emailCount >= 5 || $ipCount >= 12) {
            throw new RuntimeException('验证码发送次数过多，请一小时后重试。');
        }
        $lastDay = date('Y-m-d H:i:s', time() - 86400);
        $emailDayCount = Db::name('email_verification_codes')->where('email', $email)->where('created_at', '>=', $lastDay)->count();
        $ipDayCount = Db::name('email_verification_codes')->where('requested_ip', $ipAddress)->where('created_at', '>=', $lastDay)->count();
        if ($emailDayCount >= 10 || $ipDayCount >= 30) {
            throw new RuntimeException('今日验证码发送次数已达上限，请明天再试。');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = Web::now();
        Db::name('email_verification_codes')->where('email', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')->update(['consumed_at' => $now]);
        $id = Db::name('email_verification_codes')->insertGetId([
            'email' => $email,
            'purpose' => $purpose,
            'user_id' => $userId,
            'code_hash' => $this->codeHash($email, $purpose, $code),
            'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'consumed_at' => null,
            'requested_ip' => $ipAddress,
            'created_at' => $now,
        ]);
        try {
            $this->mailer->sendVerificationCode($email, $code, $purpose);
        } catch (\Throwable $exception) {
            Db::name('email_verification_codes')->where('id', $id)->delete();
            throw $exception;
        }
    }

    public function consume(string $email, string $purpose, string $code, ?int $userId): void
    {
        $email = strtolower(trim($email));
        $query = Db::name('email_verification_codes')->where('email', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')->order('id', 'desc')->lock(true);
        $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);
        $record = $query->find();
        if (!$record || strtotime((string) $record['expires_at']) < time()) {
            throw new RuntimeException('验证码不存在或已过期，请重新获取。');
        }
        if ((int) $record['attempts'] >= 5) {
            throw new RuntimeException('验证码错误次数过多，请重新获取。');
        }
        if (!hash_equals((string) $record['code_hash'], $this->codeHash($email, $purpose, trim($code)))) {
            Db::name('email_verification_codes')->where('id', $record['id'])->inc('attempts')->update();
            throw new RuntimeException('邮箱验证码不正确。');
        }
        Db::name('email_verification_codes')->where('id', $record['id'])->update(['consumed_at' => Web::now()]);
    }

    private function codeHash(string $email, string $purpose, string $code): string
    {
        return $this->cipher->fingerprint($email . '|' . $purpose . '|' . $code, 'email_verification');
    }
}
