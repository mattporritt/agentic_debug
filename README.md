# moodle_debug

`moodle_debug` is a narrow v1 MCP server skeleton for Moodle-aware debug orchestration. This repository currently implements:

- the agreed v1 tool surface
- strict JSON-schema validation
- deterministic PHPUnit and CLI workflows
- a mocked debug backend behind a strict interface
- file-backed artifact sessions with TTL cleanup
- a local CLI harness for smoke execution

What is implemented:

- `debug_phpunit_test`
- `debug_cli_script`
- `get_debug_session`
- `summarise_debug_session`
- `map_stack_to_moodle_context`
- official PHP MCP SDK stdio server wiring
- mocked launch/stop/stack/locals behavior

What is mocked:

- no Xdebug integration
- no live debugger transport
- no stepping primitives
- no web or Behat flows

What comes next:

- replace `MockDebugBackend` with a real adapter to an approved Xdebug-compatible backend
- harden Moodle-aware interpretation heuristics
- add richer runtime profile variants for real local Docker/CLI environments

## Layout

- [src/moodle_debug](/Users/mattp/projects/agentic_debug/src/moodle_debug)
- [tests](/Users/mattp/projects/agentic_debug/tests)
- [config/runtime_profiles.json](/Users/mattp/projects/agentic_debug/config/runtime_profiles.json)
- [docs/moodle_debug/design.md](/Users/mattp/projects/agentic_debug/docs/moodle_debug/design.md)

## Install

```bash
composer install
```

## Run the CLI harness

PHPUnit flow:

```bash
php bin/moodle-debug run phpunit --test "mod_assign\\tests\\grading_test::test_grade_submission"
```

CLI flow:

```bash
php bin/moodle-debug run cli --script "admin/cli/some_script.php"
```

You can also override profile or moodle root:

```bash
php bin/moodle-debug run phpunit --profile default_phpunit --moodle-root "/Users/mattp/projects/agentic_debug/_smoke_test/moodle_fixture" --test "mod_assign\\tests\\grading_test::test_grade_submission"
```

## Run the MCP server

```bash
php bin/moodle-debug server
```

Or directly:

```bash
php bin/moodle-debug-server
```

## Run tests

```bash
vendor/bin/phpunit
```

## Smoke fixture

The mocked local harness uses a minimal fake Moodle tree under [/_smoke_test/moodle_fixture](/Users/mattp/projects/agentic_debug/_smoke_test/moodle_fixture/config.php). It exists only to satisfy deterministic local validation and manual runs in this mocked phase.
