<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\debug_backend\DbgpXmlParser;
use MoodleDebug\debug_backend\DebugBackendException;
use MoodleDebug\debug_backend\XdebugDebugBackend;
use MoodleDebug\debug_backend\XdebugLaunchSettingsBuilder;
use MoodleDebug\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class XdebugDebugBackendTest extends TestCase
{
    public function testPrepareSessionClassifiesBusyListenerPort(): void
    {
        $backend = $this->buildBackend();
        $method = new \ReflectionMethod($backend, 'classifyBindFailure');
        $method->setAccessible(true);

        self::assertSame('port_in_use', $method->invoke($backend, 48, 'Address already in use'));
        self::assertSame('permission_denied', $method->invoke($backend, 13, 'Permission denied'));
        self::assertSame('invalid_bind_address', $method->invoke($backend, 49, 'Cannot assign requested address'));
    }

    public function testTerminateSessionClearsTrackedSessionResources(): void
    {
        $backend = $this->buildBackend();
        $sessionsProperty = new \ReflectionProperty($backend, 'sessions');
        $sessionsProperty->setAccessible(true);
        $sessionsProperty->setValue($backend, [
            'xdb_test' => [
                'server' => fopen('php://temp', 'r+'),
                'connection' => fopen('php://temp', 'r+'),
                'pipes' => [fopen('php://temp', 'r+'), fopen('php://temp', 'r+')],
                'process' => null,
            ],
        ]);

        $backend->terminate_session('xdb_test');

        self::assertSame([], $sessionsProperty->getValue($backend));
    }

    public function testNormalizesBreakpointAndExceptionStopReasons(): void
    {
        $backend = $this->buildBackend();
        $method = new \ReflectionMethod($backend, 'normalizeStopEvent');
        $method->setAccessible(true);

        $breakpointStop = $method->invoke($backend, [
            'status' => 'break',
            'reason' => 'ok',
            'message' => [],
        ]);
        $exceptionStop = $method->invoke($backend, [
            'status' => 'break',
            'reason' => 'ok',
            'message' => [
                'exception' => 'moodle_exception',
                'text' => 'Boom',
                'filename' => '/var/www/html/admin/cli/import.php',
                'lineno' => 85,
            ],
        ]);

        self::assertSame('breakpoint', $breakpointStop['reason']);
        self::assertSame('ok', $breakpointStop['raw_reason']);
        self::assertSame('exception', $exceptionStop['reason']);
        self::assertSame('moodle_exception', $exceptionStop['exception']['type']);
    }

    private function buildBackend(): XdebugDebugBackend
    {
        return new XdebugDebugBackend(
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00')),
            new XdebugLaunchSettingsBuilder(),
            new DbgpXmlParser(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(int $port): array
    {
        return [
            'stop_policy' => ['mode' => 'first_exception_or_error'],
            'runtime_profile' => [
                'profile_name' => 'test_xdebug_cli',
                'backend_kind' => 'xdebug',
                'launcher_kind' => 'cli',
                'execution_transport' => 'host_exec',
                'launcher_argv' => [],
                'php_argv' => ['php'],
                'moodle_root' => '/tmp/moodle',
                'working_directory' => '/tmp/moodle',
                'path_mappings' => ['/tmp/moodle' => '/tmp/moodle'],
                'env_allowlist' => [],
                'timeout_defaults' => ['launch' => 5, 'attach' => 5, 'overall' => 120],
                'xdebug_enabled' => true,
                'xdebug_mode' => 'debug',
                'xdebug_start_with_request' => 'yes',
                'xdebug_start_upon_error' => 'yes',
                'xdebug_client_host' => '127.0.0.1',
                'xdebug_client_port' => $port,
                'xdebug_log' => null,
                'xdebug_idekey' => 'moodle_debug',
                'php_ini_overrides' => [],
                'debugger_connect_timeout_ms' => 1500,
                'debugger_overall_timeout_ms' => 120000,
                'listener_bind_address' => '127.0.0.1',
                'moodle_docker_dir' => null,
                'moodle_docker_bin_dir' => null,
                'docker_compose_command' => ['docker', 'compose'],
                'webserver_service' => 'webserver',
                'webserver_user' => null,
                'container_working_directory' => null,
            ],
        ];
    }
}
