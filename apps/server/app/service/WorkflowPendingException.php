<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class WorkflowPendingException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $manualReview = false
    ) {
        parent::__construct($message);
    }

    public function needsManualReview(): bool
    {
        return $this->manualReview;
    }
}
