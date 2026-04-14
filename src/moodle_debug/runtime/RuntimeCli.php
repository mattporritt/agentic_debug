<?php

declare(strict_types=1);

namespace MoodleDebug\runtime;

final class RuntimeCli
{
    public function __construct(
        private readonly RuntimeApplication $runtimeApplication,
    ) {
    }

    /**
     * @param string[] $argv
     */
    public function execute(array $argv): int
    {
        $command = $argv[1] ?? null;
        if (!in_array($command, ['runtime-query', 'health'], true)) {
            return 2;
        }

        $request = $this->parseRequest(array_slice($argv, 2));
        if (isset($request['__parse_error'])) {
            $response = [
                'tool' => RuntimeApplication::TOOL_NAME,
                'version' => RuntimeApplication::RUNTIME_VERSION,
                'query' => [],
                'normalized_query' => ['intent' => $command],
                'intent' => $command,
                'results' => [],
                'diagnostics' => [[
                    'code' => 'INVALID_RUNTIME_REQUEST',
                    'message' => (string) $request['__parse_error'],
                    'level' => 'error',
                ]],
                'meta' => [
                    'status' => 'fail',
                    'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'repo_root' => getcwd() ?: '.',
                    'dry_run' => $command === 'health',
                    'exit_code' => 1,
                ],
            ];
        } else {
            $response = $command === 'health'
                ? $this->runtimeApplication->health($request)
                : $this->runtimeApplication->runtimeQuery($request);
        }

        fwrite(STDOUT, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return (int) ($response['meta']['exit_code'] ?? (($response['meta']['status'] ?? 'fail') === 'fail' ? 1 : 0));
    }

    /**
     * @param string[] $args
     * @return array<string, mixed>
     */
    private function parseRequest(array $args): array
    {
        if ($args === []) {
            return [];
        }

        $json = null;
        $inputPath = null;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--json') {
                $json = $args[$i + 1] ?? null;
                $i++;
                continue;
            }
            if ($arg === '--input') {
                $inputPath = $args[$i + 1] ?? null;
                $i++;
                continue;
            }
            if ($arg === '--stdin') {
                $json = stream_get_contents(STDIN);
                continue;
            }

            return ['__parse_error' => "Unknown runtime option: {$arg}"];
        }

        if ($json !== null && $inputPath !== null) {
            return ['__parse_error' => 'Provide only one of --json, --input, or --stdin.'];
        }

        if ($inputPath !== null) {
            $contents = @file_get_contents($inputPath);
            if ($contents === false) {
                return ['__parse_error' => "Unable to read runtime request file: {$inputPath}"];
            }
            $json = $contents;
        }

        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['__parse_error' => 'Runtime request must decode to a JSON object.'];
        }

        return $decoded;
    }
}
