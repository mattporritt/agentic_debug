<?php

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

use MoodleDebug\contracts\ClockInterface;
use MoodleDebug\runtime\RuntimeProfile;

final class XdebugDebugBackend implements DebugBackendInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $sessions = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly XdebugLaunchSettingsBuilder $settingsBuilder,
        private readonly DbgpXmlParser $xmlParser,
    ) {
    }

    public function prepare_session(array $context): array
    {
        $profile = $this->runtimeProfileFromContext($context['runtime_profile'] ?? []);
        $this->settingsBuilder->validateProfile($profile);

        $bindAddress = sprintf('tcp://%s:%d', $profile->listenerBindAddress, $profile->xdebugClientPort);
        $errno = 0;
        $error = '';
        $server = @stream_socket_server($bindAddress, $errno, $error);
        if (!is_resource($server)) {
            throw new DebugBackendException(
                'LISTENER_BIND_FAILED',
                "Failed to bind Xdebug listener on {$bindAddress}: {$error}",
                true,
                ['Ensure the configured Xdebug port is free and reachable from the target runtime.'],
                ['bind_address' => $bindAddress, 'errno' => $errno]
            );
        }

        stream_set_blocking($server, false);

        $backendSessionId = 'xdb_' . substr(sha1(json_encode($context, JSON_THROW_ON_ERROR) . $bindAddress), 0, 16);
        $this->sessions[$backendSessionId] = [
            'server' => $server,
            'connection' => null,
            'profile' => $profile,
            'context' => $context,
            'process' => null,
            'pipes' => [],
            'attached' => false,
            'stopped' => false,
            'stack' => [],
            'locals' => [],
        ];

        return [
            'backend_session_id' => $backendSessionId,
            'stop_policy' => $context['stop_policy'],
        ];
    }

    public function launch_target(array $preparedSession, array $executionPlan): array
    {
        $sessionId = (string) $preparedSession['backend_session_id'];
        $session = &$this->sessions[$sessionId];
        $profile = $session['profile'];
        \assert($profile instanceof RuntimeProfile);

        $this->verifyLaunchPreconditions($profile, $executionPlan);

        $command = $this->settingsBuilder->buildCommand($profile, $executionPlan);
        $environment = $this->settingsBuilder->buildEnvironment($profile);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $this->hostWorkingDirectoryFor($profile, $executionPlan),
            $environment,
        );

        if (!is_resource($process)) {
            throw new DebugBackendException(
                'TARGET_LAUNCH_FAILED',
                'Failed to launch PHP target with Xdebug enabled.',
                true,
                ['Verify the runtime profile launcher_argv/php_argv values and working directory.'],
                ['command' => $command]
            );
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        $session['process'] = $process;
        $session['pipes'] = $pipes;
        $session['execution_plan'] = $executionPlan;
        $session['command'] = $command;
        $session['launched_at'] = $this->clock->now()->format(DATE_ATOM);

        return [
            'backend_session_id' => $sessionId,
            'launched_at' => $session['launched_at'],
            'launcher' => $executionPlan['launcher'],
            'command' => $command,
        ];
    }

    public function wait_for_stop(string $backendSessionId, int $timeoutSeconds): array
    {
        $session = &$this->requireSession($backendSessionId);
        $profile = $session['profile'];
        \assert($profile instanceof RuntimeProfile);

        $connectTimeoutMs = min($profile->debuggerConnectTimeoutMs, max(1, $timeoutSeconds * 1000));
        $connection = $this->acceptConnection($session, $connectTimeoutMs);
        $session['connection'] = $connection;
        $session['attached'] = true;

        $initPacket = $this->readPacket($connection, $connectTimeoutMs);
        $session['init'] = $this->xmlParser->parseInit($initPacket);

        $this->sendFeatureSet($connection, 'extended_properties', '1');
        $this->sendFeatureSet($connection, 'max_depth', '2');
        $this->sendFeatureSet($connection, 'max_children', '32');
        $this->setExceptionBreakpoints($connection);

        $response = $this->sendCommand($connection, 'run');
        $parsed = $this->xmlParser->parseResponse($response);

        if (($parsed['status'] ?? '') === 'break') {
            $session['stopped'] = true;
            $exceptionType = (string) ($parsed['message']['exception'] ?? '');
            $exceptionMessage = (string) ($parsed['message']['text'] ?? '');
            $stopReason = $exceptionType !== '' ? 'exception' : (($parsed['reason'] ?? '') === 'ok' ? 'breakpoint' : 'unknown');
            $stopEvent = [
                'reason' => $stopReason,
                'stopped_at' => $this->clock->now()->format(DATE_ATOM),
                'attached' => true,
            ];

            if ($exceptionType !== '') {
                $stopEvent['exception'] = [
                    'type' => $exceptionType,
                    'message' => $exceptionMessage !== '' ? $exceptionMessage : 'Xdebug stopped on an exception breakpoint.',
                    'file' => $parsed['message']['filename'] ?? '',
                    'line' => $parsed['message']['lineno'] ?? 0,
                ];
            }

            return $stopEvent;
        }

        if (($parsed['status'] ?? '') === 'stopping' || ($parsed['status'] ?? '') === 'stopped') {
            return [
                'reason' => 'target_exit',
                'stopped_at' => $this->clock->now()->format(DATE_ATOM),
                'attached' => true,
            ];
        }

        throw new DebugBackendException(
            'DBGP_PROTOCOL_ERROR',
            'Unexpected DBGp run response while waiting for a debug stop.',
            false,
            [],
            ['status' => $parsed['status'] ?? '', 'reason' => $parsed['reason'] ?? '']
        );
    }

    public function read_stack(string $backendSessionId, int $maxFrames): array
    {
        $session = &$this->requireSession($backendSessionId);
        $connection = $this->requireConnection($session);

        $this->sendFeatureSet($connection, 'max_depth', (string) max(1, min(5, $maxFrames)));
        $response = $this->sendCommand($connection, 'stack_get');
        $frames = array_slice($this->xmlParser->parseStack($response), 0, $maxFrames);
        $session['stack'] = $frames;

        return $frames;
    }

    public function read_locals(string $backendSessionId, array $frameIndexes, int $maxLocalsPerFrame, int $maxStringLength): array
    {
        $session = &$this->requireSession($backendSessionId);
        $connection = $this->requireConnection($session);

        $this->sendFeatureSet($connection, 'max_children', (string) $maxLocalsPerFrame);
        $this->sendFeatureSet($connection, 'max_data', (string) $maxStringLength);

        $localsByFrame = [];
        foreach ($frameIndexes as $frameIndex) {
            $response = $this->sendCommand($connection, 'context_get', [
                '-d', (string) $frameIndex,
                '-c', '0',
            ]);

            $properties = $this->xmlParser->parseContextProperties($response, $frameIndex, $maxLocalsPerFrame, $maxStringLength);
            if ($properties === []) {
                continue;
            }

            $locals = [];
            foreach ($properties as $propertyGroup) {
                foreach ($propertyGroup['locals'] as $local) {
                    $locals[] = $local;
                }
            }

            $localsByFrame[] = [
                'frame_index' => $frameIndex,
                'locals' => array_slice($locals, 0, $maxLocalsPerFrame),
            ];
        }

        $session['locals'] = $localsByFrame;

        return $localsByFrame;
    }

    public function terminate_session(string $backendSessionId): void
    {
        if (!isset($this->sessions[$backendSessionId])) {
            return;
        }

        $session = $this->sessions[$backendSessionId];
        if (is_resource($session['connection'] ?? null)) {
            @fclose($session['connection']);
        }

        if (isset($session['pipes']) && is_array($session['pipes'])) {
            foreach ($session['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
        }

        if (is_resource($session['process'] ?? null)) {
            @proc_terminate($session['process']);
            @proc_close($session['process']);
        }

        if (is_resource($session['server'] ?? null)) {
            @fclose($session['server']);
        }

        unset($this->sessions[$backendSessionId]);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function &requireSession(string $backendSessionId): array
    {
        if (!isset($this->sessions[$backendSessionId])) {
            throw new DebugBackendException('INTERNAL_ORCHESTRATION_ERROR', "Unknown Xdebug backend session: {$backendSessionId}", false);
        }

        return $this->sessions[$backendSessionId];
    }

    /**
     * @param array<string, mixed> $session
     * @return resource
     */
    private function requireConnection(array $session)
    {
        $connection = $session['connection'] ?? null;
        if (!is_resource($connection)) {
            throw new DebugBackendException('DBGP_HANDSHAKE_FAILED', 'No active Xdebug connection is available for this session.', false);
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $session
     * @return resource
     */
    private function acceptConnection(array $session, int $timeoutMs)
    {
        $server = $session['server'] ?? null;
        if (!is_resource($server)) {
            throw new DebugBackendException('LISTENER_BIND_FAILED', 'Xdebug listener socket is not available.', false);
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        do {
            $remaining = max(0.0, $deadline - microtime(true));
            $connection = @stream_socket_accept($server, $remaining);
            if (is_resource($connection)) {
                stream_set_blocking($connection, true);
                return $connection;
            }

            $status = is_resource($session['process'] ?? null) ? proc_get_status($session['process']) : null;
            if (is_array($status) && ($status['running'] ?? false) === false) {
                throw new DebugBackendException(
                    'TARGET_FAILED_BEFORE_ATTACH',
                    'Target exited before Xdebug connected back to the debugger listener.',
                    true,
                    ['Ensure Xdebug is installed in the target PHP runtime and that client_host/client_port are reachable.'],
                    ['process_exit_code' => $status['exitcode'] ?? null, 'stderr' => $this->readPipeTail($session['pipes'][2] ?? null)]
                );
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new DebugBackendException(
            'XDEBUG_CONNECTION_TIMEOUT',
            'Timed out waiting for an Xdebug connection from the launched target.',
            true,
            ['Verify xdebug.client_host and xdebug.client_port in the selected runtime profile.', 'Check firewall or container network routing if the target runs outside the host process.'],
            ['stderr' => $this->readPipeTail($session['pipes'][2] ?? null)]
        );
    }

    private function verifyXdebugExtensionAvailable(RuntimeProfile $profile, array $executionPlan): void
    {
        $command = $this->buildPreflightCommand(
            $profile,
            $executionPlan,
            ['-r', 'echo extension_loaded("xdebug") ? "1" : "0";']
        );

        [$exitCode, $stdout, $stderr] = $this->runHostCommand(
            $command,
            $this->hostWorkingDirectoryFor($profile, $executionPlan),
            $this->settingsBuilder->buildEnvironment($profile),
            'TARGET_LAUNCH_FAILED',
            'Failed to launch the PHP preflight command for Xdebug detection.'
        );

        if ($exitCode !== 0) {
            $this->throwDockerAwareCommandFailure($profile, $command, trim((string) $stderr), 'The PHP preflight command failed before debug launch.');
        }

        if (trim((string) $stdout) !== '1') {
            throw new DebugBackendException(
                'XDEBUG_NOT_ENABLED',
                "Xdebug is not installed or enabled for runtime profile {$profile->profileName}.",
                true,
                ['Run the target PHP binary with `php --ri xdebug` inside the selected runtime to confirm availability.'],
                ['command' => $command, 'stderr' => trim((string) $stderr)]
            );
        }
    }

    private function verifyLaunchPreconditions(RuntimeProfile $profile, array $executionPlan): void
    {
        if ($profile->executionTransport === 'docker_exec') {
            $this->verifyDockerComposeCommand($profile);
            $this->verifyCallbackHostResolution($profile, $executionPlan);
        }

        $this->verifyXdebugExtensionAvailable($profile, $executionPlan);
    }

    /**
     * @param resource $connection
     */
    private function sendFeatureSet($connection, string $feature, string $value): void
    {
        try {
            $this->sendCommand($connection, 'feature_set', ['-n', $feature, '-v', $value]);
        } catch (DebugBackendException) {
            // Feature negotiation is best-effort; older setups may reject some flags.
        }
    }

    /**
     * @param resource $connection
     */
    private function setExceptionBreakpoints($connection): void
    {
        try {
            $this->sendCommand($connection, 'breakpoint_set', ['-t', 'exception', '-x', '*']);
            return;
        } catch (DebugBackendException) {
        }

        foreach (['Throwable', 'Exception', 'Error'] as $exceptionName) {
            try {
                $this->sendCommand($connection, 'breakpoint_set', ['-t', 'exception', '-x', $exceptionName]);
            } catch (DebugBackendException) {
                // Keep trying fallbacks.
            }
        }
    }

    /**
     * @param resource $connection
     * @param string[] $arguments
     */
    private function sendCommand($connection, string $command, array $arguments = []): string
    {
        static $transactionId = 1;

        $parts = array_merge([$command, '-i', (string) $transactionId++], $arguments);
        $payload = implode(' ', $parts) . "\0";
        $written = fwrite($connection, $payload);
        if ($written === false) {
            throw new DebugBackendException('DBGP_PROTOCOL_ERROR', "Failed to write DBGp command {$command}.", true);
        }

        return $this->readPacket($connection, 5000);
    }

    /**
     * @param resource $connection
     */
    private function readPacket($connection, int $timeoutMs): string
    {
        $lengthBuffer = '';
        while (true) {
            $char = $this->readBytes($connection, 1, $timeoutMs);
            if ($char === "\0") {
                break;
            }
            $lengthBuffer .= $char;
        }

        if (!ctype_digit($lengthBuffer)) {
            throw new DebugBackendException('DBGP_PROTOCOL_ERROR', 'Received an invalid DBGp packet length prefix.', false, [], ['prefix' => $lengthBuffer]);
        }

        $payload = $this->readBytes($connection, (int) $lengthBuffer, $timeoutMs);
        $terminator = $this->readBytes($connection, 1, $timeoutMs);
        if ($terminator !== "\0") {
            throw new DebugBackendException('DBGP_PROTOCOL_ERROR', 'Received an invalid DBGp packet terminator.', false);
        }

        return $payload;
    }

    /**
     * @param resource $connection
     */
    private function readBytes($connection, int $length, int $timeoutMs): string
    {
        $buffer = '';
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (strlen($buffer) < $length) {
            $remainingSeconds = max(0, (int) floor($deadline - microtime(true)));
            $remainingMicros = max(0, (int) (((max(0.0, $deadline - microtime(true))) - $remainingSeconds) * 1000000));
            $read = [$connection];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, $remainingSeconds, $remainingMicros);
            if ($selected === false) {
                throw new DebugBackendException('DBGP_PROTOCOL_ERROR', 'stream_select failed while reading from Xdebug.', true);
            }
            if ($selected === 0) {
                throw new DebugBackendException('DBGP_HANDSHAKE_FAILED', 'Timed out while reading a DBGp packet from Xdebug.', true);
            }

            $chunk = fread($connection, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                if (feof($connection)) {
                    throw new DebugBackendException('DBGP_PROTOCOL_ERROR', 'Xdebug closed the DBGp connection unexpectedly.', true);
                }
                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param resource|null $pipe
     */
    private function readPipeTail($pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }

        $contents = stream_get_contents($pipe);
        return $contents === false ? '' : trim($contents);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function runtimeProfileFromContext(array $data): RuntimeProfile
    {
        return new RuntimeProfile(
            profileName: (string) ($data['profile_name'] ?? 'unknown'),
            backendKind: (string) ($data['backend_kind'] ?? 'xdebug'),
            launcherKind: (string) ($data['launcher_kind'] ?? 'cli'),
            executionTransport: (string) ($data['execution_transport'] ?? 'host_exec'),
            launcherArgv: is_array($data['launcher_argv'] ?? null) ? $data['launcher_argv'] : [],
            phpArgv: is_array($data['php_argv'] ?? null) ? $data['php_argv'] : ['php'],
            moodleRoot: (string) ($data['moodle_root'] ?? ''),
            workingDirectory: (string) ($data['working_directory'] ?? getcwd()),
            pathMappings: is_array($data['path_mappings'] ?? null) ? $data['path_mappings'] : [],
            envAllowlist: is_array($data['env_allowlist'] ?? null) ? $data['env_allowlist'] : [],
            timeoutDefaults: is_array($data['timeout_defaults'] ?? null) ? $data['timeout_defaults'] : ['launch' => 5, 'attach' => 5, 'overall' => 120],
            xdebugEnabled: (bool) ($data['xdebug_enabled'] ?? false),
            xdebugMode: (string) ($data['xdebug_mode'] ?? 'debug'),
            xdebugStartWithRequest: (string) ($data['xdebug_start_with_request'] ?? 'yes'),
            xdebugStartUponError: (string) ($data['xdebug_start_upon_error'] ?? 'yes'),
            xdebugClientHost: (string) ($data['xdebug_client_host'] ?? '127.0.0.1'),
            xdebugClientPort: (int) ($data['xdebug_client_port'] ?? 9003),
            xdebugLog: isset($data['xdebug_log']) ? ($data['xdebug_log'] !== null ? (string) $data['xdebug_log'] : null) : null,
            xdebugIdekey: isset($data['xdebug_idekey']) ? ($data['xdebug_idekey'] !== null ? (string) $data['xdebug_idekey'] : null) : null,
            phpIniOverrides: is_array($data['php_ini_overrides'] ?? null) ? $data['php_ini_overrides'] : [],
            debuggerConnectTimeoutMs: (int) ($data['debugger_connect_timeout_ms'] ?? 1500),
            debuggerOverallTimeoutMs: (int) ($data['debugger_overall_timeout_ms'] ?? 120000),
            listenerBindAddress: (string) ($data['listener_bind_address'] ?? '127.0.0.1'),
            moodleDockerDir: isset($data['moodle_docker_dir']) ? ($data['moodle_docker_dir'] !== null ? (string) $data['moodle_docker_dir'] : null) : null,
            moodleDockerBinDir: isset($data['moodle_docker_bin_dir']) ? ($data['moodle_docker_bin_dir'] !== null ? (string) $data['moodle_docker_bin_dir'] : null) : null,
            dockerComposeCommand: is_array($data['docker_compose_command'] ?? null) ? array_values(array_map(static fn (mixed $item): string => (string) $item, $data['docker_compose_command'])) : [],
            webserverService: (string) ($data['webserver_service'] ?? 'webserver'),
            webserverUser: isset($data['webserver_user']) ? ($data['webserver_user'] !== null ? (string) $data['webserver_user'] : null) : null,
            containerWorkingDirectory: isset($data['container_working_directory']) ? ($data['container_working_directory'] !== null ? (string) $data['container_working_directory'] : null) : null,
        );
    }

    /**
     * @param string[] $phpCommand
     * @param array<string, mixed> $executionPlan
     * @return string[]
     */
    private function buildPreflightCommand(RuntimeProfile $profile, array $executionPlan, array $phpCommand): array
    {
        $preflightPhpArgs = array_values(array_merge(
            $profile->phpArgv,
            [
                '-d', 'xdebug.mode=off',
                '-d', 'xdebug.start_with_request=no',
                '-d', 'xdebug.start_upon_error=no',
            ],
            $phpCommand,
        ));

        if ($profile->executionTransport === 'docker_exec') {
            return array_values(array_merge(
                $profile->dockerComposeCommand,
                ['exec', '-T'],
                $profile->containerWorkingDirectory !== null && $profile->containerWorkingDirectory !== '' ? ['-w', $profile->containerWorkingDirectory] : [],
                $profile->webserverUser !== null && $profile->webserverUser !== '' ? ['-u', $profile->webserverUser] : [],
                [$profile->webserverService],
                $preflightPhpArgs,
            ));
        }

        return array_values(array_merge(
            is_array($executionPlan['launcher'] ?? null) ? $executionPlan['launcher'] : [],
            $preflightPhpArgs,
        ));
    }

    private function verifyDockerComposeCommand(RuntimeProfile $profile): void
    {
        $first = $profile->dockerComposeCommand[0] ?? null;
        if (!is_string($first) || $first === '') {
            throw new DebugBackendException('DOCKER_COMPOSE_BINARY_MISSING', 'No docker compose command is configured for this runtime profile.', false);
        }

        if (str_contains($first, '/') && !is_executable($first)) {
            throw new DebugBackendException(
                'DOCKER_COMPOSE_BINARY_MISSING',
                "Docker compose binary is missing or not executable: {$first}",
                false,
            );
        }

        [$exitCode, , $stderr] = $this->runHostCommand(
            array_merge($profile->dockerComposeCommand, ['version']),
            $profile->moodleDockerDir ?: $profile->workingDirectory,
            $this->settingsBuilder->buildEnvironment($profile),
            'DOCKER_COMPOSE_BINARY_MISSING',
            'Failed to invoke the configured docker compose command.'
        );

        if ($exitCode !== 0) {
            $this->throwDockerAwareCommandFailure(
                $profile,
                array_merge($profile->dockerComposeCommand, ['version']),
                trim((string) $stderr),
                'Docker compose is not available for this runtime profile.'
            );
        }
    }

    private function verifyCallbackHostResolution(RuntimeProfile $profile, array $executionPlan): void
    {
        $command = $this->buildPreflightCommand(
            $profile,
            $executionPlan,
            ['-r', 'echo gethostbyname(' . var_export($profile->xdebugClientHost, true) . ');']
        );

        [$exitCode, $stdout, $stderr] = $this->runHostCommand(
            $command,
            $this->hostWorkingDirectoryFor($profile, $executionPlan),
            $this->settingsBuilder->buildEnvironment($profile),
            'DOCKER_EXEC_FAILED',
            'Failed to run the Docker callback host preflight command.'
        );

        if ($exitCode !== 0) {
            $this->throwDockerAwareCommandFailure($profile, $command, trim((string) $stderr), 'Docker preflight failed while checking callback host resolution.');
        }

        $resolved = trim((string) $stdout);
        if ($resolved === '' || $resolved === $profile->xdebugClientHost) {
            throw new DebugBackendException(
                'XDEBUG_CALLBACK_HOST_UNRESOLVABLE',
                "The Xdebug callback host {$profile->xdebugClientHost} did not resolve inside the Docker webserver container.",
                true,
                ['Verify that host.docker.internal resolves inside the webserver container on this machine.'],
                ['command' => $command, 'stdout' => $resolved]
            );
        }
    }

    /**
     * @param string[] $command
     * @param array<string, string> $environment
     * @return array{0:int,1:string,2:string}
     */
    private function runHostCommand(array $command, string $workingDirectory, array $environment, string $errorCode, string $message): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            throw new DebugBackendException($errorCode, $message, true, [], ['command' => $command]);
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        foreach (array_slice($pipes, 1) as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * @param string[] $command
     */
    private function throwDockerAwareCommandFailure(RuntimeProfile $profile, array $command, string $stderr, string $fallbackMessage): never
    {
        $code = 'DOCKER_EXEC_FAILED';
        $message = $fallbackMessage;
        $retryable = true;
        $normalizedStderr = strtolower($stderr);

        if (str_contains($normalizedStderr, 'no such file or directory') || str_contains($normalizedStderr, 'command not found')) {
            $code = 'DOCKER_COMPOSE_BINARY_MISSING';
            $message = 'The configured docker compose command could not be executed.';
            $retryable = false;
        } elseif (str_contains($normalizedStderr, 'no such service')) {
            $code = 'DOCKER_SERVICE_NOT_FOUND';
            $message = "The configured Docker service {$profile->webserverService} was not found.";
            $retryable = false;
        } elseif (str_contains($normalizedStderr, 'is not running')) {
            $code = 'DOCKER_SERVICE_NOT_RUNNING';
            $message = "The configured Docker service {$profile->webserverService} is not running.";
        }

        throw new DebugBackendException(
            $code,
            $message,
            $retryable,
            ['Verify the configured docker compose command, Docker service name, and Docker daemon availability.'],
            ['command' => $command, 'stderr' => $stderr]
        );
    }

    /**
     * @param array<string, mixed> $executionPlan
     */
    private function hostWorkingDirectoryFor(RuntimeProfile $profile, array $executionPlan): string
    {
        if ($profile->executionTransport === 'docker_exec' && $profile->moodleDockerDir !== null && $profile->moodleDockerDir !== '') {
            return $profile->moodleDockerDir;
        }

        return (string) ($executionPlan['cwd'] ?? $profile->workingDirectory);
    }
}
