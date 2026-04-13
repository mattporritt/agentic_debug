# ADR 0001: `moodle_debug` is a Moodle-aware wrapper, not a custom debugger

## Status

Accepted

## Context

The project needs an MCP server that makes runtime debugging usable and safe for an agentic assistant working on Moodle.

One option would be to build a custom PHP debugger. Another is to rely on Xdebug and an existing generic Xdebug-compatible debugger MCP or adapter, and add a Moodle-specific orchestration layer on top.

## Decision

`moodle_debug` will not implement PHP runtime debugging itself.

Instead it will:

- use Xdebug as the runtime engine
- use an existing generic debug MCP or adapter for low-level debugger operations
- provide Moodle-aware orchestration, guardrails, artifact capture, and summary generation

## Consequences

Positive:

- narrower and safer scope
- leverages mature debugger infrastructure
- focuses implementation effort on the genuinely missing Moodle-specific layer
- easier to test deterministically at the contract level

Negative:

- depends on capabilities and stability of the chosen generic debugger backend
- some low-level debugger limitations will remain outside `moodle_debug` control

## Rationale

The main unsolved problem is not how to debug PHP at the protocol level. It is how to turn raw debugger behavior into agent-friendly Moodle workflows that are deterministic, reproducible, and safe.
