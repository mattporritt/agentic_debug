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

    public function testFallsBackToPublicWebRootWhenGuessingTestFile(): void
    {
        $validator = new PHPUnitSelectorValidator();
        $root = sys_get_temp_dir() . '/moodle_debug_public_root_' . uniqid('', true);
        mkdir($root . '/public/mod/assign/tests', 0777, true);
        file_put_contents($root . '/public/mod/assign/tests/grading_test.php', "<?php\n");
        $result = $validator->validate(
            'mod_assign\\tests\\grading_test::test_grade_submission',
            $root
        );

        self::assertTrue($result['valid']);
        self::assertSame(
            $root . '/public/mod/assign/tests/grading_test.php',
            $result['guessed_test_file']
        );
    }

    public function testGuessesPluginTestFileWhenNamespaceOmitsTestsSegment(): void
    {
        $validator = new PHPUnitSelectorValidator();
        $result = $validator->validate(
            'mod_assign\\base_test::test_example',
            '/tmp/moodle'
        );

        self::assertTrue($result['valid']);
        self::assertSame('/tmp/moodle/mod/assign/tests/base_test.php', $result['guessed_test_file']);
    }
}
