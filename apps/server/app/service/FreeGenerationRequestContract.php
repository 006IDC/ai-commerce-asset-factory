<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class FreeGenerationRequestContract
{
    public function assertMetadata(array $request, string $capability = ''): void
    {
        $hasPrimary = trim((string) ($request['reference_image_url'] ?? '')) !== '';
        $additional = $this->storedAdditionalReferences($request);
        if (!$hasPrimary && $additional !== []) {
            throw new RuntimeException('自由创作附加参考图缺少主参考图，任务已在冻结前停止。');
        }
        $storedCount = ($hasPrimary ? 1 : 0) + count($additional);
        $expectedCount = max(0, (int) ($request['reference_count'] ?? 0));
        if ($storedCount !== $expectedCount || count($this->fingerprints($request)) !== $expectedCount) {
            throw new RuntimeException('自由创作参考图元数据不完整，任务已在冻结前停止。');
        }
        if ($capability !== '') {
            $expectedCapability = $expectedCount > 0 ? AiCapability::IMAGE_EDIT : AiCapability::IMAGE_GENERATION;
            if ($capability !== $expectedCapability) {
                throw new RuntimeException('自由创作路由能力与参考图数量不一致，任务已在冻结前停止。');
            }
        }
    }

    /** @return list<string> */
    public function fingerprints(array $request): array
    {
        $decoded = json_decode((string) ($request['reference_fingerprints_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $decoded
        )));
    }

    public function assertFrozen(array $request, ?array $referenceImage, string $capability = '', bool $strict = true): void
    {
        if (!$strict) {
            return;
        }
        $this->assertMetadata($request, $capability);
        $expectedCount = max(0, (int) ($request['reference_count'] ?? 0));
        $actualCount = $referenceImage === null
            ? 0
            : max(1, (int) ($referenceImage['_reference_count'] ?? 1));
        $expectedFingerprints = $this->fingerprints($request);
        $actualFingerprints = [];
        if ($referenceImage !== null) {
            $references = is_array($referenceImage['_reference_originals'] ?? null)
                ? $referenceImage['_reference_originals']
                : [$referenceImage];
            foreach ($references as $reference) {
                $content = is_array($reference) ? (string) ($reference['content'] ?? '') : '';
                if ($content !== '') {
                    $actualFingerprints[] = hash('sha256', $content);
                }
            }
        }

        if ($expectedCount !== $actualCount || count($expectedFingerprints) !== $expectedCount) {
            throw new RuntimeException('自由创作参考图冻结信息与实际请求不一致，任务已在调用上游前停止。');
        }
        sort($expectedFingerprints);
        sort($actualFingerprints);
        if ($expectedFingerprints !== $actualFingerprints) {
            throw new RuntimeException('自由创作参考图内容指纹不一致，任务已在调用上游前停止。');
        }

        if ($capability !== '') {
            $expectedCapability = $expectedCount > 0 ? AiCapability::IMAGE_EDIT : AiCapability::IMAGE_GENERATION;
            if ($capability !== $expectedCapability) {
                throw new RuntimeException('自由创作路由能力与参考图数量不一致，任务已在调用上游前停止。');
            }
        }
    }

    /** @return list<mixed> */
    private function storedAdditionalReferences(array $request): array
    {
        $decoded = json_decode((string) ($request['reference_images_json'] ?? '[]'), true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
