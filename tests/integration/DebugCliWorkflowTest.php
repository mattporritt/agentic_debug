<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\integration;

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
    }
}
