<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

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
use MoodleDebug\runtime\RuntimeEnvelopeFactory;
use MoodleDebug\runtime\RuntimeHealthReporter;
use MoodleDebug\runtime\RuntimeInvestigationBuilder;
use MoodleDebug\runtime\RuntimePlanBuilder;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\runtime\RuntimeRequestNormalizer;
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
        $summaryBuilder = new SummaryBuilder();
        $runtimeSchemaValidator = new RuntimeSchemaValidator($repoRoot . '/docs/moodle_debug/schemas/runtime_contract.schema.json');

        return new RuntimeApplication(
            repoRoot: $repoRoot,
            schemaValidator: $runtimeSchemaValidator,
            application: self::create($repoRoot, $storageDirectory, $clock),
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
}
