<?php

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

interface DebugBackendInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function prepare_session(array $context): array;

    /**
     * @param array<string, mixed> $preparedSession
     * @param array<string, mixed> $executionPlan
     * @return array<string, mixed>
     */
    public function launch_target(array $preparedSession, array $executionPlan): array;

    /**
     * @return array<string, mixed>
     */
    public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function read_stack(string $backendSessionId, int $maxFrames): array;

    /**
     * @param int[] $frameIndexes
     * @return array<int, array<string, mixed>>
     */
    public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array;

    public function terminate_session(string $backendSessionId): void;
}
