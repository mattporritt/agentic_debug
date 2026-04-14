<?php

declare(strict_types=1);

namespace MoodleDebug\server;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\contracts\RuntimeSchemaValidator;
use MoodleDebug\debug_backend\DbgpXmlParser;
use MoodleDebug\debug_backend\MockDebugBackend;
use MoodleDebug\debug_backend\RoutingDebugBackend;
use MoodleDebug\debug_backend\XdebugDebugBackend;
use MoodleDebug\debug_backend\XdebugLaunchSettingsBuilder;
use MoodleDebug\runtime\CliPathValidator;
use MoodleDebug\runtime\CodexEnvironmentLoader;
use MoodleDebug\runtime\ExecutionPlanFactory;
use MoodleDebug\runtime\PathMapper;
use MoodleDebug\runtime\PHPUnitSelectorValidator;
use MoodleDebug\runtime\RuntimeApplication;
use MoodleDebug\runtime\RuntimeCli;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\runtime\SystemClock;
use MoodleDebug\session_store\FileArtifactSessionStore;

final class ApplicationFactory
{
    public function create(string $repoRoot): Application
    {
        $clock = new SystemClock();
        $environmentLoader = new CodexEnvironmentLoader($repoRoot);
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json', $environmentLoader);
        $backend = new RoutingDebugBackend(
            new MockDebugBackend($clock),
            new XdebugDebugBackend($clock, new XdebugLaunchSettingsBuilder(), new DbgpXmlParser()),
        );
        $sessionStore = new FileArtifactSessionStore(
            $repoRoot . '/_smoke_test/moodle_debug_sessions',
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
            backend: $backend,
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

    public function createRuntimeApplication(string $repoRoot): RuntimeApplication
    {
        $clock = new SystemClock();
        $environmentLoader = new CodexEnvironmentLoader($repoRoot);
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json', $environmentLoader);
        $selectorValidator = new PHPUnitSelectorValidator();
        $cliPathValidator = new CliPathValidator();
        $executionPlanFactory = new ExecutionPlanFactory();
        $pathMapper = new PathMapper();
        $contextMapper = new MoodleContextMapper();
        $summaryBuilder = new SummaryBuilder();
        $sessionStore = new FileArtifactSessionStore(
            $repoRoot . '/_smoke_test/moodle_debug_sessions',
            $clock,
            $profileLoader->getSessionTtl(),
            $profileLoader->getArtifactBytesLimit(),
        );
        $application = $this->create($repoRoot);

        return new RuntimeApplication(
            repoRoot: $repoRoot,
            schemaValidator: new RuntimeSchemaValidator($repoRoot . '/docs/moodle_debug/schemas/runtime_contract.schema.json'),
            application: $application,
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

    public function createRuntimeCli(string $repoRoot): RuntimeCli
    {
        return new RuntimeCli($this->createRuntimeApplication($repoRoot));
    }
}
