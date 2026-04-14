<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class RuntimeApplicationTest extends TestCase
{
    public function testRuntimeQueryNormalizesDefaultsForPlanPhpunit(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            sys_get_temp_dir() . '/moodle_debug_runtime_unit_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $response = $runtime->runtimeQuery([
            'intent' => 'plan_phpunit',
            'test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission',
            'moodle_root' => $repoRoot . '/_smoke_test/moodle_fixture',
        ]);

        self::assertSame('default_phpunit', $response['normalized_query']['runtime_profile']);
        self::assertSame(120, $response['normalized_query']['timeout_seconds']);
        self::assertSame(25, $response['normalized_query']['capture_policy']['max_frames']);
    }

    public function testRuntimeQueryRejectsUnsupportedIntent(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            sys_get_temp_dir() . '/moodle_debug_runtime_unit_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $response = $runtime->runtimeQuery([
            'intent' => 'freeform_guess',
        ]);

        self::assertSame('fail', $response['meta']['status']);
        self::assertSame('INVALID_RUNTIME_REQUEST', $response['diagnostics'][0]['code']);
    }

    public function testHealthResponseShapesExpectedSubsystems(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runtime = TestApplicationFactory::createRuntime(
            $repoRoot,
            sys_get_temp_dir() . '/moodle_debug_runtime_unit_' . uniqid('', true),
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $response = $runtime->health([]);
        $subsystems = $response['results'][0]['content']['subsystems'];
        $names = array_map(static fn (array $item): string => $item['name'], $subsystems);

        self::assertContains('config', $names);
        self::assertContains('listener', $names);
        self::assertContains('supported_targets', $names);
    }
}
