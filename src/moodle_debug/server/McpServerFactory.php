<?php

declare(strict_types=1);

namespace MoodleDebug\server;

use Mcp\Server;

final class McpServerFactory
{
    public function create(string $repoRoot): Server
    {
        $schemaValidator = new \MoodleDebug\contracts\SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json');
        $toolHandler = new ToolHandler((new ApplicationFactory())->create($repoRoot));

        return Server::builder()
            ->setServerInfo(
                name: 'moodle_debug',
                version: '0.1.0',
                description: 'Mock-backed Moodle-aware debug orchestration server'
            )
            ->setInstructions('Use these tools for deterministic Moodle PHPUnit and CLI debug orchestration. This phase is mock-backed and read-only after session capture.')
            ->addTool(
                fn (
                    string $moodle_root,
                    string $test_ref,
                    string $runtime_profile,
                    array $stop_policy,
                    array $capture_policy,
                    int $timeout_seconds,
                    ?string $idempotency_key = null,
                ): array => $toolHandler->debugPhpunitTest(
                    $moodle_root,
                    $test_ref,
                    $runtime_profile,
                    $stop_policy,
                    $capture_policy,
                    $timeout_seconds,
                    $idempotency_key,
                ),
                'debug_phpunit_test',
                'Run a mocked Moodle PHPUnit debug workflow for one class-based selector.',
                null,
                $schemaValidator->getToolInputSchema('debug_phpunit_test'),
            )
            ->addTool(
                fn (
                    string $moodle_root,
                    string $script_path,
                    array $script_args,
                    string $runtime_profile,
                    array $stop_policy,
                    array $capture_policy,
                    int $timeout_seconds,
                    ?string $idempotency_key = null,
                ): array => $toolHandler->debugCliScript(
                    $moodle_root,
                    $script_path,
                    $script_args,
                    $runtime_profile,
                    $stop_policy,
                    $capture_policy,
                    $timeout_seconds,
                    $idempotency_key,
                ),
                'debug_cli_script',
                'Run a mocked Moodle CLI debug workflow for one allowlisted script.',
                null,
                $schemaValidator->getToolInputSchema('debug_cli_script'),
            )
            ->addTool(
                fn (string $session_id, array $include = []): array => $toolHandler->getDebugSession($session_id, $include),
                'get_debug_session',
                'Read a stored debug session snapshot.',
                null,
                $schemaValidator->getToolInputSchema('get_debug_session'),
            )
            ->addTool(
                fn (string $session_id, ?string $summary_depth = null, ?string $focus = null): array => $toolHandler->summariseDebugSession(
                    $session_id,
                    $summary_depth,
                    $focus,
                ),
                'summarise_debug_session',
                'Generate a summary from a stored debug session snapshot.',
                null,
                $schemaValidator->getToolInputSchema('summarise_debug_session'),
            )
            ->addTool(
                fn (
                    string $moodle_root,
                    array $frames,
                    ?array $exception = null,
                    ?array $test_context = null,
                ): array => $toolHandler->mapStackToMoodleContext(
                    $moodle_root,
                    $frames,
                    $exception,
                    $test_context,
                ),
                'map_stack_to_moodle_context',
                'Map stack frames to Moodle component context.',
                null,
                $schemaValidator->getToolInputSchema('map_stack_to_moodle_context'),
            )
            ->build();
    }
}
