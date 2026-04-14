<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\contracts;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class ToolContractTest extends TestCase
{
    private SchemaValidator $validator;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator(__DIR__ . '/../../docs/moodle_debug/schemas/moodle_debug.schema.json');
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testDebugPhpunitOutputMatchesSchema(): void
    {
        $storage = sys_get_temp_dir() . '/moodle_debug_contracts_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            dirname(__DIR__, 2),
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugPhpunitTest([
            'moodle_root' => dirname(__DIR__, 2) . '/_smoke_test/moodle_fixture',
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

        self::assertTrue($this->validator->validateToolOutput('debug_phpunit_test', $result)['valid']);
    }

    public function testDebugCliOutputMatchesSchema(): void
    {
        $storage = sys_get_temp_dir() . '/moodle_debug_contracts_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            dirname(__DIR__, 2),
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugCliScript([
            'moodle_root' => dirname(__DIR__, 2) . '/_smoke_test/moodle_fixture',
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

        self::assertTrue($this->validator->validateToolOutput('debug_cli_script', $result)['valid']);
    }

    public function testSchemaAcceptsPhpunitLauncherKindInSessionOutput(): void
    {
        $result = $this->buildPhpunitResult();
        self::assertSame('phpunit', $result['session']['runtime_profile']['launcher_kind']);
        self::assertTrue($this->validator->validateToolOutput('debug_phpunit_test', $result)['valid']);
    }

    public function testSchemaAcceptsCliLauncherKindInSessionOutput(): void
    {
        $result = $this->buildCliResult();
        self::assertSame('cli', $result['session']['runtime_profile']['launcher_kind']);
        self::assertTrue($this->validator->validateToolOutput('debug_cli_script', $result)['valid']);
    }

    public function testSchemaRejectsLegacyCustomWrapperLauncherKind(): void
    {
        $result = $this->buildPhpunitResult();
        $result['session']['runtime_profile']['launcher_kind'] = 'custom_wrapper';

        $validated = $this->validator->validateToolOutput('debug_phpunit_test', $result);
        self::assertFalse($validated['valid']);
    }

    public function testSchemaAcceptsEmptyRerunLauncherForDockerBackedExecution(): void
    {
        $result = $this->buildPhpunitResult();
        $result['result']['rerun']['launcher'] = [];

        $validated = $this->validator->validateToolOutput('debug_phpunit_test', $result);
        self::assertTrue($validated['valid']);
    }

    public function testSchemaAcceptsGetDebugSessionIncludeResultOnly(): void
    {
        $validated = $this->validator->validateToolInput('get_debug_session', [
            'session_id' => 'mds_valid_session',
            'include' => [
                'result' => false,
            ],
        ]);

        self::assertTrue($validated['valid']);
    }

    public function testSchemaRejectsUnsupportedGetDebugSessionIncludeFlags(): void
    {
        $validated = $this->validator->validateToolInput('get_debug_session', [
            'session_id' => 'mds_valid_session',
            'include' => [
                'result' => true,
                'summary' => true,
            ],
        ]);

        self::assertFalse($validated['valid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPhpunitResult(): array
    {
        $storage = sys_get_temp_dir() . '/moodle_debug_contracts_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            $this->repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        return $app->debugPhpunitTest([
            'moodle_root' => $this->repoRoot . '/_smoke_test/moodle_fixture',
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
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCliResult(): array
    {
        $storage = sys_get_temp_dir() . '/moodle_debug_contracts_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            $this->repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        return $app->debugCliScript([
            'moodle_root' => $this->repoRoot . '/_smoke_test/moodle_fixture',
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
    }
}
