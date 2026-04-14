<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class ExecutionPlanFactory
{
    /**
     * @param array{normalized:string,method_name:string,guessed_test_file:?string} $selector
     * @return array<string, mixed>
     */
    public function forPhpunit(RuntimeProfile $profile, array $selector): array
    {
        $testFile = $selector['guessed_test_file'] ?? "{$profile->moodleRoot}/unknown_test.php";

        return [
            'target_type' => 'phpunit',
            'launcher' => $profile->launcherArgv,
            'command' => array_values(array_merge(
                $profile->phpArgv,
                [
                    'vendor/bin/phpunit',
                    '--filter',
                    $selector['method_name'],
                    $testFile,
                ],
            )),
            'cwd' => $profile->workingDirectory,
            'target_reference' => $selector['normalized'],
            'path_mappings' => $profile->pathMappings,
        ];
    }

    /**
     * @param string[] $scriptArgs
     * @return array<string, mixed>
     */
    public function forCli(RuntimeProfile $profile, string $scriptPath, array $scriptArgs): array
    {
        return [
            'target_type' => 'cli',
            'launcher' => $profile->launcherArgv,
            'command' => array_values(array_merge(
                $profile->phpArgv,
                [$scriptPath],
                $scriptArgs,
            )),
            'cwd' => $profile->workingDirectory,
            'target_reference' => $scriptPath,
            'path_mappings' => $profile->pathMappings,
        ];
    }
}
