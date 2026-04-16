<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\debug_backend;

final class DbgpXmlParser
{
    /**
     * @return array<string, mixed>
     */
    public function parseInit(string $xml): array
    {
        $document = $this->load($xml, 'DBGP_HANDSHAKE_FAILED', 'Failed to parse Xdebug init packet.');

        if ($document->getName() !== 'init') {
            throw new DebugBackendException('DBGP_HANDSHAKE_FAILED', 'Xdebug did not send a valid init packet.', false);
        }

        return [
            'fileuri' => (string) ($document['fileuri'] ?? ''),
            'idekey' => (string) ($document['idekey'] ?? ''),
            'appid' => (string) ($document['appid'] ?? ''),
            'language' => (string) ($document['language'] ?? ''),
            'protocol_version' => (string) ($document['protocol_version'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parseResponse(string $xml): array
    {
        $document = $this->load($xml, 'DBGP_PROTOCOL_ERROR', 'Failed to parse Xdebug response.');
        $messageNode = $document->children('https://xdebug.org/dbgp/xdebug')->message;

        return [
            'command' => (string) ($document['command'] ?? ''),
            'status' => (string) ($document['status'] ?? ''),
            'reason' => (string) ($document['reason'] ?? ''),
            'transaction_id' => (string) ($document['transaction_id'] ?? ''),
            'success' => (string) ($document['success'] ?? ''),
            'message' => $messageNode ? [
                'filename' => $this->uriToPath((string) ($messageNode['filename'] ?? '')),
                'lineno' => (int) ($messageNode['lineno'] ?? 0),
                'exception' => (string) ($messageNode['exception'] ?? ''),
                'text' => trim((string) $messageNode),
            ] : null,
            'xml' => $document,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseStack(string $xml): array
    {
        $document = $this->load($xml, 'STACK_RETRIEVAL_FAILED', 'Failed to parse stack response.');
        $frames = [];

        foreach ($document->stack as $stack) {
            $where = (string) ($stack['where'] ?? '');
            $frames[] = [
                'index' => (int) ($stack['level'] ?? 0),
                'file' => $this->uriToPath((string) ($stack['filename'] ?? '')),
                'line' => (int) ($stack['lineno'] ?? 0),
                'function' => $where !== '' ? $where : 'unknown',
            ];
        }

        return $frames;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseContextProperties(string $xml, int $frameIndex, int $maxLocalsPerFrame, int $maxStringLength): array
    {
        $document = $this->load($xml, 'LOCALS_RETRIEVAL_FAILED', 'Failed to parse locals response.');
        $locals = [];

        foreach ($document->property as $property) {
            if (count($locals) >= $maxLocalsPerFrame) {
                break;
            }

            $name = (string) ($property['name'] ?? '');
            $type = (string) ($property['type'] ?? 'unknown');
            $encoding = (string) ($property['encoding'] ?? '');
            $children = (string) ($property['children'] ?? '0');
            $numChildren = (string) ($property['numchildren'] ?? '0');
            $className = (string) ($property['classname'] ?? '');

            $valuePreview = trim((string) $property);
            if ($encoding === 'base64') {
                $decoded = base64_decode($valuePreview, true);
                $valuePreview = $decoded !== false ? $decoded : $valuePreview;
            }

            if ($children === '1' || ((int) $numChildren) > 0) {
                $summaryType = $className !== '' ? $className : $type;
                $valuePreview = sprintf('%s(%d)', $summaryType, (int) $numChildren);
            }

            $locals[] = [
                'frame_index' => $frameIndex,
                'locals' => [[
                    'name' => ltrim($name, '$'),
                    'type' => $type !== '' ? $type : 'unknown',
                    'value_preview' => substr($valuePreview, 0, $maxStringLength),
                    'redacted' => false,
                ]],
            ];
        }

        return $locals;
    }

    private function load(string $xml, string $errorCode, string $message): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$document instanceof \SimpleXMLElement) {
            throw new DebugBackendException($errorCode, $message, false, [], ['xml' => $xml]);
        }

        return $document;
    }

    private function uriToPath(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        if (str_starts_with($uri, 'file://')) {
            $path = parse_url($uri, PHP_URL_PATH);
            return is_string($path) ? rawurldecode($path) : $uri;
        }

        return $uri;
    }
}
