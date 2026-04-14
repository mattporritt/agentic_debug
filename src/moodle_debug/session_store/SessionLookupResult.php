<?php

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
