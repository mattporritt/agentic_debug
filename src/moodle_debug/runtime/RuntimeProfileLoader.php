<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class RuntimeProfileLoader
{
    /**
     * @var array<string, mixed>
     */
    private array $config;
    /**
     * @var array<string, string>
     */
    private array $environmentValues;

    public function __construct(string $configPath, ?CodexEnvironmentLoader $environmentLoader = null)
    {
        $contents = file_get_contents($configPath);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read runtime profile config: {$configPath}");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Unable to decode runtime profile config: {$configPath}");
        }

        $this->config = $decoded;
        $repoRoot = dirname(dirname($configPath));
        $this->environmentValues = ($environmentLoader ?? new CodexEnvironmentLoader($repoRoot))->load();
    }

    public function getProfile(string $profileName, string $expectedLauncherKind): RuntimeProfile
    {
        foreach ($this->config['profiles'] ?? [] as $profile) {
            if (($profile['profile_name'] ?? null) !== $profileName) {
                continue;
            }

            if (($profile['launcher_kind'] ?? null) !== $expectedLauncherKind) {
                throw new \RuntimeException("Runtime profile {$profileName} is not valid for {$expectedLauncherKind}.");
            }

            $resolved = $this->resolveProfile($profile);

            return new RuntimeProfile(
                profileName: $resolved['profile_name'],
                backendKind: $resolved['backend_kind'],
                launcherKind: $resolved['launcher_kind'],
                executionTransport: $resolved['execution_transport'],
                launcherArgv: $resolved['launcher_argv'],
                phpArgv: $resolved['php_argv'],
                moodleRoot: $resolved['moodle_root'],
                workingDirectory: $resolved['working_directory'],
                pathMappings: $resolved['path_mappings'],
                envAllowlist: $resolved['env_allowlist'],
                timeoutDefaults: $resolved['timeout_defaults'],
                xdebugEnabled: (bool) $resolved['xdebug_enabled'],
                xdebugMode: $resolved['xdebug_mode'],
                xdebugStartWithRequest: $resolved['xdebug_start_with_request'],
                xdebugStartUponError: $resolved['xdebug_start_upon_error'],
                xdebugClientHost: $resolved['xdebug_client_host'],
                xdebugClientPort: (int) $resolved['xdebug_client_port'],
                xdebugLog: $resolved['xdebug_log'],
                xdebugIdekey: $resolved['xdebug_idekey'],
                phpIniOverrides: $resolved['php_ini_overrides'],
                debuggerConnectTimeoutMs: (int) $resolved['debugger_connect_timeout_ms'],
                debuggerOverallTimeoutMs: (int) $resolved['debugger_overall_timeout_ms'],
                listenerBindAddress: $resolved['listener_bind_address'],
                moodleDockerDir: $resolved['moodle_docker_dir'],
                moodleDockerBinDir: $resolved['moodle_docker_bin_dir'],
                dockerComposeCommand: $resolved['docker_compose_command'],
                webserverService: $resolved['webserver_service'],
                webserverUser: $resolved['webserver_user'],
                containerWorkingDirectory: $resolved['container_working_directory'],
            );
        }

        throw new \RuntimeException("Runtime profile not found: {$profileName}");
    }

    /**
     * @return string[]
     */
    public function getCliAllowlist(): array
    {
        $allowlist = $this->config['cli_allowlist'] ?? ['admin/cli/'];
        if (!is_array($allowlist)) {
            return ['admin/cli/'];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $allowlist));
    }

    public function getSessionTtl(): int
    {
        return (int) ($this->config['session']['ttl_seconds'] ?? 3600);
    }

    public function getArtifactBytesLimit(): int
    {
        return (int) ($this->config['session']['artifact_bytes_limit'] ?? 524288);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function resolveProfile(array $profile): array
    {
        $executionTransport = (string) ($profile['execution_transport'] ?? 'host_exec');
        $environmentMoodleRoot = isset($this->environmentValues['MOODLE_DIR']) ? (string) $this->environmentValues['MOODLE_DIR'] : null;
        $moodleRoot = (string) ($profile['moodle_root'] ?? '');
        if ($executionTransport === 'docker_exec' && $environmentMoodleRoot !== null) {
            $moodleRoot = $environmentMoodleRoot;
        }

        $moodleDockerDir = $profile['moodle_docker_dir'] ?? null;
        if ($executionTransport === 'docker_exec' && isset($this->environmentValues['MOODLE_DOCKER_DIR'])) {
            $moodleDockerDir = $this->environmentValues['MOODLE_DOCKER_DIR'];
        }

        $moodleDockerBinDir = $profile['moodle_docker_bin_dir'] ?? null;
        if ($executionTransport === 'docker_exec' && isset($this->environmentValues['MOODLE_DOCKER_BIN_DIR'])) {
            $moodleDockerBinDir = $this->environmentValues['MOODLE_DOCKER_BIN_DIR'];
        }

        $webserverService = (string) ($profile['webserver_service'] ?? 'webserver');
        if (isset($this->environmentValues['WEBSERVER_SERVICE'])) {
            $webserverService = $this->environmentValues['WEBSERVER_SERVICE'];
        }

        $webserverUser = isset($profile['webserver_user']) ? ($profile['webserver_user'] !== null ? (string) $profile['webserver_user'] : null) : null;
        if (isset($this->environmentValues['WEBSERVER_USER'])) {
            $webserverUser = $this->environmentValues['WEBSERVER_USER'];
        }

        $workingDirectory = (string) ($profile['working_directory'] ?? $moodleRoot);
        $containerWorkingDirectory = isset($profile['container_working_directory']) ? ($profile['container_working_directory'] !== null ? (string) $profile['container_working_directory'] : null) : null;
        $pathMappings = is_array($profile['path_mappings'] ?? null) ? $profile['path_mappings'] : [];
        if ($executionTransport === 'docker_exec') {
            [$pathMappings, $containerWorkingDirectory] = $this->resolveDockerPathMappings(
                $pathMappings,
                $moodleRoot,
                $containerWorkingDirectory
            );

            if ($environmentMoodleRoot !== null && $environmentMoodleRoot !== '') {
                $workingDirectory = $moodleRoot;
            }
        }

        return [
            'profile_name' => (string) ($profile['profile_name'] ?? ''),
            'backend_kind' => (string) ($profile['backend_kind'] ?? 'mock'),
            'launcher_kind' => (string) ($profile['launcher_kind'] ?? 'cli'),
            'execution_transport' => $executionTransport,
            'launcher_argv' => is_array($profile['launcher_argv'] ?? null) ? $profile['launcher_argv'] : [],
            'php_argv' => is_array($profile['php_argv'] ?? null) ? $profile['php_argv'] : ['php'],
            'moodle_root' => $moodleRoot,
            'working_directory' => $workingDirectory,
            'path_mappings' => $pathMappings,
            'env_allowlist' => is_array($profile['env_allowlist'] ?? null) ? $profile['env_allowlist'] : [],
            'timeout_defaults' => is_array($profile['timeout_defaults'] ?? null) ? $profile['timeout_defaults'] : ['launch' => 5, 'attach' => 5, 'overall' => 120],
            'xdebug_enabled' => (bool) ($profile['xdebug_enabled'] ?? false),
            'xdebug_mode' => (string) ($profile['xdebug_mode'] ?? 'debug'),
            'xdebug_start_with_request' => (string) ($profile['xdebug_start_with_request'] ?? 'yes'),
            'xdebug_start_upon_error' => (string) ($profile['xdebug_start_upon_error'] ?? 'yes'),
            'xdebug_client_host' => (string) ($profile['xdebug_client_host'] ?? ($executionTransport === 'docker_exec' ? 'host.docker.internal' : '127.0.0.1')),
            'xdebug_client_port' => (int) ($profile['xdebug_client_port'] ?? 9003),
            'xdebug_log' => isset($profile['xdebug_log']) ? ($profile['xdebug_log'] !== null ? (string) $profile['xdebug_log'] : null) : null,
            'xdebug_idekey' => isset($profile['xdebug_idekey']) ? ($profile['xdebug_idekey'] !== null ? (string) $profile['xdebug_idekey'] : null) : null,
            'php_ini_overrides' => is_array($profile['php_ini_overrides'] ?? null) ? $profile['php_ini_overrides'] : [],
            'debugger_connect_timeout_ms' => (int) ($profile['debugger_connect_timeout_ms'] ?? 1500),
            'debugger_overall_timeout_ms' => (int) ($profile['debugger_overall_timeout_ms'] ?? 120000),
            'listener_bind_address' => (string) ($profile['listener_bind_address'] ?? ($executionTransport === 'docker_exec' ? '0.0.0.0' : '127.0.0.1')),
            'moodle_docker_dir' => $moodleDockerDir !== null ? (string) $moodleDockerDir : null,
            'moodle_docker_bin_dir' => $moodleDockerBinDir !== null ? (string) $moodleDockerBinDir : null,
            'docker_compose_command' => $this->resolveDockerComposeCommand($profile, $moodleDockerBinDir),
            'webserver_service' => $webserverService,
            'webserver_user' => $webserverUser,
            'container_working_directory' => $containerWorkingDirectory,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return string[]
     */
    private function resolveDockerComposeCommand(array $profile, mixed $moodleDockerBinDir): array
    {
        if (is_string($moodleDockerBinDir) && $moodleDockerBinDir !== '') {
            return [rtrim($moodleDockerBinDir, '/') . '/moodle-docker-compose'];
        }

        if (is_array($profile['docker_compose_command'] ?? null) && $profile['docker_compose_command'] !== []) {
            return array_values(array_map(static fn (mixed $item): string => (string) $item, $profile['docker_compose_command']));
        }

        return ['docker', 'compose'];
    }

    /**
     * @param array<string, string> $pathMappings
     * @return array{0:array<string, string>,1:?string}
     */
    private function resolveDockerPathMappings(array $pathMappings, string $moodleRoot, ?string $containerWorkingDirectory): array
    {
        if ($pathMappings === [] || $moodleRoot === '') {
            return [$pathMappings, $containerWorkingDirectory];
        }

        $remoteRoot = array_key_first($pathMappings);
        if (!is_string($remoteRoot) || $remoteRoot === '') {
            return [$pathMappings, $containerWorkingDirectory];
        }

        $effectiveRemoteRoot = $remoteRoot;
        if (basename(rtrim($moodleRoot, '/')) === 'public' && !str_ends_with($remoteRoot, '/public')) {
            $effectiveRemoteRoot = rtrim($remoteRoot, '/') . '/public';
        }

        $resolvedMappings = [$effectiveRemoteRoot => $moodleRoot];

        if ($containerWorkingDirectory === null || $containerWorkingDirectory === '' || $containerWorkingDirectory === $remoteRoot) {
            $containerWorkingDirectory = $effectiveRemoteRoot;
        }

        return [$resolvedMappings, $containerWorkingDirectory];
    }
}
