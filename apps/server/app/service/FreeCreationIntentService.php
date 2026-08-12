<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

/**
 * Builds the immutable user intent for a free-creation request.
 *
 * Published inspiration templates are complete, sendable image requests.
 * They may be edited by the user, but selecting one never creates a second
 * hidden "subject" field or inherits content from another turn.
 */
final class FreeCreationIntentService
{
    /** @return array<string, int|string|null> */
    public function compile(
        string $postedPrompt,
        ?array $template,
        string $postedSubject = '',
        string $postedAddition = ''
    ): array {
        $postedPrompt = $this->limit($postedPrompt, 4000);
        $templateContent = $this->limit((string) ($template['content'] ?? ''), 3200);
        $subject = $this->limit($postedSubject, 1800);
        $addition = $this->limit($postedAddition, 1200);

        if ($template !== null) {
            if ($templateContent === '') {
                throw new RuntimeException('所选灵感模板没有可用内容，请重新选择。');
            }
            $prompt = $postedPrompt !== '' ? $postedPrompt : $templateContent;
            $subject = $subject !== '' ? $subject : $prompt;
            $addition = '';
        } else {
            // Without a selected template the exact current user message is the
            // only source of truth. Never inherit content from a prior turn.
            $subject = $subject !== '' ? $subject : $postedPrompt;
            $this->assertSpecificSubject($subject);
            $prompt = $postedPrompt !== '' ? $postedPrompt : $subject;
            $addition = '';
            $templateContent = '';
        }

        if (mb_strlen($prompt) < 4) {
            throw new RuntimeException('请填写至少 4 个字的具体创作主体。');
        }

        return [
            'prompt_template_id' => $template === null ? null : max(0, (int) ($template['id'] ?? 0)),
            'prompt_template_code' => $template === null ? null : $this->limit((string) ($template['code'] ?? ''), 120),
            'prompt_template_name' => $template === null ? null : $this->limit((string) ($template['name'] ?? ''), 120),
            'prompt_template_content' => $templateContent,
            'subject_text' => $subject,
            'user_addition' => $addition,
            'prompt' => $this->limit($prompt, 4000),
        ];
    }

    public function assertSpecificSubject(string $subject): void
    {
        $subject = trim($subject);
        $meaningful = preg_replace('/[\p{P}\p{S}\p{Z}\p{N}_]+/u', '', $subject) ?? '';
        $generic = preg_replace('/[\p{P}\p{S}\p{Z}\p{N}_]+/u', '', mb_strtolower($subject)) ?? '';
        $genericTerms = [
            '主体', '商品', '产品', '人物', '人像', '场景', '图片', '一张图', '随便',
            '东西', '物品', '内容', '具体主体', '请生成', '帮我生成', '帮我做',
        ];
        $specificRemainder = mb_strtolower($subject);
        $specificRemainder = preg_replace(
            '/(?:请|麻烦|帮我|给我)|(?:生成|制作|做出|做|画出|画|来一张|想要)|(?:一个|一位|一名|一件|一张|某个|某位|某件|普通|随便|任意|一些|这个|那个)|(?:个|位|名|件|张|幅|组|款)|(?:具体主体|主体|商品|产品|人物|人像|场景|图片|图像|画面|照片|东西|物品|内容)|\b(?:please|generate|create|make|draw|a|an|some|any|person|product|scene|image|photo|picture|subject)\b/iu',
            '',
            $specificRemainder
        ) ?? '';
        $specificRemainder = preg_replace('/[\p{P}\p{S}\p{Z}\p{N}_]+/u', '', $specificRemainder) ?? '';
        if (mb_strlen($meaningful) < 2
            || in_array($generic, $genericTerms, true)
            || mb_strlen($specificRemainder) < 2) {
            throw new RuntimeException('请明确填写本次要生成的具体人物、商品或场景，例如“米白色香薰蜡烛”或“穿西装的成年职业女性”。');
        }
    }

    private function limit(string $value, int $length): string
    {
        return mb_strimwidth(trim($value), 0, $length, '');
    }
}
