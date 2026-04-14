<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\integration;

use PHPUnit\Framework\TestCase;

final class RuntimeCliTest extends TestCase
{
    public function testHealthCommandOutputsJsonEnvelope(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $command = sprintf(
            'php %s/bin/moodle-debug health --json %s',
            escapeshellarg($repoRoot),
            escapeshellarg('{}')
        );

        exec($command, $output, $exitCode);
        $decoded = json_decode(implode("\n", $output), true);

        self::assertSame(0, $exitCode);
        self::assertIsArray($decoded);
        self::assertSame('moodle_debug', $decoded['tool']);
        self::assertSame('health', $decoded['intent']);
    }
}
