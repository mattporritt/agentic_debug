<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\runtime;

use MoodleDebug\contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
