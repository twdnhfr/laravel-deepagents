# ADR-0002: Use `maxSteps: 0` as the uniform single-turn seam

- **Status:** Accepted
- **Date:** 2026-05-29
- **Deciders:** twdnhfr

## Context

[ADR-0001](0001-own-the-agent-loop.md) commits us to driving the loop ourselves.
To do that we need the gateway to perform **exactly one model turn** and hand us
the model's tool-call intention **without executing it**, so we can apply
approval/permissions and run the tool ourselves.

The only public lever over the gateway's internal loop is `maxSteps`, resolved
per agent by `TextGenerationOptions::forAgent()` from either a `maxSteps()`
method or a `#[MaxSteps]` attribute — and, when driving the gateway directly,
passed straight into `new TextGenerationOptions(maxSteps: ...)`.

The obvious guess was `maxSteps: 1`. A spike (`tests/Feature/MaxStepsSpikeTest.php`,
real provider parsing via `Http::fake`) showed the guard formula is **not the
same across providers**:

- **Anthropic** — `$depth + 1 < $maxSteps` (`depth` starts at 0).
- **OpenAI** — `$steps->count() < $maxSteps`, with the step pushed **before** the
  guard (count is 1 at the guard).
- **Gemini** — `$steps->count() < $maxSteps`, but the step is pushed **after**
  the guard (count is 0 at the guard).

Consequence at `maxSteps: 1`:

| Provider  | Guard at first turn | Tool executed? |
|-----------|---------------------|----------------|
| Anthropic | `1 < 1` → false     | no             |
| OpenAI    | `1 < 1` → false     | no             |
| Gemini    | `0 < 1` → **true**  | **yes** (one round) |

So `maxSteps: 1` silently runs one tool round on Gemini — before any approval
gate. That would be a correctness bug in a HITL harness.

At `maxSteps: 0` the guards are `1 < 0`, `1 < 0`, and `0 < 0` respectively — all
false. Zero execution, one model turn, tool calls returned, on **all three**.

## Decision

Drive every turn of the package loop with **`maxSteps: 0`**. Never use
`maxSteps: 1` as the single-turn seam — it is not uniform across providers.

The behaviour is asserted for Anthropic, OpenAI, and Gemini in
`tests/Feature/MaxStepsSpikeTest.php`, including a dedicated test that pins the
`maxSteps: 1` Gemini divergence so a future SDK change cannot reintroduce it
unnoticed.

## Consequences

**Positive**

- One provider-agnostic mechanism for single-turn generation; the loop code
  stays free of per-provider branching.
- The approval/permission gate is guaranteed to run *before* any tool, on every
  provider.

**Negative / risks**

- Relies on SDK-internal guard semantics (`$maxSteps ?? default`, where `0` is
  honoured because it is not null). A future SDK could treat `0` specially;
  the spike tests guard against silent regressions.
- Coverage is currently Anthropic/OpenAI/Gemini. Groq, Bedrock, Mistral, etc.
  each reimplement `processResponse()` and remain **unverified** — each needs a
  fixture in the spike before we claim support.

## References

- `tests/Feature/MaxStepsSpikeTest.php`
- `src/Runtime/Loop.php` (`new TextGenerationOptions(maxSteps: 0)`)
- `vendor/laravel/ai/src/Gateway/{Anthropic,OpenAi,Gemini}/Concerns/ParsesTextResponses.php`
- [ADR-0001](0001-own-the-agent-loop.md)
