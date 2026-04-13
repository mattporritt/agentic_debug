# `moodle_debug` Implementation Plan

## Phase 1: Contract/spec finalisation

- Review and lock `docs/moodle_debug/design.md`
- Review and lock `docs/moodle_debug/workflows.md`
- Review and lock `docs/moodle_debug/schemas/moodle_debug.schema.json`
- Confirm the concrete generic debugger backend
- Confirm runtime profile configuration model

Exit criteria:

- design sign-off
- no unresolved blockers marked `yes` in the open questions table

## Phase 2: Server skeleton + mocked backend

- Create MCP server skeleton
- Implement request/response schema validation
- Implement runtime profile loading
- Implement in-memory session store
- Implement mocked debugger backend abstraction
- Implement contract tests for all v1 tools

Exit criteria:

- all tools callable
- success/failure payloads validate against schema
- mocked workflows produce deterministic results

## Phase 3: PHPUnit workflow

- Add real PHPUnit target parsing/normalization
- Add approved launch recipe builder
- Add debugger-backed capture flow
- Add persistence of bounded artifacts
- Add failure-path integration tests

Exit criteria:

- real failing Moodle PHPUnit test can be debugged end-to-end

## Phase 4: CLI workflow

- Add CLI path allowlist enforcement
- Add argv-safe CLI launch recipe builder
- Reuse capture pipeline
- Add real integration tests

Exit criteria:

- real failing Moodle CLI script can be debugged end-to-end

## Phase 5: Summarisation + Moodle-aware interpretation

- Implement frame/component mapping heuristics
- Implement candidate fault-frame selection
- Implement confidence-scored summary generation
- Add unit/golden tests

Exit criteria:

- summaries consistently separate facts, inferences, and confidence

## Phase 6: Docs/tests/smoke validation

- Add operator/developer docs
- Add smoke fixtures under `/_smoke_test`
- Add failure-path smoke coverage
- Validate conservative guardrails

Exit criteria:

- documented local setup
- repeatable smoke verification
