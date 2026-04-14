<?php

declare(strict_types=1);

namespace MoodleDebug\server;

use MoodleDebug\contracts\ClockInterface;
use MoodleDebug\contracts\ErrorFactory;
use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\debug_backend\DebugBackendException;
use MoodleDebug\debug_backend\DebugBackendInterface;
use MoodleDebug\runtime\CliPathValidator;
use MoodleDebug\runtime\ExecutionPlanFactory;
use MoodleDebug\runtime\PathMapper;
use MoodleDebug\runtime\PHPUnitSelectorValidator;
use MoodleDebug\runtime\RuntimeProfile;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\session_store\FileArtifactSessionStore;

final class Application
{
    public function __construct(
        private readonly SchemaValidator $schemaValidator,
        private readonly RuntimeProfileLoader $profileLoader,
        private readonly DebugBackendInterface $backend,
        private readonly FileArtifactSessionStore $sessionStore,
        private readonly PHPUnitSelectorValidator $selectorValidator,
        private readonly CliPathValidator $cliPathValidator,
        private readonly ExecutionPlanFactory $executionPlanFactory,
        private readonly PathMapper $pathMapper,
        private readonly MoodleContextMapper $contextMapper,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function debugPhpunitTest(array $input): array
    {
        $validated = $this->schemaValidator->validateToolInput('debug_phpunit_test', $input);
        if (!$validated['valid']) {
            return ErrorFactory::failure('INVALID_REQUEST', $validated['message'] ?? 'Invalid request.', false);
        }

        try {
            $profile = $this->resolveProfile('default_phpunit', (string) $input['runtime_profile'], 'phpunit', (string) $input['moodle_root']);
            $selector = $this->selectorValidator->validate((string) $input['test_ref'], $profile->moodleRoot);
            if (!$selector['valid']) {
                return ErrorFactory::failure('INVALID_TEST_REF', (string) $selector['message'], true);
            }

            $executionPlan = $this->executionPlanFactory->forPhpunit($profile, $selector);
            $target = [
                'type' => 'phpunit',
                'normalized_test_ref' => $selector['normalized'],
            ];

            return $this->runDebugWorkflow('debug_phpunit_test', $profile, $executionPlan, $target, $input, [
                'test_ref' => $selector['normalized'],
            ]);
        } catch (DebugBackendException $exception) {
            return $this->backendExceptionToFailure($exception);
        } catch (\RuntimeException $exception) {
            return $this->runtimeExceptionToFailure($exception);
        } catch (\Throwable $exception) {
            return ErrorFactory::failure('INTERNAL_ORCHESTRATION_ERROR', $exception->getMessage(), false);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function debugCliScript(array $input): array
    {
        $validated = $this->schemaValidator->validateToolInput('debug_cli_script', $input);
        if (!$validated['valid']) {
            return ErrorFactory::failure('INVALID_REQUEST', $validated['message'] ?? 'Invalid request.', false);
        }

        try {
            $profile = $this->resolveProfile('default_cli', (string) $input['runtime_profile'], 'cli', (string) $input['moodle_root']);
            $scriptValidation = $this->cliPathValidator->validate((string) $input['script_path'], $this->profileLoader->getCliAllowlist());
            if (!$scriptValidation['valid']) {
                return ErrorFactory::failure('INVALID_SCRIPT_PATH', (string) $scriptValidation['message'], true);
            }

            $executionPlan = $this->executionPlanFactory->forCli(
                $profile,
                (string) $scriptValidation['normalized'],
                $input['script_args'],
            );

            $target = [
                'type' => 'cli',
                'script_path' => $scriptValidation['normalized'],
                'script_args' => $input['script_args'],
            ];

            return $this->runDebugWorkflow('debug_cli_script', $profile, $executionPlan, $target, $input, []);
        } catch (DebugBackendException $exception) {
            return $this->backendExceptionToFailure($exception);
        } catch (\RuntimeException $exception) {
            return $this->runtimeExceptionToFailure($exception);
        } catch (\Throwable $exception) {
            return ErrorFactory::failure('INTERNAL_ORCHESTRATION_ERROR', $exception->getMessage(), false);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function getDebugSession(array $input): array
    {
        $validated = $this->schemaValidator->validateToolInput('get_debug_session', $input);
        if (!$validated['valid']) {
            return ErrorFactory::failure('INVALID_REQUEST', $validated['message'] ?? 'Invalid request.', false);
        }

        $lookup = $this->sessionStore->load((string) $input['session_id']);
        if (!$lookup->found) {
            return ErrorFactory::failure('SESSION_NOT_FOUND', 'Debug session not found.', true);
        }
        if ($lookup->expired) {
            return ErrorFactory::failure('SESSION_EXPIRED', 'Debug session has expired.', true);
        }

        $response = [
            'ok' => true,
            'session' => $lookup->payload['session'],
        ];

        $include = $input['include'] ?? [];
        if (!is_array($include) || (($include['result'] ?? true) === true)) {
            $response['result'] = $lookup->payload['result'];
        }

        return $this->finalizeOutput('get_debug_session', $response);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function summariseDebugSession(array $input): array
    {
        $validated = $this->schemaValidator->validateToolInput('summarise_debug_session', $input);
        if (!$validated['valid']) {
            return ErrorFactory::failure('INVALID_REQUEST', $validated['message'] ?? 'Invalid request.', false);
        }

        $lookup = $this->sessionStore->load((string) $input['session_id']);
        if (!$lookup->found) {
            return ErrorFactory::failure('SESSION_NOT_FOUND', 'Debug session not found.', true);
        }
        if ($lookup->expired) {
            return ErrorFactory::failure('SESSION_EXPIRED', 'Debug session has expired.', true);
        }

        $summary = $this->summaryBuilder->build(
            $lookup->payload['result']['target'],
            $lookup->payload['result']['stop_event'],
            $lookup->payload['result']['frames'],
            $lookup->payload['result']['moodle_mapping'],
            (string) ($input['summary_depth'] ?? 'standard'),
            isset($input['focus']) ? (string) $input['focus'] : null,
        );

        return $this->finalizeOutput('summarise_debug_session', [
            'ok' => true,
            'summary' => $summary,
            'warnings' => $lookup->payload['session']['warnings'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function mapStackToMoodleContext(array $input): array
    {
        $validated = $this->schemaValidator->validateToolInput('map_stack_to_moodle_context', $input);
        if (!$validated['valid']) {
            return ErrorFactory::failure('INVALID_REQUEST', $validated['message'] ?? 'Invalid request.', false);
        }

        $mapping = $this->contextMapper->map(
            (string) $input['moodle_root'],
            $input['frames'],
            $input['exception'] ?? null,
            $input['test_context'] ?? [],
        );

        return $this->finalizeOutput('map_stack_to_moodle_context', [
            'ok' => true,
            'mapping' => $mapping,
        ]);
    }

    /**
     * @param array<string, mixed> $executionPlan
     * @param array<string, mixed> $target
     * @param array<string, mixed> $input
     * @param array<string, mixed> $testContext
     * @return array<string, mixed>
     */
    private function runDebugWorkflow(
        string $toolName,
        RuntimeProfile $profile,
        array $executionPlan,
        array $target,
        array $input,
        array $testContext,
    ): array {
        $sessionId = $this->buildSessionId($toolName, $target, $input['idempotency_key'] ?? null);
        $preparedSession = null;

        try {
            $preparedSession = $this->backend->prepare_session([
                'session_id' => $sessionId,
                'target' => $target,
                'stop_policy' => $input['stop_policy'],
                'runtime_profile' => $profile->toBackendContext(),
            ]);

            $launch = $this->backend->launch_target($preparedSession, $executionPlan);
            $stopEvent = $this->backend->wait_for_stop((string) $preparedSession['backend_session_id'], (int) $input['timeout_seconds']);

            if (($stopEvent['reason'] ?? null) === 'timeout') {
                return ErrorFactory::failure('SESSION_TIMEOUT', 'Debug session timed out before a stop event was captured.', true);
            }

            if (($stopEvent['reason'] ?? null) === 'target_exit') {
                if (($stopEvent['attached'] ?? false) === true) {
                    return ErrorFactory::failure('NO_STOP_EVENT', 'Target completed without hitting a meaningful debug stop condition.', true);
                }

                return ErrorFactory::failure('TARGET_FAILED_BEFORE_ATTACH', 'Target exited before a debugger stop event was captured.', true);
            }

            unset($stopEvent['attached']);

            $capturePolicy = $input['capture_policy'];
            $frames = $this->backend->read_stack((string) $preparedSession['backend_session_id'], (int) $capturePolicy['max_frames']);
            $frames = $this->pathMapper->mapFrames($frames, $profile->pathMappings);
            $focusCount = min((int) $capturePolicy['focus_top_frames'], count($frames));
            $frameIndexes = array_map(static fn (array $frame): int => (int) $frame['index'], array_slice($frames, 0, $focusCount));
            $locals = $this->backend->read_locals(
                (string) $preparedSession['backend_session_id'],
                $frameIndexes,
                (int) $capturePolicy['max_locals_per_frame'],
                (int) $capturePolicy['max_string_length'],
            );

            $mapping = $this->contextMapper->map($profile->moodleRoot, $frames, $stopEvent['exception'] ?? null, $testContext);
            $summary = $this->summaryBuilder->build($target, $stopEvent, $frames, $mapping);

            $warnings = [];
            if (count($frameIndexes) < count($frames)) {
                $warnings[] = ErrorFactory::warning('LOCALS_CAPTURE_TRUNCATED', 'Locals were captured only for the top configured frames.');
            }

            $createdAt = $this->clock->now();
            $expiresAt = $createdAt->modify('+' . $this->sessionStore->getTtlSeconds() . ' seconds');

            $sessionState = [
                'session' => [
                    'session_id' => $sessionId,
                    'created_at' => $createdAt->format(DATE_ATOM),
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                    'state' => 'stopped',
                ],
                'target_type' => $target['type'],
                'runtime_profile' => [
                    'profile_name' => $profile->profileName,
                    'launcher_kind' => $profile->launcherKind,
                    'working_directory' => $profile->workingDirectory,
                ],
                'moodle_root' => $profile->moodleRoot,
                'warnings' => $warnings,
            ];

            $result = [
                'target' => $target,
                'stop_event' => $stopEvent,
                'frames' => $frames,
                'locals_by_frame' => $locals,
                'moodle_mapping' => $mapping,
                'summary' => $summary,
                'rerun' => [
                    'launcher' => $executionPlan['launcher'],
                    'command' => $launch['command'] ?? $executionPlan['command'],
                    'cwd' => $executionPlan['cwd'],
                    'notes' => [
                        $profile->backendKind === 'xdebug'
                            ? 'Real Xdebug backend; launch plan reflects the actual PHP command used for this session.'
                            : 'Mock backend only; no real PHP process is launched in this phase.',
                        'Execution plan is deterministic for the same input payload and profile.',
                    ],
                ],
                'warnings' => $warnings,
            ];

            $payload = [
                'session' => $sessionState,
                'result' => $result,
                'launch' => $launch,
            ];

            try {
                $this->sessionStore->save($sessionId, $payload);
            } catch (\Throwable $exception) {
                return ErrorFactory::failure(
                    'ARTIFACT_PERSISTENCE_FAILED',
                    'Failed to persist debug session artifacts.',
                    true,
                    [],
                    ['message' => $exception->getMessage()]
                );
            }

            return $this->finalizeOutput($toolName, [
                'ok' => true,
                'session' => $sessionState,
                'result' => $result,
            ]);
        } catch (DebugBackendException $exception) {
            return $this->backendExceptionToFailure($exception);
        } finally {
            if (is_array($preparedSession) && isset($preparedSession['backend_session_id'])) {
                $this->backend->terminate_session((string) $preparedSession['backend_session_id']);
            }
        }
    }

    private function resolveProfile(string $defaultProfile, string $requestedProfile, string $launcherKind, string $moodleRoot): RuntimeProfile
    {
        $profileName = $requestedProfile !== '' ? $requestedProfile : $defaultProfile;
        $profile = $this->profileLoader->getProfile($profileName, $launcherKind);

        if (!str_starts_with($moodleRoot, '/')) {
            throw new \RuntimeException('Moodle root must be an absolute path.');
        }
        if (!is_dir($moodleRoot)) {
            throw new \RuntimeException('Moodle root not found.');
        }
        if (!is_file($moodleRoot . '/config.php')) {
            throw new \RuntimeException('Moodle config.php not found.');
        }
        if ($profile->moodleRoot !== $moodleRoot) {
            throw new \RuntimeException("Requested moodle_root does not match runtime profile {$profile->profileName}.");
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function buildSessionId(string $toolName, array $target, mixed $idempotencyKey): string
    {
        $seed = [
            'tool' => $toolName,
            'target' => $target,
            'idempotency_key' => $idempotencyKey,
            'now' => $this->clock->now()->format(DATE_ATOM),
        ];

        return 'mds_' . substr(sha1(json_encode($seed, JSON_THROW_ON_ERROR)), 0, 20);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function finalizeOutput(string $toolName, array $response): array
    {
        $validated = $this->schemaValidator->validateToolOutput($toolName, $response);
        if ($validated['valid']) {
            return $response;
        }

        $fallback = ErrorFactory::failure(
            'INTERNAL_ORCHESTRATION_ERROR',
            $validated['message'] ?? 'Tool output failed schema validation.',
            false
        );

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeExceptionToFailure(\RuntimeException $exception): array
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'Runtime profile not found') => ErrorFactory::failure('INVALID_REQUEST', $message, true),
            str_contains($message, 'is not valid for') => ErrorFactory::failure('INVALID_RUNTIME_PROFILE', $message, false),
            str_contains($message, 'Moodle root not found') => ErrorFactory::failure('MOODLE_ROOT_NOT_FOUND', $message, true),
            str_contains($message, 'config.php not found') => ErrorFactory::failure('MOODLE_CONFIG_MISSING', $message, true),
            default => ErrorFactory::failure('INVALID_REQUEST', $message, false),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function backendExceptionToFailure(DebugBackendException $exception): array
    {
        return ErrorFactory::failure(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->isRetryable(),
            $exception->getDiagnosticHints(),
            $exception->getDetails(),
        );
    }
}
