<?php

declare(strict_types=1);

namespace MoodleDebug\server;

final class MoodleContextMapper
{
    /**
     * @param array<int, array<string, mixed>> $frames
     * @param array<string, mixed>|null $exception
     * @param array<string, mixed> $testContext
     * @return array<string, mixed>
     */
    public function map(string $moodleRoot, array $frames, ?array $exception = null, array $testContext = []): array
    {
        $annotations = [];
        $candidateFaultFrames = [];
        $executionContext = isset($testContext['test_ref']) ? 'phpunit' : 'unknown';

        foreach ($frames as $frame) {
            $file = (string) ($frame['file'] ?? '');
            $annotation = [
                'frame_index' => (int) $frame['index'],
                'category' => 'unknown',
                'confidence' => 'low',
                'facts' => [],
                'inferences' => [],
            ];

            if (preg_match('#/mod/([^/]+)/#', $file, $matches)) {
                $annotation['component'] = 'mod_' . $matches[1];
                $annotation['category'] = str_contains($file, '/tests/') ? 'test_harness' : 'plugin';
                $annotation['confidence'] = 'high';
                $annotation['facts'][] = "Frame path is inside mod/{$matches[1]}";
                $annotation['inferences'][] = "Likely Moodle component: mod_{$matches[1]}";
                if (!str_contains($file, '/tests/')) {
                    $candidateFaultFrames[] = (int) $frame['index'];
                }
            } elseif (str_contains($file, '/admin/cli/')) {
                $annotation['component'] = 'core_admin';
                $annotation['category'] = 'core';
                $annotation['confidence'] = 'medium';
                $annotation['facts'][] = 'Frame path is inside admin/cli';
                $annotation['inferences'][] = 'Likely core admin CLI execution';
                $executionContext = 'cli_admin';
                $candidateFaultFrames[] = (int) $frame['index'];
            } elseif (str_contains($file, '/lib/')) {
                $annotation['component'] = 'core';
                $annotation['category'] = 'core';
                $annotation['confidence'] = 'medium';
                $annotation['facts'][] = 'Frame path is inside lib/';
                $annotation['inferences'][] = 'Likely core Moodle library code';
            }

            $annotations[] = $annotation;
        }

        if ($executionContext === 'unknown' && $exception !== null && str_contains((string) ($exception['file'] ?? ''), '/admin/cli/')) {
            $executionContext = 'cli_admin';
        }

        $probableFaultFrameIndex = $candidateFaultFrames[0] ?? ($frames[0]['index'] ?? 0);

        return [
            'annotations' => $annotations,
            'execution_context' => $executionContext,
            'candidate_fault_frame_indexes' => array_values(array_unique($candidateFaultFrames === [] ? [(int) $probableFaultFrameIndex] : $candidateFaultFrames)),
            'probable_fault_frame_index' => (int) $probableFaultFrameIndex,
        ];
    }
}
