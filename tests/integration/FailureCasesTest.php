<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\integration;

use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class FailureCasesTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testRejectsInvalidSelector(): void
    {
        $app = TestApplicationFactory::create(
            $this->repoRoot,
            sys_get_temp_dir() . '/moodle_debug_failures_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugPhpunitTest([
            'moodle_root' => $this->repoRoot . '/_smoke_test/moodle_fixture',
            'test_ref' => 'bad-selector',
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
        self::assertSame('INVALID_TEST_REF', $result['error']['code']);
    }

    public function testRejectsDisallowedCliPath(): void
    {
        $app = TestApplicationFactory::create(
            $this->repoRoot,
            sys_get_temp_dir() . '/moodle_debug_failures_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugCliScript([
            'moodle_root' => $this->repoRoot . '/_smoke_test/moodle_fixture',
            'script_path' => 'mod/assign/cli/unsafe.php',
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
        self::assertSame('INVALID_SCRIPT_PATH', $result['error']['code']);
    }

    public function testRejectsMissingProfile(): void
    {
        $app = TestApplicationFactory::create(
            $this->repoRoot,
            sys_get_temp_dir() . '/moodle_debug_failures_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugCliScript([
            'moodle_root' => $this->repoRoot . '/_smoke_test/moodle_fixture',
            'script_path' => 'admin/cli/some_script.php',
            'script_args' => [],
            'runtime_profile' => 'missing_profile',
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
        self::assertSame('INVALID_REQUEST', $result['error']['code']);
    }

    public function testReturnsSessionNotFound(): void
    {
        $app = TestApplicationFactory::create(
            $this->repoRoot,
            sys_get_temp_dir() . '/moodle_debug_failures_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->getDebugSession([
            'session_id' => 'mds_missing_session',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('SESSION_NOT_FOUND', $result['error']['code']);
    }
}
