<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\session_store\FileArtifactSessionStore;
use MoodleDebug\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class FileArtifactSessionStoreTest extends TestCase
{
    public function testExpiresSessionsByTtl(): void
    {
        $directory = sys_get_temp_dir() . '/moodle_debug_session_store_' . uniqid('', true);
        $clock = new FixedClock(new \DateTimeImmutable('2026-04-14T00:00:00+00:00'));
        $store = new FileArtifactSessionStore($directory, $clock, 60, 524288);

        $store->save('session_one', [
            'session' => [
                'session' => [
                    'session_id' => 'session_one',
                    'created_at' => '2026-04-14T00:00:00+00:00',
                    'expires_at' => '2026-04-14T00:01:00+00:00',
                    'state' => 'stopped',
                ],
            ],
            'result' => [],
        ]);

        self::assertTrue($store->load('session_one')->found);
        $clock->advance('+2 minutes');
        self::assertTrue($store->load('session_one')->expired);
    }
}
