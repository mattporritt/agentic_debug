<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\runtime\RuntimeProfileLoader;
use PHPUnit\Framework\TestCase;

final class RuntimeProfileLoaderTest extends TestCase
{
    public function testLoadsNamedProfile(): void
    {
        $loader = new RuntimeProfileLoader(__DIR__ . '/../../config/runtime_profiles.json');
        $profile = $loader->getProfile('default_phpunit', 'phpunit');

        self::assertSame('default_phpunit', $profile->profileName);
        self::assertSame('phpunit', $profile->launcherKind);
        self::assertNotEmpty($profile->launcherArgv);
    }
}
