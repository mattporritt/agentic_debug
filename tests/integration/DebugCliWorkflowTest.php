<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\integration;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\debug_backend\DebugBackendInterface;
use MoodleDebug\runtime\CliPathValidator;
use MoodleDebug\runtime\ExecutionPlanFactory;
use MoodleDebug\runtime\PathMapper;
use MoodleDebug\runtime\PHPUnitSelectorValidator;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\server\Application;
use MoodleDebug\server\MoodleContextMapper;
use MoodleDebug\server\SummaryBuilder;
use MoodleDebug\session_store\FileArtifactSessionStore;
use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class DebugCliWorkflowTest extends TestCase
{
    public function testRunsEndToEndForCli(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_integration_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugCliScript([
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'script_path' => 'admin/cli/some_script.php',
            'script_args' => ['--verbose=1'],
            'runtime_profile' => 'default_cli',
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'capture_policy' => [
                'max_frames' => 25,
                'max_locals_per_frame' => 10,
                'max_string_length' => 512,
                'include_args' => true,
                'include_locals' => true,
                'focus_top_frames' => 5,
            ],
            'timeout_seconds' => 120,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('cli', $result['result']['target']['type']);
        self::assertSame('cli', $result['session']['runtime_profile']['launcher_kind']);
        self::assertSame('core_admin', $result['result']['moodle_mapping']['annotations'][0]['component']);
        self::assertSame('cli_workflow', $result['result']['moodle_mapping']['likely_issue']['category']);
        self::assertStringContainsString('CLI entrypoint', implode(' ', $result['result']['summary']['suggested_next_actions']));
    }

    public function testBuildsExplicitDockerBackedRerunMetadata(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $clock = new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'));
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json');
        $application = new Application(
            schemaValidator: new SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json'),
            profileLoader: $profileLoader,
            backend: new class implements DebugBackendInterface {
                public function prepare_session(array $context): array
                {
                    return ['backend_session_id' => 'xdb_fake'];
                }
                public function launch_target(array $preparedSession, array $executionPlan): array
                {
                    return [
                        'backend_session_id' => 'xdb_fake',
                        'launched_at' => '2026-04-15T00:00:00+00:00',
                        'launcher' => [],
                        'command' => [
                            '/tmp/moodle-docker/bin/moodle-docker-compose',
                            'exec',
                            '-T',
                            '-w',
                            '/var/www/html',
                            'webserver',
                            'php',
                            'admin/cli/some_script.php',
                        ],
                    ];
                }
                public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array
                {
                    return [
                        'reason' => 'breakpoint',
                        'stopped_at' => '2026-04-15T00:00:02+00:00',
                        'attached' => true,
                        'raw_status' => 'break',
                        'raw_reason' => 'ok',
                    ];
                }
                public function read_stack(string $backendSessionId, int $maxFrames): array
                {
                    return [[
                        'index' => 0,
                        'file' => '/var/www/html/admin/cli/some_script.php',
                        'line' => 42,
                        'function' => '{main}',
                    ]];
                }
                public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array
                {
                    return [];
                }
                public function terminate_session(string $backendSessionId): void {}
            },
            sessionStore: new FileArtifactSessionStore(sys_get_temp_dir() . '/moodle_debug_integration_' . uniqid('', true), $clock, 3600, 524288),
            selectorValidator: new PHPUnitSelectorValidator(),
            cliPathValidator: new CliPathValidator(),
            executionPlanFactory: new ExecutionPlanFactory(),
            pathMapper: new PathMapper(),
            contextMapper: new MoodleContextMapper(),
            summaryBuilder: new SummaryBuilder(),
            clock: $clock,
        );

        $result = $application->debugCliScript([
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'script_path' => 'admin/cli/some_script.php',
            'script_args' => [],
            'runtime_profile' => 'real_xdebug_cli',
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'capture_policy' => [
                'max_frames' => 25,
                'max_locals_per_frame' => 10,
                'max_string_length' => 512,
                'include_args' => true,
                'include_locals' => true,
                'focus_top_frames' => 5,
            ],
            'timeout_seconds' => 120,
        ]);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame([], $result['result']['rerun']['launcher']);
        self::assertSame('/tmp/moodle-docker/bin/moodle-docker-compose', $result['result']['rerun']['command'][0]);
        self::assertStringContainsString('full docker exec transport recipe', $result['result']['rerun']['notes'][1]);
    }
}
