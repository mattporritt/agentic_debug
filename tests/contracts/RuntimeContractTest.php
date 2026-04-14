<?php

declare(strict_types=1);

namespace MoodleDebug\Tests\contracts;

use MoodleDebug\contracts\RuntimeSchemaValidator;
use MoodleDebug\Tests\Support\FixedClock;
use MoodleDebug\Tests\Support\TestApplicationFactory;
use PHPUnit\Framework\TestCase;

final class RuntimeContractTest extends TestCase
{
    private RuntimeSchemaValidator $validator;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->validator = new RuntimeSchemaValidator($this->repoRoot . '/docs/moodle_debug/schemas/runtime_contract.schema.json');
    }

    public function testRuntimeQueryExecutePhpunitResponseMatchesSchema(): void
    {
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_contracts_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $this->repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $response = $runtime->runtimeQuery([
            'intent' => 'execute_phpunit',
            'moodle_root' => $this->repoRoot . '/_smoke_test/moodle_fixture',
            'runtime_profile' => 'default_phpunit',
            'test_ref' => 'mod_assign\\tests\\grading_test::test_grade_submission',
        ]);

        self::assertTrue($this->validator->validateResponse('runtime_query', $response)['valid']);
    }

    public function testHealthResponseMatchesSchema(): void
    {
        $storage = sys_get_temp_dir() . '/moodle_debug_runtime_contracts_' . uniqid('', true);
        $runtime = TestApplicationFactory::createRuntime(
            $this->repoRoot,
            $storage,
            new FixedClock(new \DateTimeImmutable('2026-04-15T00:00:00+00:00'))
        );

        $response = $runtime->health([]);
        self::assertTrue($this->validator->validateResponse('health', $response)['valid']);
    }

    public function testRuntimeQueryRequestRejectsMissingIntent(): void
    {
        $validated = $this->validator->validateRequest('runtime_query', [
            'session_id' => 'mds_12345678',
        ]);

        self::assertFalse($validated['valid']);
    }
}
