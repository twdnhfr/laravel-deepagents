# Changelog

All notable changes to `laravel-deepagents` will be documented in this file.

## v0.4.0 - 2026-06-11

Human-in-the-loop, completed: per-call decisions before `resume()`. Until now a suspended run could only be approved wholesale — the host can now approve, correct, or reject each pending tool call individually.

### Added

- **`RunState::approve(...ids)`** — explicitly approve pending tool calls (optional; an undecided call is executed on `resume()` as before).
- **`RunState::edit(id, arguments)`** — approve a call with corrected arguments. The model's original request stays on the assistant message in the history, so the change is auditable.
- **`RunState::reject(id, reason)`** — the call is never executed; the reason is handed back to the model as the tool's result (`The user rejected this tool call: …`), so it can adjust its plan. The equivalent of deepagents' `respond`.

Decisions are plain data on the serialized run state, so they survive `toJson()`/`fromJson()` across HTTP/queue boundaries — collect them in a controller, persist, resume in a worker. `resume()` is unchanged: without recorded decisions it behaves exactly as before. Deciding on a non-suspended run or an unknown call id throws a `LoopException`.

## v0.3.0 - 2026-06-11

Pre-1.0 cleanup and loop-semantics fixes — part 1 of the road to 1.0.

### Changed

- **`maxTurns` is now a per-user-request budget tracked on the `RunState`** (new serialized `turns` field). An approval pause no longer refills the budget — turns are counted across `resume()`; `continue()` resets the budget for each fresh user message. Run states serialized by older versions restore fine (`turns` defaults to 0).
- **`continue()` on a suspended run now throws** `LoopException::cannotContinueSuspended` instead of silently discarding the pending tool calls. `resume()` the run first.

### Fixed

- A `beforeModel` hook that halts the run now skips the model call entirely, instead of paying for a turn whose result is discarded.
- The `task` tool now surfaces a suspended sub-agent as a clear misconfiguration message and returns a halted sub-agent's `haltReason`, instead of "(the sub-agent returned no output)".

### Removed

- Package-skeleton leftovers that were never part of the real API: the placeholder `laravel-deepagents` artisan command, the empty `LaravelDeepagents` class and facade (incl. the composer alias), the unused views/factories scaffolding, and the `spatie/laravel-ray` dev-dependency.

## v0.2.0 - 2026-05-30

Resilience: around-call middleware at the loop seam ([ADR-0005](docs/adr/0005-resilience-at-the-loop-seam.md)). Transient failure — rate limits, dropped connections, flaky tools, no-progress loops — now has a first-class home in the package-owned loop, with batteries included and an escape hatch for host-specific policy. See [`docs/resilience.md`](docs/resilience.md).

### Added

- **Middleware seams** `ModelMiddleware` / `ToolMiddleware` wrapping the per-turn model call and each tool invocation, composed onion-style; reach them via `modelMiddleware()` / `toolMiddleware()`.
- **Provider failover** — `provider()` now also accepts an ordered chain (`['anthropic' => 'claude-sonnet-4-5', 'openai' => null]`). `FailoverProviders` reuses the SDK's `FailoverableException` and emits its `ProviderFailedOver` event.
- **`retryModelCall()`** — retries transient, non-failoverable errors (dropped connection / timeout); rate limits route to failover instead, by design.
- **`validateToolArgs()`** — validates a tool call against the tool's own `schema()` and returns a corrective message to the model on a mismatch, instead of calling the tool with bad input.
- **`retryTools()`** — retries a tool invocation on a host-defined transient predicate.
- **`guardAgainstLoops()`** — stops a no-progress run (the same tool call repeated N times) via a new terminal `RunState` status `halted` (+ `haltReason`), serializable like any other state.

### Changed

- **`DeepAgent::provider()`** accepts `string|TextProvider|array` — the array form configures a failover chain, mirroring `laravel/ai`'s own provider-list convention. Existing single-provider usage is unchanged.

**Full Changelog**: https://github.com/twdnhfr/laravel-deepagents/compare/v0.1.0...v0.2.0

## v0.1.0 - 2026-05-29

First development release — the runtime core plus the Tier 1 / Tier 2 feature set.
See [`docs/adr/`](docs/adr/) for the load-bearing architecture decisions and
[`docs/adoption.md`](docs/adoption.md) for the feature roadmap.

### Added

- **`DeepAgent`** — fluent builder and front door: `provider()`, `model()`,
  `instructions()`, `tool()`/`tools()`, `withTodos()`, `subAgent()`, `memory()`,
  `backend()`, `summarize()`, `requireApproval()`, `hook()`, `maxTurns()`,
  `basePrompt()`, `run()`, `resume()`, `continue()`.
- **Owned agent loop** (`Runtime\Loop`) — drives one model turn at a time via
  `maxSteps: 0` (the seam verified across Anthropic, OpenAI and Gemini), so the
  package controls tool execution rather than the SDK gateway.
- **Serializable `Runtime\RunState`** and **human-in-the-loop**: `requireApproval()`
  takes all tools / a tool allow-list / a per-call closure; a gated turn suspends,
  serializes (`toJson()`), and continues with `resume()`.
- **Multi-turn conversations** — `continue()` carries full prior context forward.
- **Built-in tools** — `write_todos` (planning) and `task` (sub-agents with an
  isolated `RunState`).
- **Context management** — automatic history summarization (`summarize()`).
- **Memory** — load `AGENTS.md`-style files into the system prompt (`memory()`),
  plus a default BASE prompt with `BASE → instructions → memory` assembly.
- **Loop hooks** (`Runtime\Hook` / `LoopHook`) for `beforeModel` / `afterModel`.
- **Robustness** — safe tool execution (a thrown tool error is returned to the
  model instead of crashing the run) and dangling tool-call repair.
- **Backends** — pluggable storage via `Contracts\Backend`: `StateBackend`
  (in-memory), `FilesystemBackend` (disk), `DatabaseBackend` (a table; persistent)
  and `CacheBackend` (any cache store + TTL). `BackendManager` + `config/deepagents.php`
  pick the default; `DeepAgent->backend()` overrides per agent.
- **Context** — large tool outputs are offloaded to the backend
  (`offloadLargeToolResults()`) and clipped inline; `read_artifact`/`write_artifact`
  (`withArtifacts()`) read/write artifacts.

### Notes

- Requires `laravel/ai` `^0.7.2` (Laravel 13, PHP 8.3+). Laravel 12 is not
  supported: `laravel/ai`'s `Tool::schema()` type-hints
  `Illuminate\Contracts\JsonSchema\JsonSchema`, a contract that only ships in
  Laravel 13 — on Laravel 12 the gateway passes a `JsonSchemaTypeFactory` that
  does not implement it, raising a `TypeError`.
- A live example app — a small Laravel chat UI built on this package — lives at
  [`twdnhfr/deepagents-chat`](https://github.com/twdnhfr/deepagents-chat).
- Filesystem & shell *tools* are intentionally deferred; token streaming through
  the loop is omitted by design (see [ADR-0004](docs/adr/0004-no-token-streaming-through-the-loop.md)).
