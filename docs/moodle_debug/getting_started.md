# Getting Started with `moodle_debug`

## What this project is

`moodle_debug` is a Moodle-aware debugging tool with two interfaces:

- an MCP server for direct agent use
- a JSON-only runtime CLI for subprocess orchestration

It exists because raw Xdebug and raw PHP debugger primitives are too low-level
for agentic workflows. `moodle_debug` adds Moodle-specific orchestration,
bounded artifact capture, and Moodle-aware interpretation on top.

## What it can do today

- debug one Moodle PHPUnit test
- debug one allowlisted Moodle CLI script
- persist session artifacts
- interpret stack frames in Moodle terms
- expose a subprocess-friendly runtime API for health, plan, get, interpret, and execute flows

## What it does not do

- web request debugging
- Behat or browser debugging
- profiling, tracing, or coverage
- arbitrary attach to unrelated PHP processes
- public live stepping primitives

## Install

Clone the repository and install dependencies:

```bash
composer install
```

Verify the default test suite:

```bash
vendor/bin/phpunit
```

## Quick start paths

### 1. Fast local smoke path

These use the built-in mock backend and the fake Moodle tree under `/_smoke_test`.

Run a PHPUnit debug flow:

```bash
php bin/moodle-debug run phpunit --test "mod_assign\\tests\\grading_test::test_grade_submission"
```

Run a CLI debug flow:

```bash
php bin/moodle-debug run cli --script "admin/cli/some_script.php"
```

### 2. Runtime CLI path

Health:

```bash
php bin/moodle-debug health --json '{}'
```

Plan without launching:

```bash
php bin/moodle-debug runtime-query --json '{
  "intent": "plan_phpunit",
  "moodle_root": "/Users/you/projects/agentic_debug/_smoke_test/moodle_fixture",
  "runtime_profile": "default_phpunit",
  "test_ref": "mod_assign\\tests\\grading_test::test_grade_submission"
}'
```

Execute explicitly:

```bash
php bin/moodle-debug runtime-query --json '{
  "intent": "execute_cli",
  "moodle_root": "/Users/you/projects/agentic_debug/_smoke_test/moodle_fixture",
  "runtime_profile": "default_cli",
  "script_path": "admin/cli/some_script.php"
}'
```

### 3. Real Docker-backed Xdebug path

Set the Docker/Moodle environment first:

```bash
export MOODLE_DIR="$HOME/projects/moodle"
export MOODLE_DOCKER_DIR="$HOME/projects/moodle-docker"
export MOODLE_DOCKER_BIN_DIR="$HOME/projects/moodle-docker/bin"
export WEBSERVER_SERVICE="webserver"
```

Run a real PHPUnit flow:

```bash
php bin/moodle-debug run phpunit \
  --profile real_xdebug_phpunit \
  --moodle-root "$MOODLE_DIR" \
  --test "core_admin\\external\\set_block_protection_test::test_execute_no_login"
```

Run a real CLI flow:

```bash
php bin/moodle-debug run cli \
  --profile real_xdebug_cli \
  --moodle-root "$MOODLE_DIR" \
  --script "admin/cli/import.php" \
  --args "--srccourseid=999999 --dstcourseid=1"
```

## Where to go next

- architecture and contracts: [design.md](/Users/mattp/projects/agentic_debug/docs/moodle_debug/design.md)
- runtime subprocess contract: [runtime_contract.md](/Users/mattp/projects/agentic_debug/docs/moodle_debug/runtime_contract.md)
- developer/codebase guide: [developer_guide.md](/Users/mattp/projects/agentic_debug/docs/moodle_debug/developer_guide.md)
