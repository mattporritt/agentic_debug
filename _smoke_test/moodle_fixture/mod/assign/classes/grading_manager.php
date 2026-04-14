<?php

declare(strict_types=1);

namespace mod_assign;

final class coding_exception extends \RuntimeException
{
}

final class grading_manager
{
    public function apply_grade(int $userid, float $grade): void
    {
        $status = 'pending';
        throw new coding_exception("Smoke fixture grading failed for user {$userid} with status {$status}");
    }
}
