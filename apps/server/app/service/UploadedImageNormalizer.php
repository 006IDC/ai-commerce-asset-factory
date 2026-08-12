<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class UploadedImageNormalizer
{
    public function normalize(object $file): array
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = (string) $file->getMime();
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > 24 * 1024 * 1024 || !in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('商品图仅支持 JPG、PNG、WebP，原文件不能超过 24MB。');
        }
        $path = (string) $file->getPathname();
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new RuntimeException('读取上传的商品图失败。');
        }
        $dimensions = getimagesizefromstring($content);
        if (!$dimensions || (int) $dimensions[0] <= 0 || (int) $dimensions[1] <= 0) {
            throw new RuntimeException('上传的商品图无法识别。');
        }
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        if ($size <= 8 * 1024 * 1024 && max($width, $height) <= 2400) {
            return ['content' => $content, 'mime' => $mime, 'filename' => 'product-image'];
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('服务器缺少 GD 扩展，无法优化手机大图。');
        }
        $source = @imagecreatefromstring($content);
        if ($source === false) {
            throw new RuntimeException('上传的商品图无法解码。');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $rotated = match ($orientation) {
                3 => imagerotate($source, 180, 0),
                6 => imagerotate($source, -90, 0),
                8 => imagerotate($source, 90, 0),
                default => false,
            };
            if ($rotated !== false) {
                $source = $rotated;
                $width = imagesx($source);
                $height = imagesy($source);
            }
        }
        $scale = min(1, 2400 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            throw new RuntimeException('服务器无法创建商品图优化画布。');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start();
        $outputMime = $mime === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
        $saved = $outputMime === 'image/jpeg' ? imagejpeg($target, null, 88) : imagewebp($target, null, 88);
        $normalized = ob_get_clean();
        if (!$saved || !is_string($normalized) || $normalized === '' || strlen($normalized) > 10 * 1024 * 1024) {
            throw new RuntimeException('手机大图优化失败，请压缩后重试。');
        }
        return ['content' => $normalized, 'mime' => $outputMime, 'filename' => 'product-image'];
    }
}
