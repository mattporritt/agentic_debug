# `moodle_debug` Runtime Subprocess Contract

## Purpose

This document describes the subprocess-facing runtime contract implemented by `moodle_debug`.

It exists alongside the MCP contract:

- MCP remains the direct tool surface for agent use
- the runtime contract is a thin JSON CLI intended for sibling-tool orchestration

The runtime contract does not replace the MCP tool contract.

## Commands

Supported entrypoints:

- `php bin/moodle-debug runtime-query --json '{...}'`
- `php bin/moodle-debug runtime-query --input /path/to/request.json`
- `php bin/moodle-debug runtime-query --stdin`
- `php bin/moodle-debug health --json '{}'`

Behavior:

- stdout is JSON only
- expected operational failures return a structured JSON envelope on stdout
- exit code is non-zero for failed operations
- incidental human-readable usage text stays on stderr only

## Supported intents

`runtime-query` accepts explicit bounded intents only:

- `interpret_session`
- `get_session`
- `plan_phpunit`
- `plan_cli`
- `execute_phpunit`
- `execute_cli`

No vague natural-language target selection is supported in this phase.

## Top-level envelope

Every successful or failed runtime response has the same top-level shape:

- `tool`
- `version`
- `query`
- `normalized_query`
- `intent`
- `results`
- `diagnostics`
- `meta`

### Top-level meanings

- `query`: original request as received by the CLI
- `normalized_query`: request after defaults and runtime normalization
- `results`: ordered structured payloads for downstream orchestration
- `diagnostics`: structured warnings/errors that do not require scraping prose
- `meta`: overall status and execution metadata

## Result item shape

Each item in `results` includes:

- `id`
- `type`
- `rank`
- `confidence`
- `source`
- `content`
- `diagnostics`

Common `type` values:

- `session_interpretation`
- `session_record`
- `execution_plan`
- `debug_execution`
- `health_report`

## Investigation payload

Execution and interpretation results expose a stable machine-friendly investigation payload with:

- `session_provenance`
- `likely_fault`
- `candidate_frames`
- `inspection_targets`
- `rerun_command`

`likely_fault` includes:

- `frame_index`
- `file`
- `line`
- `function`
- `class`
- `component`
- `subsystem`
- `issue_category`
- `confidence`

`inspection_targets` are intentionally bounded. Current kinds include:

- `file`
- `test_selector`
- `cli_script`
- `moodle_component`

## Plan mode

Plan mode resolves and validates a target without launching it.

Returned plan details include:

- target type and normalized target
- runtime profile name
- backend kind
- execution transport
- working directory
- intended command
- listener callback host/port
- policy warnings

Plan mode is safe for proactive orchestrator use.

## Health mode

`health` returns a single `health_report` result with subsystem statuses.

Current subsystem names:

- `config`
- `session_store`
- `codex_env`
- `listener`
- `docker`
- `xdebug`
- `supported_targets`

Subsystem status values:

- `ok`
- `warn`
- `fail`

Health is conservative:

- it does not launch real debug targets
- it verifies runtime/profile readiness
- it lightly probes listener bind capability
- it reports Xdebug container readiness as capability-oriented unless an explicit execution path is used

## Schema

The runtime JSON schema lives at:

- [runtime_contract.schema.json](/Users/mattp/projects/agentic_debug/docs/moodle_debug/schemas/runtime_contract.schema.json)

This schema is intentionally separate from:

- [moodle_debug.schema.json](/Users/mattp/projects/agentic_debug/docs/moodle_debug/schemas/moodle_debug.schema.json)

## Limits in this phase

- no orchestrator changes yet
- no web or Behat workflows
- no profiling/tracing/coverage
- no implicit target discovery from prose
- no live interactive stepping
