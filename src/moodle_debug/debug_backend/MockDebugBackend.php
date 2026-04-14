<?php

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

use MoodleDebug\contracts\ClockInterface;

/**
 * Deterministic in-memory backend used by most tests and smoke flows.
 *
 * The mock backend returns stable stack, locals, and exception payloads so
 * higher-level orchestration and interpretation can be exercised without a real
 * Xdebug environment.
 */
final class MockDebugBackend implements DebugBackendInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $sessions = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function prepare_session(array $context): array
    {
        $backendSessionId = 'mock_' . substr(sha1(json_encode($context, JSON_THROW_ON_ERROR)), 0, 16);

        $this->sessions[$backendSessionId] = [
            'context' => $context,
            'launched' => false,
        ];

        return [
            'backend_session_id' => $backendSessionId,
            'stop_policy' => $context['stop_policy'],
        ];
    }

    public function launch_target(array $preparedSession, array $executionPlan): array
    {
        $backendSessionId = $preparedSession['backend_session_id'];
        $targetReference = (string) $executionPlan['target_reference'];
        $targetType = (string) $executionPlan['target_type'];
        $remoteRoot = array_key_first($executionPlan['path_mappings']);
        $remoteRoot = is_string($remoteRoot) ? rtrim($remoteRoot, '/') : '/var/www/html';

        if ($targetType === 'phpunit') {
            $frames = [
                [
                    'index' => 0,
                    'file' => "{$remoteRoot}/mod/assign/classes/grading_manager.php",
                    'line' => 87,
                    'class' => 'mod_assign\\grading_manager',
                    'function' => 'apply_grade',
                    'args' => [
                        ['name' => 'userid', 'type' => 'int', 'value_preview' => '42', 'redacted' => false],
                        ['name' => 'grade', 'type' => 'float', 'value_preview' => '73.5', 'redacted' => false],
                    ],
                ],
                [
                    'index' => 1,
                    'file' => "{$remoteRoot}/mod/assign/tests/grading_test.php",
                    'line' => 52,
                    'class' => strtok($targetReference, '::'),
                    'function' => substr($targetReference, strrpos($targetReference, '::') + 2),
                    'args' => [],
                ],
                [
                    'index' => 2,
                    'file' => "{$remoteRoot}/lib/phpunit/classes/advanced_testcase.php",
                    'line' => 410,
                    'class' => 'advanced_testcase',
                    'function' => 'runTest',
                    'args' => [],
                ],
            ];

            $locals = [
                [
                    'frame_index' => 0,
                    'locals' => [
                        ['name' => 'userid', 'type' => 'int', 'value_preview' => '42', 'redacted' => false],
                        ['name' => 'grade', 'type' => 'float', 'value_preview' => '73.5', 'redacted' => false],
                        ['name' => 'status', 'type' => 'string', 'value_preview' => 'pending', 'redacted' => false],
                    ],
                ],
                [
                    'frame_index' => 1,
                    'locals' => [
                        ['name' => 'submissionid', 'type' => 'int', 'value_preview' => '1001', 'redacted' => false],
                    ],
                ],
            ];

            $exception = [
                'type' => 'coding_exception',
                'message' => "Mocked PHPUnit failure for {$targetReference}",
                'code' => 0,
                'file' => "{$remoteRoot}/mod/assign/classes/grading_manager.php",
                'line' => 87,
            ];
        } else {
            $frames = [
                [
                    'index' => 0,
                    'file' => "{$remoteRoot}/admin/cli/some_script.php",
                    'line' => 42,
                    'class' => 'admin\\cli\\some_script',
                    'function' => 'execute',
                    'args' => [
                        ['name' => 'verbose', 'type' => 'bool', 'value_preview' => 'true', 'redacted' => false],
                    ],
                ],
                [
                    'index' => 1,
                    'file' => "{$remoteRoot}/lib/clilib.php",
                    'line' => 212,
                    'function' => 'cli_error',
                    'args' => [],
                ],
            ];

            $locals = [
                [
                    'frame_index' => 0,
                    'locals' => [
                        ['name' => 'operation', 'type' => 'string', 'value_preview' => 'reindex', 'redacted' => false],
                        ['name' => 'dryrun', 'type' => 'bool', 'value_preview' => 'false', 'redacted' => false],
                    ],
                ],
            ];

            $exception = [
                'type' => 'moodle_exception',
                'message' => "Mocked CLI failure for {$targetReference}",
                'code' => 0,
                'file' => "{$remoteRoot}/admin/cli/some_script.php",
                'line' => 42,
            ];
        }

        $this->sessions[$backendSessionId]['launched'] = true;
        $this->sessions[$backendSessionId]['execution_plan'] = $executionPlan;
        $this->sessions[$backendSessionId]['frames'] = $frames;
        $this->sessions[$backendSessionId]['locals'] = $locals;
        $this->sessions[$backendSessionId]['exception'] = $exception;

        return [
            'backend_session_id' => $backendSessionId,
            'launched_at' => $this->clock->now()->format(DATE_ATOM),
            'launcher' => $executionPlan['launcher'],
            'command' => $executionPlan['command'],
        ];
    }

    public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array
    {
        $session = $this->sessions[$backendSessionId] ?? null;
        if (!is_array($session) || !($session['launched'] ?? false)) {
            return [
                'reason' => 'target_exit',
                'stopped_at' => $this->clock->now()->format(DATE_ATOM),
            ];
        }

        if ($timeoutSeconds < 1) {
            return [
                'reason' => 'timeout',
                'stopped_at' => $this->clock->now()->format(DATE_ATOM),
            ];
        }

        return [
            'reason' => 'exception',
            'stopped_at' => $this->clock->now()->format(DATE_ATOM),
            'exception' => $session['exception'],
        ];
    }

    public function read_stack(string $backendSessionId, int $maxFrames): array
    {
        $frames = $this->sessions[$backendSessionId]['frames'] ?? [];

        return array_slice($frames, 0, $maxFrames);
    }

    public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array
    {
        $localsByFrame = $this->sessions[$backendSessionId]['locals'] ?? [];
        $filtered = [];

        foreach ($localsByFrame as $frameLocals) {
            if (!in_array($frameLocals['frame_index'], $frameIndexes, true)) {
                continue;
            }

            $locals = array_slice($frameLocals['locals'], 0, $maxLocalsPerFrame);
            foreach ($locals as &$local) {
                $local['value_preview'] = substr((string) $local['value_preview'], 0, $maxStringLength);
            }
            unset($local);

            $filtered[] = [
                'frame_index' => $frameLocals['frame_index'],
                'locals' => $locals,
            ];
        }

        return $filtered;
    }

    public function terminate_session(string $backendSessionId): void
    {
        unset($this->sessions[$backendSessionId]);
    }
}
