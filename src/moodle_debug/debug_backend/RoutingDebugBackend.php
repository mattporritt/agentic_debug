<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

final class RoutingDebugBackend implements DebugBackendInterface
{
    /**
     * @var array<string, DebugBackendInterface>
     */
    private array $sessionBackends = [];

    public function __construct(
        private readonly DebugBackendInterface $mockBackend,
        private readonly DebugBackendInterface $xdebugBackend,
    ) {
    }

    public function prepare_session(array $context): array
    {
        $backend = match ($context['runtime_profile']['backend_kind'] ?? 'mock') {
            'xdebug' => $this->xdebugBackend,
            default => $this->mockBackend,
        };

        $prepared = $backend->prepare_session($context);
        $this->sessionBackends[(string) $prepared['backend_session_id']] = $backend;

        return $prepared;
    }

    public function launch_target(array $preparedSession, array $executionPlan): array
    {
        return $this->lookup($preparedSession['backend_session_id'])->launch_target($preparedSession, $executionPlan);
    }

    public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array
    {
        return $this->lookup($backendSessionId)->wait_for_stop($backendSessionId, $timeoutSeconds);
    }

    public function read_stack(string $backendSessionId, int $maxFrames): array
    {
        return $this->lookup($backendSessionId)->read_stack($backendSessionId, $maxFrames);
    }

    public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array
    {
        return $this->lookup($backendSessionId)->read_locals($backendSessionId, $frameIndexes, $maxLocalsPerFrame, $maxStringLength);
    }

    public function terminate_session(string $backendSessionId): void
    {
        $backend = $this->lookup($backendSessionId);
        $backend->terminate_session($backendSessionId);
        unset($this->sessionBackends[$backendSessionId]);
    }

    private function lookup(string $backendSessionId): DebugBackendInterface
    {
        if (!isset($this->sessionBackends[$backendSessionId])) {
            throw new DebugBackendException(
                'INTERNAL_ORCHESTRATION_ERROR',
                "Unknown backend session: {$backendSessionId}",
                false,
            );
        }

        return $this->sessionBackends[$backendSessionId];
    }
}
