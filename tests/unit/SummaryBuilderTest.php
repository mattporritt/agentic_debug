<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\server\SummaryBuilder;
use PHPUnit\Framework\TestCase;

final class SummaryBuilderTest extends TestCase
{
    public function testBuildsBreakpointSummaryWithoutExceptionLanguage(): void
    {
        $builder = new SummaryBuilder();

        $summary = $builder->build(
            ['type' => 'cli'],
            ['reason' => 'breakpoint', 'stopped_at' => '2026-04-15T00:00:00+00:00'],
            [['file' => '/tmp/moodle/admin/cli/import.php', 'line' => 85, 'function' => '{main}', 'index' => 0]],
            [
                'annotations' => [[
                    'frame_index' => 0,
                    'component' => 'core_admin',
                    'confidence' => 'medium',
                ]],
                'probable_fault_frame_index' => 0,
                'execution_context' => 'cli_admin',
            ],
        );

        self::assertSame('Debug breakpoint stop near core_admin', $summary['headline']);
        self::assertContains('Stop reason: breakpoint', $summary['facts']);
        self::assertNotContains('Exception type: moodle_exception', $summary['facts']);
    }

    public function testIncludesExceptionFactWhenExceptionPayloadExists(): void
    {
        $builder = new SummaryBuilder();

        $summary = $builder->build(
            ['type' => 'phpunit'],
            [
                'reason' => 'exception',
                'stopped_at' => '2026-04-15T00:00:00+00:00',
                'exception' => ['type' => 'coding_exception', 'message' => 'Boom'],
            ],
            [['file' => '/tmp/moodle/mod/assign/tests/grading_test.php', 'line' => 12, 'function' => 'test_example', 'index' => 0]],
            [
                'annotations' => [[
                    'frame_index' => 0,
                    'component' => 'mod_assign',
                    'confidence' => 'high',
                ]],
                'probable_fault_frame_index' => 0,
                'execution_context' => 'phpunit',
            ],
        );

        self::assertContains('Exception type: coding_exception', $summary['facts']);
        self::assertSame('First mapped non-harness frame in the captured stack.', $summary['probable_fault']['reason']);
    }
}
