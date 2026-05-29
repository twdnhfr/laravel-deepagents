# Laravel Deep Agents

[![Latest Version on Packagist](https://img.shields.io/packagist/v/twdnhfr/laravel-deepagents.svg?style=flat-square)](https://packagist.org/packages/twdnhfr/laravel-deepagents)
[![Tests](https://img.shields.io/github/actions/workflow/status/twdnhfr/laravel-deepagents/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/twdnhfr/laravel-deepagents/actions?query=workflow%3Arun-tests+branch%3Amain)
[![License](https://img.shields.io/github/license/twdnhfr/laravel-deepagents?style=flat-square)](LICENSE.md)

A **deep-agent harness** for the [Laravel AI SDK](https://github.com/laravel/ai) — an owned, resumable agent loop
with planning, sub-agents, human-in-the-loop approval, multi-turn conversations, memory and automatic context
management, built on top of `laravel/ai`.

> [!WARNING]
> **Early work in progress.** The runtime and core harness are built and tested — owned agent loop, human-in-the-loop,
> multi-turn, planning, sub-agents, memory and context management (summarization + tool-output offloading) over
> pluggable backends. Skills are planned; filesystem/shell tools and token streaming are out of scope for now (see
> [`TODO.md`](TODO.md) and [`docs/adr/`](docs/adr/)). APIs may change; not yet on Packagist.

> [!NOTE]
> Not affiliated with or endorsed by LangChain. An independent reimplementation for Laravel, inspired by the
> [`deepagents`](https://github.com/langchain-ai/deepagents) project.

## What is Laravel Deep Agents?

The [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`) is an excellent **engine**: one unified, expressive
API over many providers (OpenAI, Anthropic, Gemini, and more), with tool calling, structured output, streaming,
embeddings and more. Point it at a model, hand it some tools, call `prompt()` — done.

But an *engine* is not an *agent harness*. The moment you ask an agent to do real, long-horizon work — research across
many steps, read and write files, delegate subtasks to focused sub-agents, **pause for your approval before doing
something destructive**, and later **pick up exactly where it left off** — you need a layer of opinionated machinery
on top: planning, a virtual filesystem, sub-agents, automatic context management, human-in-the-loop, skills and memory.

In the Python/LangChain world that layer is [`deepagents`](https://github.com/langchain-ai/deepagents) — *"the
batteries-included agent harness"*, itself an attempt to distill what makes Claude Code general-purpose. There was no
equivalent for Laravel.

**Laravel Deep Agents is that layer.** It builds *on top of* `laravel/ai` and brings the deepagents feature set to PHP:

> The SDK stays the engine. This package adds the harness.

### Why a custom loop?

`laravel/ai` runs its model↔tool loop *inside* the provider — great for a one-shot `prompt()`, but it gives you no
place to step in *between* the model choosing a tool and that tool running. That in-between is exactly where approval
gates, permission checks and context compaction live.

So this package **owns the loop**: it drives one model turn at a time and decides for itself when to run a tool, when
to pause for a human, and when it's done. The entire state of a run is a plain, serializable value object — so a run
can pause, be stored in your database or a queued job, and resume in a completely different request.

The how-and-why is recorded as Architecture Decision Records in [`docs/adr/`](docs/adr/).

## Requirements

- PHP 8.3+
- Laravel 13
- [`laravel/ai`](https://github.com/laravel/ai) `^0.7` (configured with at least one provider/API key)

## Installation

> Not published to Packagist yet. Once it is:

```bash
composer require twdnhfr/laravel-deepagents
```

The package auto-registers. Configure your provider and API key through the Laravel AI SDK as usual (e.g.
`ANTHROPIC_API_KEY` in `.env` and the provider entry in `config/ai.php`).

## Quickstart

Define a tool (the standard `laravel/ai` `Tool` contract), then build and run an agent:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetWeather implements Tool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Get the current weather for a city.';
    }

    public function handle(Request $request): string
    {
        return "It's 21°C and sunny in {$request['city']}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->description('The city name.')->required()];
    }
}
```

```php
use Twdnhfr\LaravelDeepagents\DeepAgent;

$state = DeepAgent::make()
    ->provider('anthropic')              // resolved from config/ai.php
    ->model('claude-sonnet-4-5')         // optional — defaults to the provider's default model
    ->instructions('You are a helpful weather assistant.')
    ->tool(new GetWeather)
    ->run('What should I wear in Berlin today?');

echo $state->finalText;
```

By default the agent runs **autonomously**: it calls tools and loops until it has a final answer.

### Planning with todos

Give the agent the built-in `write_todos` tool so it can keep a visible plan. The list lives on the run state:

```php
$state = DeepAgent::make()
    ->provider('anthropic')
    ->withTodos()
    ->instructions('Plan your work with the todo list before acting.')
    ->run('Research and outline a short article on vector databases');

foreach ($state->todos as $todo) {
    echo "[{$todo['status']}] {$todo['content']}\n";
}
```

### Human-in-the-loop: approve before tools run

Opt into approval mode and the agent **suspends before any tool call** instead of executing it. The suspended run is
fully serializable, so you can store it, show the pending action to a human, and resume later — in another request or
a queued job:

```php
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

$agent = DeepAgent::make()
    ->provider('anthropic')
    ->tool(new DeleteStaleRecords)   // something you don't want running unsupervised
    ->requireApproval();

$state = $agent->run('Clean up records older than a year');

if ($state->isSuspended()) {
    // Show the human exactly what the model wants to do…
    foreach ($state->pendingToolCalls as $call) {
        info("Agent wants to call {$call['name']}", $call['arguments']);
    }

    // …and persist the run while you wait for their decision.
    $stored = $state->toJson();   // -> a DB column, cache entry, queued job, etc.
}

// Later, once approved (possibly in a different request):
$final = $agent->resume(RunState::fromJson($stored));

echo $final->finalText;
```

### Multi-turn conversations

`run()` starts a fresh run; `continue()` carries an existing run forward with
the user's next message, so the agent keeps full prior context. The run state is
serializable, so you can persist it between turns (session, DB, …):

```php
$state = $agent->run('What is the weather in Berlin?');
// ...store $state->toJson()...

// next turn, same conversation:
$state = $agent->continue(RunState::fromJson($stored), 'And in Tokyo?');
echo $state->finalText; // resolves "And in Tokyo?" using the prior turn
```

## How it works

```
DeepAgent (fluent builder)              ← the public API
   │ configures & starts
   ▼
Runtime\Loop  ── drives one turn ──►  laravel/ai
   │  maxSteps: 0                       TextProvider::textGateway()->generateText($messages)
   │  (autonomous | approval pause)      └─ any provider (OpenAI, Anthropic, Gemini, …)
   ▼
Runtime\RunState  ── json_encode ──►  DB / queue / HTTP body ── json_decode ──►  resume()
```

- **`DeepAgent`** — the fluent front door (`make()`, `provider()`, `model()`, `instructions()`, `tool()`/`tools()`,
  `withTodos()`, `subAgent()`, `memory()`, `summarize()`, `requireApproval()`, `maxTurns()`, `run()`, `resume()`,
  `continue()`).
- **`Runtime\Loop`** — drives the agent one model turn at a time using `maxSteps: 0`, the seam that returns the model's
  tool-call intention *without* executing it (verified across Anthropic, OpenAI and Gemini — see
  [ADR-0002](docs/adr/0002-maxsteps-zero-single-turn-seam.md)).
- **`Runtime\RunState`** — the serializable state of a run (history, pending tool calls, todos, status). The single
  source of truth that survives suspend → persist → resume.
- **`Contracts\Backend`** + **`Backends\StateBackend`** — the pluggable file-storage seam for the upcoming filesystem
  tools.

## Status & roadmap

| Area | State |
|------|-------|
| Agent loop (autonomous + per-tool approval), serializable `RunState`, HITL resume | ✅ built & tested |
| `DeepAgent` fluent builder + multi-turn (`continue()`) | ✅ |
| `write_todos` planning tool | ✅ |
| Sub-agents (`task`) | ✅ |
| Context management (summarization + tool-output offloading to artifacts) | ✅ |
| Memory (`AGENTS.md`) + BASE prompt assembly | ✅ |
| Safe tool execution + dangling-tool-call repair | ✅ |
| Pluggable backends (state, filesystem, database, cache) + config-driven default | ✅ |
| Filesystem & shell tools | 🧊 deferred (see [adoption](docs/adoption.md)) |
| Skills, harness profiles, MCP | ⏳ planned |
| Token streaming through the loop | ❌ by design — see [ADR-0004](docs/adr/0004-no-token-streaming-through-the-loop.md) |

The full plan lives in [`TODO.md`](TODO.md).

## Demo

See every feature run offline (no API keys), via a scripted provider:

```bash
php examples/demo.php
```

It walks through autonomous tool use, planning, human-in-the-loop approval,
context summarization and sub-agents. See [`examples/`](examples/).

### Example app: `deepagents-chat`

[**twdnhfr/deepagents-chat**](https://github.com/twdnhfr/deepagents-chat) is a
small Laravel chat app built on this package — a live, end-to-end example. It
shows the owned loop in a real request cycle: a tool-call trace, the
human-in-the-loop approval flow, multi-turn conversations, a `DatabaseBackend`
with tool-output offloading, and Markdown-rendered replies.

## Testing

```bash
composer test
```

## Credits & inspiration

- [`langchain-ai/deepagents`](https://github.com/langchain-ai/deepagents) — the Python harness whose feature set and
  naming this package follows.
- [`laravel/ai`](https://github.com/laravel/ai) — the SDK this package is built on.
- [twdnhfr](https://github.com/twdnhfr) and [all contributors](../../contributors).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
