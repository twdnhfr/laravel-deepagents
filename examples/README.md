# Examples

## `demo.php` — offline feature walkthrough

```bash
php examples/demo.php
```

A deterministic, **offline** tour of the runtime — no API keys, no network. A
scripted provider (`Support/ScriptedProvider.php`) returns canned model turns,
so you can see the behaviour of each feature without calling a real model:

1. **Autonomous run** — the agent calls a tool, then answers.
2. **Planning** — the built-in `write_todos` tool.
3. **Human-in-the-loop (per-tool approval)** — a safe tool runs unattended while
   a gated one suspends the run; it's serialized to JSON and resumed after approval.
4. **Context management** — `SummarizeHistory` compacts a long history.
5. **Sub-agents** — the `task` tool delegates to an isolated nested run.
6. **Memory & prompt assembly** — `AGENTS.md` plus the default BASE prompt, shown
   as the composed system prompt (BASE → instructions → memory).

## `openrouter.php` — live demo against a real model

```bash
cp .env.example .env   # then set OPENROUTER_API_KEY (and optionally OPENROUTER_MODEL)
php examples/openrouter.php
```

Calls a **real** model through [OpenRouter](https://openrouter.ai). It boots just
enough of Laravel (a container + the `Http` facade) to use the SDK's OpenRouter
provider directly, then drives `DeepAgent`s through the package's loop across
four scenarios — **autonomous tool use**, **human-in-the-loop** (suspend →
serialize → approve → resume), **sub-agents** (`task` delegation) and **memory**
(`AGENTS.md` steering the answer) — so real model decisions drive each flow.
Reads `OPENROUTER_API_KEY` / `OPENROUTER_MODEL` from `.env` (gitignored).

---

To run the offline flows in `demo.php` against a real model instead, swap
`->provider(new ScriptedProvider([...]))` for `->provider('anthropic')` (or any
provider configured in your app's `config/ai.php`) inside a Laravel application.
