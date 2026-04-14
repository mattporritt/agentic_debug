<?php

declare(strict_types=1);

namespace MoodleDebug\contracts;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
