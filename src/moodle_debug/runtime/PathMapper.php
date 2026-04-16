<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class PathMapper
{
    /**
     * @param array<string, string> $pathMappings
     * @param array<int, array<string, mixed>> $frames
     * @return array<int, array<string, mixed>>
     */
    public function mapFrames(array $frames, array $pathMappings): array
    {
        uasort($pathMappings, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        uksort($pathMappings, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $mapped = [];
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if (is_string($file)) {
                foreach ($pathMappings as $remote => $local) {
                    if (str_starts_with($file, $remote)) {
                        $frame['file'] = $local . substr($file, strlen($remote));
                        break;
                    }
                }
            }
            $mapped[] = $frame;
        }

        return $mapped;
    }
}
