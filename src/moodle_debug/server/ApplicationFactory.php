<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

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
use MoodleDebug\runtime\RuntimeEnvelopeFactory;
use MoodleDebug\runtime\RuntimeHealthReporter;
use MoodleDebug\runtime\RuntimeInvestigationBuilder;
use MoodleDebug\runtime\RuntimePlanBuilder;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\runtime\RuntimeRequestNormalizer;
use MoodleDebug\runtime\SystemClock;
use MoodleDebug\session_store\FileArtifactSessionStore;

/**
 * Wires concrete production services for both MCP-facing and runtime-facing
 * entrypoints.
 *
 * Keeping composition in one place makes it much easier to evolve the debugger
 * without hidden differences between CLI, MCP, and test wiring.
 */
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
        $summaryBuilder = new SummaryBuilder();
        $sessionStore = new FileArtifactSessionStore(
            $repoRoot . '/_smoke_test/moodle_debug_sessions',
            $clock,
            $profileLoader->getSessionTtl(),
            $profileLoader->getArtifactBytesLimit(),
        );
        $application = $this->create($repoRoot);
        $runtimeSchemaValidator = new RuntimeSchemaValidator($repoRoot . '/docs/moodle_debug/schemas/runtime_contract.schema.json');

        return new RuntimeApplication(
            repoRoot: $repoRoot,
            schemaValidator: $runtimeSchemaValidator,
            application: $application,
            sessionStore: $sessionStore,
            summaryBuilder: $summaryBuilder,
            requestNormalizer: new RuntimeRequestNormalizer(),
            planBuilder: new RuntimePlanBuilder(
                $profileLoader,
                $selectorValidator,
                $cliPathValidator,
                $executionPlanFactory,
                new XdebugLaunchSettingsBuilder(),
            ),
            investigationBuilder: new RuntimeInvestigationBuilder(),
            healthReporter: new RuntimeHealthReporter(
                $repoRoot,
                $profileLoader,
                $environmentLoader,
                $clock,
            ),
            envelopeFactory: new RuntimeEnvelopeFactory(
                $repoRoot,
                $runtimeSchemaValidator,
                $clock,
            ),
        );
    }

    public function createRuntimeCli(string $repoRoot): RuntimeCli
    {
        return new RuntimeCli($this->createRuntimeApplication($repoRoot));
    }
}
