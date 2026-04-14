<?php

declare(strict_types=1);

namespace MoodleDebug\server;

final class ToolHandler
{
    public function __construct(private readonly Application $application)
    {
    }

    /**
     * @param array<string, mixed> $stop_policy
     * @param array<string, mixed> $capture_policy
     * @return array<string, mixed>
     */
    public function debugPhpunitTest(
        string $moodle_root,
        string $test_ref,
        string $runtime_profile,
        array $stop_policy,
        array $capture_policy,
        int $timeout_seconds,
        ?string $idempotency_key = null,
    ): array {
        return $this->application->debugPhpunitTest(compact(
            'moodle_root',
            'test_ref',
            'runtime_profile',
            'stop_policy',
            'capture_policy',
            'timeout_seconds',
            'idempotency_key',
        ));
    }

    /**
     * @param string[] $script_args
     * @param array<string, mixed> $stop_policy
     * @param array<string, mixed> $capture_policy
     * @return array<string, mixed>
     */
    public function debugCliScript(
        string $moodle_root,
        string $script_path,
        array $script_args,
        string $runtime_profile,
        array $stop_policy,
        array $capture_policy,
        int $timeout_seconds,
        ?string $idempotency_key = null,
    ): array {
        return $this->application->debugCliScript(compact(
            'moodle_root',
            'script_path',
            'script_args',
            'runtime_profile',
            'stop_policy',
            'capture_policy',
            'timeout_seconds',
            'idempotency_key',
        ));
    }

    /**
     * @param array<string, mixed> $include
     * @return array<string, mixed>
     */
    public function getDebugSession(string $session_id, array $include = []): array
    {
        return $this->application->getDebugSession(compact('session_id', 'include'));
    }

    /**
     * @return array<string, mixed>
     */
    public function summariseDebugSession(string $session_id, ?string $summary_depth = null, ?string $focus = null): array
    {
        return $this->application->summariseDebugSession(array_filter(compact('session_id', 'summary_depth', 'focus'), static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param array<int, array<string, mixed>> $frames
     * @param array<string, mixed>|null $exception
     * @param array<string, mixed>|null $test_context
     * @return array<string, mixed>
     */
    public function mapStackToMoodleContext(string $moodle_root, array $frames, ?array $exception = null, ?array $test_context = null): array
    {
        return $this->application->mapStackToMoodleContext(array_filter(
            compact('moodle_root', 'frames', 'exception', 'test_context'),
            static fn (mixed $value): bool => $value !== null
        ));
    }
}
