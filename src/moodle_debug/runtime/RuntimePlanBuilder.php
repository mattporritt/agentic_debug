<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\runtime;

use MoodleDebug\debug_backend\XdebugLaunchSettingsBuilder;

/**
 * Resolves explicit runtime targets into bounded execution plans.
 *
 * This class is shared by dry-run planning and explicit execution so the same
 * target validation and command construction rules apply in both paths.
 */
final class RuntimePlanBuilder
{
    public function __construct(
        private readonly RuntimeProfileLoader $profileLoader,
        private readonly PHPUnitSelectorValidator $selectorValidator,
        private readonly CliPathValidator $cliPathValidator,
        private readonly ExecutionPlanFactory $executionPlanFactory,
        private readonly XdebugLaunchSettingsBuilder $xdebugLaunchSettingsBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{0:RuntimeProfile,1:array{normalized:string,method_name:string,guessed_test_file:?string},2:array<string, mixed>}
     */
    public function resolvePhpunit(array $normalized): array
    {
        $profile = $this->profileLoader->getProfile((string) $normalized['runtime_profile'], 'phpunit');
        $selector = $this->selectorValidator->validate((string) $normalized['test_ref'], $profile->moodleRoot);
        if (($selector['valid'] ?? false) !== true) {
            throw new \RuntimeException((string) ($selector['message'] ?? 'Invalid PHPUnit selector.'));
        }

        return [$profile, $selector, $this->executionPlanFactory->forPhpunit($profile, $selector)];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{0:RuntimeProfile,1:string,2:array<string, mixed>}
     */
    public function resolveCli(array $normalized): array
    {
        $profile = $this->profileLoader->getProfile((string) $normalized['runtime_profile'], 'cli');
        $script = $this->cliPathValidator->validate((string) $normalized['script_path'], $this->profileLoader->getCliAllowlist());
        if (($script['valid'] ?? false) !== true) {
            throw new \RuntimeException((string) ($script['message'] ?? 'Invalid CLI script path.'));
        }

        return [$profile, (string) $script['normalized'], $this->executionPlanFactory->forCli($profile, (string) $script['normalized'], $normalized['script_args'])];
    }

    /**
     * @param array<string, mixed> $validatedTarget
     * @param array<string, mixed> $executionPlan
     * @return array<string, mixed>
     */
    public function buildPlanPayload(RuntimeProfile $profile, array $executionPlan, array $validatedTarget): array
    {
        $warnings = [];
        if ($profile->backendKind === 'xdebug') {
            $this->xdebugLaunchSettingsBuilder->validateProfile($profile);
        }

        $resolvedCommand = $profile->backendKind === 'xdebug'
            ? $this->xdebugLaunchSettingsBuilder->buildCommand($profile, $executionPlan)
            : array_values(array_merge($executionPlan['launcher'] ?? [], $executionPlan['command'] ?? []));

        if ($profile->backendKind !== 'xdebug') {
            $warnings[] = [
                'code' => 'MOCK_BACKEND_ONLY',
                'message' => 'This plan uses the mock backend; no real debugger connection will be attempted.',
            ];
        }

        return [
            'target' => $validatedTarget,
            'validated_target' => $validatedTarget,
            'runtime_profile' => [
                'profile_name' => $profile->profileName,
                'launcher_kind' => $profile->launcherKind,
                'backend_kind' => $profile->backendKind,
                'execution_transport' => $profile->executionTransport,
                'working_directory' => $profile->workingDirectory,
            ],
            'execution' => [
                'allowed' => true,
                'launcher' => $executionPlan['launcher'],
                'command' => $resolvedCommand,
                'cwd' => $executionPlan['cwd'],
                'listener' => [
                    'bind_address' => $profile->listenerBindAddress,
                    'client_host' => $profile->xdebugClientHost,
                    'client_port' => $profile->xdebugClientPort,
                ],
            ],
            'warnings' => $warnings,
        ];
    }
}
