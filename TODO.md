# `twdnhfr/laravel-deepagents` — Roadmap

> A batteries-included **deep-agent harness** for the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai` `^0.7`).
> `^0.7` is the currently published line; it already contains the 1.x-bound code we build on. Move to `^1.0` once it's tagged on Packagist.
> Inspired by [`langchain-ai/deepagents`](https://github.com/langchain-ai/deepagents): planning, sub-agents, a virtual
> filesystem, persistent memory, skills, human-in-the-loop and automatic context management — as an opinionated layer on
> top of the SDK's agent loop. Distilled from the hand-built harness in our PMKI app, cleaned up for open source.

This file is a living roadmap, not a spec. Check items off as they land. Decisions marked **🔑** are load-bearing and
should be resolved before the code that depends on them.

---

## 1. Vision & scope

`laravel/ai` gives you a **unified, multi-modal provider SDK** (chat, tools, structured output, images, audio,
embeddings, vector stores, streaming, queue, broadcast) plus a coarse `HasMiddleware` pipeline and an `AgentTool` for
sub-agent delegation. What it does **not** ship is the *harness* — the opinionated long-horizon agent loop with planning,
a virtual filesystem, context compaction, skills, memory and HITL. That gap is this package.

**We build the harness. The SDK stays the engine.**

### In scope
- An opinionated agent **factory/builder** that returns a fully-wired `laravel/ai` agent.
- A built-in **tool suite**: todos/planning, filesystem, shell, sub-agent `task`.
- **Sub-agents** with isolated context (sync, queued, and "bring-your-own-runnable").
- **Pluggable backends** for file storage & execution (state, local disk, sandbox, persistent store).
- **Context management**: summarization + tool-output offloading.
- **Persistent memory** and **skills** loaded on demand.
- **Human-in-the-loop**: approve / edit / reject tool calls, with resume.
- **Permissions**, **harness/model profiles**, **prompt assembly**, **MCP** tools.

### Non-goals (explicitly **not** this package)
- ❌ Multi-modal generation (image/audio/embeddings/reranking) — that's `laravel/ai`'s job; we only consume it.
- ❌ PMKI-specific domain tools (document ingest, PDF transcription, citations, tagging) — those stay in the app.
- ❌ A chat UI / frontend. We expose state & streaming; rendering is the host app's concern.
- ❌ Re-implementing provider transport or failover — owned by `laravel/ai`.

---

## 2. 🔑 The central architectural decision

deepagents' power comes from **fine-grained middleware hooks** (`before_model`, `after_model`, `wrap_model_call`,
`wrap_tool_call`) and **checkpointed, resumable** execution — both provided by LangGraph. In `laravel/ai` the
model↔tool loop lives **inside the Provider** (`InvokesTools`), and the only public seam is the **coarse `HasMiddleware`
pipeline that wraps the whole `prompt()` call**. This is the impedance mismatch we must design around.

Two paths — likely **start at A, grow the loop toward B** where we genuinely need it:

- **Path A — extend the SDK loop.** Use `HasMiddleware` + tools + prompt injection + a base `Agent` class. Everything
  PMKI does today fits here. Fast, low-risk. **Limitation:** no true *mid-loop* hooks → summarization, tool-output
  offloading and HITL-before-tool can only be approximated.
- **Path B — own a thin loop runtime.** Drive single-shot generations ourselves in a small PHP step-loop that adds the
  hook points and **state checkpointing**, using `laravel/ai` purely as provider/transport. Faithful to deepagents;
  unlocks real HITL-resume and mid-loop context management. ~a few hundred LOC for the loop; the hard part is the
  serializable run-state + persistence.

- [ ] **🔑 Decide A vs B per capability.** Path B itself is decided and recorded in
      [`docs/adr/0001`](docs/adr/0001-own-the-agent-loop.md) + [`0002`](docs/adr/0002-maxsteps-zero-single-turn-seam.md);
      still open is which capabilities ship on Path A vs the Path B runtime.
- [x] Spike: confirm the loop seam. **Done** → `tests/Feature/MaxStepsSpikeTest.php`. Finding: set **`maxSteps:0`** (via a
      `maxSteps()` method or `#[MaxSteps]` attribute, resolved by `TextGenerationOptions::forAgent()`) to make the gateway
      do exactly one model turn and return the model's tool calls **without executing them** — the seam Path B needs.
      Verified uniform across Anthropic + OpenAI + Gemini. ⚠️ `maxSteps:1` is **not** uniform — Gemini pushes its step
      after the guard (`count()==0`) and runs one tool round, so use `0`, not `1`. Groq/Bedrock/… still unverified.
- [x] Spike: prototype HITL. **Done** → `tests/Feature/RunStateSpikeTest.php`. A tiny stateless `HitlLoop` drives the
      `maxSteps:0` seam turn-by-turn via the public `TextProvider::textGateway()->generateText()` (which, unlike
      `Agent::prompt()`, lets us control the full message history). It pauses before the tool with nothing executed,
      the run survives `json_encode → json_decode → RunState::fromArray`, and `resume()` executes the approved tool and
      runs to completion. Verified on Anthropic + Gemini. → **Path B is viable end-to-end.**
      Now promoted to real classes: `src/Runtime/RunState.php` + `src/Runtime/Loop.php` (autonomous *and* approval modes,
      multi-tool-call turns, turn limit). The spike tests now exercise the production classes.

