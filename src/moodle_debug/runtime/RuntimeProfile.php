<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final readonly class RuntimeProfile
{
    /**
     * @param string[] $launcherArgv
     * @param string[] $phpArgv
     * @param array<string, string> $pathMappings
     * @param string[] $envAllowlist
     * @param array{launch:int,attach:int,overall:int} $timeoutDefaults
     */
    public function __construct(
        public string $profileName,
        public string $launcherKind,
        public array $launcherArgv,
        public array $phpArgv,
        public string $moodleRoot,
        public string $workingDirectory,
        public array $pathMappings,
        public array $envAllowlist,
        public array $timeoutDefaults,
    ) {
    }
}
