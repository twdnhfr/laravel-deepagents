# Feature adoption from Python `deepagents`

This package follows the feature set of [`langchain-ai/deepagents`](https://github.com/langchain-ai/deepagents),
adapted to PHP/Laravel and to our [Path B runtime](adr/0001-own-the-agent-loop.md). We adopt the **features**, not the
LangGraph **middleware machinery** — our analogues are tools (`Tools\RunAware`), fields on `RunState`, and loop hooks.

This file is the running decision log for *what we port, in what order, and what we skip*.

## Scope note: filesystem & shell tools are deferred

Python deepagents ships `read_file` / `write_file` / `edit_file` / `ls` / `glob` / `grep` and a sandboxed `execute`.
**We are deferring these.** This package is aimed at *orchestration* — planning, sub-agents, human-in-the-loop,
context management — **not** at an agent that mutates the host filesystem or runs shell commands. That use case has
real security weight (deepagents itself warns about it) and isn't the goal here.

The `Contracts\Backend` + `Backends\StateBackend` seam stays: it's useful for **virtual, in-state "files"** (scratch
space that serializes with the run) and leaves the door open to opt-in filesystem/sandbox backends later. But the
file-mutating tool suite is **out of scope for now**.

## Adoption tiers

Legend: ✅ done · 🔜 in progress / next · ⏳ planned · 🧊 deferred · ⛔ skip (use Laravel instead)

### Tier 1 — high value, fits our design

| Feature | Status | Notes |
|---|---|---|
| **Loop hooks** (`beforeModel` / `afterModel`) | 🔜 | The meta-feature. A small seam in `Runtime\Loop` — *not* a LangGraph reimplementation. Unlocks context management, memory injection, metrics. |
| **Context management** (summarization) | 🔜 | Compact the run history when it grows past a token budget. Built on the loop hook. The original reason we own the loop. |
| **Sub-agents** (`task` tool) | ✅ | A nested `DeepAgent` run with an isolated `RunState` (own history/todos), exposed as a tool. Shares the parent's storage `Backend` by default — our analogue to deepagents' shared virtual filesystem — unless the sub-agent sets its own. |
| Filesystem & shell tools | 🧊 | Deferred — see scope note above. |

### Tier 2 — medium value

| Feature | Status | Notes |
|---|---|---|
| **Per-tool approval** (`interrupt_on`-style) | ✅ | `requireApproval()` now takes all / a tool allow-list / a per-call closure. A turn with any gated call suspends. |
| **Memory** (`AGENTS.md`) | ✅ | `DeepAgent->memory()` reads files from the backend and injects them into the system prompt at run start. `FilesystemBackend` added for on-disk `AGENTS.md`. |
| **Prompt assembly + BASE prompt** | ✅ | `DeepAgent::BASE_PROMPT` is prepended by default; assembly order is BASE → instructions → memory. Override/disable via `basePrompt()`. |
| **Patch dangling tool calls** | ✅ | `Loop` repairs a history where an assistant tool-call has no matching `tool_result` (synthetic result inserted) before sending — keeps restored/edited states sendable. |
| **Permissions** (allow/deny) | ⏳ | Path/operation rules — only relevant alongside filesystem tools, hence also deferred for now. |

### Tier 3 — later / niche

| Feature | Status | Notes |
|---|---|---|
| **Skills** (`SKILL.md`, progressive disclosure) | ⏳ | Powerful but heavier; PMKI has a variant to learn from. |
| **Rubric** (self-evaluation loop) | ⏳ | Nice optional add-on; a grader sub-agent that loops until "satisfied". |
| **Harness/provider profiles** | ⏳ | Model-specific prompt/tool tuning. |
| **Sandbox / disk backends, MCP** | ⏳ | When the need is concrete (MCP depends on `laravel/ai` support). |

### Skip — use Laravel instead

| Python feature | Why skip |
|---|---|
| LangSmith backend / tracing | Use Laravel logging / Telescope. |
| DeltaChannel / checkpointer internals | We have our own `RunState`. |
| Async/remote sub-agents (Agent Protocol) | Later via Laravel queues (`laravel/ai`'s `queue()`), not the Python protocol. |
| `context_hub` backend | LangChain-specific. |

## Note: prompt caching is an SDK-level gap

Python deepagents wires `langchain_anthropic`'s `AnthropicPromptCachingMiddleware`.
There is **no equivalent in `laravel/ai`**: its Anthropic text gateway sends the
`system` prompt as a plain string and adds no `cache_control` markers anywhere
(verified in `BuildsTextRequests` / `MapsMessages`). So Anthropic prompt caching
is absent **at the SDK level** — for the SDK's own `Agent::prompt()` path *and*
for our gateway-direct loop equally; we don't lose anything by owning the loop.

It still matters for us, because the loop re-sends the full history every turn —
caching would cut Anthropic cost/latency on long runs. But the gateway hard-codes
`system` as a string, so wiring it cleanly needs SDK support or a thin gateway
wrapper. **Status: 🧊 optional, later** (a perf/cost nicety, not a correctness gap).

## Working order

1. **Loop hooks** — the seam. ✅
2. **Context management** — summarization on the hook ✅; tool-output offloading to the storage backend (`offloadLargeToolResults()` + `read_artifact`/`write_artifact`, keeping the `RunState` lean) ✅.
3. **Sub-agents** — the `task` tool. ✅

Tier 2 done. See [`TODO.md`](../TODO.md) for the full milestone list.
