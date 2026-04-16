<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\contracts;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Thin wrapper around JSON Schema validation for MCP tool contracts.
 *
 * The project keeps runtime subprocess schemas separate from MCP schemas so the
 * two interfaces can evolve independently without confusing each other.
 */
final class SchemaValidator
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
            throw new \RuntimeException("Unable to read schema file: {$schemaPath}");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Unable to decode schema file: {$schemaPath}");
        }

        $this->rootSchema = $decoded;
        $this->validator = new Validator();
    }

    /**
     * @return array<string, mixed>
     */
    public function getToolInputSchema(string $toolName): array
    {
        $schema = $this->getByPath([
            'properties',
            'tools',
            'properties',
            $toolName,
            'properties',
            'input',
        ]);

        if (!is_array($schema)) {
            throw new \RuntimeException("Input schema not found for tool {$toolName}");
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{valid:bool,message?:string}
     */
    public function validateToolInput(string $toolName, array $payload): array
    {
        return $this->validate($payload, $this->buildDocumentForToolSection($toolName, 'input'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{valid:bool,message?:string}
     */
    public function validateToolOutput(string $toolName, array $payload): array
    {
        $section = (($payload['ok'] ?? null) === true) ? 'success' : 'failure';

        return $this->validate($payload, $this->buildDocumentForToolSection($toolName, $section));
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $schema
     * @return array{valid:bool,message?:string}
     */
    private function validate(array $instance, array $schema): array
    {
        $result = $this->validator->validate(
            json_decode(json_encode($instance, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
        );

        if ($result->isValid()) {
            return ['valid' => true];
        }

        $error = $result->error();
        $message = $error instanceof ValidationError ? $this->formatError($error) : 'Schema validation failed.';

        return [
            'valid' => false,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocumentForToolSection(string $toolName, string $section): array
    {
        $subSchema = $this->getByPath([
            'properties',
            'tools',
            'properties',
            $toolName,
            'properties',
            $section,
        ]);

        if (!is_array($subSchema)) {
            throw new \RuntimeException("Schema section {$section} not found for tool {$toolName}");
        }

        $document = $subSchema;
        $document['$schema'] = $this->rootSchema['$schema'] ?? 'https://json-schema.org/draft/2020-12/schema';
        $document['$defs'] = $this->rootSchema['$defs'] ?? [];

        $properties = $document['properties'] ?? [];
        $properties['definitions'] = $this->rootSchema['properties']['definitions'] ?? [];
        $document['properties'] = $properties;

        return $document;
    }

    /**
     * @param array<int, string> $path
     * @return mixed
     */
    private function getByPath(array $path): mixed
    {
        $current = $this->rootSchema;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function formatError(ValidationError $error): string
    {
        $path = $error->data()->fullPath();
        $pointer = $path === [] ? '$' : '$.' . implode('.', array_map(static fn (string|int $item): string => (string) $item, $path));

        return "{$pointer}: {$error->message()}";
    }
}
