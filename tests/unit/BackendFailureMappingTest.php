<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\debug_backend\DebugBackendException;
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
use PHPUnit\Framework\TestCase;

final class BackendFailureMappingTest extends TestCase
{
    public function testMapsBackendExceptionsToStructuredFailures(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $clock = new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'));
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json');

        $application = new Application(
            schemaValidator: new SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json'),
            profileLoader: $profileLoader,
            backend: new class implements DebugBackendInterface {
                public function prepare_session(array $context): array
                {
                    throw new DebugBackendException('XDEBUG_NOT_ENABLED', 'Xdebug is not installed.', true);
                }
                public function launch_target(array $preparedSession, array $executionPlan): array { return []; }
                public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array { return []; }
                public function read_stack(string $backendSessionId, int $maxFrames): array { return []; }
                public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array { return []; }
                public function terminate_session(string $backendSessionId): void {}
            },
            sessionStore: new FileArtifactSessionStore(sys_get_temp_dir() . '/moodle_debug_backend_fail_' . uniqid('', true), $clock, 3600, 524288),
            selectorValidator: new PHPUnitSelectorValidator(),
            cliPathValidator: new CliPathValidator(),
            executionPlanFactory: new ExecutionPlanFactory(),
            pathMapper: new PathMapper(),
            contextMapper: new MoodleContextMapper(),
            summaryBuilder: new SummaryBuilder(),
            clock: $clock,
        );

        $result = $application->debugPhpunitTest([
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission',
            'runtime_profile' => 'default_phpunit',
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

        self::assertFalse($result['ok']);
        self::assertSame('XDEBUG_NOT_ENABLED', $result['error']['code']);
    }

    public function testMapsDockerBackendFailuresToStructuredFailures(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $clock = new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'));
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json');

        $application = new Application(
            schemaValidator: new SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json'),
            profileLoader: $profileLoader,
            backend: new class implements DebugBackendInterface {
                public function prepare_session(array $context): array
                {
                    throw new DebugBackendException('DOCKER_SERVICE_NOT_RUNNING', 'The configured Docker service webserver is not running.', true);
                }
                public function launch_target(array $preparedSession, array $executionPlan): array { return []; }
                public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array { return []; }
                public function read_stack(string $backendSessionId, int $maxFrames): array { return []; }
                public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array { return []; }
                public function terminate_session(string $backendSessionId): void {}
            },
            sessionStore: new FileArtifactSessionStore(sys_get_temp_dir() . '/moodle_debug_backend_fail_' . uniqid('', true), $clock, 3600, 524288),
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

        self::assertFalse($result['ok']);
        self::assertSame('DOCKER_SERVICE_NOT_RUNNING', $result['error']['code']);
    }
}
