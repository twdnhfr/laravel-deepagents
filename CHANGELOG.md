# Changelog

All notable changes to `laravel-deepagents` will be documented in this file.

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
