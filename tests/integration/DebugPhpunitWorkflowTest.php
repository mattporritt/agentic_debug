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

final class DebugPhpunitWorkflowTest extends TestCase
{
    public function testRunsEndToEndAndPersistsSession(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_integration_' . uniqid('', true);
        $app = TestApplicationFactory::create(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'))
        );

        $result = $app->debugPhpunitTest([
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

        self::assertTrue($result['ok']);
        self::assertSame('phpunit', $result['result']['target']['type']);
        self::assertSame('phpunit', $result['session']['runtime_profile']['launcher_kind']);
        self::assertSame('mod_assign', $result['result']['moodle_mapping']['annotations'][0]['component']);
        self::assertSame('production_frame', $result['result']['moodle_mapping']['annotations'][0]['frame_kind']);
        self::assertSame('plugin_logic', $result['result']['moodle_mapping']['likely_issue']['category']);
        self::assertStringContainsString('mod_assign', $result['result']['summary']['headline']);
        self::assertStringContainsString('failing PHPUnit selector', implode(' ', $result['result']['summary']['suggested_next_actions']));

        $session = $app->getDebugSession([
            'session_id' => $result['session']['session']['session_id'],
        ]);

        self::assertTrue($session['ok']);
        self::assertSame($result['session']['session']['session_id'], $session['session']['session']['session_id']);
    }
}
