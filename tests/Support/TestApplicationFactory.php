<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\Support;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\debug_backend\MockDebugBackend;
use MoodleDebug\runtime\CliPathValidator;
use MoodleDebug\runtime\ExecutionPlanFactory;
use MoodleDebug\runtime\PathMapper;
use MoodleDebug\runtime\PHPUnitSelectorValidator;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\server\Application;
use MoodleDebug\server\MoodleContextMapper;
use MoodleDebug\server\SummaryBuilder;
use MoodleDebug\session_store\FileArtifactSessionStore;

final class TestApplicationFactory
{
    public static function create(string $repoRoot, string $storageDirectory, FixedClock $clock): Application
    {
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json');

        return new Application(
            schemaValidator: new SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json'),
            profileLoader: $profileLoader,
            backend: new MockDebugBackend($clock),
            sessionStore: new FileArtifactSessionStore(
                $storageDirectory,
                $clock,
                $profileLoader->getSessionTtl(),
                $profileLoader->getArtifactBytesLimit(),
            ),
            selectorValidator: new PHPUnitSelectorValidator(),
            cliPathValidator: new CliPathValidator(),
            executionPlanFactory: new ExecutionPlanFactory(),
            pathMapper: new PathMapper(),
            contextMapper: new MoodleContextMapper(),
            summaryBuilder: new SummaryBuilder(),
            clock: $clock,
        );
    }
}
