<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class CodexEnvironmentLoader
{
    /**
     * @param array<string, string>|null $environment
     * @param string[]|null $candidateFiles
     */
    public function __construct(
        private readonly ?string $repoRoot = null,
        private readonly ?array $environment = null,
        private readonly ?array $candidateFiles = null,
    ) {
    }

    /**
     * Precedence:
     * 1. process environment
     * 2. parsed .codex.env-style file
     * 3. runtime profile config defaults
     *
     * @return array<string, string>
     */
    public function load(): array
    {
        $fileValues = [];
        foreach ($this->resolveCandidateFiles() as $file) {
            if (!is_file($file)) {
                continue;
            }

            $fileValues = $this->parseFile($file);
            break;
        }

        $environment = $this->environment ?? $_ENV;
        $overrides = [];
        foreach ([
            'MOODLE_DIR',
            'MOODLE_DOCKER_DIR',
            'MOODLE_DOCKER_BIN_DIR',
            'WEBSERVER_SERVICE',
            'WEBSERVER_USER',
            'MOODLE_DEBUG_XDEBUG_CLIENT_PORT',
            'MOODLE_DEBUG_CODEX_ENV_FILE',
        ] as $key) {
            $value = $environment[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                $overrides[$key] = $value;
            }
        }

        return array_merge($fileValues, $overrides);
    }

    /**
     * @return string[]
     */
    private function resolveCandidateFiles(): array
    {
        if (is_array($this->candidateFiles)) {
            return $this->candidateFiles;
        }

        $explicit = ($this->environment['MOODLE_DEBUG_CODEX_ENV_FILE'] ?? getenv('MOODLE_DEBUG_CODEX_ENV_FILE')) ?: null;
        $candidates = [];
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }

        if ($this->repoRoot !== null) {
            $candidates[] = rtrim($this->repoRoot, '/') . '/.codex.env';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<string, string>
     */
    private function parseFile(string $path): array
    {
        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            return [];
        }

        $values = [];
        foreach ($contents as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, "\"'");

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
