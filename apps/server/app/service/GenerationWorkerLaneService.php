<?php

declare(strict_types=1);

namespace app\service;

/** Keeps worker-slot ownership deterministic and independently testable. */
final class GenerationWorkerLaneService
{
    public function resolve(int $slot, int $globalConcurrency, int $visionConcurrency): array
    {
        $slot = max(1, min(10, $slot));
        $globalConcurrency = max(1, min(10, $globalConcurrency));
        $visionConcurrency = max(2, min(4, $visionConcurrency));
        $visionStart = 2;
        return [
            'paused' => $slot > $globalConcurrency,
            'maintenance' => $slot === 1,
            'vision' => $slot >= $visionStart && $slot < $visionStart + $visionConcurrency,
            'generation' => $slot <= $globalConcurrency
                && !($slot >= $visionStart && $slot < $visionStart + $visionConcurrency),
        ];
    }
}
