<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class PublicReleaseService
{
    public const FILTERS = [
        'all' => '全部',
        'milestones' => '里程碑',
        'creation-workflow' => '创作与工作流',
        'admin-reliability' => '管理与可靠性',
    ];

    private ?array $validated = null;

    public function all(): array
    {
        if ($this->validated !== null) {
            return $this->validated;
        }

        $rows = require dirname(__DIR__, 2) . '/config/public_releases.php';
        if (!is_array($rows)) {
            throw new RuntimeException('公开版本清单格式不正确。');
        }
        $errors = $this->validationErrors($rows);
        if ($errors !== []) {
            throw new RuntimeException('公开版本清单校验失败：' . implode('；', $errors));
        }

        $this->validated = array_map([$this, 'publicFields'], array_values(array_filter(
            $rows,
            static fn (array $row): bool => !empty($row['visible'])
        )));
        return $this->validated;
    }

    public function filtered(string $filter): array
    {
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : 'all';
        return array_values(array_filter($this->all(), static function (array $row) use ($filter): bool {
            return match ($filter) {
                'milestones' => !empty($row['milestone']),
                'creation-workflow', 'admin-reliability' => (string) $row['category'] === $filter,
                default => true,
            };
        }));
    }

    public function recent(int $limit = 8): array
    {
        return array_slice($this->all(), 0, max(1, min(12, $limit)));
    }

    public function currentVersion(): string
    {
        return trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
    }

    public function publicVersion(): string
    {
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $this->currentVersion(), $matches)) {
            throw new RuntimeException('应用版本号格式不正确。');
        }
        return $matches[1];
    }

    public function filters(): array
    {
        return self::FILTERS;
    }

    public function validationErrors(?array $rows = null): array
    {
        $rows ??= require dirname(__DIR__, 2) . '/config/public_releases.php';
        $errors = [];
        $versions = [];
        $previous = null;
        $allowedCategories = ['creation-workflow', 'admin-reliability'];
        $sensitivePatterns = [
            '/api[\s_-]*key/i',
            '/secret/i',
            '/password/i',
            '/root@/i',
            '#/(?:opt|etc|root|var)/#i',
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
            '/internal\.example\.com/i',
            '/sub2api/i',
            '/comfyui/i',
        ];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $errors[] = "第 " . ($index + 1) . ' 项不是数组';
                continue;
            }
            $version = trim((string) ($row['version'] ?? ''));
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $errors[] = "版本 {$version} 不是 SemVer";
            }
            if (isset($versions[$version])) {
                $errors[] = "版本 {$version} 重复";
            }
            $versions[$version] = true;
            if ($previous !== null && version_compare($previous, $version, '<=')) {
                $errors[] = "版本 {$version} 排序不正确";
            }
            $previous = $version;
            if (!in_array((string) ($row['category'] ?? ''), $allowedCategories, true)) {
                $errors[] = "版本 {$version} 分类不正确";
            }
            foreach (['public_title', 'public_summary'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    $errors[] = "版本 {$version} 缺少 {$field}";
                }
            }
            if (empty($row['highlights']) || !is_array($row['highlights'])) {
                $errors[] = "版本 {$version} 缺少公开亮点";
            }
            if (trim((string) ($row['source_reference'] ?? '')) === '') {
                $errors[] = "版本 {$version} 缺少内部事实来源";
            }
            $publicText = implode(' ', [
                (string) ($row['public_title'] ?? ''),
                (string) ($row['public_summary'] ?? ''),
                implode(' ', array_map('strval', (array) ($row['highlights'] ?? []))),
            ]);
            foreach ($sensitivePatterns as $pattern) {
                if (preg_match($pattern, $publicText)) {
                    $errors[] = "版本 {$version} 公开文案包含敏感信息";
                    break;
                }
            }
        }

        if ($rows !== []) {
            try {
                $latest = trim((string) ($rows[0]['version'] ?? ''));
                if ($latest !== $this->publicVersion()) {
                    $errors[] = "最新公开版本 {$latest} 与应用版本不一致";
                }
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        return array_values(array_unique($errors));
    }

    private function publicFields(array $row): array
    {
        return [
            'version' => (string) $row['version'],
            'public_title' => (string) $row['public_title'],
            'public_summary' => (string) $row['public_summary'],
            'highlights' => array_values(array_map('strval', (array) $row['highlights'])),
            'category' => (string) $row['category'],
            'milestone' => !empty($row['milestone']),
        ];
    }
}
