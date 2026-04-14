<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

/**
 * Immutable resolved runtime profile.
 *
 * Profiles represent the fully merged execution context after checked-in
 * defaults and codex-style environment overrides have been applied.
 */
final readonly class RuntimeProfile
{
    /**
     * @param string[] $launcherArgv
     * @param string[] $phpArgv
     * @param array<string, string> $pathMappings
     * @param string[] $envAllowlist
     * @param array{launch:int,attach:int,overall:int} $timeoutDefaults
     * @param array<string, string> $phpIniOverrides
     * @param string[] $dockerComposeCommand
     */
    public function __construct(
        public string $profileName,
        public string $backendKind,
        public string $launcherKind,
        public string $executionTransport,
        public array $launcherArgv,
        public array $phpArgv,
        public string $moodleRoot,
        public string $workingDirectory,
        public array $pathMappings,
        public array $envAllowlist,
        public array $timeoutDefaults,
        public bool $xdebugEnabled,
        public string $xdebugMode,
        public string $xdebugStartWithRequest,
        public string $xdebugStartUponError,
        public string $xdebugClientHost,
        public int $xdebugClientPort,
        public ?string $xdebugLog,
        public ?string $xdebugIdekey,
        public array $phpIniOverrides,
        public int $debuggerConnectTimeoutMs,
        public int $debuggerOverallTimeoutMs,
        public string $listenerBindAddress,
        public ?string $moodleDockerDir,
        public ?string $moodleDockerBinDir,
        public array $dockerComposeCommand,
        public string $webserverService,
        public ?string $webserverUser,
        public ?string $containerWorkingDirectory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toBackendContext(): array
    {
        return [
            'profile_name' => $this->profileName,
            'backend_kind' => $this->backendKind,
            'launcher_kind' => $this->launcherKind,
            'execution_transport' => $this->executionTransport,
            'working_directory' => $this->workingDirectory,
            'moodle_root' => $this->moodleRoot,
            'launcher_argv' => $this->launcherArgv,
            'php_argv' => $this->phpArgv,
            'env_allowlist' => $this->envAllowlist,
            'timeout_defaults' => $this->timeoutDefaults,
            'xdebug_enabled' => $this->xdebugEnabled,
            'xdebug_mode' => $this->xdebugMode,
            'xdebug_start_with_request' => $this->xdebugStartWithRequest,
            'xdebug_start_upon_error' => $this->xdebugStartUponError,
            'xdebug_client_host' => $this->xdebugClientHost,
            'xdebug_client_port' => $this->xdebugClientPort,
            'xdebug_log' => $this->xdebugLog,
            'xdebug_idekey' => $this->xdebugIdekey,
            'php_ini_overrides' => $this->phpIniOverrides,
            'debugger_connect_timeout_ms' => $this->debuggerConnectTimeoutMs,
            'debugger_overall_timeout_ms' => $this->debuggerOverallTimeoutMs,
            'listener_bind_address' => $this->listenerBindAddress,
            'moodle_docker_dir' => $this->moodleDockerDir,
            'moodle_docker_bin_dir' => $this->moodleDockerBinDir,
            'docker_compose_command' => $this->dockerComposeCommand,
            'webserver_service' => $this->webserverService,
            'webserver_user' => $this->webserverUser,
            'container_working_directory' => $this->containerWorkingDirectory,
            'path_mappings' => $this->pathMappings,
        ];
    }
}
