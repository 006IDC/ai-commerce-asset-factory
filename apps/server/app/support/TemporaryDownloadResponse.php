<?php

declare(strict_types=1);

namespace app\support;

use think\Response;

final class TemporaryDownloadResponse extends Response
{
    public function __construct(
        private readonly string $path,
        private readonly string $downloadName,
        private readonly string $mimeType = 'application/octet-stream'
    ) {
        $this->init('', 200);
    }

    public function send(): void
    {
        if (!is_file($this->path)) {
            http_response_code(404);
            echo 'Download file not found.';
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $encodedName = rawurlencode($this->downloadName);
        header('Content-Type: ' . $this->mimeType);
        header('Content-Length: ' . (string) filesize($this->path));
        header('Content-Disposition: attachment; filename="' . $encodedName . '"; filename*=UTF-8\'\'' . $encodedName);
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');

        $stream = fopen($this->path, 'rb');
        try {
            if ($stream === false) {
                throw new \RuntimeException('无法读取临时下载文件');
            }
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($this->path);
        }
    }
}
