# moodle_debug

`moodle_debug` is a narrow v1 MCP server for Moodle-aware debug orchestration. This repository currently implements:

- the agreed v1 tool surface
- strict JSON-schema validation
- deterministic PHPUnit and CLI workflows
- a swappable debug backend behind a strict interface
- file-backed artifact sessions with TTL cleanup
- a local CLI harness for smoke execution

What is implemented:

- `debug_phpunit_test`
- `debug_cli_script`
- `get_debug_session`
- `summarise_debug_session`
- `map_stack_to_moodle_context`
- official PHP MCP SDK stdio server wiring
- `MockDebugBackend` for fast deterministic tests
- `XdebugDebugBackend` for real step-debug launches over a local DBGp listener

Current backend model:

- public MCP tools are unchanged
- sessions remain artifact-backed and read-only after capture
- backend selection is profile-driven
- mock profiles keep the existing deterministic phase-2 behavior
- real profiles launch PHP inside the Moodle Docker `webserver` container and capture stack/locals from a live DBGp session back to a host listener

Current Phase 2 contract details:

- `session.runtime_profile.launcher_kind` is the runtime target family and is always one of `phpunit` or `cli`
- `get_debug_session.include` only supports `result: true|false`
- omitted `include` behaves like `{"result": true}`
- granular include flags such as `summary`, `mapping`, or `locals` are intentionally not supported in v1

What remains mocked or intentionally limited:

- no live stepping exposed through MCP tools
- no stepping primitives
- no web or Behat flows
- no web request debugging
- no arbitrary attach to existing PHP processes
- no profiling, tracing, or coverage

What comes next:

- refine Moodle-aware interpretation heuristics for broader real-session shapes
- add broader environment coverage once the Docker-first path is stable

Current interpretation behavior:

- `map_stack_to_moodle_context` classifies each captured frame with Moodle-aware `component`, `subsystem`, likely issue `category`, and `frame_kind`
- mapping output also includes a short `fault_ranking` shortlist plus a top-level `likely_issue` with component, subsystem, rationale, and confidence
- `summarise_debug_session` uses those signals to produce truthful facts, bounded inferences, and practical next actions

Interpretation limits:

- ranking is heuristic, not blame assignment
- breakpoint stops are useful inspection points, not proof of root cause
- confidence measures how strong the Moodle-specific signals are in captured artifacts, not certainty that a bug is found

## Layout

- [src/moodle_debug](/Users/mattp/projects/agentic_debug/src/moodle_debug)
- [tests](/Users/mattp/projects/agentic_debug/tests)
- [config/runtime_profiles.json](/Users/mattp/projects/agentic_debug/config/runtime_profiles.json)
- [docs/moodle_debug/design.md](/Users/mattp/projects/agentic_debug/docs/moodle_debug/design.md)

## Install

Dependencies are not committed to this repository. After cloning or after any dependency change, install them locally with Composer.

```bash
composer install
```

Recommended first-run setup:

```bash
composer install
vendor/bin/phpunit
```

Notes:

- `composer.lock` is committed and should be used for reproducible installs
- `vendor/` is intentionally ignored and must be created locally
- if you pull changes that update `composer.lock`, run `composer install` again

## Runtime profiles

The repo ships with four runtime profiles in [config/runtime_profiles.json](/Users/mattp/projects/agentic_debug/config/runtime_profiles.json):

- `default_phpunit`: mock backend
- `default_cli`: mock backend
- `real_xdebug_phpunit`: real Xdebug backend
- `real_xdebug_cli`: real Xdebug backend

Real profiles extend the existing shape with explicit Xdebug settings such as:

- `backend_kind`
- `execution_transport`
- `xdebug_enabled`
- `xdebug_mode`
- `xdebug_start_with_request`
- `xdebug_start_upon_error`
- `xdebug_client_host`
- `xdebug_client_port`
- `xdebug_log`
- `xdebug_idekey`
- `php_ini_overrides`
- `debugger_connect_timeout_ms`
- `debugger_overall_timeout_ms`
- `listener_bind_address`
- `docker_compose_command`
- `webserver_service`
- `webserver_user`
- `container_working_directory`

`launcher_kind` still means target family only and remains `phpunit | cli`.

For the shipped real profiles:

- `launcher_kind` stays `phpunit` or `cli`
- `execution_transport` is `docker_exec`
- the host process owns the DBGp listener
- the PHP target runs inside the Docker `webserver` container
- `xdebug_client_host` defaults to `host.docker.internal`
- `listener_bind_address` defaults to `0.0.0.0`

## Listener port strategy

- The default real-backend listener port remains `9003`
- `moodle_debug` binds that host port before launching the Docker-backed PHP target
- If the port is transiently busy, the backend retries a small bounded number of times before failing
- If the port remains occupied, the run fails with `LISTENER_BIND_FAILED`
- For opt-in real tests, `MOODLE_DEBUG_XDEBUG_CLIENT_PORT` can be set to place each run on a deterministic alternate port

