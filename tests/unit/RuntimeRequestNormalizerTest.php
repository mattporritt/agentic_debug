<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\unit;

use MoodleDebug\runtime\RuntimeRequestNormalizer;
use PHPUnit\Framework\TestCase;

final class RuntimeRequestNormalizerTest extends TestCase
{
    public function testNormalizesDefaultsForExecuteCli(): void
    {
        $normalizer = new RuntimeRequestNormalizer();

        $normalized = $normalizer->normalize([
            'intent' => 'execute_cli',
            'script_path' => 'admin/cli/some_script.php',
        ], '/tmp/repo');

        self::assertSame('default_cli', $normalized['runtime_profile']);
        self::assertSame('/tmp/repo/_smoke_test/moodle_fixture', $normalized['moodle_root']);
        self::assertSame([], $normalized['script_args']);
        self::assertSame(25, $normalized['capture_policy']['max_frames']);
    }

    public function testPreservesExplicitValues(): void
    {
        $normalizer = new RuntimeRequestNormalizer();

        $normalized = $normalizer->normalize([
            'intent' => 'plan_phpunit',
            'runtime_profile' => 'real_xdebug_phpunit',
            'moodle_root' => '/tmp/moodle',
            'test_ref' => 'core_user\\tests\\profile_test::test_update_profile',
            'capture_policy' => [
                'max_frames' => 12,
            ],
        ], '/tmp/repo');

        self::assertSame('real_xdebug_phpunit', $normalized['runtime_profile']);
        self::assertSame('/tmp/moodle', $normalized['moodle_root']);
        self::assertSame('core_user\\tests\\profile_test::test_update_profile', $normalized['test_ref']);
        self::assertSame(12, $normalized['capture_policy']['max_frames']);
        self::assertSame(10, $normalized['capture_policy']['max_locals_per_frame']);
    }
}
