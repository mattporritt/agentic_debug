<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\server;

/**
 * Converts raw stack frames into bounded Moodle-aware interpretation signals.
 *
 * The heuristics here are intentionally explicit and conservative. They do not
 * try to "prove" a root cause. Instead they classify frames, rank likely
 * inspection points, and separate directly observed facts from Moodle-specific
 * inferences.
 */
final class MoodleContextMapper
{
    /**
     * Lightweight frame-kind buckets keep ranking explainable and testable.
     */
    private const FRAME_KIND_PRODUCTION = 'production_frame';
    private const FRAME_KIND_TEST = 'test_frame';
    private const FRAME_KIND_FRAMEWORK = 'framework_frame';
    private const FRAME_KIND_BOOTSTRAP = 'bootstrap_frame';
    private const FRAME_KIND_CONTAINER = 'container_frame';
    private const FRAME_KIND_EXECUTION_CONTEXT = 'execution_context_frame';
    private const FRAME_KIND_UNKNOWN = 'unknown';

    private const ISSUE_CORE_LOGIC = 'core_logic';
    private const ISSUE_PLUGIN_LOGIC = 'plugin_logic';
    private const ISSUE_RENDERER_OUTPUT = 'renderer_output';
    private const ISSUE_EXTERNAL_API = 'external_api';
    private const ISSUE_ACCESS_CONTROL = 'access_control';
    private const ISSUE_FORM_PROCESSING = 'form_processing';
    private const ISSUE_CLI_WORKFLOW = 'cli_workflow';
    private const ISSUE_TEST_ONLY = 'test_only';
    private const ISSUE_BOOTSTRAP_INFRASTRUCTURE = 'bootstrap_infrastructure';
    private const ISSUE_EXECUTION_CONTEXT = 'execution_context';
    private const ISSUE_UNKNOWN = 'unknown';

