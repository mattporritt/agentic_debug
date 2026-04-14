<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class PHPUnitSelectorValidator
{
    /**
     * @return array{valid:bool,normalized?:string,class_name?:string,method_name?:string,guessed_test_file?:string,message?:string}
     */
    public function validate(string $selector, string $moodleRoot): array
    {
        $selector = trim($selector);
        if (!preg_match('/^(?<class>[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(?<method>test_[A-Za-z0-9_]+)$/', $selector, $matches)) {
            return [
                'valid' => false,
                'message' => 'Only fully-qualified class-based selectors are supported: fully\\qualified\\test_class::test_method',
            ];
        }

        $className = $matches['class'];
        $methodName = $matches['method'];
        $parts = explode('\\', $className);
        $guessedFile = null;

        if (count($parts) >= 3 && $parts[1] === 'tests') {
            $component = $parts[0];
            $shortClass = end($parts);
            if (str_starts_with($component, 'mod_')) {
                $plugin = substr($component, 4);
                $guessedFile = "{$moodleRoot}/mod/{$plugin}/tests/{$shortClass}.php";
            } elseif (str_starts_with($component, 'core_')) {
                $subsystem = substr($component, 5);
                $guessedFile = "{$moodleRoot}/{$subsystem}/tests/{$shortClass}.php";
            }
        }

        return [
            'valid' => true,
            'normalized' => "{$className}::{$methodName}",
            'class_name' => $className,
            'method_name' => $methodName,
            'guessed_test_file' => $guessedFile,
        ];
    }
}
