<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class CliPathValidator
{
    /**
     * @param string[] $allowlist
     * @return array{valid:bool,normalized?:string,message?:string}
     */
    public function validate(string $scriptPath, array $allowlist): array
    {
        $normalized = ltrim(str_replace('\\', '/', trim($scriptPath)), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return [
                'valid' => false,
                'message' => 'CLI script path must be a safe path under the Moodle root.',
            ];
        }

        foreach ($allowlist as $prefix) {
            $prefix = ltrim(str_replace('\\', '/', $prefix), '/');
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return [
                    'valid' => true,
                    'normalized' => $normalized,
                ];
            }
        }

        return [
            'valid' => false,
            'message' => 'Only scripts under admin/cli/ or explicitly allowlisted prefixes are supported in v1.',
        ];
    }
}
