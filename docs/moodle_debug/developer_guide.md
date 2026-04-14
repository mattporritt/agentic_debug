# `moodle_debug` Developer Guide

## Repository intent

This repository is intentionally narrow.

Its job is to make Moodle debugging usable and deterministic for an agentic
coding workflow by combining:

- validated target selection
- bounded runtime orchestration
- artifact-backed session storage
- Moodle-aware interpretation

It is not trying to become a general PHP IDE or a replacement for Xdebug.

## Main layers

### 1. Public MCP application

Core file:

- [Application.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/server/Application.php)

Responsibilities:

- validate MCP tool inputs
- launch approved workflows
- persist sessions
- build summaries and Moodle mapping

### 2. Runtime subprocess application

Core files:

- [RuntimeApplication.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimeApplication.php)
- [RuntimeCli.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimeCli.php)

Responsibilities:

- expose sibling-tool style JSON commands
- keep session retrieval, planning, interpretation, and execution separate
- never require callers to scrape prose

### 3. Runtime helpers

Key files:

- [RuntimeRequestNormalizer.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimeRequestNormalizer.php)
- [RuntimePlanBuilder.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimePlanBuilder.php)
- [RuntimeInvestigationBuilder.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimeInvestigationBuilder.php)
- [RuntimeHealthReporter.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimeHealthReporter.php)
- [RuntimeEnvelopeFactory.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/runtime/RuntimeEnvelopeFactory.php)

These exist to keep the runtime layer readable and easy to extend without
re-growing a monolithic orchestration file.

### 4. Debug backend seam

Core files:

- [DebugBackendInterface.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/debug_backend/DebugBackendInterface.php)
- [MockDebugBackend.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/debug_backend/MockDebugBackend.php)
- [XdebugDebugBackend.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/debug_backend/XdebugDebugBackend.php)

Guideline:

- preserve this seam
- keep MCP and runtime layers backend-agnostic where possible

### 5. Interpretation layer

Core files:

- [MoodleContextMapper.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/server/MoodleContextMapper.php)
- [SummaryBuilder.php](/Users/mattp/projects/agentic_debug/src/moodle_debug/server/SummaryBuilder.php)

Guideline:

- keep heuristics explicit and testable
- separate facts from inferences
- keep confidence conservative

## Testing strategy

### Default suite

Run:

```bash
vendor/bin/phpunit
```

This should stay green without Docker or Xdebug.

### Opt-in real suite

Run only when the local Moodle Docker environment is ready:

```bash
MOODLE_DEBUG_RUN_REAL_XDEBUG_TESTS=1 \
MOODLE_DIR=/path/to/moodle \
MOODLE_DOCKER_DIR=/path/to/moodle-docker \
MOODLE_DOCKER_BIN_DIR=/path/to/moodle-docker/bin \
WEBSERVER_SERVICE=webserver \
vendor/bin/phpunit tests/integration_real/RealXdebugBackendTest.php
```

### What to add tests for

When changing behavior, prefer adding tests at the smallest useful level:

- unit tests for normalization, mapping, summary, and payload shaping
- integration tests for public workflows and runtime intents
- contract tests for MCP and runtime schemas
- opt-in real tests only when validating actual Docker/Xdebug behavior

## Safe extension guidelines

- Do not widen target classes casually. PHPUnit selectors and allowlisted CLI paths are intentional guardrails.
- Prefer adding small helpers over growing the main application files again.
- Keep runtime subprocess schemas and MCP schemas separate.
- Preserve JSON-only stdout for runtime CLI commands.
- Treat session artifacts as immutable snapshots.
- When adding heuristics, document the rule and add a focused test.

## Useful entrypoints

- MCP server: `php bin/moodle-debug server`
- runtime health: `php bin/moodle-debug health --json '{}'`
- runtime query: `php bin/moodle-debug runtime-query --json '{...}'`
- mock PHPUnit flow: `php bin/moodle-debug run phpunit --test "..."`
