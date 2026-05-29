# ADR-0004: No token streaming through the owned loop (for now)

- **Status:** Accepted
- **Date:** 2026-05-29
- **Deciders:** twdnhfr

## Context

A streaming chat UX (tokens appearing live) is desirable. We investigated wiring
real token streaming into the package loop and found a hard incompatibility with
[ADR-0001](0001-own-the-agent-loop.md) (we own tool execution).

`laravel/ai`'s `TextGateway::streamText()` **executes tools inside the gateway
while streaming**: in `…/Concerns/HandlesTextStreaming.php`, once the model emits
tool calls the gateway calls `executeTool()` unconditionally
(`handleStreamingToolCalls`), and `maxSteps` only controls whether it *loops
further* — not *whether* it executes. This differs from the non-streaming path,
where `maxSteps: 0` prevents execution and hands us the tool-call intention
(see [ADR-0002](0002-maxsteps-zero-single-turn-seam.md)).

Because the streaming path executes tools itself, it bypasses the three things
the owned loop exists to provide:

- **human-in-the-loop approval** (pause *before* a tool runs),
- **`RunAware`** state injection (e.g. `write_todos` needs the run bound),
- **safe tool execution** (catch a thrown tool error).

The only ways to get real token streaming were:

1. **Autonomous-only**: let the gateway stream and execute tools — loses HITL,
   `RunAware`, and safe-exec.
2. **Final-answer streaming**: drive tool turns non-streamed (features intact),
   then re-generate the final, tool-free answer via `streamText` — costs **one
   extra model call per run** (detect "done" via `generateText`, then stream a
   fresh final answer).

Neither was attractive: (1) sacrifices the package's core value, (2) pays an
extra call and can drift from the detected answer.

## Decision

**Do not provide token streaming through the agent loop for now.** Keep the loop
non-streamed so HITL, `RunAware`, and safe execution stay correct and simple.

Hosts that want a streaming *feel* can reveal the completed final answer
progressively on the client (no library support needed).

Revisit if `laravel/ai` gains a streaming mode that yields tool-call intentions
**without executing them** — i.e. a streaming equivalent of `maxSteps: 0`. Then
final-answer (or full) streaming becomes clean and free of the trade-offs above.

## Consequences

**Positive**

- The loop's guarantees (approval before tools, state injection, error capture)
  hold unconditionally — no "streaming mode" that quietly drops them.
- No extra model calls; simpler runtime.
- The dead-end is documented, so it isn't re-investigated.

**Negative**

- No low-latency first-token UX from the library; a chat that wants the look of
  streaming must fake it client-side over the completed answer.

## References

- `vendor/laravel/ai/src/Gateway/Anthropic/Concerns/HandlesTextStreaming.php`
  (`handleStreamingToolCalls` executes tools regardless of `maxSteps`)
- [ADR-0001](0001-own-the-agent-loop.md), [ADR-0002](0002-maxsteps-zero-single-turn-seam.md)
