<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\runtime\RuntimeInvestigationBuilder;
use PHPUnit\Framework\TestCase;

final class RuntimeInvestigationBuilderTest extends TestCase
{
    public function testBuildExtractsLikelyFaultAndInspectionTargets(): void
    {
        $builder = new RuntimeInvestigationBuilder();

        $payload = [
            'session' => [
                'session' => [
                    'session_id' => 'mds_test_session',
                    'created_at' => '2026-04-15T00:00:00+00:00',
                ],
                'runtime_profile' => [
                    'profile_name' => 'default_phpunit',
                ],
                'target_type' => 'phpunit',
            ],
            'result' => [
                'target' => [
                    'type' => 'phpunit',
                    'normalized_test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission',
                ],
                'summary' => [
                    'confidence' => 'high',
                    'probable_fault' => [
                        'component' => 'mod_assign',
                    ],
                ],
                'frames' => [[
                    'index' => 0,
                    'file' => '/tmp/moodle/mod/assign/classes/grading_manager.php',
                    'line' => 87,
                    'class' => 'mod_assign\\grading_manager',
                    'function' => 'apply_grade',
                ]],
                'moodle_mapping' => [
                    'probable_fault_frame_index' => 0,
                    'fault_ranking' => [[
                        'frame_index' => 0,
                        'confidence' => 'high',
                        'rationale' => 'Production Moodle frame.',
                    ]],
                    'likely_issue' => [
                        'component' => 'mod_assign',
                        'subsystem' => 'activity_module',
                        'category' => 'plugin_logic',
                        'confidence' => 'high',
                    ],
                ],
                'rerun' => [
                    'command' => ['php', 'vendor/bin/phpunit'],
                ],
            ],
        ];

        $investigation = $builder->build($payload);

        self::assertSame('/tmp/moodle/mod/assign/classes/grading_manager.php', $investigation['likely_fault']['file']);
        self::assertSame('plugin_logic', $investigation['likely_fault']['issue_category']);
        self::assertCount(3, $investigation['inspection_targets']);
        self::assertSame('moodle_component', $investigation['inspection_targets'][2]['kind']);
    }
}
