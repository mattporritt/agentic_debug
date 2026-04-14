<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class RuntimeProfileLoader
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    public function __construct(string $configPath)
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

            return new RuntimeProfile(
                profileName: $profile['profile_name'],
                launcherKind: $profile['launcher_kind'],
                launcherArgv: $profile['launcher_argv'],
                phpArgv: $profile['php_argv'],
                moodleRoot: $profile['moodle_root'],
                workingDirectory: $profile['working_directory'],
                pathMappings: $profile['path_mappings'],
                envAllowlist: $profile['env_allowlist'],
                timeoutDefaults: $profile['timeout_defaults'],
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
}
