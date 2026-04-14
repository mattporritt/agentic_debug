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

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator(__DIR__ . '/../../docs/moodle_debug/schemas/moodle_debug.schema.json');
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
}
