<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\debug_backend\DbgpXmlParser;
use PHPUnit\Framework\TestCase;

final class DbgpXmlParserTest extends TestCase
{
    public function testParsesStackAndContextPayloads(): void
    {
        $parser = new DbgpXmlParser();

        $stack = $parser->parseStack(<<<XML
<response xmlns="urn:debugger_protocol_v1" command="stack_get">
  <stack level="0" type="file" filename="file:///tmp/moodle/admin/cli/some_script.php" lineno="42" where="execute"/>
</response>
XML);
        $locals = $parser->parseContextProperties(<<<'XML'
<response xmlns="urn:debugger_protocol_v1" command="context_get">
  <property name="$operation" fullname="$operation" type="string" size="7" encoding="base64">cmVpbmRleA==</property>
</response>
XML, 0, 10, 512);

        self::assertSame('/tmp/moodle/admin/cli/some_script.php', $stack[0]['file']);
        self::assertSame('execute', $stack[0]['function']);
        self::assertSame('operation', $locals[0]['locals'][0]['name']);
        self::assertSame('reindex', $locals[0]['locals'][0]['value_preview']);
    }
}
