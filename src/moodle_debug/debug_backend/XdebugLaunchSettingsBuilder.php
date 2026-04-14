<?php

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

use MoodleDebug\runtime\RuntimeProfile;

/**
 * Builds launch commands and environments for Xdebug-backed runs.
 *
 * This class is shared by preflight planning and the real backend so command
 * construction stays deterministic across dry-run and execute paths.
 */
final class XdebugLaunchSettingsBuilder
{
    /**
     * @param array<string, mixed> $executionPlan
     * @return string[]
     */
    public function buildCommand(RuntimeProfile $profile, array $executionPlan): array
    {
        return match ($profile->executionTransport) {
            'docker_exec' => $this->buildDockerExecCommand($profile, $executionPlan),
            default => $this->buildHostCommand($profile, $executionPlan),
        };
    }

    /**
     * @param array<string, mixed> $executionPlan
     * @return string[]
     */
    public function buildHostCommand(RuntimeProfile $profile, array $executionPlan): array
    {
        $command = $executionPlan['command'] ?? null;
        if (!is_array($command) || $command === []) {
            throw new DebugBackendException('MALFORMED_RUNTIME_PROFILE', 'Execution plan did not provide a PHP command array.', false);
        }

        $prefix = is_array($executionPlan['launcher'] ?? null) ? $executionPlan['launcher'] : [];
        $phpCommand = array_shift($command);
        if (!is_string($phpCommand) || $phpCommand === '') {
            throw new DebugBackendException('MALFORMED_RUNTIME_PROFILE', 'Execution plan did not provide a valid PHP executable.', false);
        }

        $iniArgs = [
            '-d', 'xdebug.start_with_request=' . $profile->xdebugStartWithRequest,
            '-d', 'xdebug.start_upon_error=' . $profile->xdebugStartUponError,
            '-d', 'xdebug.client_host=' . $profile->xdebugClientHost,
            '-d', 'xdebug.client_port=' . $profile->xdebugClientPort,
            '-d', 'xdebug.discover_client_host=0',
        ];

        if ($profile->xdebugLog !== null && $profile->xdebugLog !== '') {
            $iniArgs[] = '-d';
            $iniArgs[] = 'xdebug.log=' . $profile->xdebugLog;
        }

        if ($profile->xdebugIdekey !== null && $profile->xdebugIdekey !== '') {
            $iniArgs[] = '-d';
            $iniArgs[] = 'xdebug.idekey=' . $profile->xdebugIdekey;
        }

        if ($profile->debuggerConnectTimeoutMs > 0) {
            $iniArgs[] = '-d';
            $iniArgs[] = 'xdebug.connect_timeout_ms=' . $profile->debuggerConnectTimeoutMs;
        }

        foreach ($profile->phpIniOverrides as $key => $value) {
            $iniArgs[] = '-d';
            $iniArgs[] = "{$key}={$value}";
        }

        return array_values(array_merge(
            $prefix,
            [$phpCommand],
            $iniArgs,
            $command,
        ));
    }

    /**
     * @param array<string, mixed> $executionPlan
     * @return string[]
     */
    public function buildDockerExecCommand(RuntimeProfile $profile, array $executionPlan): array
    {
        if ($profile->dockerComposeCommand === []) {
            throw new DebugBackendException('INVALID_RUNTIME_PROFILE', "Runtime profile {$profile->profileName} is missing docker_compose_command.", false);
        }
        if ($profile->webserverService === '') {
            throw new DebugBackendException('INVALID_RUNTIME_PROFILE', "Runtime profile {$profile->profileName} is missing webserver_service.", false);
        }

        $innerCommand = $this->buildHostCommand($profile, $this->mapExecutionPlanToContainer($profile, $executionPlan));

        $command = array_values(array_merge(
            $profile->dockerComposeCommand,
            ['exec', '-T'],
            $profile->containerWorkingDirectory !== null && $profile->containerWorkingDirectory !== '' ? ['-w', $profile->containerWorkingDirectory] : [],
            $profile->webserverUser !== null && $profile->webserverUser !== '' ? ['-u', $profile->webserverUser] : [],
            [$profile->webserverService],
            $innerCommand,
        ));

        return $command;
    }

    /**
     * @return array<string, string>
     */
    public function buildEnvironment(RuntimeProfile $profile): array
    {
        $environment = [];
        foreach (['PATH', 'HOME', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        foreach ($profile->envAllowlist as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        $environment['XDEBUG_MODE'] = $profile->xdebugMode;
        if ($profile->xdebugIdekey !== null && $profile->xdebugIdekey !== '') {
            $environment['XDEBUG_SESSION'] = $profile->xdebugIdekey;
        }

        return $environment;
    }

    public function validateProfile(RuntimeProfile $profile): void
    {
        if (!$profile->xdebugEnabled) {
            throw new DebugBackendException(
                'XDEBUG_NOT_ENABLED',
                "Runtime profile {$profile->profileName} is not marked as Xdebug-enabled.",
                true,
            );
        }

        if (!filter_var($profile->xdebugClientHost, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $profile->xdebugClientHost)) {
            throw new DebugBackendException(
                'INVALID_RUNTIME_PROFILE',
                "Runtime profile {$profile->profileName} has an invalid xdebug_client_host.",
                false,
            );
        }

        if ($profile->xdebugClientPort < 1 || $profile->xdebugClientPort > 65535) {
            throw new DebugBackendException(
                'INVALID_RUNTIME_PROFILE',
                "Runtime profile {$profile->profileName} has an invalid xdebug_client_port.",
                false,
            );
        }

        if ($profile->executionTransport === 'docker_exec') {
            if ($profile->dockerComposeCommand === []) {
                throw new DebugBackendException(
                    'INVALID_RUNTIME_PROFILE',
                    "Runtime profile {$profile->profileName} has no docker_compose_command.",
                    false,
                );
            }
            if ($profile->webserverService === '') {
                throw new DebugBackendException(
                    'INVALID_RUNTIME_PROFILE',
                    "Runtime profile {$profile->profileName} has no webserver_service.",
                    false,
                );
            }
            if ($profile->xdebugClientHost === '') {
                throw new DebugBackendException(
                    'INVALID_RUNTIME_PROFILE',
                    "Runtime profile {$profile->profileName} has no xdebug_client_host.",
                    false,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $executionPlan
     * @return array<string, mixed>
     */
    private function mapExecutionPlanToContainer(RuntimeProfile $profile, array $executionPlan): array
    {
        $mapped = $executionPlan;
        $command = [];
        foreach ($executionPlan['command'] as $item) {
            $command[] = is_string($item) ? $this->mapLocalPathToRemote($profile, $item) : $item;
        }
        $mapped['command'] = $command;

        return $mapped;
    }

    private function mapLocalPathToRemote(RuntimeProfile $profile, string $value): string
    {
        foreach ($profile->pathMappings as $remoteRoot => $localRoot) {
            if ($localRoot !== '' && str_starts_with($value, $localRoot)) {
                return $remoteRoot . substr($value, strlen($localRoot));
            }
        }

        return $value;
    }
}
