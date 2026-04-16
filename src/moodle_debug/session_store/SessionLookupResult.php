<?php

// Copyright (c) Moodle Pty Ltd. All rights reserved.
// Licensed under the Moodle Community License v1.3.
// See LICENSE.md in the repository root for full terms.
// Commercial use requires a separate written agreement with Moodle.

declare(strict_types=1);

namespace MoodleDebug\session_store;

final readonly class SessionLookupResult
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public bool $found,
        public bool $expired,
        public ?array $payload = null,
    ) {
    }
}
