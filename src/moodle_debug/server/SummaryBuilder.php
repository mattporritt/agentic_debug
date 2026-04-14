<?php

declare(strict_types=1);

namespace MoodleDebug\server;

/**
 * Builds concise agent-facing summaries from captured debug artifacts.
 *
 * The summary intentionally stays honest about stop reason and confidence. It
 * should be useful for follow-up work, but it should never overstate that a
 * ranked frame is definitely the bug.
 */
final class SummaryBuilder
{
    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $stopEvent
     * @param array<int, array<string, mixed>> $frames
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public function build(array $target, array $stopEvent, array $frames, array $mapping, string $summaryDepth = 'standard', ?string $focus = null): array
    {
        $probableFaultIndex = (int) ($mapping['probable_fault_frame_index'] ?? 0);
        $annotationByFrame = [];
        foreach ($mapping['annotations'] as $annotation) {
            $annotationByFrame[$annotation['frame_index']] = $annotation;
        }

        $faultAnnotation = $annotationByFrame[$probableFaultIndex] ?? null;
        $likelyIssue = $mapping['likely_issue'] ?? [];
        $faultComponent = (string) ($faultAnnotation['component'] ?? 'unknown');
        $component = $faultComponent !== 'unknown' ? $faultComponent : (string) ($likelyIssue['component'] ?? 'unknown');
        $issueCategory = $mapping['likely_issue']['category'] ?? ($faultAnnotation['category'] ?? 'unknown');
        $issueConfidence = $mapping['likely_issue']['confidence'] ?? ($faultAnnotation['confidence'] ?? 'low');
        $topFrame = $frames[0] ?? [];
        $stopReason = (string) ($stopEvent['reason'] ?? 'unknown');
        $headline = $this->buildHeadline($stopReason, $component, $issueCategory);

        $facts = [
            "Target type: {$target['type']}",
            'Stop reason: ' . $stopReason,
            'Top frame: ' . (($topFrame['file'] ?? 'unknown') . ':' . ($topFrame['line'] ?? '?')),
        ];

        if (isset($stopEvent['exception']['type'])) {
            $facts[] = 'Exception type: ' . $stopEvent['exception']['type'];
        }

        if (($mapping['execution_context'] ?? 'unknown') !== 'unknown') {
            $facts[] = 'Execution context: ' . $mapping['execution_context'];
        }

        if ($issueCategory !== 'unknown') {
            $facts[] = 'Likely issue category: ' . $issueCategory;
        }

        $inferences = [
            [
                'statement' => $this->buildPrimaryInference($component, $issueCategory, $issueConfidence),
                'confidence' => $issueConfidence,
            ],
            [
                'statement' => $this->buildCauseVsContextInference($stopReason, $faultAnnotation),
                'confidence' => $stopReason === 'breakpoint' ? 'low' : ($faultAnnotation['confidence'] ?? 'medium'),
            ],
        ];

        if ($focus !== null && $focus !== '') {
            $inferences[] = [
                'statement' => "Requested focus: {$focus}",
                'confidence' => 'low',
            ];
        }

        if ($summaryDepth === 'detailed' && isset($mapping['fault_ranking'][1])) {
            $secondary = $mapping['fault_ranking'][1];
            $inferences[] = [
                'statement' => sprintf(
                    'Secondary candidate frame %d: %s',
                    (int) $secondary['frame_index'],
                    (string) $secondary['rationale']
                ),
                'confidence' => (string) ($secondary['confidence'] ?? 'low'),
            ];
        }

        return [
            'headline' => $headline,
            'facts' => $facts,
            'inferences' => $inferences,
            'probable_fault' => [
                'frame_index' => $probableFaultIndex,
                'component' => $component,
                'reason' => $this->buildProbableFaultReason($stopReason, $mapping, $faultAnnotation),
            ],
            'confidence' => $issueConfidence,
            'suggested_next_actions' => $this->buildSuggestedNextActions($target, $frames, $mapping, $faultAnnotation),
        ];
    }

    private function buildHeadline(string $stopReason, string $component, string $issueCategory): string
    {
        return match ($stopReason) {
            'exception' => sprintf('Exception stop near %s in %s', $component, $issueCategory),
            'breakpoint' => sprintf('Breakpoint stop near %s in %s', $component, $issueCategory),
            default => sprintf('Debug %s stop near %s', $stopReason, $component),
        };
    }