---

## 3. Build-on points in `laravel/ai` `^0.7`

What we reuse instead of rebuilding:

- [ ] `Contracts\Agent` + `Promptable` — `prompt()` / `stream()` / `queue()` / `broadcast()`.
- [ ] `Contracts\Tool` + `ObjectSchema` / `Schema` — tool definition & JSON-schema args.
- [ ] `Tools\AgentTool` — sub-agent-as-tool (our `task` builds on this).
- [ ] `Contracts\HasMiddleware` + `Middleware\*` — the prompt pipeline (Path A seam).
- [ ] `Contracts\ConversationStore` + `Concerns\HasConversations` — conversation persistence.
- [ ] `Responses\Streamable*` + Vercel/Reverb streaming — for live output.
- [ ] `Concerns\InteractsWithFake*` — for our test suite (fake providers/tools).
- [ ] Structured output (`StructuredAgentResponse`) — for typed sub-agent results.

---

## 4. Feature roadmap

### M0 — Foundation & scaffolding
- [x] Scaffold from `spatie/package-skeleton-laravel`.
- [x] Name, namespace (`Twdnhfr\LaravelDeepagents`), author, `laravel/ai: ^0.7` dependency.
- [x] Record core architecture decisions as ADRs → [`docs/adr/`](docs/adr/) (Path B loop, `maxSteps:0` seam, `^0.7`).
- [ ] Define core contracts: `Backend`, `HarnessProfile`, `Skill`, `Memory`, `Tool` (or alias the SDK's).
- [ ] Config file (`config/deepagents.php`): default model, backend, limits, profiles, feature flags.
- [ ] CI green on PHP 8.3/8.4 × Laravel 13 (matrix), PHPStan, Pint.

### M1 — Core agent + built-in tools
- [x] `DeepAgent` fluent builder (`make()`) → `src/DeepAgent.php`. Configures provider/model/instructions/tools/
      approval/turn-limit and drives the run through `Runtime\Loop`: `run()` returns a completed-or-suspended
      `RunState`, `resume()` continues. (Drives our own loop, not the SDK's internal one — that's the whole point.)
- [x] **Multi-turn**: `DeepAgent->continue(RunState, message)` appends the next user message to an existing run and
      advances it, keeping full prior context across turns (persist the serializable `RunState` between requests).
- [x] **Planning**: `write_todos` tool → `src/Tools/WriteTodos.php`; todos stored on `RunState->todos` (serialize/resume),
      wired via the `Tools\RunAware` seam (loop injects the run before `handle()`). `DeepAgent->withTodos()` adds it.
- [x] **Loop hooks** (`beforeModel`/`afterModel`) → `src/Runtime/Hook.php` + `LoopHook`; run around each turn, registered via `DeepAgent->hook()`.
- [x] **Context management**: `src/Context/SummarizeHistory.php` compacts the history past a token budget (tool-pair-safe). `DeepAgent->summarize()`.
- [x] **Sub-agent `task` tool**: `src/Tools/Task.php` — nested `DeepAgent` run with an isolated `RunState`. `DeepAgent->subAgent()`.
- [x] Default system-prompt: `DeepAgent::BASE_PROMPT` prepended by default; assembly order BASE → instructions → memory; `basePrompt()` overrides/disables.
- [🧊] **Filesystem & shell tools** (`ls/read_file/write_file/edit_file/glob/grep`, `execute`) — **deferred**: this package
      targets orchestration, not host-filesystem mutation. See the scope note in [`docs/adoption.md`](docs/adoption.md).

### M2 — Backends (pluggable file storage & execution)
- [x] `Backend` contract → `src/Contracts/Backend.php` (read/write/delete/exists/list). Consumed by memory loading and
      artifact offloading. (edit/glob/grep + a `Sandbox` for `execute` only matter once filesystem/shell tools land.)
- [x] `StateBackend` → in-memory, serializable. `FilesystemBackend` → real files under a root (`..` guard).
- [x] `DatabaseBackend` → a `path`/`contents` table (persistent; survives suspend/resume). Migration: `create_deepagents_artifacts_table`.
- [x] `CacheBackend` → any Laravel cache store + TTL (listing unsupported).
- [x] `BackendManager` + `config/deepagents.php` — config-driven default backend (`backend` / `backends`), resolved by `DeepAgent`.
- [ ] `SandboxBackend` — shell execution (local; pluggable for Docker/remote later). *(with filesystem/shell tools)*
- [ ] `CompositeBackend` — route paths to different backends.
- [ ] **Permissions**: ordered allow/deny rules enforced at the tool level; sub-agents inherit unless overridden.
- [ ] **Permissions**: ordered allow/deny rules enforced at the tool level; sub-agents inherit unless overridden.

### M3 — Context management
- [ ] **Summarization middleware**: compress long histories at a token threshold (use `yethee/tiktoken` for counting).
- [x] **Tool-output offloading**: `offloadLargeToolResults()` moves oversized tool results into the storage **`Backend`** (via the `Tools\BackendAware` seam) and clips the inline content to a preview + pointer, so the `RunState` blob stays small. `read_artifact`/`write_artifact` tools (`withArtifacts()`) let the model retrieve a window of, or store, artifacts. Use a persistent `backend()` for artifacts that must survive suspend/resume.
- [ ] Configurable trigger thresholds + per-agent overrides.
- [ ] (Path B) hook this *between* steps rather than only around the outer prompt.

### M4 — Memory & Skills
- [x] **Memory**: `DeepAgent->memory(...paths)` loads `AGENTS.md`-style files (read from the agent's `backend()`) into the
      system prompt at run start. `FilesystemBackend` added for on-disk files. (Agent-driven `get`/`manage` memory tools: later.)
- [ ] Cross-session persistence of memory via a store.
- [ ] **Skills**: discover + load-on-demand reusable instruction bundles; `SkillManager` + `skill` tool.
- [ ] Skill sources resolvable from disk and/or DB (last-wins override).

### M5 — Human-in-the-loop & safety
- [x] **Interrupt-on-tool**: `requireApproval()` takes all / a tool allow-list / a per-call closure; a turn with any gated call suspends. (edit/reject of a pending call: later.)
- [x] **🔑 Resume**: `RunState->toJson()` / `RunState::fromJson()` + `DeepAgent->resume()` restore a run across an HTTP/queue boundary.
- [x] **Patch dangling tool calls**: `Loop` inserts a synthetic `tool_result` for any assistant tool-call lacking one, so restored/edited histories stay sendable.
- [x] Safe tool execution: `Loop` catches a thrown tool error and returns it as the tool result, so a failing tool can't crash the run (the model sees the error and can react). Unknown-tool stays a hard error (misconfiguration).
- [ ] Audit/event hooks for every tool call (Laravel events).

### M6 — Profiles, prompt assembly & caching
- [ ] **Harness/model profiles**: per-model prompt suffix, excluded tools/middleware, tool-description overrides.
- [ ] Registry + sensible built-in profiles (Anthropic / OpenAI / local).
- [ ] **Prompt caching** passthrough for Anthropic (no-op elsewhere).

### M7 — MCP & advanced sub-agents
- [ ] **MCP** tool integration (consume MCP servers as tools).
- [ ] **Queued sub-agents** (background via Laravel queues) + status/cancel.
- [ ] **Bring-your-own** compiled sub-agent (arbitrary callable/agent as `task` target).
- [ ] Structured/typed sub-agent results.

### M8 — DX, docs, release
- [ ] Artisan generators: `make:agent`, `make:tool`, `make:skill`.
- [ ] Facade + clean public API surface; semantic-versioned.
- [ ] Pest test suite (unit + feature) using SDK fakes; high coverage of tools & backends.
- [ ] PHPStan max level on `src/`.
- [ ] README quickstart + `docs/` (architecture, backends, sub-agents, HITL, profiles, going-to-production).
- [~] Runnable `examples/` — `examples/demo.php` (offline, scripted) covers all Tier-1 features. More to come (research agent, etc.).
- [ ] CHANGELOG, CONTRIBUTING, SECURITY, Code of Conduct.
- [ ] Tag `v0.1.0`, submit to Packagist.

---

## 5. Security model
Adopt deepagents' **"trust the LLM"** stance: the agent can do whatever its tools allow. Enforce boundaries at the
**tool / backend / permission** layer, never by expecting the model to self-police.
- [ ] Document the threat model (esp. `execute` + `FilesystemBackend` on real disks).
- [ ] Backends default to the safe `StateBackend`; real disk & shell are explicit opt-in.

---

## 6. Open questions
- [ ] **🔑 §2** — how far down Path B do we go? (Both spikes green — seam + HITL-resume. Decision now: which capabilities
      ship on Path A vs the Path B runtime.)
- [ ] HITL-resume persistence: where does `RunState` live — DB column, cache, or the SDK's conversation store?
- [ ] HITL resume across HTTP: persist run-state in DB, cache, or the conversation store?
- [ ] Streaming: hard-require Reverb, or stay transport-agnostic?
- [ ] What's the smallest useful **v0.1** slice? (Proposed: M1 + `StateBackend` + sync sub-agents.)
- [ ] Migration story from PMKI's in-app harness → this package (what gets extracted vs left behind).

---

## 7. Provenance / inspiration
- **`langchain-ai/deepagents`** — feature set & naming (sub-agents, filesystem, skills, memory, HITL, profiles).
- **`laravel/ai` `^0.7`** — the engine we extend (1.x precursor line).
- **PMKI** — the working proof-of-concept harness we're cleaning up and generalizing.
