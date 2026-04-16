<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\integration_real;

use MoodleDebug\server\ApplicationFactory;
use PHPUnit\Framework\TestCase;

final class RealXdebugBackendTest extends TestCase
{
    private ?int $assignedPort = null;

    protected function setUp(): void
    {
        if (getenv('MOODLE_DEBUG_RUN_REAL_XDEBUG_TESTS') !== '1') {
            self::markTestSkipped('Set MOODLE_DEBUG_RUN_REAL_XDEBUG_TESTS=1 to run Docker-backed real Xdebug integration tests.');
        }

        if (!is_string(getenv('MOODLE_DIR')) || getenv('MOODLE_DIR') === '') {
            self::markTestSkipped('Set MOODLE_DIR to the Docker-mounted Moodle checkout before running real Xdebug integration tests.');
        }

        $dockerCheckCommand = $this->resolveDockerCheckCommand();
        exec($dockerCheckCommand . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            self::markTestSkipped('Docker compose is not available for real Xdebug integration tests: ' . implode("\n", $output));
        }

        $service = getenv('WEBSERVER_SERVICE') ?: 'webserver';
        $serviceList = implode("\n", $output);
        if (!str_contains($serviceList, $service)) {
            self::markTestSkipped("Docker service {$service} is not available in the current Moodle Docker environment.");
        }

        $this->assignedPort = $this->portForCurrentTest();
        putenv('MOODLE_DEBUG_XDEBUG_CLIENT_PORT=' . $this->assignedPort);
        $_ENV['MOODLE_DEBUG_XDEBUG_CLIENT_PORT'] = (string) $this->assignedPort;
        $this->waitForPortToBeFree($this->assignedPort);
    }

    protected function tearDown(): void
    {
        if ($this->assignedPort !== null) {
            $this->waitForPortToBeFree($this->assignedPort, 120, 250000);
        }

        putenv('MOODLE_DEBUG_XDEBUG_CLIENT_PORT');
        unset($_ENV['MOODLE_DEBUG_XDEBUG_CLIENT_PORT']);
        $this->assignedPort = null;
    }

    public function testRealPhpunitWorkflowCapturesMeaningfulBreakpointStop(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $application = (new ApplicationFactory())->create($repoRoot);

        $result = $application->debugPhpunitTest([
            'moodle_root' => $this->resolveMoodleRoot($repoRoot),
            'test_ref' => 'core_admin\\external\\set_block_protection_test::test_execute_no_login',
            'runtime_profile' => getenv('MOODLE_DEBUG_REAL_PHPUNIT_PROFILE') ?: 'real_xdebug_phpunit',
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

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('phpunit', $result['session']['runtime_profile']['launcher_kind']);
        self::assertSame('breakpoint', $result['result']['stop_event']['reason']);
        self::assertNotSame('unknown', $result['result']['moodle_mapping']['likely_issue']['category']);
        self::assertNotEmpty($result['result']['summary']['suggested_next_actions']);
    }

    public function testRealCliWorkflowCapturesMeaningfulBreakpointStop(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $application = (new ApplicationFactory())->create($repoRoot);

        $result = $application->debugCliScript([
            'moodle_root' => $this->resolveMoodleRoot($repoRoot),
            'script_path' => 'admin/cli/import.php',
            'script_args' => ['--srccourseid=999999', '--dstcourseid=1'],
            'runtime_profile' => getenv('MOODLE_DEBUG_REAL_CLI_PROFILE') ?: 'real_xdebug_cli',
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

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('cli', $result['session']['runtime_profile']['launcher_kind']);
        self::assertSame('breakpoint', $result['result']['stop_event']['reason']);
        self::assertSame('cli_workflow', $result['result']['moodle_mapping']['likely_issue']['category']);
        self::assertStringContainsString('CLI entrypoint', implode(' ', $result['result']['summary']['suggested_next_actions']));
    }

    public function testRealCliWorkflowReturnsNoStopEventForNormalExit(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $application = (new ApplicationFactory())->create($repoRoot);

        $result = $application->debugCliScript([
            'moodle_root' => $this->resolveMoodleRoot($repoRoot),
            'script_path' => 'admin/cli/backup.php',
            'script_args' => [],
            'runtime_profile' => getenv('MOODLE_DEBUG_REAL_CLI_PROFILE') ?: 'real_xdebug_cli',
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
        self::assertSame('NO_STOP_EVENT', $result['error']['code']);
    }

    private function resolveMoodleRoot(string $repoRoot): string
    {
        $moodleDir = getenv('MOODLE_DIR');
        if (is_string($moodleDir) && $moodleDir !== '') {
            return $moodleDir;
        }

        return $repoRoot . '/_smoke_test/moodle_fixture';
    }

    private function resolveDockerCheckCommand(): string
    {
        $dockerBinDir = getenv('MOODLE_DOCKER_BIN_DIR');
        if (is_string($dockerBinDir) && $dockerBinDir !== '') {
            return escapeshellarg(rtrim($dockerBinDir, '/') . '/moodle-docker-compose') . ' ps --services';
        }

        $dockerDir = getenv('MOODLE_DOCKER_DIR');
        if (is_string($dockerDir) && $dockerDir !== '') {
            return 'cd ' . escapeshellarg($dockerDir) . ' && docker compose ps --services';
        }

        $dockerCompose = getenv('MOODLE_DEBUG_REAL_DOCKER_COMPOSE') ?: 'docker compose';
        return $dockerCompose . ' ps --services';
    }

    private function waitForPortToBeFree(int $port, int $attempts = 80, int $sleepMicros = 250000): void
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
            if (is_resource($server)) {
                fclose($server);
                return;
            }

            usleep($sleepMicros);
        }

        self::markTestSkipped("Port {$port} is still in use after waiting for the previous real debug run to release it.");
    }

    private function portForCurrentTest(): int
    {
        return match ($this->name()) {
            'testRealPhpunitWorkflowCapturesMeaningfulBreakpointStop' => 19031,
            'testRealCliWorkflowCapturesMeaningfulBreakpointStop' => 19032,
            'testRealCliWorkflowReturnsNoStopEventForNormalExit' => 19033,
            default => 19039,
        };
    }
}
