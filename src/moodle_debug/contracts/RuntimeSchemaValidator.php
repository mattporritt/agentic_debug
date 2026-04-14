<?php

declare(strict_types=1);

namespace MoodleDebug\contracts;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

final class RuntimeSchemaValidator
{
    /**
     * @var array<string, mixed>
     */
    private array $rootSchema;

    private Validator $validator;

    public function __construct(string $schemaPath)
    {
        $contents = file_get_contents($schemaPath);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read runtime schema file: {$schemaPath}");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Unable to decode runtime schema file: {$schemaPath}");
        }

        $this->rootSchema = $decoded;
        $this->validator = new Validator();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{valid:bool,message?:string}
     */
    public function validateRequest(string $section, array $payload): array
    {
        return $this->validate($payload, $this->getSchemaSection($section . '_request'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{valid:bool,message?:string}
     */
    public function validateResponse(string $section, array $payload): array
    {
        return $this->validate($payload, $this->getSchemaSection($section . '_response'));
    }

    /**
     * @return array<string, mixed>
     */
    private function getSchemaSection(string $section): array
    {
        $schema = $this->rootSchema[$section] ?? null;
        if (!is_array($schema)) {
            throw new \RuntimeException("Runtime schema section not found: {$section}");
        }

        $document = $schema;
        $document['$schema'] = $this->rootSchema['$schema'] ?? 'https://json-schema.org/draft/2020-12/schema';
        $document['$defs'] = $this->rootSchema['$defs'] ?? [];

        return $document;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $schema
     * @return array{valid:bool,message?:string}
     */
    private function validate(array $instance, array $schema): array
    {
        $encodedInstance = $instance === []
            ? '{}'
            : json_encode($instance, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate(
            json_decode($encodedInstance, false, 512, JSON_THROW_ON_ERROR),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
        );

        if ($result->isValid()) {
            return ['valid' => true];
        }

        $error = $result->error();
        $message = $error instanceof ValidationError ? $this->formatError($error) : 'Runtime schema validation failed.';

        return [
            'valid' => false,
            'message' => $message,
        ];
    }

    private function formatError(ValidationError $error): string
    {
        $path = $error->data()->fullPath();
        $pointer = $path === [] ? '$' : '$.' . implode('.', array_map(static fn (string|int $item): string => (string) $item, $path));

        return "{$pointer}: {$error->message()}";
    }
}
