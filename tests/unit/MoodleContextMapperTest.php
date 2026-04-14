<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\server\MoodleContextMapper;
use PHPUnit\Framework\TestCase;

final class MoodleContextMapperTest extends TestCase
{
    public function testMapsPluginProductionFramesAheadOfPhpunitHarness(): void
    {
        $mapper = new MoodleContextMapper();

        $mapping = $mapper->map(
            '/tmp/moodle',
            [
                [
                    'index' => 0,
                    'file' => '/tmp/moodle/mod/assign/classes/grading_manager.php',
                    'line' => 87,
                    'class' => 'mod_assign\\grading_manager',
                    'function' => 'apply_grade',
                ],
                [
                    'index' => 1,
                    'file' => '/tmp/moodle/mod/assign/tests/grading_test.php',
                    'line' => 52,
                    'class' => 'mod_assign\\tests\\grading_test',
                    'function' => 'test_grade_submission',
                ],
                [
                    'index' => 2,
                    'file' => '/tmp/moodle/lib/phpunit/classes/advanced_testcase.php',
                    'line' => 410,
                    'class' => 'advanced_testcase',
                    'function' => 'runTest',
                ],
            ],
            [
                'type' => 'coding_exception',
                'file' => '/tmp/moodle/mod/assign/classes/grading_manager.php',
                'line' => 87,
            ],
            ['test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission'],
        );

        self::assertSame('phpunit', $mapping['execution_context']);
        self::assertSame(0, $mapping['probable_fault_frame_index']);
        self::assertSame('plugin_logic', $mapping['likely_issue']['category']);
        self::assertSame('high', $mapping['likely_issue']['confidence']);
        self::assertSame('production_frame', $mapping['annotations'][0]['frame_kind']);
        self::assertSame('test_frame', $mapping['annotations'][1]['frame_kind']);
        self::assertSame([0], $mapping['candidate_fault_frame_indexes']);
    }

    public function testClassifiesCliImportAsCliWorkflow(): void
    {
        $mapper = new MoodleContextMapper();

        $mapping = $mapper->map(
            '/tmp/moodle',
            [
                [
                    'index' => 0,
                    'file' => '/tmp/moodle/admin/cli/import.php',
                    'line' => 85,
                    'function' => '{main}',
                ],
                [
                    'index' => 1,
                    'file' => '/tmp/moodle/lib/clilib.php',
                    'line' => 220,
                    'function' => 'cli_error',
                ],
            ],
            null,
            [],
        );

        self::assertSame('cli_admin', $mapping['execution_context']);
        self::assertSame('cli_workflow', $mapping['likely_issue']['category']);
        self::assertSame('execution_context_frame', $mapping['annotations'][0]['frame_kind']);
        self::assertSame('cli_import', $mapping['annotations'][0]['subsystem']);
        self::assertSame(0, $mapping['fault_ranking'][0]['frame_index']);
    }

    public function testRecognisesExternalApiFrames(): void
    {
        $mapper = new MoodleContextMapper();

        $mapping = $mapper->map(
            '/tmp/moodle',
            [[
                'index' => 0,
                'file' => '/tmp/moodle/admin/classes/external/set_block_protection.php',
                'line' => 31,
                'class' => 'core_admin\\external\\set_block_protection',
                'function' => 'execute',
            ]],
            null,
            [],
        );

        self::assertSame('external_api', $mapping['execution_context']);
        self::assertSame('external_api', $mapping['annotations'][0]['category']);
        self::assertSame('core_admin', $mapping['annotations'][0]['component']);
    }

    public function testUsesPhpunitSelectorHintWhenStackIsHarnessHeavy(): void
    {
        $mapper = new MoodleContextMapper();

        $mapping = $mapper->map(
            '/tmp/moodle',
            [[
                'index' => 0,
                'file' => '/tmp/moodle/vendor/phpunit/phpunit/src/Util/Test.php',
                'line' => 39,
                'class' => 'PHPUnit\\Util\\Test',
                'function' => 'currentTestCase',
            ]],
            null,
            ['test_ref' => 'core_admin\\external\\set_block_protection_test::test_execute_no_login'],
        );

        self::assertSame('test_only', $mapping['annotations'][0]['category']);
        self::assertSame('external_api', $mapping['likely_issue']['category']);
        self::assertSame('core_admin', $mapping['likely_issue']['component']);
        self::assertSame('medium', $mapping['likely_issue']['confidence']);
    }

    public function testFallsBackToLowConfidenceUnknownWhenSignalsAreWeak(): void
    {
        $mapper = new MoodleContextMapper();

        $mapping = $mapper->map(
            '/tmp/moodle',
            [[
                'index' => 0,
                'file' => '',
                'line' => 0,
                'function' => 'call_user_func',
            ]],
            null,
            [],
        );

        self::assertSame('bootstrap_infrastructure', $mapping['likely_issue']['category']);
        self::assertSame('low', $mapping['likely_issue']['confidence']);
        self::assertSame('container_frame', $mapping['annotations'][0]['frame_kind']);
    }
}
