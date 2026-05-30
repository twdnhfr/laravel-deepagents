# ADR-0005: Resilience lives at the loop seam, as around-call middleware

- **Status:** Accepted
- **Date:** 2026-05-30
- **Deciders:** twdnhfr

## Context

Real agent runs hit transient failures: a provider rate-limits, a 5xx blips, a
network call times out, a tool throws, or the model gets stuck repeating the
same tool call forever. A library that owns the loop must offer somewhere to
handle these — otherwise every host re-implements retry, failover, and
loop-detection by hand (as observed in a downstream app that wraps each tool in
a retrying decorator and routes model calls through a circuit breaker).

Two facts shape where that handling can live:

1. **`laravel/ai` already has a failover vocabulary, but not on our path.** The
   SDK defines `FailoverableException` (a marker), emits an `AgentFailedOver`
   event, and implements provider-chain failover — but only in `Promptable`
   (`vendor/laravel/ai/src/Promptable.php:99-125,191-204`) and the `Pending*`
   responses, i.e. the gateway-internal loop we deliberately replaced in
   [ADR-0001](0001-own-the-agent-loop.md). Our single-turn `generateText(maxSteps: 0)`
   call (`src/Runtime/Loop.php:144`) bypasses it. The SDK's failover is a pure
   *try-next-provider-on-`FailoverableException`* loop — **no** retry/backoff,
   **no** circuit breaker, **no** cooldown persistence.

2. **Our existing extension point is the wrong shape for this.** A `Hook`
   (`src/Runtime/Hook.php`) runs `beforeModel` / `afterModel` and operates on the
   `RunState` *between* calls. Retry, failover, and timeouts need to wrap a *call*
   — decide whether to invoke it, re-invoke it, swap its provider, or
   short-circuit it. A between-calls hook cannot do that.

We also want to stay unopinionated: the things that vary per host — circuit
breaker with cross-request cache state, provider-specific rate-limit header
parsing, the *name* of a fallback provider, localized error messages, token
budgets — must not be baked into the library.

## Decision

**Add around-call middleware as the resilience seam, keep `Hook` for state, and
ship generic policies on top of the seam — but not host-specific policy.**

1. **Two middleware contracts**, distinct from `Hook`:
   - `ModelMiddleware` wraps the per-turn `generateText` call. It can retry,
     fail over to another provider/model, log, or short-circuit.
   - `ToolMiddleware` wraps a single tool invocation. It can retry, validate
     arguments, time out, or rewrite the result.

   The seam is exactly at the `generateText` call (`Loop.php:144`) and the
   `$tool->handle()` call (`Loop.php:191`), because the assistant turn is
   recorded onto the history only *after* `generateText` returns
   (`Loop.php:156`). A call that throws leaves the history untouched, so a retry
   or fail-over re-issues a clean turn — no half-written state to repair.

2. **Batteries, built on that seam and shipped with the library:**
   - provider failover — mirrors the SDK: iterate a provider/model chain, catch
     `FailoverableException`, emit `AgentFailedOver`, try the next. We reuse the
     SDK's marker and event rather than inventing our own vocabulary.
   - retry-with-backoff — for transient errors that are *not* failoverable
     (connection resets, timeouts). Rate-limit (`FailoverableException`) routes
     to failover, not to a pointless same-provider retry.
   - argument validation — checks tool arguments against the tool's own
     `schema()` and returns a structured error to the model so it self-corrects.
   - tool retry — re-invokes a tool on a host-defined transient predicate.

3. **A graceful halt path.** Add `RunState::STATUS_HALTED` and a `haltReason`,
   plus a check in `Loop::advance` after each turn so any `afterModel` hook can
   stop the run cleanly (distinct from "done"). A built-in `LoopGuard` hook uses
   this to stop a no-progress loop (the same tool call repeated N times) instead
   of burning turns until `maxTurns` throws.

4. **What stays out of the library.** Circuit-breaker cross-request state,
   provider header parsing, fallback-provider names, localized messages, token
   budgets. Hosts plug these in through the same middleware seam (the batteries
   above are themselves implemented on it — so the seam is proven sufficient).

Middleware are runtime config on the `Loop` (like provider, tools, and hooks) —
**not** serialized. This preserves the `RunState` invariant of holding no live
objects ([ADR-0001](0001-own-the-agent-loop.md)). The new halt status and
reason *are* plain data, so a halted run still round-trips.

Interface sketches (subject to change) live in
[../resilience.md](../resilience.md); this ADR records only the decision.

## Consequences

**Positive**

- One place to handle transient failure; hosts stop hand-rolling it per tool.
- The library re-uses the SDK's failover semantics (`FailoverableException`,
  `AgentFailedOver`) at our own seam instead of forking a parallel concept.
- No-progress loops stop with a clear, serialized reason rather than silently
  running to the turn limit.
- Host-specific policy (circuit breakers, header parsing, i18n) plugs in without
  forking the loop — the shipped batteries dogfood the seam.

**Negative**

- Two new contracts and a middleware pipeline add surface area and a small
  per-call indirection cost.
- `Hook` vs `ModelMiddleware`/`ToolMiddleware` is a distinction users must learn
  (state-between-calls vs around-a-call); the docs must draw the line clearly.
- A new terminal status (`halted`) is a breaking addition for any caller that
  exhaustively matches on `RunState::STATUS_*`.

## References

- `vendor/laravel/ai/src/Promptable.php` (`iterateProvidersWithFailover`,
  `withModelFailover` — the SDK's failover, on the path we replaced)
- `vendor/laravel/ai/src/Exceptions/FailoverableException.php`,
  `vendor/laravel/ai/src/Gateway/Concerns/HandlesFailoverErrors.php`
- `src/Runtime/Loop.php` (`turn()` model call, `executeCalls()` tool call)
- `src/Runtime/Hook.php`, `src/Runtime/RunState.php`
- [ADR-0001](0001-own-the-agent-loop.md), [ADR-0004](0004-no-token-streaming-through-the-loop.md)
- Interface sketches: [../resilience.md](../resilience.md)