Once the DBGp callback is accepted, the listener socket is released immediately so later runs do not wait for the whole session lifecycle to finish before reusing the port.

## Codex-style environment compatibility

`moodle_debug` can reuse the same environment conventions as the Moodle Codex runner without depending on the Codex repository itself.

Supported variables:

- `MOODLE_DIR`
- `MOODLE_DOCKER_DIR`
- `MOODLE_DOCKER_BIN_DIR`
- `WEBSERVER_SERVICE`
- `WEBSERVER_USER`
- `MOODLE_DEBUG_CODEX_ENV_FILE`

Lookup precedence:

1. process environment variables
2. a `.codex.env`-style file
3. checked-in runtime profile defaults

By default the loader looks for [/.codex.env](/Users/mattp/projects/agentic_debug/.codex.env) in the repository root if it exists. You can point at a different file with `MOODLE_DEBUG_CODEX_ENV_FILE`.

## How the real backend works

The real backend keeps the public MCP contract stable and swaps in a real implementation behind `DebugBackendInterface`.

At a high level:

1. `moodle_debug` binds a host-side DBGp listener socket.
2. It launches the selected PHP target inside the Docker `webserver` container using `moodle-docker-compose exec`.
3. Xdebug inside that container connects back to `moodle_debug` on the host.
4. The backend sets exception breakpoints and runs the target.
5. On stop, it normalizes the captured event into a stable stop reason such as `exception`, `breakpoint`, or `target_exit`, then captures stack frames and bounded locals.
6. It persists the same artifact-backed session shape used by the mock backend.

This phase does not expose live stepping as MCP tools. Any continue/feature negotiation is internal to the backend adapter only.

## Moodle-aware interpretation

The interpretation layer only uses captured artifacts:

- normalized stop reason
- stack frames
- bounded locals
- workflow target metadata
- Moodle path, namespace, and entrypoint conventions

It does not do broad static indexing or hidden code analysis.

High-level ranking rules:

- prefer Moodle production frames over PHPUnit harness, vendor, bootstrap, and runtime wrapper frames
- favor frames whose file matches a recorded exception file when an exception payload exists
- de-prioritize `tests/`, `lib/phpunit/`, vendor, and setup/bootstrap frames
- treat `admin/cli/...` entrypoints as meaningful CLI context, while still distinguishing context from confirmed cause
- keep a short ranked shortlist instead of pretending the top frame is definitely the bug

Likely issue categories currently include:

- `core_logic`
- `plugin_logic`
- `renderer_output`
- `external_api`
- `access_control`
- `form_processing`
- `cli_workflow`
- `test_only`
- `bootstrap_infrastructure`
- `execution_context`
- `unknown`

Facts vs inferences:

- facts come directly from captured artifacts such as stop reason, top frame path, or exception type
- inferences are Moodle-aware heuristics such as component, subsystem, issue category, and ranked likely-fault frames
- `high`, `medium`, and `low` confidence describe the strength of those heuristics only

## Docker and Xdebug prerequisites

Real profiles require:

- Moodle PHP runs inside the Docker `webserver` service
- Xdebug 3 is installed and enabled inside that container
- the configured Docker compose command can run from the host
- `host.docker.internal` or your chosen callback host resolves from inside the container
- the configured `xdebug_client_port` is reachable from the container to the host listener

Useful checks:

```bash
docker compose exec -T webserver php --ri xdebug
docker compose exec -T webserver php -r 'echo gethostbyname("host.docker.internal"), PHP_EOL;'
```

## Run the CLI harness

Mock PHPUnit flow:

```bash
php bin/moodle-debug run phpunit --test "mod_assign\\tests\\grading_test::test_grade_submission"
```

Mock CLI flow:

```bash
php bin/moodle-debug run cli --script "admin/cli/some_script.php"
```

Real Xdebug PHPUnit flow:

```bash
MOODLE_DIR="$HOME/projects/moodle" \
MOODLE_DOCKER_DIR="$HOME/projects/moodle-docker" \
MOODLE_DOCKER_BIN_DIR="$HOME/projects/moodle-docker/bin" \
WEBSERVER_SERVICE=webserver \
php bin/moodle-debug run phpunit \
  --profile real_xdebug_phpunit \
  --moodle-root "$HOME/projects/moodle" \
  --test "core_admin\\external\\set_block_protection_test::test_execute_no_login"
```

Real Xdebug CLI flow:

```bash
MOODLE_DIR="$HOME/projects/moodle" \
MOODLE_DOCKER_DIR="$HOME/projects/moodle-docker" \
MOODLE_DOCKER_BIN_DIR="$HOME/projects/moodle-docker/bin" \
WEBSERVER_SERVICE=webserver \
php bin/moodle-debug run cli \
  --profile real_xdebug_cli \
  --moodle-root "$HOME/projects/moodle" \
  --script "admin/cli/import.php" \
  --args "--srccourseid=999999 --dstcourseid=1"
```

