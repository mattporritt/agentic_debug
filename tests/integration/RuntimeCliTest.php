<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

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

    public function testRuntimeQuerySupportsInputFileMode(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $requestPath = sys_get_temp_dir() . '/moodle_debug_runtime_request_' . uniqid('', true) . '.json';
        file_put_contents($requestPath, json_encode([
            'intent' => 'plan_cli',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_cli',
            'script_path' => 'admin/cli/some_script.php',
        ], JSON_THROW_ON_ERROR));

        $command = sprintf(
            'php %s/bin/moodle-debug runtime-query --input %s',
            escapeshellarg($repoRoot),
            escapeshellarg($requestPath)
        );

        exec($command, $output, $exitCode);
        @unlink($requestPath);
        $decoded = json_decode(implode("\n", $output), true);

        self::assertSame(0, $exitCode);
        self::assertIsArray($decoded);
        self::assertSame('plan_cli', $decoded['intent']);
        self::assertSame('execution_plan', $decoded['results'][0]['type']);
    }
}
