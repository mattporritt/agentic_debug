<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

use MoodleDebug\contracts\RuntimeSchemaValidator;
use MoodleDebug\debug_backend\DebugBackendException;
use MoodleDebug\server\Application;
use MoodleDebug\server\SummaryBuilder;
use MoodleDebug\session_store\FileArtifactSessionStore;

/**
 * Runtime-facing orchestration facade used by sibling-tool subprocess callers.
 *
 * MCP remains the direct agent-facing API. This class exists to expose the same
 * validated debugger capabilities through a deterministic JSON contract that is
 * easy for another tool to call via `proc_open()` or similar orchestration.
 */
final class RuntimeApplication
{
    public const TOOL_NAME = 'moodle_debug';
    public const RUNTIME_VERSION = 'runtime-v1';

    public function __construct(
        private readonly string $repoRoot,
        private readonly RuntimeSchemaValidator $schemaValidator,
        private readonly Application $application,
        private readonly FileArtifactSessionStore $sessionStore,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly RuntimeRequestNormalizer $requestNormalizer,
        private readonly RuntimePlanBuilder $planBuilder,
        private readonly RuntimeInvestigationBuilder $investigationBuilder,
        private readonly RuntimeHealthReporter $healthReporter,
        private readonly RuntimeEnvelopeFactory $envelopeFactory,
    ) {
    }

