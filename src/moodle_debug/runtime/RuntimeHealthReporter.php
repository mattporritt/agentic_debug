<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

use MoodleDebug\contracts\ClockInterface;

/**
 * Builds a bounded health report for subprocess orchestration.
 *
 * Health deliberately stops short of launching a real debug target. It reports
 * whether configuration, storage, listener binding, and profile resolution look
 * usable so an orchestrator can decide whether a more explicit plan or execute
 * call is worthwhile.
 */
final class RuntimeHealthReporter
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly RuntimeProfileLoader $profileLoader,
        private readonly CodexEnvironmentLoader $environmentLoader,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status:string,normalized_query:array<string, mixed>,result:array<string, mixed>}
     */
    public function build(array $request): array
    {
        $profileNames = $request['profile_names'] ?? ['default_phpunit', 'default_cli', 'real_xdebug_phpunit', 'real_xdebug_cli'];
        $profileNames = is_array($profileNames) ? array_values(array_map(static fn (mixed $item): string => (string) $item, $profileNames)) : [];
        $environment = $this->environmentLoader->load();
        $listenerAddress = (string) ($request['listener_bind_address'] ?? '127.0.0.1');
        $listenerPort = (int) ($request['listener_port'] ?? 9003);

        $subsystems = [];
        $subsystems[] = $this->buildSubsystem('config', is_file($this->repoRoot . '/config/runtime_profiles.json') ? 'ok' : 'fail', 'Runtime profile config lookup completed.', [
            'config_path' => $this->repoRoot . '/config/runtime_profiles.json',
        ]);
        $subsystems[] = $this->buildSubsystem('session_store', $this->isSessionStoreWritable() ? 'ok' : 'fail', 'Session artifact directory checked.', [
            'storage_directory' => $this->repoRoot . '/_smoke_test/moodle_debug_sessions',
        ]);
        $subsystems[] = $this->buildSubsystem('codex_env', $environment === [] ? 'warn' : 'ok', $environment === [] ? 'No codex-style environment overrides were found.' : 'Codex-style environment values were loaded.', [
            'available_keys' => array_keys($environment),
        ]);
        $subsystems[] = $this->buildSubsystem('listener', $this->canBindListener($listenerAddress, $listenerPort) ? 'ok' : 'warn', 'Listener bind capability probe completed.', [
            'bind_address' => $listenerAddress,
            'listener_port' => $listenerPort,
        ]);

        $profileDiagnostics = $this->collectProfileDiagnostics($profileNames);
        $dockerStatus = 'warn';
        $dockerMessage = 'Docker-backed profiles can be validated; runtime execution probes are deferred to explicit plan/execute requests.';
        if ($this->hasDockerBackedProfile($profileDiagnostics)) {
            $dockerStatus = $this->canResolveDockerCommand($profileDiagnostics) ? 'ok' : 'warn';
        }

        $subsystems[] = $this->buildSubsystem('docker', $dockerStatus, $dockerMessage, [
            'profiles' => $profileDiagnostics,
        ]);
        $subsystems[] = $this->buildSubsystem('xdebug', 'warn', 'Health verifies Xdebug-capable profile configuration only; container runtime Xdebug availability is checked during explicit plan or execute flows.', [
            'probe_supported' => true,
            'probed' => false,
        ]);
        $subsystems[] = $this->buildSubsystem('supported_targets', 'ok', 'Explicit bounded target classes available.', [
            'target_types' => ['phpunit_selector', 'cli_script'],
        ]);

        return [
            'status' => $this->reduceStatus($subsystems),
            'normalized_query' => [
                'intent' => 'health',
                'profile_names' => $profileNames,
            ],
            'result' => [
                'id' => 'health_report',
                'type' => 'health_report',
                'rank' => 1,
                'confidence' => 'high',
                'source' => [
                    'kind' => 'runtime',
                    'profile_name' => null,
                    'session_id' => null,
                ],
                'content' => [
                    'subsystems' => $subsystems,
                    'generated_at' => $this->clock->now()->format(DATE_ATOM),
                ],
                'diagnostics' => [],
            ],
        ];
    }

    /**
     * @param string[] $profileNames
     * @return array<int, array<string, mixed>>
     */
    private function collectProfileDiagnostics(array $profileNames): array
    {
        $profileDiagnostics = [];
        foreach ($profileNames as $profileName) {
            try {
                $launcherKind = str_contains($profileName, 'phpunit') ? 'phpunit' : 'cli';
                $profile = $this->profileLoader->getProfile($profileName, $launcherKind);
                $profileDiagnostics[] = [
                    'profile_name' => $profile->profileName,
                    'backend_kind' => $profile->backendKind,
                    'execution_transport' => $profile->executionTransport,
                    'launcher_kind' => $profile->launcherKind,
                ];
            } catch (\Throwable $exception) {
                $profileDiagnostics[] = [
                    'profile_name' => $profileName,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $profileDiagnostics;
    }

    /**
     * @param array<int, array<string, mixed>> $profileDiagnostics
     */
    private function hasDockerBackedProfile(array $profileDiagnostics): bool
    {
        foreach ($profileDiagnostics as $profile) {
            if (($profile['execution_transport'] ?? null) === 'docker_exec') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $profileDiagnostics
     */
    private function canResolveDockerCommand(array $profileDiagnostics): bool
    {
        foreach ($profileDiagnostics as $profile) {
            if (($profile['execution_transport'] ?? null) !== 'docker_exec') {
                continue;
            }

            $profileName = (string) ($profile['profile_name'] ?? '');
            $launcherKind = str_contains($profileName, 'phpunit') ? 'phpunit' : 'cli';
            try {
                $runtimeProfile = $this->profileLoader->getProfile($profileName, $launcherKind);
            } catch (\Throwable) {
                return false;
            }

            $command = $runtimeProfile->dockerComposeCommand[0] ?? null;
            if (!is_string($command) || $command === '') {
                return false;
            }

            if (str_contains($command, '/')) {
                return is_file($command);
            }

            return $this->commandExists($command);
        }

        return true;
    }

    private function commandExists(string $command): bool
    {
        $output = [];
        $exitCode = 1;
        @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }

    private function isSessionStoreWritable(): bool
    {
        $tempFile = $this->repoRoot . '/_smoke_test/moodle_debug_sessions/.runtime_probe';
        $written = @file_put_contents($tempFile, 'ok');
        if ($written === false) {
            return false;
        }
        @unlink($tempFile);

        return true;
    }

    private function canBindListener(string $address, int $port): bool
    {
        $host = $address === '0.0.0.0' ? '127.0.0.1' : $address;
        $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $error);
        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $subsystems
     */
    private function reduceStatus(array $subsystems): string
    {
        $hasFail = false;
        $hasWarn = false;
        foreach ($subsystems as $subsystem) {
            $status = (string) ($subsystem['status'] ?? 'ok');
            $hasFail = $hasFail || $status === 'fail';
            $hasWarn = $hasWarn || $status === 'warn';
        }

        return $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'ok');
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function buildSubsystem(string $name, string $status, string $message, array $details = []): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }
}
