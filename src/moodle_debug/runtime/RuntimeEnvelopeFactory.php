<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\runtime;

use MoodleDebug\contracts\ClockInterface;
use MoodleDebug\contracts\RuntimeSchemaValidator;

/**
 * Owns the stable subprocess response envelope used by runtime-facing commands.
 *
 * Keeping envelope creation in one place makes it easier to preserve a clean
 * sibling-tool contract even as the debugger grows new internal capabilities.
 */
final class RuntimeEnvelopeFactory
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly RuntimeSchemaValidator $schemaValidator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $normalizedQuery
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public function success(string $section, array $query, array $normalizedQuery, string $intent, array $results, bool $dryRun, ?string $statusOverride = null): array
    {
        $status = $statusOverride ?? $this->reduceStatus(array_map(
            static fn (array $result): array => [
                'status' => count($result['diagnostics'] ?? []) > 0 ? 'warn' : 'ok',
            ],
            $results
        ));

        return $this->finalize($section, $this->buildEnvelope(
            query: $query,
            normalizedQuery: $normalizedQuery,
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
        ));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $normalizedQuery
     * @param string[] $diagnosticHints
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function failure(string $section, array $query, string $intent, string $code, string $message, int $exitCode, bool $dryRun, ?array $normalizedQuery = null, array $diagnosticHints = [], array $details = []): array
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

        return $this->finalize($section, $this->buildEnvelope(
            query: $query,
            normalizedQuery: $normalizedQuery ?? ['intent' => $intent],
            intent: $intent,
            results: [],
            diagnostics: [$diagnostic],
            meta: [
                'status' => 'fail',
                'generated_at' => $this->clock->now()->format(DATE_ATOM),
                'repo_root' => $this->repoRoot,
                'dry_run' => $dryRun,
                'exit_code' => $exitCode,
            ],
        ));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $normalizedQuery
     * @param array<int, array<string, mixed>> $results
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildEnvelope(array $query, array $normalizedQuery, string $intent, array $results, array $diagnostics, array $meta): array
    {
        return [
            'tool' => RuntimeApplication::TOOL_NAME,
            'version' => RuntimeApplication::RUNTIME_VERSION,
            'query' => $this->normalizeObjectPayload($query),
            'normalized_query' => $this->normalizeObjectPayload($normalizedQuery),
            'intent' => $intent,
            'results' => $results,
            'diagnostics' => $diagnostics,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function finalize(string $section, array $response): array
    {
        $validated = $this->schemaValidator->validateResponse($section, $response);
        if ($validated['valid']) {
            return $response;
        }

        return [
            'tool' => RuntimeApplication::TOOL_NAME,
            'version' => RuntimeApplication::RUNTIME_VERSION,
            'query' => ['input' => []],
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
}
