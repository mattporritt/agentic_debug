<?php

declare(strict_types=1);

namespace MoodleDebug\session_store;

use MoodleDebug\contracts\ClockInterface;

/**
 * File-backed session storage for bounded debugger artifacts.
 *
 * Sessions are intentionally immutable snapshots: once a run completes, later
 * calls only read or expire the saved artifacts.
 */
final class FileArtifactSessionStore
{
    public function __construct(
        private readonly string $storageDirectory,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds,
        private readonly int $artifactBytesLimit,
    ) {
        if (!is_dir($this->storageDirectory)) {
            mkdir($this->storageDirectory, 0777, true);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $sessionId, array $payload): void
    {
        $this->cleanupExpired();

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > $this->artifactBytesLimit) {
            throw new \RuntimeException("Session artifact exceeds configured limit for {$sessionId}");
        }

        file_put_contents($this->filePath($sessionId), $json);
    }

    public function load(string $sessionId): SessionLookupResult
    {
        $path = $this->filePath($sessionId);
        if (!is_file($path)) {
            return new SessionLookupResult(false, false);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return new SessionLookupResult(false, false);
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            unlink($path);
            return new SessionLookupResult(false, false);
        }

        $expiresAt = $decoded['session']['session']['expires_at'] ?? null;
        if (is_string($expiresAt) && new \DateTimeImmutable($expiresAt) <= $this->clock->now()) {
            unlink($path);
            return new SessionLookupResult(true, true);
        }

        return new SessionLookupResult(true, false, $decoded);
    }

    /**
     * @return string[]
     */
    public function cleanupExpired(): array
    {
        if (!is_dir($this->storageDirectory)) {
            return [];
        }

        $removed = [];
        foreach (glob($this->storageDirectory . '/*.json') ?: [] as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $decoded = json_decode($contents, true);
            $expiresAt = $decoded['session']['session']['expires_at'] ?? null;
            if (is_string($expiresAt) && new \DateTimeImmutable($expiresAt) <= $this->clock->now()) {
                $removed[] = basename($path, '.json');
                unlink($path);
            }
        }

        return $removed;
    }

    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    private function filePath(string $sessionId): string
    {
        return rtrim($this->storageDirectory, '/') . '/' . $sessionId . '.json';
    }
}
