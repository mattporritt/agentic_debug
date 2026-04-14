<?php

declare(strict_types=1);

namespace mod_assign\tests;

require_once __DIR__ . '/../classes/grading_manager.php';

use PHPUnit\Framework\TestCase;
use mod_assign\grading_manager;

final class grading_test extends TestCase
{
    public function test_grade_submission(): void
    {
        $manager = new grading_manager();
        $manager->apply_grade(42, 73.5);
    }
}
