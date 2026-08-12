<?php

declare(strict_types=1);

namespace app\service;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final class SmtpMailer
{
    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function configuration(): array
    {
        $host = trim($this->settings->get('smtp_host'));
        $fromEmail = strtolower(trim($this->settings->get('smtp_from_email')));
        $password = $this->settings->get('smtp_password');

        return [
            'configured' => $host !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false,
            'host' => $host,
            'port' => max(1, (int) $this->settings->get('smtp_port')),
            'encryption' => $this->settings->get('smtp_encryption'),
            'username' => $this->settings->get('smtp_username'),
            'passwordConfigured' => $password !== '',
            'passwordStoredInDatabase' => $this->settings->hasDatabaseValue('smtp_password'),
            'fromEmail' => $fromEmail,
            'fromName' => $this->settings->get('smtp_from_name'),
        ];
    }

    public function sendVerificationCode(string $email, string $code, string $purpose): void
    {
        $scene = match ($purpose) {
            'change_email' => '修改绑定邮箱',
            'reset_password' => '重置登录密码',
            default => '注册商户账户',
        };
        $subject = '邮箱验证码 - AI 电商素材工厂';
        $body = '<p>你正在' . htmlspecialchars($scene, ENT_QUOTES, 'UTF-8') . '。</p>'
            . '<p>验证码：<strong style="font-size:24px;letter-spacing:4px">' . $code . '</strong></p>'
            . '<p>验证码 10 分钟内有效，请勿转发给他人。</p>';
        $this->send($email, $subject, $body, "验证码：{$code}，10 分钟内有效。");
    }

    public function sendTest(string $email): void
    {
        $this->send(
            $email,
            'SMTP 测试邮件 - AI 电商素材工厂',
            '<p>SMTP 配置测试成功，业务系统可以发送邮箱验证码。</p>',
            'SMTP 配置测试成功，业务系统可以发送邮箱验证码。'
        );
    }

    public function sendContentViolationWarning(string $email, array $categoryLabels, int $penaltyCredits, string $sourceLabel): void
    {
        $categories = $categoryLabels === [] ? '违反平台内容安全规则' : implode('、', $categoryLabels);
        $penaltyText = $penaltyCredits > 0 ? "本次已扣除 {$penaltyCredits} 点数。" : '本次账户没有可扣除点数。';
        $subject = '内容安全警告 - AI 电商素材工厂';
        $html = '<p>你的账户提交了未通过内容安全检测的请求，系统已拒绝处理。</p>'
            . '<p>提交场景：<strong>' . htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p>违规分类：<strong>' . htmlspecialchars($categories, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p>' . htmlspecialchars($penaltyText, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>请勿使用本平台制作色情、违法、血腥暴力或其他违反法律法规的内容。重复违规可能导致账户停用。</p>';
        $text = "你的请求未通过内容安全检测。提交场景：{$sourceLabel}；违规分类：{$categories}。{$penaltyText}请勿再次提交违规内容。";
        $this->send($email, $subject, $html, $text);
    }

    private function send(string $email, string $subject, string $html, string $text): void
    {
        $config = $this->configuration();
        if (!$config['configured']) {
            throw new RuntimeException('SMTP 尚未配置，请联系管理员。');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('收件邮箱格式不正确。');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->Timeout = 8;
            $mail->SMTPAuth = $config['username'] !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $config['username'];
                $mail->Password = $this->settings->get('smtp_password');
            }
            if ($config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($config['fromEmail'], $config['fromName'] ?: 'AI 电商素材工厂');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
        } catch (Throwable $exception) {
            $failure = trim((string) ($mail->ErrorInfo ?: $exception->getMessage()));
            if (str_contains(strtolower($failure), 'could not connect')
                || str_contains(strtolower($failure), 'failed to connect')) {
                throw new RuntimeException(
                    '邮件发送失败：暂时无法连接 SMTP 服务器。请稍后重试；若持续失败，请检查主机、端口、加密方式及邮件服务商连接限制。'
                );
            }
            throw new RuntimeException('邮件发送失败：' . $failure);
        }
    }
}
