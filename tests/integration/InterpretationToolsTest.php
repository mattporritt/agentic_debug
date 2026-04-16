<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\integration;

use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class InterpretationToolsTest extends TestCase
{
    public function testSummariseDebugSessionReturnsMoodleAwareInterpretation(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_integration_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $run = $app->debugPhpunitTest([
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

        $summary = $app->summariseDebugSession([
            'session_id' => $run['session']['session']['session_id'],
            'summary_depth' => 'detailed',
        ]);

        self::assertTrue($summary['ok']);
        self::assertStringContainsString('plugin_logic', $summary['summary']['headline']);
        self::assertStringContainsString('Likely issue category: plugin_logic', implode("\n", $summary['summary']['facts']));
        self::assertNotEmpty($summary['summary']['inferences']);
    }

    public function testMapStackToMoodleContextReturnsFaultRanking(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_integration_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $mapping = $app->mapStackToMoodleContext([
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'frames' => [
                [
                    'index' => 0,
                    'file' => $repoRoot . '/_smoke_test/moodle_fixture/admin/cli/import.php',
                    'line' => 80,
                    'function' => '{main}',
                ],
                [
                    'index' => 1,
                    'file' => $repoRoot . '/_smoke_test/moodle_fixture/lib/clilib.php',
                    'line' => 210,
                    'function' => 'cli_error',
                ],
            ],
        ]);

        self::assertTrue($mapping['ok']);
        self::assertSame('cli_workflow', $mapping['mapping']['likely_issue']['category']);
        self::assertSame(0, $mapping['mapping']['fault_ranking'][0]['frame_index']);
        self::assertSame('execution_context_frame', $mapping['mapping']['annotations'][0]['frame_kind']);
    }
}
