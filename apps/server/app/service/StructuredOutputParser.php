<?php

declare(strict_types=1);

namespace app\service;

/**
 * Tolerant, non-generative parser for chat-model structured output.
 * It extracts only facts already present in the response and never fills business data.
 */
final class StructuredOutputParser
{
    /**
     * @param array<string, list<string>> $aliases
     * @param list<string> $wrappers
     * @return array{ok:bool,data:array,rawText:string,diagnostic:array}
     */
    public function parse(mixed $content, array $aliases = [], array $wrappers = ['data', 'result', 'product']): array
    {
        $shape = $this->shape($content);
        $text = $this->flatten($content);
        $text = $this->stripReasoning($text);
        $diagnostic = [
            'response_shape' => $shape,
            'response_length' => strlen($text),
            'response_hash' => hash('sha256', $text),
            'parser_step' => 'none',
            'parse_attempts' => 0,
        ];

        foreach ($this->candidates($text) as $step => $candidate) {
            $diagnostic['parse_attempts']++;
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                continue;
            }
            $decoded = $this->unwrap($decoded, $wrappers);
            $decoded = $this->applyAliases($decoded, $aliases);
            $diagnostic['parser_step'] = $step;
            return ['ok' => true, 'data' => $decoded, 'rawText' => $text, 'diagnostic' => $diagnostic];
        }

        $diagnostic['json_error'] = json_last_error_msg();
        return ['ok' => false, 'data' => [], 'rawText' => $text, 'diagnostic' => $diagnostic];
    }

    /** @return array<string,string> */
    private function candidates(string $text): array
    {
        $candidates = [];
        $trimmed = trim($text);
        if ($trimmed !== '') {
            $candidates['direct'] = $trimmed;
        }
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/iu', $text, $matches)) {
            foreach ($matches[1] as $index => $block) {
                $block = trim((string) $block);
                if ($block !== '') {
                    $candidates['fenced_' . ($index + 1)] = $block;
                }
            }
        }
        foreach ($this->balancedObjects($text) as $index => $object) {
            $candidates['balanced_' . ($index + 1)] = $object;
        }
        return array_values(array_unique($candidates, SORT_STRING)) === array_values($candidates)
            ? $candidates
            : $this->uniqueCandidates($candidates);
    }

    /** @param array<string,string> $candidates @return array<string,string> */
    private function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $step => $candidate) {
            if (isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            $unique[$step] = $candidate;
        }
        return $unique;
    }

    /** @return list<string> */
    private function balancedObjects(string $text): array
    {
        $objects = [];
        $length = strlen($text);
        $start = null;
        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($character === '"') {
                $quoted = true;
                continue;
            }
            if ($character === '{') {
                if ($depth === 0) {
                    $start = $index;
                }
                $depth++;
                continue;
            }
            if ($character === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $objects[] = substr($text, $start, $index - $start + 1);
                    $start = null;
                }
            }
        }
        return $objects;
    }

    private function flatten(mixed $content): string
    {
        if (is_string($content) || is_numeric($content)) {
            return trim((string) $content);
        }
        if (!is_array($content)) {
            return '';
        }
        foreach (['output_text', 'text', 'content'] as $key) {
            if (array_key_exists($key, $content)) {
                $nested = $this->flatten($content[$key]);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }
        $parts = [];
        foreach ($content as $item) {
            $part = $this->flatten($item);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return trim(implode("\n", $parts));
    }

    private function stripReasoning(string $text): string
    {
        $text = preg_replace('/<think\b[^>]*>[\s\S]*?<\/think>/iu', '', $text) ?? $text;
        $text = preg_replace('/<reasoning\b[^>]*>[\s\S]*?<\/reasoning>/iu', '', $text) ?? $text;
        return trim($text);
    }

    /** @param list<string> $wrappers */
    private function unwrap(array $decoded, array $wrappers): array
    {
        for ($depth = 0; $depth < 4; $depth++) {
            $next = null;
            foreach ($wrappers as $wrapper) {
                if (isset($decoded[$wrapper]) && is_array($decoded[$wrapper]) && !array_is_list($decoded[$wrapper])) {
                    $next = $decoded[$wrapper];
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $decoded = $next;
        }
        return $decoded;
    }

    /** @param array<string,list<string>> $aliases */
    private function applyAliases(array $decoded, array $aliases): array
    {
        foreach ($aliases as $canonical => $candidates) {
            if (array_key_exists($canonical, $decoded)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $decoded)) {
                    $decoded[$canonical] = $decoded[$candidate];
                    break;
                }
            }
        }
        return $decoded;
    }

    private function shape(mixed $content): string
    {
        if (!is_array($content)) {
            return get_debug_type($content);
        }
        if (array_is_list($content)) {
            return 'list<' . (isset($content[0]) ? get_debug_type($content[0]) : 'empty') . '>';
        }
        return 'object<' . implode(',', array_slice(array_keys($content), 0, 8)) . '>';
    }
}
