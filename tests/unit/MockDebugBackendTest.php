<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\debug_backend\MockDebugBackend;
use MoodleDebug\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class MockDebugBackendTest extends TestCase
{
    public function testReturnsDeterministicPhpunitFrames(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'));
        $backend = new MockDebugBackend($clock);
        $prepared = $backend->prepare_session([
            'session_id' => 'session_a',
            'target' => ['type' => 'phpunit'],
            'stop_policy' => ['mode' => 'first_exception_or_error'],
        ]);

        $backend->launch_target($prepared, [
            'target_type' => 'phpunit',
            'target_reference' => 'mod_assign\\tests\\grading_test::test_grade_submission',
            'launcher' => ['mock-launcher', 'phpunit'],
            'command' => ['php', 'vendor/bin/phpunit'],
            'path_mappings' => ['/var/www/html' => '/tmp/moodle'],
        ]);

        $frames = $backend->read_stack($prepared['backend_session_id'], 10);
        $stop = $backend->wait_for_stop($prepared['backend_session_id'], 120);

        self::assertSame('exception', $stop['reason']);
        self::assertSame('/var/www/html/mod/assign/classes/grading_manager.php', $frames[0]['file']);
    }
}
