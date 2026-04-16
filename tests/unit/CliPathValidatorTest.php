<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\runtime\CliPathValidator;
use PHPUnit\Framework\TestCase;

final class CliPathValidatorTest extends TestCase
{
    public function testOnlyAllowsAdminCliByDefault(): void
    {
        $validator = new CliPathValidator();

        self::assertTrue($validator->validate('admin/cli/some_script.php', ['admin/cli/'])['valid']);
        self::assertFalse($validator->validate('mod/assign/cli/unsafe.php', ['admin/cli/'])['valid']);
    }
}
