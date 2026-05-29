# ADR-0001: Own the agent loop (Path B) instead of the SDK's internal loop

- **Status:** Accepted
- **Date:** 2026-05-29
- **Deciders:** twdnhfr

## Context

This package is a deep-agent *harness* on top of `laravel/ai`. The defining
features we want — human-in-the-loop (HITL) approval before a tool runs,
mid-loop context management (summarization, tool-output offloading), and
resumable/checkpointed runs — all require the ability to intervene **between**
the model deciding to call a tool and that tool actually running.

`laravel/ai` does not expose that seam through its normal API:

- The model↔tool loop lives **inside each provider gateway**, implemented as a
  recursive `processResponse()` (e.g.
  `vendor/laravel/ai/src/Gateway/Anthropic/Concerns/ParsesTextResponses.php`)
  that executes tool calls and re-invokes the model until the model stops.
- The only callbacks around that loop are `onToolInvocation($invoking, $invoked)`
  — observation only; they cannot pause or resume.
- `Agent::prompt()` always **appends a fresh `UserMessage`** to the history
  (`vendor/laravel/ai/src/Providers/Concerns/GeneratesText.php:55`), so it
  cannot be used to continue a run from a tool result without polluting the
  conversation.

Two paths were considered:

- **Path A — extend the SDK loop.** Use the coarse `HasMiddleware` pipeline,
  tools, and prompt injection. Low effort, but the middleware only wraps the
  *outer* `prompt()` call, so true mid-loop hooks and HITL-before-tool are not
  achievable.
- **Path B — own a thin loop runtime.** Drive single model turns ourselves and
  run the loop in our own code, using `laravel/ai` purely as the
  provider/transport layer.

A public seam for Path B does exist: `TextProvider::textGateway()` is part of
the `TextProvider` contract, and `TextGateway::generateText()` accepts an
explicit `$messages` array — so we can control the full history per turn.

## Decision

Build a **package-owned, turn-by-turn agent loop** (Path B) as the runtime core:

- `src/Runtime/Loop.php` — drives one model turn at a time via
  `TextProvider::textGateway()->generateText(...)` with a caller-controlled
  message history (see [ADR-0002](0002-maxsteps-zero-single-turn-seam.md) for the
  single-turn mechanism).
- `src/Runtime/RunState.php` — the serializable run state (history, pending tool
  calls, status), so a run can pause, be persisted, and resume in a fresh
  process.
- `src/DeepAgent.php` — the fluent builder/front door that configures and drives
  the loop.

Path A remains available for simple cases via the SDK directly; this package's
value is the Path B runtime.

End-to-end viability is proven by `tests/Feature/RunStateSpikeTest.php` (pause →
serialize → resume → complete) on Anthropic and Gemini.

## Consequences

**Positive**

- Enables HITL approval *before* a tool executes, with serialize/resume.
- Enables mid-loop context management with access to the running history.
- Deterministic, inspectable control flow; checkpointing and audit are natural.
- We reuse the SDK for everything hard (provider transport, message mapping,
  tool schemas, structured output, streaming, fakes) — we only own the loop.

**Negative / risks**

- We depend on a lower-level SDK seam (`textGateway()->generateText()`) and on
  loop-control semantics (`maxSteps`) that could shift in future SDK releases.
  Mitigated by the spike tests acting as regression guards.
- We reimplement loop concerns (turn limits, error handling) the SDK otherwise
  handles internally.
- Streaming across self-driven turns is more involved than a single
  `prompt()` call (deferred).

## References

- `src/Runtime/Loop.php`, `src/Runtime/RunState.php`, `src/DeepAgent.php`
- `tests/Feature/RunStateSpikeTest.php`, `tests/Unit/LoopTest.php`
- [ADR-0002](0002-maxsteps-zero-single-turn-seam.md)
