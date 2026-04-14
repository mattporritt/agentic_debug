<?php

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
