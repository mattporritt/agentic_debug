<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\runtime\PHPUnitSelectorValidator;
use PHPUnit\Framework\TestCase;

final class PHPUnitSelectorValidatorTest extends TestCase
{
    public function testAcceptsOnlyClassBasedSelectors(): void
    {
        $validator = new PHPUnitSelectorValidator();
        $valid = $validator->validate('mod_assign\\tests\\grading_test::test_grade_submission', '/tmp/moodle');
        $invalid = $validator->validate('mod/assign/tests/grading_test.php', '/tmp/moodle');

        self::assertTrue($valid['valid']);
        self::assertSame('mod_assign\\tests\\grading_test::test_grade_submission', $valid['normalized']);
        self::assertFalse($invalid['valid']);
    }
}
