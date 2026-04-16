<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\integration;

use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class RuntimeApplicationTest extends TestCase
{
    public function testRuntimeQueryCanInterpretExistingSession(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_integration_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $execute = $runtime->runtimeQuery([
            'intent' => 'execute_phpunit',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_phpunit',
            'test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission',
        ]);

        self::assertSame('ok', $execute['meta']['status']);
        $sessionId = $execute['results'][0]['source']['session_id'];

        $interpret = $runtime->runtimeQuery([
            'intent' => 'interpret_session',
            'session_id' => $sessionId,
            'summary_depth' => 'detailed',
        ]);

        self::assertSame('interpret_session', $interpret['intent']);
        self::assertSame($sessionId, $interpret['results'][0]['id']);
        self::assertArrayHasKey('investigation', $interpret['results'][0]['content']);
        self::assertSame('plugin_logic', $interpret['results'][0]['content']['investigation']['likely_fault']['issue_category']);
    }

    public function testRuntimeQueryCanGetSessionWithoutFullResult(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_integration_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $execute = $runtime->runtimeQuery([
            'intent' => 'execute_cli',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_cli',
            'script_path' => 'admin/cli/some_script.php',
        ]);

        $sessionId = $execute['results'][0]['source']['session_id'];
        $get = $runtime->runtimeQuery([
            'intent' => 'get_session',
            'session_id' => $sessionId,
            'include_result' => false,
        ]);

        self::assertSame('get_session', $get['intent']);
        self::assertArrayHasKey('session', $get['results'][0]['content']);
        self::assertArrayNotHasKey('result', $get['results'][0]['content']);
    }

    public function testRuntimeQueryCanPlanPhpunitAndCliTargets(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_integration_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $phpunitPlan = $runtime->runtimeQuery([
            'intent' => 'plan_phpunit',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_phpunit',
            'test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission',
        ]);
        $cliPlan = $runtime->runtimeQuery([
            'intent' => 'plan_cli',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_cli',
            'script_path' => 'admin/cli/some_script.php',
            'script_args' => ['--verbose=1'],
        ]);

        self::assertSame('plan_phpunit', $phpunitPlan['intent']);
        self::assertSame('phpunit', $phpunitPlan['results'][0]['content']['plan']['target']['type']);
        self::assertSame('host_exec', $phpunitPlan['results'][0]['content']['plan']['runtime_profile']['execution_transport']);
        self::assertSame('plan_cli', $cliPlan['intent']);
        self::assertSame('cli', $cliPlan['results'][0]['content']['plan']['target']['type']);
        self::assertSame('admin/cli/some_script.php', $cliPlan['results'][0]['content']['plan']['validated_target']['script_path']);
    }

    public function testRuntimeQueryExecuteReturnsMachineFriendlyInvestigationPayload(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_integration_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $execute = $runtime->runtimeQuery([
            'intent' => 'execute_cli',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_cli',
            'script_path' => 'admin/cli/some_script.php',
        ]);

        $investigation = $execute['results'][0]['content']['investigation'];

        self::assertSame('cli_workflow', $investigation['likely_fault']['issue_category']);
        self::assertSame('core_admin', $investigation['likely_fault']['component']);
        self::assertNotEmpty($investigation['candidate_frames']);
        self::assertNotEmpty($investigation['inspection_targets']);
        self::assertNotEmpty($investigation['rerun_command']);
    }

    public function testHealthReturnsStructuredSubsystemStatuses(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_integration_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $health = $runtime->health([]);

        self::assertSame('health', $health['intent']);
        self::assertSame('health_report', $health['results'][0]['type']);
        self::assertNotEmpty($health['results'][0]['content']['subsystems']);
    }
}