If you already have Codex-style environment config, export it first or place it in `.codex.env`. For example:

```bash
export MOODLE_DIR="$HOME/projects/moodle"
export MOODLE_DOCKER_DIR="$HOME/projects/moodle-docker"
export MOODLE_DOCKER_BIN_DIR="$HOME/projects/moodle-docker/bin"
export WEBSERVER_SERVICE="webserver"
```

`MOODLE_DIR` should point at the Moodle checkout root used by the Docker bind mount. If your PHPUnit tests live under a `public/` subtree, `moodle_debug` will fall back to that layout when resolving test files.

You can also override profile or Moodle root:

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

Opt-in real backend integration tests:

```bash
MOODLE_DEBUG_RUN_REAL_XDEBUG_TESTS=1 vendor/bin/phpunit --testsuite moodle_debug
```

The real integration tests skip unless:

- `MOODLE_DEBUG_RUN_REAL_XDEBUG_TESTS=1`
- Docker compose is available
- the configured `webserver` service exists in the current Moodle Docker environment
- `MOODLE_DIR` points at a real Moodle checkout mounted into the Docker `webserver`

For the most repeatable real runs, set `MOODLE_DEBUG_XDEBUG_CLIENT_PORT` explicitly when running one-off verification or CI-style checks.

Optional profile overrides:

- `MOODLE_DEBUG_REAL_PHPUNIT_PROFILE`
- `MOODLE_DEBUG_REAL_CLI_PROFILE`

## Real backend troubleshooting

- `DOCKER_COMPOSE_BINARY_MISSING`: the configured compose command could not be executed from the host
- `DOCKER_SERVICE_NOT_FOUND`: the configured `webserver_service` does not exist in the Docker environment
- `DOCKER_SERVICE_NOT_RUNNING`: the configured service exists but is not running
- `DOCKER_EXEC_FAILED`: the host could invoke Docker, but the container-side exec failed
- `XDEBUG_CALLBACK_HOST_UNRESOLVABLE`: the callback host, usually `host.docker.internal`, did not resolve inside the container
- `XDEBUG_NOT_ENABLED`: Xdebug is not installed or enabled in the container PHP runtime
- `LISTENER_BIND_FAILED`: the host listener address or port could not be bound; diagnostic details distinguish port-in-use, permission, and invalid-address failures
- `XDEBUG_CONNECTION_TIMEOUT`: the container never connected back to the host listener
- `TARGET_FAILED_BEFORE_ATTACH`: the target exited before Xdebug connected
- `NO_STOP_EVENT`: the target completed after attaching but without hitting a meaningful debug stop

## Common failure modes

- `DBGP_HANDSHAKE_FAILED` or `DBGP_PROTOCOL_ERROR`: the callback was established but the DBGp exchange failed

## Stop reason interpretation

- `exception`: Xdebug reported an exception breakpoint and the result includes an exception payload
- `breakpoint`: Xdebug stopped at a meaningful breakpoint, but no exception payload was captured
- `target_exit`: the target completed after attaching without a meaningful stop; callers receive `NO_STOP_EVENT`
- `timeout`: the backend timed out before a meaningful stop was captured

Summaries follow the normalized stop reason directly and do not imply an exception occurred unless an exception payload was actually captured.

Breakpoint-based summaries remain conservative: they describe the top-ranked frame as the best inspection point after de-prioritizing harness and infrastructure noise, not as a confirmed root cause.

## Docker-backed rerun metadata

`result.rerun` is the stable rerun recipe returned to agents:

- `rerun.command` is always the complete executable command array to repeat the run
- `rerun.cwd` is the host working directory for that command
- `rerun.launcher` may be empty for Docker-backed runs because `rerun.command` already contains the full `moodle-docker-compose exec ...` transport recipe
- `rerun.notes` explains whether launcher and command are intentionally split for the current backend

## `map_stack_to_moodle_context` notes

- `test_context` is optional
- if provided in v1, it should carry meaningful data such as `test_ref`
- an empty `test_context` object is rejected by schema validation rather than silently ignored

## Verification status in this repository

The default test suite verifies:

- public MCP contract stability
- mock backend behavior
- Xdebug profile parsing and launch setting generation
- DBGp XML parsing helpers
- backend error mapping

The real Xdebug integration tests are present but opt-in. They use deterministic alternate listener ports when `MOODLE_DEBUG_XDEBUG_CLIENT_PORT` is set by the test harness.
The real backend is Docker-first; host-native PHP is still supported by the internal transport model, but it is not the primary documented workflow.

## Smoke fixture

The local harness uses a minimal fake Moodle tree under [/_smoke_test/moodle_fixture](/Users/mattp/projects/agentic_debug/_smoke_test/moodle_fixture/config.php). It supports both the deterministic mock backend and the opt-in real Xdebug backend smoke flows.
