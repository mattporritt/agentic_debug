<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

/**
 * Shapes stored debug artifacts into a machine-friendly investigation payload.
 *
 * Downstream tools should not need to scrape prose summaries to find the likely
 * fault file, candidate frames, or rerun command. This builder extracts the
 * stable fields most useful for follow-up inspection and orchestration.
 */
final class RuntimeInvestigationBuilder
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function build(array $payload): array
    {
        $result = $payload['result'] ?? [];
        $mapping = $result['moodle_mapping'] ?? [];
        $frames = $result['frames'] ?? [];
        $frameByIndex = [];
        foreach ($frames as $frame) {
            $frameByIndex[(int) ($frame['index'] ?? 0)] = $frame;
        }

        $probableIndex = (int) ($mapping['probable_fault_frame_index'] ?? 0);
        $likelyFrame = $frameByIndex[$probableIndex] ?? [];
        $likelyIssue = $mapping['likely_issue'] ?? [];
        $candidateFrames = [];
        foreach ($mapping['fault_ranking'] ?? [] as $candidate) {
            $index = (int) ($candidate['frame_index'] ?? 0);
            $frame = $frameByIndex[$index] ?? [];
            $candidateFrames[] = [
                'frame_index' => $index,
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'confidence' => $candidate['confidence'] ?? 'low',
                'rationale' => $candidate['rationale'] ?? null,
            ];
        }

        return [
            'session_provenance' => [
                'session_id' => $payload['session']['session']['session_id'] ?? null,
                'created_at' => $payload['session']['session']['created_at'] ?? null,
                'profile_name' => $payload['session']['runtime_profile']['profile_name'] ?? null,
                'target_type' => $payload['session']['target_type'] ?? null,
            ],
            'likely_fault' => [
                'frame_index' => $probableIndex,
                'file' => $likelyFrame['file'] ?? null,
                'line' => $likelyFrame['line'] ?? null,
                'function' => $likelyFrame['function'] ?? null,
                'class' => $likelyFrame['class'] ?? null,
                'component' => $likelyIssue['component'] ?? ($result['summary']['probable_fault']['component'] ?? 'unknown'),
                'subsystem' => $likelyIssue['subsystem'] ?? 'unknown',
                'issue_category' => $likelyIssue['category'] ?? 'unknown',
                'confidence' => $likelyIssue['confidence'] ?? ($result['summary']['confidence'] ?? 'low'),
            ],
            'candidate_frames' => $candidateFrames,
            'inspection_targets' => $this->buildInspectionTargets($result, $likelyFrame, $likelyIssue),
            'rerun_command' => $result['rerun']['command'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $likelyFrame
     * @param array<string, mixed> $likelyIssue
     * @return array<int, array<string, string>>
     */
    private function buildInspectionTargets(array $result, array $likelyFrame, array $likelyIssue): array
    {
        $inspectionTargets = [];
        if (isset($likelyFrame['file'])) {
            $inspectionTargets[] = [
                'kind' => 'file',
                'value' => (string) $likelyFrame['file'],
            ];
        }
        if (isset($result['target']['normalized_test_ref'])) {
            $inspectionTargets[] = [
                'kind' => 'test_selector',
                'value' => (string) $result['target']['normalized_test_ref'],
            ];
        }
        if (isset($result['target']['script_path'])) {
            $inspectionTargets[] = [
                'kind' => 'cli_script',
                'value' => (string) $result['target']['script_path'],
            ];
        }
        if (($likelyIssue['component'] ?? 'unknown') !== 'unknown') {
            $inspectionTargets[] = [
                'kind' => 'moodle_component',
                'value' => (string) $likelyIssue['component'],
            ];
        }

        return array_values($inspectionTargets);
    }
}
