<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\Support;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\contracts\RuntimeSchemaValidator;
use MoodleDebug\debug_backend\MockDebugBackend;
use MoodleDebug\runtime\CliPathValidator;
use MoodleDebug\runtime\CodexEnvironmentLoader;
use MoodleDebug\runtime\ExecutionPlanFactory;
use MoodleDebug\runtime\PathMapper;
use MoodleDebug\runtime\PHPUnitSelectorValidator;
use MoodleDebug\runtime\RuntimeApplication;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\debug_backend\XdebugLaunchSettingsBuilder;
use MoodleDebug\server\Application;
use MoodleDebug\server\MoodleContextMapper;
use MoodleDebug\server\SummaryBuilder;
use MoodleDebug\session_store\FileArtifactSessionStore;

final class TestApplicationFactory
{
    public static function create(string $repoRoot, string $storageDirectory, FixedClock $clock): Application
    {
        $environmentLoader = new CodexEnvironmentLoader($repoRoot, []);
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json', $environmentLoader);
        $sessionStore = new FileArtifactSessionStore(
            $storageDirectory,
            $clock,
            $profileLoader->getSessionTtl(),
            $profileLoader->getArtifactBytesLimit(),
        );
        $selectorValidator = new PHPUnitSelectorValidator();
        $cliPathValidator = new CliPathValidator();
        $executionPlanFactory = new ExecutionPlanFactory();
        $pathMapper = new PathMapper();
        $contextMapper = new MoodleContextMapper();
        $summaryBuilder = new SummaryBuilder();

        return new Application(
            schemaValidator: new SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json'),
            profileLoader: $profileLoader,
            backend: new MockDebugBackend($clock),
            sessionStore: $sessionStore,
            selectorValidator: $selectorValidator,
            cliPathValidator: $cliPathValidator,
            executionPlanFactory: $executionPlanFactory,
            pathMapper: $pathMapper,
            contextMapper: $contextMapper,
            summaryBuilder: $summaryBuilder,
            clock: $clock,
        );
    }

    public static function createRuntime(string $repoRoot, string $storageDirectory, FixedClock $clock): RuntimeApplication
    {
        $environmentLoader = new CodexEnvironmentLoader($repoRoot, []);
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json', $environmentLoader);
        $sessionStore = new FileArtifactSessionStore(
            $storageDirectory,
            $clock,
            $profileLoader->getSessionTtl(),
            $profileLoader->getArtifactBytesLimit(),
        );
        $selectorValidator = new PHPUnitSelectorValidator();
        $cliPathValidator = new CliPathValidator();
        $executionPlanFactory = new ExecutionPlanFactory();
        $contextMapper = new MoodleContextMapper();
        $summaryBuilder = new SummaryBuilder();

        return new RuntimeApplication(
            repoRoot: $repoRoot,
            schemaValidator: new RuntimeSchemaValidator($repoRoot . '/docs/moodle_debug/schemas/runtime_contract.schema.json'),
            application: self::create($repoRoot, $storageDirectory, $clock),
            profileLoader: $profileLoader,
            sessionStore: $sessionStore,
            selectorValidator: $selectorValidator,
            cliPathValidator: $cliPathValidator,
            executionPlanFactory: $executionPlanFactory,
            summaryBuilder: $summaryBuilder,
            contextMapper: $contextMapper,
            xdebugLaunchSettingsBuilder: new XdebugLaunchSettingsBuilder(),
            environmentLoader: $environmentLoader,
            clock: $clock,
        );
    }
}
