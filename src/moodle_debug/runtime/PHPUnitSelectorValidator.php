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
        $guessedFile = $this->guessTestFile($parts, $moodleRoot);

        return [
            'valid' => true,
            'normalized' => "{$className}::{$methodName}",
            'class_name' => $className,
            'method_name' => $methodName,
            'guessed_test_file' => $guessedFile,
        ];
    }

    /**
     * @param string[] $parts
     */
    private function guessTestFile(array $parts, string $moodleRoot): ?string
    {
        if (count($parts) < 2) {
            return null;
        }

        $component = $parts[0];
        $relativeParts = array_slice($parts, 1);
        $relativePath = implode('/', $relativeParts) . '.php';

        if (str_starts_with($component, 'mod_')) {
            $plugin = substr($component, 4);
            if (in_array('tests', $relativeParts, true)) {
                return $this->resolveGuessedFile($moodleRoot, "mod/{$plugin}/{$relativePath}");
            }

            return $this->resolveGuessedFile($moodleRoot, 'mod/' . $plugin . '/tests/' . $relativePath);
        }

        if (str_starts_with($component, 'core_')) {
            $subsystem = substr($component, 5);
            if (($relativeParts[0] ?? null) === 'tests') {
                return $this->resolveGuessedFile($moodleRoot, "{$subsystem}/{$relativePath}");
            }

            return $this->resolveGuessedFile($moodleRoot, $subsystem . '/tests/' . $relativePath);
        }

        return null;
    }

    private function resolveGuessedFile(string $moodleRoot, string $relativePath): string
    {
        $primary = rtrim($moodleRoot, '/') . '/' . ltrim($relativePath, '/');
        if (is_file($primary)) {
            return $primary;
        }

        $publicFallback = rtrim($moodleRoot, '/') . '/public/' . ltrim($relativePath, '/');
        if (is_file($publicFallback)) {
            return $publicFallback;
        }

        return $primary;
    }
}
