<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\runtime\CodexEnvironmentLoader;
use MoodleDebug\runtime\RuntimeProfileLoader;
use PHPUnit\Framework\TestCase;

final class RuntimeProfileLoaderTest extends TestCase
{
    public function testLoadsNamedProfile(): void
    {
        $loader = new RuntimeProfileLoader(__DIR__ . '/../../config/runtime_profiles.json');
        $profile = $loader->getProfile('default_phpunit', 'phpunit');

        self::assertSame('default_phpunit', $profile->profileName);
        self::assertSame('mock', $profile->backendKind);
        self::assertSame('phpunit', $profile->launcherKind);
        self::assertSame('host_exec', $profile->executionTransport);
        self::assertNotEmpty($profile->launcherArgv);
    }

    public function testLoadsDockerBackedRealXdebugProfileFields(): void
    {
        $loader = new RuntimeProfileLoader(__DIR__ . '/../../config/runtime_profiles.json');
        $profile = $loader->getProfile('real_xdebug_cli', 'cli');

        self::assertSame('xdebug', $profile->backendKind);
        self::assertSame('docker_exec', $profile->executionTransport);
        self::assertTrue($profile->xdebugEnabled);
        self::assertSame('host.docker.internal', $profile->xdebugClientHost);
        self::assertSame(9003, $profile->xdebugClientPort);
        self::assertSame(['docker', 'compose'], $profile->dockerComposeCommand);
        self::assertSame('webserver', $profile->webserverService);
    }

    public function testAppliesCodexStyleEnvironmentOverrides(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $codexEnv = $this->createCodexEnvFile();
        $environmentLoader = new CodexEnvironmentLoader(
            repoRoot: $repoRoot,
            environment: ['MOODLE_DEBUG_CODEX_ENV_FILE' => $codexEnv],
        );
        $loader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json', $environmentLoader);

        $profile = $loader->getProfile('real_xdebug_phpunit', 'phpunit');

        self::assertSame('/tmp/codex-moodle', $profile->moodleRoot);
        self::assertSame('/tmp/codex-moodle-docker', $profile->moodleDockerDir);
        self::assertSame('/tmp/codex-bin', $profile->moodleDockerBinDir);
        self::assertSame(['/tmp/codex-bin/moodle-docker-compose'], $profile->dockerComposeCommand);
        self::assertSame('alt-webserver', $profile->webserverService);
        self::assertSame('www-data', $profile->webserverUser);
        self::assertSame(19103, $profile->xdebugClientPort);
        self::assertSame('/tmp/codex-moodle', $profile->pathMappings['/var/www/html']);
    }

    public function testAdjustsDockerRemoteRootWhenMoodleDirPointsAtPublicWebRoot(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $codexEnv = tempnam(sys_get_temp_dir(), 'moodle_debug_codex_public_env_');
        self::assertIsString($codexEnv);
        file_put_contents($codexEnv, implode("\n", [
            'MOODLE_DIR=/tmp/codex-moodle/public',
            'MOODLE_DOCKER_DIR=/tmp/codex-moodle-docker',
        ]));

        $environmentLoader = new CodexEnvironmentLoader(
            repoRoot: $repoRoot,
            environment: ['MOODLE_DEBUG_CODEX_ENV_FILE' => $codexEnv],
        );
        $loader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json', $environmentLoader);

        $profile = $loader->getProfile('real_xdebug_phpunit', 'phpunit');

        self::assertSame('/tmp/codex-moodle/public', $profile->moodleRoot);
        self::assertSame('/tmp/codex-moodle/public', $profile->workingDirectory);
        self::assertSame('/var/www/html/public', $profile->containerWorkingDirectory);
        self::assertSame('/tmp/codex-moodle/public', $profile->pathMappings['/var/www/html/public']);
    }

    private function createCodexEnvFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'moodle_debug_codex_env_');
        self::assertIsString($path);
        file_put_contents($path, implode("\n", [
            'MOODLE_DIR=/tmp/codex-moodle',
            'MOODLE_DOCKER_DIR=/tmp/codex-moodle-docker',
            'MOODLE_DOCKER_BIN_DIR=/tmp/codex-bin',
            'WEBSERVER_SERVICE=alt-webserver',
            'WEBSERVER_USER=www-data',
            'MOODLE_DEBUG_XDEBUG_CLIENT_PORT=19103',
        ]));

        return $path;
    }
}