    private function buildPrimaryInference(string $component, string $issueCategory, string $confidence): string
    {
        if ($component === 'unknown' && $issueCategory === 'unknown') {
            return 'The captured stack does not strongly identify a Moodle-specific fault area yet.';
        }

        return sprintf(
            'Most relevant Moodle area is likely %s within %s (%s confidence).',
            $component,
            $issueCategory,
            $confidence
        );
    }

    /**
     * @param array<string, mixed>|null $faultAnnotation
     */
    private function buildCauseVsContextInference(string $stopReason, ?array $faultAnnotation): string
    {
        $frameKind = (string) ($faultAnnotation['frame_kind'] ?? 'unknown');

        if ($stopReason === 'breakpoint') {
            return sprintf(
                'This run stopped at a breakpoint-like pause, so the top-ranked frame is a likely inspection point rather than a confirmed root cause (%s).',
                $frameKind
            );
        }

        return sprintf(
            'This run stopped on %s, so the top-ranked frame is more likely to be close to the fault than pure execution context (%s).',
            $stopReason,
            $frameKind
        );
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed>|null $faultAnnotation
     */
    private function buildProbableFaultReason(string $stopReason, array $mapping, ?array $faultAnnotation): string
    {
        $ranking = $mapping['fault_ranking'][0]['rationale'] ?? 'Top-ranked Moodle frame from captured stack heuristics.';

        if ($stopReason === 'breakpoint') {
            return 'Top-ranked inspection frame after de-prioritizing harness and bootstrap noise. Breakpoint stops are suggestive, not conclusive. ' . $ranking;
        }

        if (($mapping['likely_issue']['confidence'] ?? 'low') === 'low') {
            return 'Top-ranked frame is the best available candidate, but Moodle-specific signals are weak. ' . $ranking;
        }

        return 'Top-ranked frame after weighting Moodle production code above harness and infrastructure frames. ' . $ranking;
    }

    /**
     * @param array<int, array<string, mixed>> $frames
     * @param array<string, mixed> $mapping
     * @param array<string, mixed>|null $faultAnnotation
     * @return array<int, string>
     */
    private function buildSuggestedNextActions(array $target, array $frames, array $mapping, ?array $faultAnnotation): array
    {
        $actions = [];
        $probableFaultIndex = (int) ($mapping['probable_fault_frame_index'] ?? 0);
        $frameByIndex = [];
        foreach ($frames as $frame) {
            $frameByIndex[(int) ($frame['index'] ?? 0)] = $frame;
        }

        $faultFrame = $frameByIndex[$probableFaultIndex] ?? null;
        if (is_array($faultFrame) && isset($faultFrame['file'])) {
            $actions[] = 'Inspect the likely-fault file: ' . $faultFrame['file'];
        }

        if (($target['type'] ?? 'unknown') === 'phpunit' && isset($target['normalized_test_ref'])) {
            $actions[] = 'Compare the likely-fault frame with the failing PHPUnit selector: ' . $target['normalized_test_ref'];
            if (($mapping['likely_issue']['component'] ?? 'unknown') !== 'unknown') {
                $actions[] = 'Inspect the Moodle area suggested by the selector: ' . $mapping['likely_issue']['component'];
            }
        }

        if (($target['type'] ?? 'unknown') === 'cli' && isset($target['script_path'])) {
            $actions[] = 'Inspect the CLI entrypoint and its first non-bootstrap caller: ' . $target['script_path'];
        }

        if (($faultAnnotation['frame_kind'] ?? 'unknown') === 'test_frame' && isset($mapping['fault_ranking'][1]['frame_index'])) {
            $secondaryIndex = (int) $mapping['fault_ranking'][1]['frame_index'];
            if (isset($frameByIndex[$secondaryIndex]['file'])) {
                $actions[] = 'Inspect the first non-test candidate frame: ' . $frameByIndex[$secondaryIndex]['file'];
            }
        }

        if (($mapping['likely_issue']['category'] ?? 'unknown') !== 'unknown') {
            $actions[] = 'Verify whether the issue belongs to ' . $mapping['likely_issue']['category'] . ' before changing broader code paths.';
        }

        return array_values(array_slice(array_unique($actions), 0, 4));
    }
}
