<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\server\SummaryBuilder;
use PHPUnit\Framework\TestCase;

final class SummaryBuilderTest extends TestCase
{
    public function testBuildsBreakpointSummaryWithoutExceptionLanguage(): void
    {
        $builder = new SummaryBuilder();

        $summary = $builder->build(
            ['type' => 'cli'],
            ['reason' => 'breakpoint', 'stopped_at' => '2026-04-15T00:00:00+00:00'],
            [['file' => '/tmp/moodle/admin/cli/import.php', 'line' => 85, 'function' => '{main}', 'index' => 0]],
            [
                'annotations' => [[
                    'frame_index' => 0,
                    'component' => 'core_admin',
                    'category' => 'cli_workflow',
                    'frame_kind' => 'execution_context_frame',
                    'confidence' => 'medium',
                ]],
                'probable_fault_frame_index' => 0,
                'execution_context' => 'cli_admin',
                'likely_issue' => [
                    'category' => 'cli_workflow',
                    'confidence' => 'medium',
                    'rationale' => 'Top-ranked frame is cli_workflow with medium confidence.',
                ],
                'fault_ranking' => [[
                    'frame_index' => 0,
                    'confidence' => 'medium',
                    'rationale' => 'Classified as execution_context_frame; component core_admin; subsystem cli_import.',
                ]],
            ],
        );

        self::assertSame('Breakpoint stop near core_admin in cli_workflow', $summary['headline']);
        self::assertContains('Stop reason: breakpoint', $summary['facts']);
        self::assertContains('Likely issue category: cli_workflow', $summary['facts']);
        self::assertNotContains('Exception type: moodle_exception', $summary['facts']);
        self::assertStringContainsString('suggestive, not conclusive', $summary['probable_fault']['reason']);
    }

    public function testIncludesExceptionFactWhenExceptionPayloadExists(): void
    {
        $builder = new SummaryBuilder();

        $summary = $builder->build(
            ['type' => 'phpunit'],
            [
                'reason' => 'exception',
                'stopped_at' => '2026-04-15T00:00:00+00:00',
                'exception' => ['type' => 'coding_exception', 'message' => 'Boom'],
            ],
            [['file' => '/tmp/moodle/mod/assign/tests/grading_test.php', 'line' => 12, 'function' => 'test_example', 'index' => 0]],
            [
                'annotations' => [[
                    'frame_index' => 0,
                    'component' => 'mod_assign',
                    'category' => 'plugin_logic',
                    'frame_kind' => 'production_frame',
                    'confidence' => 'high',
                ]],
                'probable_fault_frame_index' => 0,
                'execution_context' => 'phpunit',
                'likely_issue' => [
                    'category' => 'plugin_logic',
                    'confidence' => 'high',
                    'rationale' => 'Top-ranked frame is plugin_logic with high confidence.',
                ],
                'fault_ranking' => [[
                    'frame_index' => 0,
                    'confidence' => 'high',
                    'rationale' => 'Classified as production_frame; component mod_assign; subsystem activity_module.',
                ]],
            ],
        );

        self::assertContains('Exception type: coding_exception', $summary['facts']);
        self::assertSame('Exception stop near mod_assign in plugin_logic', $summary['headline']);
        self::assertStringContainsString('weighting Moodle production code above harness and infrastructure frames', $summary['probable_fault']['reason']);
    }

    public function testUsesLowConfidenceLanguageWhenSignalsAreWeak(): void
    {
        $builder = new SummaryBuilder();

        $summary = $builder->build(
            ['type' => 'phpunit', 'normalized_test_ref' => 'core_user\\tests\\profile_test::test_update_profile'],
            ['reason' => 'breakpoint', 'stopped_at' => '2026-04-15T00:00:00+00:00'],
            [['file' => '[internal]', 'line' => 0, 'function' => 'call_user_func', 'index' => 0]],
            [
                'annotations' => [[
                    'frame_index' => 0,
                    'component' => 'unknown',
                    'category' => 'unknown',
                    'frame_kind' => 'container_frame',
                    'confidence' => 'low',
                ]],
                'probable_fault_frame_index' => 0,
                'execution_context' => 'unknown',
                'likely_issue' => [
                    'category' => 'unknown',
                    'confidence' => 'low',
                    'rationale' => 'No ranked Moodle-specific frame was identified.',
                ],
                'fault_ranking' => [[
                    'frame_index' => 0,
                    'confidence' => 'low',
                    'rationale' => 'Classified as container_frame.',
                ]],
            ],
        );

        self::assertSame('Breakpoint stop near unknown in unknown', $summary['headline']);
        self::assertStringContainsString('does not strongly identify a Moodle-specific fault area yet', $summary['inferences'][0]['statement']);
        self::assertSame('low', $summary['confidence']);
    }

    public function testUsesLikelyIssueFallbackWhenPhpunitStopIsHarnessHeavy(): void
    {
        $builder = new SummaryBuilder();

        $summary = $builder->build(
            ['type' => 'phpunit', 'normalized_test_ref' => 'core_admin\\external\\set_block_protection_test::test_execute_no_login'],
            ['reason' => 'breakpoint', 'stopped_at' => '2026-04-15T00:00:00+00:00'],
            [['file' => '/tmp/moodle/vendor/phpunit/phpunit/src/Util/Test.php', 'line' => 39, 'function' => 'currentTestCase', 'index' => 0]],
            [
                'annotations' => [[
                    'frame_index' => 0,
                    'component' => 'unknown',
                    'category' => 'test_only',
                    'frame_kind' => 'test_frame',
                    'confidence' => 'low',
                ]],
                'probable_fault_frame_index' => 0,
                'execution_context' => 'phpunit',
                'likely_issue' => [
                    'category' => 'external_api',
                    'component' => 'core_admin',
                    'subsystem' => 'external_api',
                    'confidence' => 'medium',
                    'rationale' => 'The PHPUnit selector suggests core_admin external API code.',
                ],
                'fault_ranking' => [[
                    'frame_index' => 0,
                    'confidence' => 'low',
                    'rationale' => 'Classified as test_frame.',
                ]],
            ],
        );

        self::assertSame('Breakpoint stop near core_admin in external_api', $summary['headline']);
        self::assertStringContainsString('selector: core_admin\\external\\set_block_protection_test::test_execute_no_login', implode(' ', $summary['suggested_next_actions']));
        self::assertStringContainsString('Inspect the Moodle area suggested by the selector: core_admin', implode(' ', $summary['suggested_next_actions']));
    }
}