    /**
     * Handle an explicit runtime intent such as plan, execute, or interpret.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function runtimeQuery(array $request): array
    {
        $validated = $this->schemaValidator->validateRequest('runtime_query', $request);
        if (!$validated['valid']) {
            return $this->envelopeFactory->failure(
                section: 'runtime_query',
                query: $request,
                intent: 'invalid_request',
                code: 'INVALID_RUNTIME_REQUEST',
                message: $validated['message'] ?? 'Invalid runtime request.',
                exitCode: 1,
                dryRun: true
            );
        }

        $normalized = $this->requestNormalizer->normalize($request, $this->repoRoot);
        $intent = (string) $normalized['intent'];

        if ($intent === '') {
            return $this->envelopeFactory->failure(
                section: 'runtime_query',
                query: $request,
                intent: 'invalid_request',
                code: 'INVALID_RUNTIME_REQUEST',
                message: 'Runtime request must include an explicit intent.',
                exitCode: 1,
                dryRun: true,
                normalizedQuery: $normalized
            );
        }

        try {
            return match ($intent) {
                'interpret_session' => $this->handleInterpretSession($request, $normalized),
                'get_session' => $this->handleGetSession($request, $normalized),
                'plan_phpunit' => $this->handlePlanPhpunit($request, $normalized),
                'plan_cli' => $this->handlePlanCli($request, $normalized),
                'execute_phpunit' => $this->handleExecutePhpunit($request, $normalized),
                'execute_cli' => $this->handleExecuteCli($request, $normalized),
                default => $this->envelopeFactory->failure(
                    section: 'runtime_query',
                    query: $request,
                    intent: $intent,
                    code: 'INVALID_INTENT',
                    message: "Unsupported runtime intent: {$intent}",
                    exitCode: 1,
                    dryRun: false,
                    normalizedQuery: $normalized
                ),
            };
        } catch (DebugBackendException $exception) {
            return $this->envelopeFactory->failure(
                section: 'runtime_query',
                query: $request,
                intent: $intent,
                code: $exception->getErrorCode(),
                message: $exception->getMessage(),
                exitCode: 1,
                dryRun: $this->isDryRunIntent($intent),
                normalizedQuery: $normalized,
                diagnosticHints: $exception->getDiagnosticHints(),
                details: $exception->getDetails()
            );
        } catch (\RuntimeException $exception) {
            return $this->envelopeFactory->failure(
                section: 'runtime_query',
                query: $request,
                intent: $intent,
                code: 'INVALID_REQUEST',
                message: $exception->getMessage(),
                exitCode: 1,
                dryRun: $this->isDryRunIntent($intent),
                normalizedQuery: $normalized
            );
        } catch (\Throwable $exception) {
            return $this->envelopeFactory->failure(
                section: 'runtime_query',
                query: $request,
                intent: $intent,
                code: 'INTERNAL_ORCHESTRATION_ERROR',
                message: $exception->getMessage(),
                exitCode: 1,
                dryRun: $this->isDryRunIntent($intent),
                normalizedQuery: $normalized
            );
        }
    }

    /**
     * Return a bounded environment readiness report.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function health(array $request = []): array
    {
        $validated = $this->schemaValidator->validateRequest('health', $request);
        if (!$validated['valid']) {
            return $this->envelopeFactory->failure(
                section: 'health',
                query: $request,
                intent: 'health',
                code: 'INVALID_RUNTIME_REQUEST',
                message: $validated['message'] ?? 'Invalid health request.',
                exitCode: 1,
                dryRun: true
            );
        }

        $report = $this->healthReporter->build($request);

        return $this->envelopeFactory->success(
            section: 'health',
            query: $request,
            normalizedQuery: $report['normalized_query'],
            intent: 'health',
            results: [$report['result']],
            dryRun: true,
            statusOverride: (string) $report['status'],
        );
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
            return $this->envelopeFactory->failure('runtime_query', $request, 'interpret_session', 'SESSION_NOT_FOUND', 'Debug session not found.', 1, true, $normalized);
        }
        if ($lookup->expired) {
            return $this->envelopeFactory->failure('runtime_query', $request, 'interpret_session', 'SESSION_EXPIRED', 'Debug session has expired.', 1, true, $normalized);
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

        return $this->envelopeFactory->success('runtime_query', $request, $normalized, 'interpret_session', [[
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
                'investigation' => $this->investigationBuilder->build($payload),
                'rerun' => $payload['result']['rerun'] ?? null,
            ],
            'diagnostics' => $payload['result']['warnings'] ?? [],
        ]], true);
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
            return $this->failureFromToolResponse($request, 'get_session', $session, $normalized, $includeResult === false);
        }

        $content = [
            'session' => $session['session'],
        ];
        if ($includeResult && isset($session['result'])) {
            $content['result'] = $session['result'];
            $content['investigation'] = $this->investigationBuilder->build([
                'session' => $session['session'],
                'result' => $session['result'],
            ]);
        }

        return $this->envelopeFactory->success('runtime_query', $request, $normalized, 'get_session', [[
            'id' => (string) $normalized['session_id'],
            'type' => 'session_record',
            'rank' => 1,
            'confidence' => isset($session['result']['summary']['confidence']) ? (string) $session['result']['summary']['confidence'] : 'high',
            'source' => [
                'kind' => 'session_store',
                'profile_name' => $session['session']['runtime_profile']['profile_name'] ?? null,
                'session_id' => (string) $normalized['session_id'],
            ],
            'content' => $content,
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
        [$profile, $selector, $executionPlan] = $this->planBuilder->resolvePhpunit($normalized);
        $plan = $this->planBuilder->buildPlanPayload($profile, $executionPlan, [
            'type' => 'phpunit',
            'normalized_test_ref' => $selector['normalized'],
        ]);

        return $this->envelopeFactory->success('runtime_query', $request, $normalized, 'plan_phpunit', [[
            'id' => 'plan_phpunit:' . $selector['normalized'],
            'type' => 'execution_plan',
            'rank' => 1,
            'confidence' => 'high',
            'source' => [
                'kind' => 'runtime_profile',
                'profile_name' => $profile->profileName,
                'session_id' => null,
            ],
            'content' => ['plan' => $plan],
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
        [$profile, $scriptPath, $executionPlan] = $this->planBuilder->resolveCli($normalized);
        $plan = $this->planBuilder->buildPlanPayload($profile, $executionPlan, [
            'type' => 'cli',
            'script_path' => $scriptPath,
            'script_args' => $normalized['script_args'],
        ]);

        return $this->envelopeFactory->success('runtime_query', $request, $normalized, 'plan_cli', [[
            'id' => 'plan_cli:' . $scriptPath,
            'type' => 'execution_plan',
            'rank' => 1,
            'confidence' => 'high',
            'source' => [
                'kind' => 'runtime_profile',
                'profile_name' => $profile->profileName,
                'session_id' => null,
            ],
            'content' => ['plan' => $plan],
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
        $response = $this->application->debugPhpunitTest([
            'moodle_root' => $normalized['moodle_root'],
            'test_ref' => $normalized['test_ref'],
            'runtime_profile' => $normalized['runtime_profile'],
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'capture_policy' => $normalized['capture_policy'],
            'timeout_seconds' => $normalized['timeout_seconds'],
        ]);

        if (($response['ok'] ?? false) !== true) {
            return $this->failureFromToolResponse($request, 'execute_phpunit', $response, $normalized, false);
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
        $response = $this->application->debugCliScript([
            'moodle_root' => $normalized['moodle_root'],
            'script_path' => $normalized['script_path'],
            'script_args' => $normalized['script_args'],
            'runtime_profile' => $normalized['runtime_profile'],
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'capture_policy' => $normalized['capture_policy'],
            'timeout_seconds' => $normalized['timeout_seconds'],
        ]);

        if (($response['ok'] ?? false) !== true) {
            return $this->failureFromToolResponse($request, 'execute_cli', $response, $normalized, false);
        }

        return $this->buildExecuteSuccessEnvelope($request, $normalized, 'execute_cli', $response);
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

        return $this->envelopeFactory->success('runtime_query', $request, $normalized, $intent, [[
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
                'investigation' => $this->investigationBuilder->build($payload),
                'rerun' => $response['result']['rerun'],
                'stop_event' => $response['result']['stop_event'],
            ],
            'diagnostics' => $response['result']['warnings'] ?? [],
        ]], false);
    }

    /**
     * Convert existing tool-style failures into the runtime envelope.
     *
     * @param array<string, mixed> $toolResponse
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function failureFromToolResponse(array $request, string $intent, array $toolResponse, array $normalized, bool $dryRun): array
    {
        $error = $toolResponse['error'] ?? [];

        return $this->envelopeFactory->failure(
            section: 'runtime_query',
            query: $request,
            intent: $intent,
            code: (string) ($error['code'] ?? 'INTERNAL_ORCHESTRATION_ERROR'),
            message: (string) ($error['message'] ?? 'Runtime operation failed.'),
            exitCode: 1,
            dryRun: $dryRun,
            normalizedQuery: $normalized,
            diagnosticHints: is_array($error['diagnostic_hints'] ?? null)
                ? array_values(array_map(static fn (mixed $item): string => (string) $item, $error['diagnostic_hints']))
                : [],
            details: is_array($error['details'] ?? null) ? $error['details'] : [],
        );
    }

    private function isDryRunIntent(string $intent): bool
    {
        return str_starts_with($intent, 'plan_') || in_array($intent, ['get_session', 'interpret_session', 'health'], true);
    }

}
