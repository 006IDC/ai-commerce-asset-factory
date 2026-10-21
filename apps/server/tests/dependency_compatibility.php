<?php

declare(strict_types=1);

use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\WhitespacePathNormalizer;
use PHPMailer\PHPMailer\PHPMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

$normalizer = new WhitespacePathNormalizer();
foreach (["foo\x1bbar", "foo\x80\x1bbar", "foo\x80bar"] as $path) {
    try {
        $normalizer->normalizePath($path);
    } catch (CorruptedPathDetected) {
        continue;
    }
    throw new RuntimeException('Flysystem must reject control characters and malformed UTF-8 paths.');
}
if ($normalizer->normalizePath('商品/图片.png') !== '商品/图片.png') {
    throw new RuntimeException('Flysystem must preserve valid Unicode paths.');
}

$mail = new PHPMailer(true);
$mail->CharSet = 'UTF-8';
$mail->setFrom('sender@example.com', '合成测试');
$mail->addAddress('recipient@example.com');
$mail->isHTML(true);
$mail->Subject = '合成验证码';
$mail->Body = '<p>验证码：123456</p>';
$mail->AltBody = '验证码：123456';
if (!$mail->preSend() || !str_contains($mail->getSentMIMEMessage(), 'multipart/alternative')) {
    throw new RuntimeException('PHPMailer must compose UTF-8 HTML and plaintext verification mail.');
}
echo "Dependency compatibility contract passed.\n";
