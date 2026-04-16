<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\debug_backend\DebugBackendException;
use MoodleDebug\debug_backend\XdebugLaunchSettingsBuilder;
use MoodleDebug\runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;

final class XdebugLaunchSettingsBuilderTest extends TestCase
{
    public function testBuildsHostCommandAndEnvironmentForRealProfile(): void
    {
        $profile = $this->buildProfile();
        $builder = new XdebugLaunchSettingsBuilder();

        $command = $builder->buildCommand($profile, [
            'launcher' => [],
            'command' => ['php', 'admin/cli/some_script.php'],
        ]);
        $environment = $builder->buildEnvironment($profile);

        self::assertContains('-d', $command);
        self::assertContains('xdebug.start_with_request=yes', $command);
        self::assertContains('xdebug.client_port=9003', $command);
        self::assertSame('debug', $environment['XDEBUG_MODE']);
    }

    public function testBuildsDockerExecCommandForDockerTransport(): void
    {
        $profile = $this->buildProfile(
            launcherKind: 'phpunit',
            executionTransport: 'docker_exec',
            moodleRoot: '/Users/mattp/projects/moodle',
            workingDirectory: '/Users/mattp/projects/agentic_debug',
            dockerComposeCommand: ['/opt/moodle-docker/bin/moodle-docker-compose'],
            webserverService: 'webserver',
            webserverUser: 'www-data',
            containerWorkingDirectory: '/var/www/html',
            pathMappings: ['/var/www/html' => '/Users/mattp/projects/moodle'],
            xdebugClientHost: 'host.docker.internal',
        );
        $builder = new XdebugLaunchSettingsBuilder();

        $command = $builder->buildCommand($profile, [
            'launcher' => [],
            'command' => [
                'php',
                '/Users/mattp/projects/moodle/vendor/bin/phpunit',
                '--filter',
                'test_grade_submission',
                '/Users/mattp/projects/moodle/mod/assign/tests/grading_test.php',
            ],
        ]);

        self::assertSame('/opt/moodle-docker/bin/moodle-docker-compose', $command[0]);
        self::assertContains('exec', $command);
        self::assertContains('-T', $command);
        self::assertContains('-u', $command);
        self::assertContains('www-data', $command);
        self::assertContains('webserver', $command);
        self::assertContains('/var/www/html/vendor/bin/phpunit', $command);
        self::assertContains('/var/www/html/mod/assign/tests/grading_test.php', $command);
        self::assertContains('xdebug.client_host=host.docker.internal', $command);
    }

    public function testRejectsInvalidPort(): void
    {
        $builder = new XdebugLaunchSettingsBuilder();
        $profile = $this->buildProfile(xdebugClientPort: 70000);

        $this->expectException(DebugBackendException::class);
        $this->expectExceptionMessage('invalid xdebug_client_port');

        $builder->validateProfile($profile);
    }

    /**
     * @param array<string, string> $pathMappings
     * @param string[] $dockerComposeCommand
     */
    private function buildProfile(
        string $backendKind = 'xdebug',
        string $launcherKind = 'cli',
        string $executionTransport = 'host_exec',
        string $moodleRoot = '/tmp/moodle',
        string $workingDirectory = '/tmp/moodle',
        array $pathMappings = ['/tmp/moodle' => '/tmp/moodle'],
        int $xdebugClientPort = 9003,
        string $xdebugClientHost = '127.0.0.1',
        array $dockerComposeCommand = ['docker', 'compose'],
        string $webserverService = 'webserver',
        ?string $webserverUser = null,
        ?string $containerWorkingDirectory = null,
    ): RuntimeProfile {
        return new RuntimeProfile(
            profileName: 'real_xdebug_cli',
            backendKind: $backendKind,
            launcherKind: $launcherKind,
            executionTransport: $executionTransport,
            launcherArgv: [],
            phpArgv: ['php'],
            moodleRoot: $moodleRoot,
            workingDirectory: $workingDirectory,
            pathMappings: $pathMappings,
            envAllowlist: [],
            timeoutDefaults: ['launch' => 5, 'attach' => 5, 'overall' => 120],
            xdebugEnabled: true,
            xdebugMode: 'debug',
            xdebugStartWithRequest: 'yes',
            xdebugStartUponError: 'yes',
            xdebugClientHost: $xdebugClientHost,
            xdebugClientPort: $xdebugClientPort,
            xdebugLog: null,
            xdebugIdekey: 'moodle_debug',
            phpIniOverrides: [],
            debuggerConnectTimeoutMs: 1500,
            debuggerOverallTimeoutMs: 120000,
            listenerBindAddress: $executionTransport === 'docker_exec' ? '0.0.0.0' : '127.0.0.1',
            moodleDockerDir: $executionTransport === 'docker_exec' ? '/Users/mattp/projects/moodle-docker' : null,
            moodleDockerBinDir: $executionTransport === 'docker_exec' ? '/opt/moodle-docker/bin' : null,
            dockerComposeCommand: $dockerComposeCommand,
            webserverService: $webserverService,
            webserverUser: $webserverUser,
            containerWorkingDirectory: $containerWorkingDirectory,
        );
    }
}
