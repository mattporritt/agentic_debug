<?php

declare(strict_types=1);

namespace MoodleDebug\server;

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
        $component = $faultAnnotation['component'] ?? 'unknown';
        $headline = sprintf(
            'Debug %s stop near %s',
            (string) ($stopEvent['reason'] ?? 'unknown'),
            $component
        );

        $facts = [
            "Target type: {$target['type']}",
            'Stop reason: ' . ($stopEvent['reason'] ?? 'unknown'),
            'Top frame: ' . (($frames[0]['file'] ?? 'unknown') . ':' . ($frames[0]['line'] ?? '?')),
        ];

        if (isset($stopEvent['exception']['type'])) {
            $facts[] = 'Exception type: ' . $stopEvent['exception']['type'];
        }

        $inferences = [
            [
                'statement' => "Likely actionable component: {$component}",
                'confidence' => $faultAnnotation['confidence'] ?? 'medium',
            ],
        ];

        if ($focus !== null && $focus !== '') {
            $inferences[] = [
                'statement' => "Requested focus: {$focus}",
                'confidence' => 'low',
            ];
        }

        if ($summaryDepth === 'detailed') {
            $facts[] = 'Execution context: ' . ($mapping['execution_context'] ?? 'unknown');
        }

        return [
            'headline' => $headline,
            'facts' => $facts,
            'inferences' => $inferences,
            'probable_fault' => [
                'frame_index' => $probableFaultIndex,
                'component' => $component,
                'reason' => 'First mapped non-harness frame in the captured stack.',
            ],
            'confidence' => $faultAnnotation['confidence'] ?? 'medium',
            'suggested_next_actions' => [
                'Inspect the probable fault frame and its immediate caller.',
                'Re-run the same mocked workflow with the same selector or script to confirm reproducibility.',
            ],
        ];
    }
}
