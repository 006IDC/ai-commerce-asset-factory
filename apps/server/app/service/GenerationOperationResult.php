<?php

declare(strict_types=1);

namespace app\service;

/**
 * Fact-based response contract shared by asynchronous generation entry points.
 * Messages are derived from state instead of guessing whether a charge/refund
 * happened from an upstream exception string.
 */
final class GenerationOperationResult
{
    public static function success(array $extra = []): array
    {
        return array_replace(self::base(true), $extra);
    }

    public static function failure(
        string $errorCode,
        string $stage,
        string $message,
        bool $taskCreated = false,
        bool $creditsReserved = false,
        string $refundStatus = 'not_applicable',
        bool $retryable = false,
        ?int $taskId = null,
        ?int $jobId = null,
        array $extra = []
    ): array {
        return array_replace(self::base(false), [
            'errorCode' => $errorCode,
            'stage' => $stage,
            'taskCreated' => $taskCreated,
            'jobCreated' => $jobId !== null,
            'creditsReserved' => $creditsReserved,
            'refundStatus' => $refundStatus,
            'retryable' => $retryable,
            'message' => $message,
            'taskId' => $taskId,
            'jobId' => $jobId,
        ], $extra);
    }

    private static function base(bool $ok): array
    {
        return [
            'ok' => $ok,
            'errorCode' => null,
            'stage' => $ok ? 'accepted' : 'validation',
            'taskCreated' => false,
            'jobCreated' => false,
            'creditsReserved' => false,
            'refundStatus' => 'not_applicable',
            'retryable' => false,
            'message' => '',
            'taskId' => null,
            'jobId' => null,
        ];
    }
}
