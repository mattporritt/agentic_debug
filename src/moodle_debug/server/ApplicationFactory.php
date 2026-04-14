<?php

declare(strict_types=1);

namespace MoodleDebug\server;

use MoodleDebug\contracts\SchemaValidator;
use MoodleDebug\debug_backend\MockDebugBackend;
use MoodleDebug\runtime\CliPathValidator;
use MoodleDebug\runtime\ExecutionPlanFactory;
use MoodleDebug\runtime\PathMapper;
use MoodleDebug\runtime\PHPUnitSelectorValidator;
use MoodleDebug\runtime\RuntimeProfileLoader;
use MoodleDebug\runtime\SystemClock;
use MoodleDebug\session_store\FileArtifactSessionStore;

final class ApplicationFactory
{
    public function create(string $repoRoot): Application
    {
        $clock = new SystemClock();
        $profileLoader = new RuntimeProfileLoader($repoRoot . '/config/runtime_profiles.json');

        return new Application(
            schemaValidator: new SchemaValidator($repoRoot . '/docs/moodle_debug/schemas/moodle_debug.schema.json'),
            profileLoader: $profileLoader,
            backend: new MockDebugBackend($clock),
            sessionStore: new FileArtifactSessionStore(
                $repoRoot . '/_smoke_test/moodle_debug_sessions',
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