    /**
     * @param array<int, array<string, mixed>> $frames
     * @param array<string, mixed>|null $exception
     * @param array<string, mixed> $testContext
     * @return array<string, mixed>
     */
    public function map(string $moodleRoot, array $frames, ?array $exception = null, array $testContext = []): array
    {
        $annotations = [];
        $faultRanking = [];
        $executionContext = $this->detectExecutionContext($frames, $exception, $testContext);

        foreach ($frames as $frame) {
            $annotation = $this->annotateFrame($frame, $executionContext, $testContext, $exception);
            $annotations[] = $annotation;
            $faultRanking[] = [
                'frame_index' => $annotation['frame_index'],
                // Ranking is intentionally explainable: each score is derived
                // from a small set of named signals in scoreFrame().
                'score' => $this->scoreFrame($annotation, $exception, $executionContext),
                'confidence' => $annotation['confidence'],
                'rationale' => $this->buildRankingRationale($annotation),
            ];
        }

        usort($faultRanking, static function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return $left['frame_index'] <=> $right['frame_index'];
            }

            return $right['score'] <=> $left['score'];
        });

        $faultRanking = array_values(array_slice($faultRanking, 0, 5));
        $probableFaultFrameIndex = $faultRanking[0]['frame_index'] ?? ($frames[0]['index'] ?? 0);
        $candidateFaultFrames = array_values(array_map(
            static fn (array $candidate): int => (int) $candidate['frame_index'],
            array_filter($faultRanking, static fn (array $candidate): bool => $candidate['score'] >= 25)
        ));
        if ($candidateFaultFrames === []) {
            $candidateFaultFrames = [(int) $probableFaultFrameIndex];
        }

        $annotationsByFrame = [];
        foreach ($annotations as $annotation) {
            $annotationsByFrame[$annotation['frame_index']] = $annotation;
        }
        $primaryAnnotation = $annotationsByFrame[$probableFaultFrameIndex] ?? null;
        $targetHint = $this->buildTargetHint($testContext);
        $likelyIssue = $this->buildLikelyIssue($primaryAnnotation, $targetHint);

        return [
            'annotations' => $annotations,
            'execution_context' => $executionContext,
            'candidate_fault_frame_indexes' => array_values(array_unique($candidateFaultFrames)),
            'probable_fault_frame_index' => (int) $probableFaultFrameIndex,
            'fault_ranking' => array_map(
                static fn (array $candidate): array => [
                    'frame_index' => (int) $candidate['frame_index'],
                    'confidence' => (string) $candidate['confidence'],
                    'rationale' => (string) $candidate['rationale'],
                ],
                $faultRanking
            ),
            'likely_issue' => $likelyIssue,
        ];
    }

    /**
     * @param array<string, mixed> $frame
     * @param array<string, mixed> $testContext
     * @param array<string, mixed>|null $exception
     * @return array<string, mixed>
     */
    private function annotateFrame(array $frame, string $executionContext, array $testContext, ?array $exception): array
    {
        $file = (string) ($frame['file'] ?? '');
        $class = (string) ($frame['class'] ?? '');
        $function = (string) ($frame['function'] ?? '');
        $frameIndex = (int) ($frame['index'] ?? 0);
        $component = $this->extractComponent($file, $class);
        $frameKind = $this->classifyFrameKind($file, $class, $function, $executionContext);
        $subsystem = $this->inferSubsystem($file, $class, $function, $executionContext);
        $issueCategory = $this->inferIssueCategory($component, $subsystem, $frameKind, $executionContext);

        $facts = [];
        $inferences = [];
        $confidence = 'low';

        if ($component !== null) {
            $facts[] = "Component-like path or namespace evidence points to {$component}";
            $inferences[] = "Likely Moodle component: {$component}";
            $confidence = str_starts_with($component, 'core_') || $component === 'core' ? 'medium' : 'high';
        }

        if ($subsystem !== 'unknown') {
            $facts[] = "Frame matches Moodle subsystem pattern: {$subsystem}";
            $inferences[] = "Likely subsystem area: {$subsystem}";
            if ($confidence === 'low') {
                $confidence = 'medium';
            }
        }

        if ($frameKind !== self::FRAME_KIND_UNKNOWN) {
            $facts[] = "Frame classified as {$frameKind}";
        }

        if ($exception !== null && ($exception['file'] ?? null) === $file) {
            $facts[] = 'Frame file matches the recorded exception file';
            if ($confidence !== 'high') {
                $confidence = 'high';
            }
        }

        return [
            'frame_index' => $frameIndex,
            'component' => $component ?? 'unknown',
            'subsystem' => $subsystem,
            'category' => $issueCategory,
            'frame_kind' => $frameKind,
            'confidence' => $confidence,
            'facts' => $facts,
            'inferences' => $inferences,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $frames
     * @param array<string, mixed>|null $exception
     * @param array<string, mixed> $testContext
     */
    private function detectExecutionContext(array $frames, ?array $exception, array $testContext): string
    {
        if (isset($testContext['test_ref'])) {
            return 'phpunit';
        }

        foreach ($frames as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if (str_contains($file, '/admin/cli/')) {
                return 'cli_admin';
            }
            if (str_contains($file, '/classes/external/') || str_ends_with($file, '/externallib.php')) {
                return 'external_api';
            }
            if (str_contains($file, '/classes/output/') || str_ends_with($file, '/renderer.php')) {
                return 'renderer';
            }
            if (str_contains($file, '/accesslib.php')) {
                return 'access_control';
            }
            if (str_contains($file, '/form/') || str_contains($file, '/mform') || str_contains((string) ($frame['class'] ?? ''), 'moodleform')) {
                return 'form_processing';
            }
        }

        $exceptionFile = (string) ($exception['file'] ?? '');
        if (str_contains($exceptionFile, '/admin/cli/')) {
            return 'cli_admin';
        }

        return 'unknown';
    }

    private function extractComponent(string $file, string $class): ?string
    {
        $patterns = [
            '#/mod/([^/]+)/#' => static fn (array $m): string => 'mod_' . $m[1],
            '#/blocks/([^/]+)/#' => static fn (array $m): string => 'block_' . $m[1],
            '#/admin/tool/([^/]+)/#' => static fn (array $m): string => 'tool_' . $m[1],
            '#/report/([^/]+)/#' => static fn (array $m): string => 'report_' . $m[1],
            '#/theme/([^/]+)/#' => static fn (array $m): string => 'theme_' . $m[1],
            '#/course/format/([^/]+)/#' => static fn (array $m): string => 'format_' . $m[1],
            '#/question/type/([^/]+)/#' => static fn (array $m): string => 'qtype_' . $m[1],
        ];

        foreach ($patterns as $pattern => $builder) {
            if (preg_match($pattern, $file, $matches) === 1) {
                return $builder($matches);
            }
        }

        $corePatterns = [
            '#/admin/#' => 'core_admin',
            '#/user/#' => 'core_user',
            '#/course/#' => 'core_course',
            '#/question/#' => 'core_question',
            '#/webservice/#' => 'core_webservice',
        ];

        foreach ($corePatterns as $pattern => $component) {
            if (preg_match($pattern, $file) === 1) {
                return $component;
            }
        }

        if (str_starts_with($class, 'core_')) {
            $parts = explode('\\', $class);
            return $parts[0];
        }

        if (str_contains($file, '/lib/')) {
            return 'core';
        }

        return null;
    }

    private function classifyFrameKind(string $file, string $class, string $function, string $executionContext): string
    {
        if (str_contains($file, '/tests/') || str_contains($file, '/phpunit/') || str_contains($class, '\\tests\\') || str_starts_with($class, 'advanced_testcase')) {
            return self::FRAME_KIND_TEST;
        }

        if (str_contains($file, '/vendor/phpunit/') || str_contains($file, '/lib/phpunit/') || str_contains($file, '/vendor/') || str_contains($class, 'PHPUnit\\')) {
            return self::FRAME_KIND_FRAMEWORK;
        }

        if (str_ends_with($file, '/config.php') || str_contains($file, '/lib/setup.php') || str_contains($file, '/lib/clilib.php')) {
            return self::FRAME_KIND_BOOTSTRAP;
        }

        if (str_contains($file, '/admin/cli/') && $function === '{main}') {
            return self::FRAME_KIND_EXECUTION_CONTEXT;
        }

        if ($executionContext === 'phpunit' && str_contains($file, '/tests/')) {
            return self::FRAME_KIND_TEST;
        }

        if ($file === '' || $file === '[internal]' || str_starts_with($file, 'php://')) {
            return self::FRAME_KIND_CONTAINER;
        }

        if (str_starts_with($file, '/')) {
            return self::FRAME_KIND_PRODUCTION;
        }

        return self::FRAME_KIND_UNKNOWN;
    }

    private function inferSubsystem(string $file, string $class, string $function, string $executionContext): string
    {
        return match (true) {
            str_contains($file, '/classes/external/'), str_ends_with($file, '/externallib.php') => 'external_api',
            str_contains($file, '/classes/output/'), str_ends_with($file, '/renderer.php') => 'output_rendering',
            str_contains($file, '/accesslib.php'), str_contains($function, 'require_capability'), str_contains($function, 'has_capability') => 'access_control',
            str_contains($file, '/form/'), str_contains($file, '/mform'), str_contains($class, 'moodleform') => 'form_processing',
            str_contains($file, '/admin/cli/'), str_contains($file, '/admin/tool/'), $executionContext === 'cli_admin' => str_contains($file, 'import') ? 'cli_import' : 'cli_admin',
            str_contains($file, '/mod/') => 'activity_module',
            str_contains($file, '/blocks/') => 'block_plugin',
            str_contains($file, '/theme/') => 'theme_rendering',
            str_contains($file, '/question/') => 'question_bank',
            str_contains($file, '/tests/'), str_contains($class, '\\tests\\') => 'test_harness',
            str_contains($file, '/lib/'), str_contains($file, '/config.php') => 'bootstrap_framework',
            default => 'unknown',
        };
    }

    private function inferIssueCategory(?string $component, string $subsystem, string $frameKind, string $executionContext): string
    {
        if ($frameKind === self::FRAME_KIND_TEST) {
            return self::ISSUE_TEST_ONLY;
        }

        if (in_array($frameKind, [self::FRAME_KIND_FRAMEWORK, self::FRAME_KIND_BOOTSTRAP, self::FRAME_KIND_CONTAINER], true)) {
            return self::ISSUE_BOOTSTRAP_INFRASTRUCTURE;
        }

        if ($frameKind === self::FRAME_KIND_EXECUTION_CONTEXT) {
            return $executionContext === 'cli_admin' ? self::ISSUE_CLI_WORKFLOW : self::ISSUE_EXECUTION_CONTEXT;
        }

        return match ($subsystem) {
            'output_rendering', 'theme_rendering' => self::ISSUE_RENDERER_OUTPUT,
            'external_api' => self::ISSUE_EXTERNAL_API,
            'access_control' => self::ISSUE_ACCESS_CONTROL,
            'form_processing' => self::ISSUE_FORM_PROCESSING,
            'cli_import', 'cli_admin' => self::ISSUE_CLI_WORKFLOW,
            default => $component !== null && !str_starts_with($component, 'core') ? self::ISSUE_PLUGIN_LOGIC : (($executionContext === 'cli_admin') ? self::ISSUE_CLI_WORKFLOW : self::ISSUE_CORE_LOGIC),
        };
    }

    /**
     * Score signals are deliberately small and named so later tuning remains legible.
     *
     * @param array<string, mixed> $annotation
     * @param array<string, mixed>|null $exception
     */
    private function scoreFrame(array $annotation, ?array $exception, string $executionContext): int
    {
        $score = 0;
        $frameKind = (string) $annotation['frame_kind'];
        $category = (string) $annotation['category'];
        $component = (string) ($annotation['component'] ?? 'unknown');
        $facts = $annotation['facts'] ?? [];

        $score += match ($frameKind) {
            self::FRAME_KIND_PRODUCTION => 60,
            self::FRAME_KIND_EXECUTION_CONTEXT => 35,
            self::FRAME_KIND_TEST => -35,
            self::FRAME_KIND_FRAMEWORK => -45,
            self::FRAME_KIND_BOOTSTRAP => -50,
            self::FRAME_KIND_CONTAINER => -40,
            default => 0,
        };

        $score += match ($category) {
            self::ISSUE_PLUGIN_LOGIC, self::ISSUE_CORE_LOGIC => 25,
            self::ISSUE_EXTERNAL_API, self::ISSUE_RENDERER_OUTPUT, self::ISSUE_ACCESS_CONTROL, self::ISSUE_FORM_PROCESSING, self::ISSUE_CLI_WORKFLOW => 20,
            self::ISSUE_TEST_ONLY => -25,
            self::ISSUE_BOOTSTRAP_INFRASTRUCTURE => -30,
            self::ISSUE_EXECUTION_CONTEXT => 5,
            default => 0,
        };

        if ($component !== 'unknown') {
            $score += str_starts_with($component, 'core') ? 5 : 10;
        }

        if (in_array('Frame file matches the recorded exception file', $facts, true)) {
            $score += 20;
        }

        if ($executionContext === 'phpunit' && $frameKind === self::FRAME_KIND_TEST) {
            $score -= 10;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $annotation
     */
    private function buildRankingRationale(array $annotation): string
    {
        $parts = [];
        $parts[] = sprintf('Classified as %s', (string) $annotation['frame_kind']);

        if (($annotation['component'] ?? 'unknown') !== 'unknown') {
            $parts[] = sprintf('component %s', (string) $annotation['component']);
        }

        if (($annotation['subsystem'] ?? 'unknown') !== 'unknown') {
            $parts[] = sprintf('subsystem %s', (string) $annotation['subsystem']);
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * @param array<string, mixed>|null $annotation
     */
    private function buildLikelyIssueRationale(?array $annotation): string
    {
        if ($annotation === null) {
            return 'No ranked Moodle-specific frame was identified.';
        }

        return sprintf(
            'Top-ranked frame is %s with %s confidence.',
            (string) ($annotation['category'] ?? self::ISSUE_UNKNOWN),
            (string) ($annotation['confidence'] ?? 'low')
        );
    }

    /**
     * @param array<string, mixed> $testContext
     * @return array<string, string>|null
     */
    private function buildTargetHint(array $testContext): ?array
    {
        $testRef = (string) ($testContext['test_ref'] ?? '');
        if ($testRef === '') {
            return null;
        }

        $className = strstr($testRef, '::', true);
        $className = $className === false ? $testRef : $className;
        $component = null;
        $subsystem = 'unknown';
        $category = self::ISSUE_UNKNOWN;

        if (preg_match('/^(mod_[^\\\\]+|block_[^\\\\]+|tool_[^\\\\]+|theme_[^\\\\]+|report_[^\\\\]+|qtype_[^\\\\]+|core_[^\\\\]+)/', $className, $matches) === 1) {
            $component = $matches[1];
        }

        if (str_contains($className, '\\external\\')) {
            $subsystem = 'external_api';
            $category = self::ISSUE_EXTERNAL_API;
        } elseif (str_contains($className, '\\output\\') || str_contains($className, 'renderer')) {
            $subsystem = 'output_rendering';
            $category = self::ISSUE_RENDERER_OUTPUT;
        } elseif (str_contains($className, '\\tests\\')) {
            $subsystem = 'test_harness';
            $category = $component !== null && !str_starts_with($component, 'core') ? self::ISSUE_PLUGIN_LOGIC : self::ISSUE_CORE_LOGIC;
        }

        if ($component === null && preg_match('/^(core_[^\\\\]+)/', $className, $matches) === 1) {
            $component = $matches[1];
            if ($category === self::ISSUE_UNKNOWN) {
                $category = self::ISSUE_CORE_LOGIC;
            }
        }

        if ($component !== null && $category === self::ISSUE_UNKNOWN) {
            $category = str_starts_with($component, 'core') ? self::ISSUE_CORE_LOGIC : self::ISSUE_PLUGIN_LOGIC;
        }

        if ($component === null && $category === self::ISSUE_UNKNOWN) {
            return null;
        }

        return [
            'category' => $category,
            'component' => $component ?? 'unknown',
            'subsystem' => $subsystem,
            'confidence' => 'medium',
            'rationale' => sprintf(
                'The PHPUnit selector %s suggests %s in %s, even though the captured stop is harness-heavy.',
                $testRef,
                $component ?? 'unknown',
                $category
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $primaryAnnotation
     * @param array<string, string>|null $targetHint
     * @return array<string, string>
     */
    private function buildLikelyIssue(?array $primaryAnnotation, ?array $targetHint): array
    {
        $primaryCategory = (string) ($primaryAnnotation['category'] ?? self::ISSUE_UNKNOWN);

        if (
            $targetHint !== null
            && in_array($primaryCategory, [self::ISSUE_TEST_ONLY, self::ISSUE_BOOTSTRAP_INFRASTRUCTURE, self::ISSUE_UNKNOWN], true)
        ) {
            return $targetHint;
        }

        return [
            'category' => $primaryCategory,
            'component' => (string) ($primaryAnnotation['component'] ?? 'unknown'),
            'subsystem' => (string) ($primaryAnnotation['subsystem'] ?? 'unknown'),
            'confidence' => (string) ($primaryAnnotation['confidence'] ?? 'low'),
            'rationale' => $this->buildLikelyIssueRationale($primaryAnnotation),
        ];
    }
}
