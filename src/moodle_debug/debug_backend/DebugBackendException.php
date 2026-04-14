<?php

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

final class DebugBackendException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param string[] $diagnosticHints
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly bool $retryable = false,
        private readonly array $diagnosticHints = [],
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * @return string[]
     */
    public function getDiagnosticHints(): array
    {
        return $this->diagnosticHints;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
