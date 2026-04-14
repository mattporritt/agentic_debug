<?php

declare(strict_types=1);

namespace MoodleDebug\contracts;

final class ErrorFactory
{
    /**
     * @param string[] $diagnosticHints
     * @param array<string, mixed> $details
     * @return array{ok:false,error:array{code:string,message:string,retryable:bool,diagnostic_hints?:string[],details?:array<string,mixed>}}
     */
    public static function failure(
        string $code,
        string $message,
        bool $retryable = false,
        array $diagnosticHints = [],
        array $details = [],
    ): array {
        $error = [
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
        ];

        if ($diagnosticHints !== []) {
            $error['diagnostic_hints'] = array_values($diagnosticHints);
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        return [
            'ok' => false,
            'error' => $error,
        ];
    }

    /**
     * @return array{code:string,message:string}
     */
    public static function warning(string $code, string $message): array
    {
        return [
            'code' => $code,
            'message' => $message,
        ];
    }
}
