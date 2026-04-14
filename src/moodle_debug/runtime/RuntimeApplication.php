<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

use MoodleDebug\contracts\ClockInterface;
use MoodleDebug\contracts\RuntimeSchemaValidator;
use MoodleDebug\debug_backend\DebugBackendException;
use MoodleDebug\debug_backend\XdebugLaunchSettingsBuilder;
use MoodleDebug\server\Application;
use MoodleDebug\server\MoodleContextMapper;
use MoodleDebug\server\SummaryBuilder;
use MoodleDebug\session_store\FileArtifactSessionStore;

final class RuntimeApplication
{
    public const TOOL_NAME = 'moodle_debug';
    public const RUNTIME_VERSION = 'runtime-v1';

    public function __construct(
        private readonly string $repoRoot,
        private readonly RuntimeSchemaValidator $schemaValidator,
        private readonly Application $application,
        private readonly RuntimeProfileLoader $profileLoader,
        private readonly FileArtifactSessionStore $sessionStore,
        private readonly PHPUnitSelectorValidator $selectorValidator,
        private readonly CliPathValidator $cliPathValidator,
        private readonly ExecutionPlanFactory $executionPlanFactory,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly MoodleContextMapper $contextMapper,
        private readonly XdebugLaunchSettingsBuilder $xdebugLaunchSettingsBuilder,
        private readonly CodexEnvironmentLoader $environmentLoader,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function runtimeQuery(array $request): array
    {
        $validated = $this->schemaValidator->validateRequest('runtime_query', $request);
        if (!$validated['valid']) {
            return $this->buildFailureEnvelope(
                $request,
                'invalid_request',
                'INVALID_RUNTIME_REQUEST',
                $validated['message'] ?? 'Invalid runtime request.',
                1
            );
        }

        $normalized = $this->normalizeQuery($request);
        $intent = $normalized['intent'];

        try {
            return match ($intent) {
                'interpret_session' => $this->handleInterpretSession($request, $normalized),
                'get_session' => $this->handleGetSession($request, $normalized),
                'plan_phpunit' => $this->handlePlanPhpunit($request, $normalized),
                'plan_cli' => $this->handlePlanCli($request, $normalized),
                'execute_phpunit' => $this->handleExecutePhpunit($request, $normalized),
                'execute_cli' => $this->handleExecuteCli($request, $normalized),
                default => $this->buildFailureEnvelope($request, $intent, 'INVALID_INTENT', "Unsupported runtime intent: {$intent}", 1),
            };
        } catch (DebugBackendException $exception) {
            return $this->buildFailureEnvelope(
                $request,
                $intent,
                $exception->getErrorCode(),
                $exception->getMessage(),
                1,
                $normalized,
                $exception->getDiagnosticHints(),
                $exception->getDetails()
            );
        } catch (\RuntimeException $exception) {
            return $this->buildFailureEnvelope($request, $intent, 'INVALID_REQUEST', $exception->getMessage(), 1, $normalized);
        } catch (\Throwable $exception) {
            return $this->buildFailureEnvelope(
                $request,
                $intent,
                'INTERNAL_ORCHESTRATION_ERROR',
                $exception->getMessage(),
                1,
                $normalized
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function health(array $request = []): array
    {
        $validated = $this->schemaValidator->validateRequest('health', $request);
        if (!$validated['valid']) {
            return $this->buildFailureEnvelope(
                $request,
                'health',
                'INVALID_RUNTIME_REQUEST',
                $validated['message'] ?? 'Invalid health request.',
                1
            );
        }

        $profileNames = $request['profile_names'] ?? ['default_phpunit', 'default_cli', 'real_xdebug_phpunit', 'real_xdebug_cli'];
        $profileNames = is_array($profileNames) ? array_values(array_map(static fn (mixed $item): string => (string) $item, $profileNames)) : [];
        $environment = $this->environmentLoader->load();

        $subsystems = [];
        $subsystems[] = $this->buildHealthSubsystem('config', is_file($this->repoRoot . '/config/runtime_profiles.json') ? 'ok' : 'fail', 'Runtime profile config lookup completed.', [
            'config_path' => $this->repoRoot . '/config/runtime_profiles.json',
        ]);
        $subsystems[] = $this->buildHealthSubsystem('session_store', $this->isSessionStoreWritable() ? 'ok' : 'fail', 'Session artifact directory checked.', [
            'storage_directory' => $this->repoRoot . '/_smoke_test/moodle_debug_sessions',
        ]);
        $subsystems[] = $this->buildHealthSubsystem('codex_env', $environment === [] ? 'warn' : 'ok', $environment === [] ? 'No codex-style environment overrides were found.' : 'Codex-style environment values were loaded.', [
            'available_keys' => array_keys($environment),
        ]);
        $subsystems[] = $this->buildHealthSubsystem('listener', $this->canBindListener((string) ($request['listener_bind_address'] ?? '127.0.0.1'), (int) ($request['listener_port'] ?? 9003)) ? 'ok' : 'warn', 'Listener bind capability probe completed.', [
            'bind_address' => (string) ($request['listener_bind_address'] ?? '127.0.0.1'),
            'listener_port' => (int) ($request['listener_port'] ?? 9003),
        ]);

        $profileDiagnostics = [];
        foreach ($profileNames as $profileName) {
            try {
                $launcherKind = str_contains($profileName, 'phpunit') ? 'phpunit' : 'cli';
                $profile = $this->profileLoader->getProfile($profileName, $launcherKind);
                $profileDiagnostics[] = [
                    'profile_name' => $profile->profileName,
                    'backend_kind' => $profile->backendKind,
                    'execution_transport' => $profile->executionTransport,
                    'launcher_kind' => $profile->launcherKind,
                ];
            } catch (\Throwable $exception) {
                $profileDiagnostics[] = [
                    'profile_name' => $profileName,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $dockerStatus = 'warn';
        $dockerMessage = 'Docker-backed profiles can be validated; runtime execution probes are deferred to explicit plan/execute requests.';
        if ($this->hasDockerBackedProfile($profileDiagnostics)) {
            $dockerStatus = $this->canResolveDockerCommand($profileDiagnostics) ? 'ok' : 'warn';
        }

        $subsystems[] = $this->buildHealthSubsystem('docker', $dockerStatus, $dockerMessage, [
            'profiles' => $profileDiagnostics,
        ]);
        $subsystems[] = $this->buildHealthSubsystem('xdebug', 'warn', 'Health verifies Xdebug-capable profile configuration only; container runtime Xdebug availability is checked during explicit plan or execute flows.', [
            'probe_supported' => true,
            'probed' => false,
        ]);
        $subsystems[] = $this->buildHealthSubsystem('supported_targets', 'ok', 'Explicit bounded target classes available.', [
            'target_types' => ['phpunit_selector', 'cli_script'],
        ]);

        $status = $this->reduceStatus($subsystems);
        $response = $this->buildEnvelope(
            query: $request,
            normalizedQuery: [
                'intent' => 'health',
                'profile_names' => $profileNames,
            ],
            intent: 'health',
            results: [[
                'id' => 'health_report',
                'type' => 'health_report',
                'rank' => 1,
                'confidence' => 'high',
                'source' => [
                    'kind' => 'runtime',
                    'profile_name' => null,
                    'session_id' => null,
                ],
                'content' => [
                    'subsystems' => $subsystems,
                ],
                'diagnostics' => [],
            ]],
            diagnostics: [],
            meta: [
                'status' => $status,
                'generated_at' => $this->clock->now()->format(DATE_ATOM),
                'repo_root' => $this->repoRoot,
                'dry_run' => true,
                'exit_code' => $status === 'fail' ? 1 : 0,
            ],
        );

        return $this->finalizeResponse('health', $response);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function handleInterpretSession(array $request, array $normalized): array
    {
        $lookup = $this->sessionStore->load((string) $normalized['session_id']);
        if (!$lookup->found) {
            return $this->buildFailureEnvelope($request, 'interpret_session', 'SESSION_NOT_FOUND', 'Debug session not found.', 1, $normalized);
        }
        if ($lookup->expired) {
            return $this->buildFailureEnvelope($request, 'interpret_session', 'SESSION_EXPIRED', 'Debug session has expired.', 1, $normalized);
        }

        $payload = $lookup->payload ?? [];
        $summary = $this->summaryBuilder->build(
            $payload['result']['target'],
            $payload['result']['stop_event'],
            $payload['result']['frames'],
            $payload['result']['moodle_mapping'],
            (string) ($normalized['summary_depth'] ?? 'standard'),
            null,
        );

        $results = [[
            'id' => (string) $normalized['session_id'],
            'type' => 'session_interpretation',
            'rank' => 1,
            'confidence' => (string) ($summary['confidence'] ?? 'medium'),
            'source' => [
                'kind' => 'session_store',
                'profile_name' => $payload['session']['runtime_profile']['profile_name'] ?? null,
                'session_id' => (string) $normalized['session_id'],
            ],
            'content' => [
                'session' => $payload['session'],
                'summary' => $summary,
                'investigation' => $this->buildInvestigationPayload($payload),
                'rerun' => $payload['result']['rerun'] ?? null,
            ],
            'diagnostics' => $payload['result']['warnings'] ?? [],
        ]];

        return $this->buildSuccessEnvelope($request, $normalized, 'interpret_session', $results, true);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function handleGetSession(array $request, array $normalized): array
    {
        $includeResult = (bool) ($normalized['include_result'] ?? true);
        $session = $this->application->getDebugSession([
            'session_id' => $normalized['session_id'],
            'include' => ['result' => $includeResult],
        ]);

        if (($session['ok'] ?? false) !== true) {
            return $this->buildFailureEnvelopeFromToolError($request, 'get_session', $session, $normalized);
        }

        $payload = [
            'session' => $session['session'],
        ];
        if ($includeResult && isset($session['result'])) {
            $payload['result'] = $session['result'];
            $payload['investigation'] = $this->buildInvestigationPayload([
                'session' => $session['session'],
                'result' => $session['result'],
            ]);
        }

        return $this->buildSuccessEnvelope($request, $normalized, 'get_session', [[
            'id' => (string) $normalized['session_id'],
            'type' => 'session_record',
            'rank' => 1,
            'confidence' => isset($session['result']['summary']['confidence']) ? (string) $session['result']['summary']['confidence'] : 'high',
            'source' => [
                'kind' => 'session_store',
                'profile_name' => $session['session']['runtime_profile']['profile_name'] ?? null,
                'session_id' => (string) $normalized['session_id'],
            ],
            'content' => $payload,
            'diagnostics' => $session['session']['warnings'] ?? [],
        ]], $includeResult === false);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function handlePlanPhpunit(array $request, array $normalized): array
    {
        [$profile, $selector, $executionPlan] = $this->resolvePhpunitPlan($normalized);
        $plan = $this->buildPlanPayload($profile, $executionPlan, [
            'type' => 'phpunit',
            'normalized_test_ref' => $selector['normalized'],
        ]);

        return $this->buildSuccessEnvelope($request, $normalized, 'plan_phpunit', [[
            'id' => 'plan_phpunit:' . $selector['normalized'],
            'type' => 'execution_plan',
            'rank' => 1,
            'confidence' => 'high',
            'source' => [
                'kind' => 'runtime_profile',
                'profile_name' => $profile->profileName,
                'session_id' => null,
            ],
            'content' => [
                'plan' => $plan,
            ],
            'diagnostics' => $plan['warnings'],
        ]], true);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function handlePlanCli(array $request, array $normalized): array
    {
        [$profile, $scriptPath, $executionPlan] = $this->resolveCliPlan($normalized);
        $plan = $this->buildPlanPayload($profile, $executionPlan, [
            'type' => 'cli',
            'script_path' => $scriptPath,
            'script_args' => $normalized['script_args'],
        ]);

        return $this->buildSuccessEnvelope($request, $normalized, 'plan_cli', [[
            'id' => 'plan_cli:' . $scriptPath,
            'type' => 'execution_plan',
            'rank' => 1,
            'confidence' => 'high',
            'source' => [
                'kind' => 'runtime_profile',
                'profile_name' => $profile->profileName,
                'session_id' => null,
            ],
            'content' => [
                'plan' => $plan,
            ],
            'diagnostics' => $plan['warnings'],
        ]], true);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function handleExecutePhpunit(array $request, array $normalized): array
    {
        $response = $this->application->debugPhpunitTest($this->buildPhpunitToolPayload($normalized));
        if (($response['ok'] ?? false) !== true) {
            return $this->buildFailureEnvelopeFromToolError($request, 'execute_phpunit', $response, $normalized);
        }

        return $this->buildExecuteSuccessEnvelope($request, $normalized, 'execute_phpunit', $response);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function handleExecuteCli(array $request, array $normalized): array
    {
        $response = $this->application->debugCliScript($this->buildCliToolPayload($normalized));
        if (($response['ok'] ?? false) !== true) {
            return $this->buildFailureEnvelopeFromToolError($request, 'execute_cli', $response, $normalized);
        }

        return $this->buildExecuteSuccessEnvelope($request, $normalized, 'execute_cli', $response);
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{0:RuntimeProfile,1:array{normalized:string,method_name:string,guessed_test_file:?string},2:array<string,mixed>}
     */
    private function resolvePhpunitPlan(array $normalized): array
    {
        $profile = $this->profileLoader->getProfile((string) $normalized['runtime_profile'], 'phpunit');
        $selector = $this->selectorValidator->validate((string) $normalized['test_ref'], $profile->moodleRoot);
        if (($selector['valid'] ?? false) !== true) {
            throw new \RuntimeException((string) ($selector['message'] ?? 'Invalid PHPUnit selector.'));
        }

        $executionPlan = $this->executionPlanFactory->forPhpunit($profile, $selector);

        return [$profile, $selector, $executionPlan];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{0:RuntimeProfile,1:string,2:array<string,mixed>}
     */
    private function resolveCliPlan(array $normalized): array
    {
        $profile = $this->profileLoader->getProfile((string) $normalized['runtime_profile'], 'cli');
        $script = $this->cliPathValidator->validate((string) $normalized['script_path'], $this->profileLoader->getCliAllowlist());
        if (($script['valid'] ?? false) !== true) {
            throw new \RuntimeException((string) ($script['message'] ?? 'Invalid CLI script path.'));
        }

        $executionPlan = $this->executionPlanFactory->forCli($profile, (string) $script['normalized'], $normalized['script_args']);

        return [$profile, (string) $script['normalized'], $executionPlan];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildPhpunitToolPayload(array $normalized): array
    {
        return [
            'moodle_root' => $normalized['moodle_root'],
            'test_ref' => $normalized['test_ref'],
            'runtime_profile' => $normalized['runtime_profile'],
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'capture_policy' => $normalized['capture_policy'],
            'timeout_seconds' => $normalized['timeout_seconds'],
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildCliToolPayload(array $normalized): array
    {
        return [
            'moodle_root' => $normalized['moodle_root'],
            'script_path' => $normalized['script_path'],
            'script_args' => $normalized['script_args'],
            'runtime_profile' => $normalized['runtime_profile'],
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'capture_policy' => $normalized['capture_policy'],
            'timeout_seconds' => $normalized['timeout_seconds'],
        ];
    }

    /**
     * @param array<string, mixed> $profileTarget
     * @param array<string, mixed> $executionPlan
     * @return array<string, mixed>
     */
    private function buildPlanPayload(RuntimeProfile $profile, array $executionPlan, array $profileTarget): array
    {
        $warnings = [];
        if ($profile->backendKind === 'xdebug') {
            $this->xdebugLaunchSettingsBuilder->validateProfile($profile);
        }

        $resolvedCommand = $profile->backendKind === 'xdebug'
            ? $this->xdebugLaunchSettingsBuilder->buildCommand($profile, $executionPlan)
            : array_values(array_merge($executionPlan['launcher'] ?? [], $executionPlan['command'] ?? []));

        if ($profile->backendKind !== 'xdebug') {
            $warnings[] = [
                'code' => 'MOCK_BACKEND_ONLY',
                'message' => 'This plan uses the mock backend; no real debugger connection will be attempted.',
            ];
        }

        return [
            'target' => $profileTarget,
            'validated_target' => $profileTarget,
            'runtime_profile' => [
                'profile_name' => $profile->profileName,
                'launcher_kind' => $profile->launcherKind,
                'backend_kind' => $profile->backendKind,
                'execution_transport' => $profile->executionTransport,
                'working_directory' => $profile->workingDirectory,
            ],
            'execution' => [
                'allowed' => true,
                'launcher' => $executionPlan['launcher'],
                'command' => $resolvedCommand,
                'cwd' => $executionPlan['cwd'],
                'listener' => [
                    'bind_address' => $profile->listenerBindAddress,
                    'client_host' => $profile->xdebugClientHost,
                    'client_port' => $profile->xdebugClientPort,
                ],
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function buildExecuteSuccessEnvelope(array $request, array $normalized, string $intent, array $response): array
    {
        $payload = [
            'session' => $response['session'],
            'result' => $response['result'],
        ];

        $resultItem = [
            'id' => (string) $response['session']['session']['session_id'],
            'type' => 'debug_execution',
            'rank' => 1,
            'confidence' => (string) ($response['result']['summary']['confidence'] ?? 'medium'),
            'source' => [
                'kind' => 'debug_run',
                'profile_name' => $response['session']['runtime_profile']['profile_name'] ?? null,
                'session_id' => (string) $response['session']['session']['session_id'],
            ],
            'content' => [
                'session' => $response['session'],
                'summary' => $response['result']['summary'],
                'investigation' => $this->buildInvestigationPayload($payload),
                'rerun' => $response['result']['rerun'],
                'stop_event' => $response['result']['stop_event'],
            ],
            'diagnostics' => $response['result']['warnings'] ?? [],
        ];

        return $this->buildSuccessEnvelope($request, $normalized, $intent, [$resultItem], false);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildInvestigationPayload(array $payload): array
    {
        $result = $payload['result'] ?? [];
        $mapping = $result['moodle_mapping'] ?? [];
        $frames = $result['frames'] ?? [];
        $frameByIndex = [];
        foreach ($frames as $frame) {
            $frameByIndex[(int) ($frame['index'] ?? 0)] = $frame;
        }

        $probableIndex = (int) ($mapping['probable_fault_frame_index'] ?? 0);
        $likelyFrame = $frameByIndex[$probableIndex] ?? [];
        $likelyIssue = $mapping['likely_issue'] ?? [];
        $candidates = [];
        foreach ($mapping['fault_ranking'] ?? [] as $candidate) {
            $index = (int) ($candidate['frame_index'] ?? 0);
            $frame = $frameByIndex[$index] ?? [];
            $candidates[] = [
                'frame_index' => $index,
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'confidence' => $candidate['confidence'] ?? 'low',
                'rationale' => $candidate['rationale'] ?? null,
            ];
        }

        $inspectionTargets = [];
        if (isset($likelyFrame['file'])) {
            $inspectionTargets[] = [
                'kind' => 'file',
                'value' => $likelyFrame['file'],
            ];
        }
        if (isset($result['target']['normalized_test_ref'])) {
            $inspectionTargets[] = [
                'kind' => 'test_selector',
                'value' => $result['target']['normalized_test_ref'],
            ];
        }
        if (isset($result['target']['script_path'])) {
            $inspectionTargets[] = [
                'kind' => 'cli_script',
                'value' => $result['target']['script_path'],
            ];
        }
        if (($likelyIssue['component'] ?? 'unknown') !== 'unknown') {
            $inspectionTargets[] = [
                'kind' => 'moodle_component',
                'value' => $likelyIssue['component'],
            ];
        }

        return [
            'session_provenance' => [
                'session_id' => $payload['session']['session']['session_id'] ?? null,
                'created_at' => $payload['session']['session']['created_at'] ?? null,
                'profile_name' => $payload['session']['runtime_profile']['profile_name'] ?? null,
                'target_type' => $payload['session']['target_type'] ?? null,
            ],
            'likely_fault' => [
                'frame_index' => $probableIndex,
                'file' => $likelyFrame['file'] ?? null,
                'line' => $likelyFrame['line'] ?? null,
                'function' => $likelyFrame['function'] ?? null,
                'class' => $likelyFrame['class'] ?? null,
                'component' => $likelyIssue['component'] ?? ($result['summary']['probable_fault']['component'] ?? 'unknown'),
                'subsystem' => $likelyIssue['subsystem'] ?? 'unknown',
                'issue_category' => $likelyIssue['category'] ?? 'unknown',
                'confidence' => $likelyIssue['confidence'] ?? ($result['summary']['confidence'] ?? 'low'),
            ],
            'candidate_frames' => $candidates,
            'inspection_targets' => array_values($inspectionTargets),
            'rerun_command' => $result['rerun']['command'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function normalizeQuery(array $request): array
    {
        $defaultCapturePolicy = [
            'max_frames' => 25,
            'max_locals_per_frame' => 10,
            'max_string_length' => 512,
            'include_args' => true,
            'include_locals' => true,
            'focus_top_frames' => 5,
        ];

        $intent = (string) ($request['intent'] ?? '');
        $normalized = [
            'intent' => $intent,
            'session_id' => isset($request['session_id']) ? (string) $request['session_id'] : null,
            'runtime_profile' => isset($request['runtime_profile']) ? (string) $request['runtime_profile'] : $this->defaultProfileForIntent($intent),
            'moodle_root' => isset($request['moodle_root']) ? (string) $request['moodle_root'] : ($this->repoRoot . '/_smoke_test/moodle_fixture'),
            'test_ref' => isset($request['test_ref']) ? (string) $request['test_ref'] : null,
            'script_path' => isset($request['script_path']) ? (string) $request['script_path'] : null,
            'script_args' => isset($request['script_args']) && is_array($request['script_args']) ? array_values(array_map(static fn (mixed $item): string => (string) $item, $request['script_args'])) : [],
            'timeout_seconds' => isset($request['timeout_seconds']) ? (int) $request['timeout_seconds'] : 120,
            'summary_depth' => isset($request['summary_depth']) ? (string) $request['summary_depth'] : 'standard',
            'include_result' => isset($request['include_result']) ? (bool) $request['include_result'] : true,
            'capture_policy' => isset($request['capture_policy']) && is_array($request['capture_policy']) ? array_merge($defaultCapturePolicy, $request['capture_policy']) : $defaultCapturePolicy,
        ];

        return $normalized;
    }

    private function defaultProfileForIntent(string $intent): string
    {
        return match ($intent) {
            'plan_phpunit', 'execute_phpunit' => 'default_phpunit',
            'plan_cli', 'execute_cli' => 'default_cli',
            default => '',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildEnvelope(array $query, array $normalizedQuery, string $intent, array $results, array $diagnostics, array $meta): array
    {
        return [
            'tool' => self::TOOL_NAME,
            'version' => self::RUNTIME_VERSION,
            'query' => $this->normalizeObjectPayload($query),
            'normalized_query' => $this->normalizeObjectPayload($normalizedQuery),
            'intent' => $intent,
            'results' => $results,
            'diagnostics' => $diagnostics,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function buildSuccessEnvelope(array $request, array $normalized, string $intent, array $results, bool $dryRun): array
    {
        $status = $this->reduceStatus(array_map(
            static fn (array $result): array => [
                'status' => count($result['diagnostics'] ?? []) > 0 ? 'warn' : 'ok',
            ],
            $results
        ));

        $response = $this->buildEnvelope(
            query: $request,
            normalizedQuery: $normalized,
            intent: $intent,
            results: $results,
            diagnostics: [],
            meta: [
                'status' => $status,
                'generated_at' => $this->clock->now()->format(DATE_ATOM),
                'repo_root' => $this->repoRoot,
                'dry_run' => $dryRun,
                'exit_code' => 0,
            ],
        );

        return $this->finalizeResponse('runtime_query', $response);
    }

    /**
     * @param array<string, mixed> $normalized
     * @param string[] $diagnosticHints
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function buildFailureEnvelope(array $request, string $intent, string $code, string $message, int $exitCode, ?array $normalized = null, array $diagnosticHints = [], array $details = []): array
    {
        $diagnostic = [
            'code' => $code,
            'message' => $message,
            'level' => 'error',
        ];
        if ($diagnosticHints !== []) {
            $diagnostic['hints'] = $diagnosticHints;
        }
        if ($details !== []) {
            $diagnostic['details'] = $details;
        }

        $response = $this->buildEnvelope(
            query: $request,
            normalizedQuery: $normalized ?? $this->normalizeQuery($request),
            intent: $intent,
            results: [],
            diagnostics: [$diagnostic],
            meta: [
                'status' => 'fail',
                'generated_at' => $this->clock->now()->format(DATE_ATOM),
                'repo_root' => $this->repoRoot,
                'dry_run' => str_starts_with($intent, 'plan_') || $intent === 'health',
                'exit_code' => $exitCode,
            ],
        );

        return $this->finalizeResponse(str_starts_with($intent, 'health') ? 'health' : 'runtime_query', $response);
    }

    /**
     * @param array<string, mixed> $toolResponse
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildFailureEnvelopeFromToolError(array $request, string $intent, array $toolResponse, array $normalized): array
    {
        $error = $toolResponse['error'] ?? [];

        return $this->buildFailureEnvelope(
            $request,
            $intent,
            (string) ($error['code'] ?? 'INTERNAL_ORCHESTRATION_ERROR'),
            (string) ($error['message'] ?? 'Runtime operation failed.'),
            1,
            $normalized,
            is_array($error['diagnostic_hints'] ?? null) ? array_values(array_map(static fn (mixed $item): string => (string) $item, $error['diagnostic_hints'])) : [],
            is_array($error['details'] ?? null) ? $error['details'] : [],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $subsystems
     */
    private function reduceStatus(array $subsystems): string
    {
        $hasFail = false;
        $hasWarn = false;
        foreach ($subsystems as $subsystem) {
            $status = (string) ($subsystem['status'] ?? 'ok');
            $hasFail = $hasFail || $status === 'fail';
            $hasWarn = $hasWarn || $status === 'warn';
        }

        return $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'ok');
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function buildHealthSubsystem(string $name, string $status, string $message, array $details = []): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function isSessionStoreWritable(): bool
    {
        $tempFile = $this->repoRoot . '/_smoke_test/moodle_debug_sessions/.runtime_probe';
        $written = @file_put_contents($tempFile, 'ok');
        if ($written === false) {
            return false;
        }
        @unlink($tempFile);

        return true;
    }

    private function canBindListener(string $address, int $port): bool
    {
        $host = $address === '0.0.0.0' ? '127.0.0.1' : $address;
        $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $error);
        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $profileDiagnostics
     */
    private function hasDockerBackedProfile(array $profileDiagnostics): bool
    {
        foreach ($profileDiagnostics as $profile) {
            if (($profile['execution_transport'] ?? null) === 'docker_exec') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $profileDiagnostics
     */
    private function canResolveDockerCommand(array $profileDiagnostics): bool
    {
        foreach ($profileDiagnostics as $profile) {
            if (($profile['execution_transport'] ?? null) !== 'docker_exec') {
                continue;
            }

            $profileName = (string) ($profile['profile_name'] ?? '');
            $launcherKind = str_contains($profileName, 'phpunit') ? 'phpunit' : 'cli';
            try {
                $runtimeProfile = $this->profileLoader->getProfile($profileName, $launcherKind);
            } catch (\Throwable) {
                return false;
            }

            $command = $runtimeProfile->dockerComposeCommand[0] ?? null;
            if (!is_string($command) || $command === '') {
                return false;
            }

            if (str_contains($command, '/')) {
                return is_file($command);
            }

            return $this->commandExists($command);
        }

        return true;
    }

    private function commandExists(string $command): bool
    {
        $output = [];
        $exitCode = 1;
        @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeResponse(string $section, array $response): array
    {
        $validated = $this->schemaValidator->validateResponse($section, $response);
        if ($validated['valid']) {
            return $response;
        }

        return [
            'tool' => self::TOOL_NAME,
            'version' => self::RUNTIME_VERSION,
            'query' => [],
            'normalized_query' => ['intent' => $section],
            'intent' => $section,
            'results' => [],
            'diagnostics' => [[
                'code' => 'INTERNAL_ORCHESTRATION_ERROR',
                'message' => $validated['message'] ?? 'Runtime response failed schema validation.',
                'level' => 'error',
            ]],
            'meta' => [
                'status' => 'fail',
                'generated_at' => $this->clock->now()->format(DATE_ATOM),
                'repo_root' => $this->repoRoot,
                'dry_run' => false,
                'exit_code' => 1,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeObjectPayload(array $payload): array
    {
        if ($payload === []) {
            return ['input' => []];
        }

        return array_is_list($payload) ? ['items' => $payload] : $payload;
    }
}
