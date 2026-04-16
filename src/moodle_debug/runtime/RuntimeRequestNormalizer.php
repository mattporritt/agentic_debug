<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\runtime;

/**
 * Converts sparse runtime-query requests into a deterministic internal shape.
 *
 * The sibling-tool contract is intentionally explicit, but callers are still
 * allowed to omit common defaults such as capture policy or default profiles.
 * Centralizing that normalization keeps the CLI, tests, and future orchestrator
 * integration aligned on one source of truth.
 */
final class RuntimeRequestNormalizer
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function normalize(array $request, string $repoRoot): array
    {
        $defaultCapturePolicy = [
            'max_frames' => 25,
            'max_locals_per_frame' => 10,
            'max_string_length' => 512,
            'include_args' => true,
            'include_locals' => true,
            'focus_top_frames' => 5,
        ];

        $intent = (string) ($request['intent'] ?? '');

        return [
            'intent' => $intent,
            'session_id' => isset($request['session_id']) ? (string) $request['session_id'] : null,
            'runtime_profile' => isset($request['runtime_profile']) ? (string) $request['runtime_profile'] : $this->defaultProfileForIntent($intent),
            'moodle_root' => isset($request['moodle_root']) ? (string) $request['moodle_root'] : ($repoRoot . '/_smoke_test/moodle_fixture'),
            'test_ref' => isset($request['test_ref']) ? (string) $request['test_ref'] : null,
            'script_path' => isset($request['script_path']) ? (string) $request['script_path'] : null,
            'script_args' => isset($request['script_args']) && is_array($request['script_args'])
                ? array_values(array_map(static fn (mixed $item): string => (string) $item, $request['script_args']))
                : [],
            'timeout_seconds' => isset($request['timeout_seconds']) ? (int) $request['timeout_seconds'] : 120,
            'summary_depth' => isset($request['summary_depth']) ? (string) $request['summary_depth'] : 'standard',
            'include_result' => isset($request['include_result']) ? (bool) $request['include_result'] : true,
            'capture_policy' => isset($request['capture_policy']) && is_array($request['capture_policy'])
                ? array_merge($defaultCapturePolicy, $request['capture_policy'])
                : $defaultCapturePolicy,
        ];
    }

    private function defaultProfileForIntent(string $intent): string
    {
        return match ($intent) {
            'plan_phpunit', 'execute_phpunit' => 'default_phpunit',
            'plan_cli', 'execute_cli' => 'default_cli',
            default => '',
        };
    }
}
