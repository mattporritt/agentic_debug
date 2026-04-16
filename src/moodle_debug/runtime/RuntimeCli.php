<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\runtime;

/**
 * Minimal JSON-only CLI for subprocess orchestration.
 *
 * The CLI deliberately avoids mixing prose into stdout so future callers such
 * as `agentic_orchestrator` can treat it as a stable machine interface.
 */
final class RuntimeCli
{
    public function __construct(
        private readonly RuntimeApplication $runtimeApplication,
    ) {
    }

    /**
     * @param string[] $argv
     */
    public function execute(array $argv): int
    {
        $command = $argv[1] ?? null;
        if (!in_array($command, ['runtime-query', 'health'], true)) {
            return 2;
        }

        $request = $this->parseRequest(array_slice($argv, 2));
        if (isset($request['__parse_error'])) {
            $response = [
                'tool' => RuntimeApplication::TOOL_NAME,
                'version' => RuntimeApplication::RUNTIME_VERSION,
                'query' => [],
                'normalized_query' => ['intent' => $command],
                'intent' => $command,
                'results' => [],
                'diagnostics' => [[
                    'code' => 'INVALID_RUNTIME_REQUEST',
                    'message' => (string) $request['__parse_error'],
                    'level' => 'error',
                ]],
                'meta' => [
                    'status' => 'fail',
                    'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'repo_root' => getcwd() ?: '.',
                    'dry_run' => $command === 'health',
                    'exit_code' => 1,
                ],
            ];
        } else {
            $response = $command === 'health'
                ? $this->runtimeApplication->health($request)
                : $this->runtimeApplication->runtimeQuery($request);
        }

        fwrite(STDOUT, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return (int) ($response['meta']['exit_code'] ?? (($response['meta']['status'] ?? 'fail') === 'fail' ? 1 : 0));
    }

    /**
     * @param string[] $args
     * @return array<string, mixed>
     */
    private function parseRequest(array $args): array
    {
        if ($args === []) {
            return [];
        }

        $json = null;
        $inputPath = null;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--json') {
                $json = $args[$i + 1] ?? null;
                $i++;
                continue;
            }
            if ($arg === '--input') {
                $inputPath = $args[$i + 1] ?? null;
                $i++;
                continue;
            }
            if ($arg === '--stdin') {
                $json = stream_get_contents(STDIN);
                continue;
            }

            return ['__parse_error' => "Unknown runtime option: {$arg}"];
        }

        if ($json !== null && $inputPath !== null) {
            return ['__parse_error' => 'Provide only one of --json, --input, or --stdin.'];
        }

        if ($inputPath !== null) {
            $contents = @file_get_contents($inputPath);
            if ($contents === false) {
                return ['__parse_error' => "Unable to read runtime request file: {$inputPath}"];
            }
            $json = $contents;
        }

        if ($json === null || trim($json) === '') {
            return [];
        }

        // Requests are always object-shaped. An array or scalar would make the
        // subprocess contract ambiguous for downstream callers.
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['__parse_error' => 'Runtime request must decode to a JSON object.'];
        }

        return $decoded;
    }
}
