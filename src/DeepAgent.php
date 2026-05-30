<?php

namespace Twdnhfr\LaravelDeepagents;

use Closure;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Stringable;
use Twdnhfr\LaravelDeepagents\Backends\BackendManager;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\Context\OffloadLargeToolResults;
use Twdnhfr\LaravelDeepagents\Context\SummarizeHistory;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;
use Twdnhfr\LaravelDeepagents\Runtime\Hook;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tools\ReadArtifact;
use Twdnhfr\LaravelDeepagents\Tools\Task;
use Twdnhfr\LaravelDeepagents\Tools\WriteArtifact;
use Twdnhfr\LaravelDeepagents\Tools\WriteTodos;

/**
 * The package's front door: a fluent builder that configures an agent and runs
 * it through the package-owned {@see Loop}.
 *
 * ```php
 * $state = DeepAgent::make()
 *     ->provider('anthropic')
 *     ->model('claude-sonnet-4-5')
 *     ->instructions('You are a research assistant.')
 *     ->tools([$search, $writeFile])
 *     ->run('Research LangGraph and write a summary');
 *
 * $answer = $state->finalText;
 * ```
 *
 * Opt into human-in-the-loop with `->requireApproval()`: `run()` then returns a
 * suspended {@see RunState} (serializable) whenever the model wants a tool, and
 * `resume()` continues once the pending calls are approved.
 *
 * Configuration lives on the builder; per-run state lives on the RunState. The
 * builder is reusable — each `run()` starts a fresh run.
 */
final class DeepAgent
{
    /** The default agentic system prompt, prepended unless overridden or disabled. */
    public const BASE_PROMPT = <<<'TXT'
        You are a capable, autonomous agent. Pursue the user's goal using the tools available to you.

        - Be concise and direct; skip unnecessary preamble.
        - Prefer using your tools to gather information and take action rather than guessing.
        - If you keep a todo list, update it as you make progress.
        - Keep working until the task is complete; stop only when it is done or you are genuinely blocked.
        TXT;

    protected string|TextProvider|null $provider = null;

    protected ?string $basePrompt = self::BASE_PROMPT;

    protected ?string $model = null;

    protected string $instructions = '';

    /** @var array<int, Tool> */
    protected array $tools = [];

    /** @var (Closure(array{id: string, name: string, arguments: array<string, mixed>}): bool)|null */
    protected ?Closure $approvalGate = null;

    protected int $maxTurns = 50;

    /** @var array<int, Hook> */
    protected array $hooks = [];

    /** @var array{trigger: int, keep: int}|null */
    protected ?array $summarize = null;

    /** @var array<string, array{description: string, agent: self}> */
    protected array $subAgents = [];

    protected ?Backend $backend = null;

    /** @var array<int, string> */
    protected array $memory = [];

    protected ?int $offloadMaxChars = null;

    protected bool $artifactsReadable = false;

    protected bool $artifactsWritable = false;

    public static function make(): static
    {
        return new self;
    }

    /**
     * Set the provider by name (resolved from config) or as a ready instance.
     */
    public function provider(string|TextProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function instructions(string|Stringable $instructions): static
    {
        $this->instructions = (string) $instructions;

        return $this;
    }

    /**
     * Override the default BASE prompt. Pass `null` to omit it entirely and use
     * only your own instructions.
     */
    public function basePrompt(?string $prompt): static
    {
        $this->basePrompt = $prompt;

        return $this;
    }

    /**
     * Replace the tool set.
     *
     * @param  array<int, Tool>  $tools
     */
    public function tools(array $tools): static
    {
        $this->tools = array_values($tools);

        return $this;
    }

    /**
     * Add a single tool.
     */
    public function tool(Tool $tool): static
    {
        $this->tools[] = $tool;

        return $this;
    }

    /**
     * Give the agent the built-in `write_todos` planning tool.
     */
    public function withTodos(): static
    {
        return $this->tool(new WriteTodos);
    }

    /**
     * Register a sub-agent the model can delegate to via the `task` tool. The
     * sub-agent runs in its own isolated run. Register one or more.
     */
    public function subAgent(string $name, string $description, self $agent): static
    {
        $this->subAgents[$name] = ['description' => $description, 'agent' => $agent];

        return $this;
    }

    /**
     * Require human approval before tool calls (human-in-the-loop). A turn that
     * contains any approved-gated tool suspends before running.
     *
     * - `requireApproval()` — every tool requires approval.
     * - `requireApproval(['delete', 'send_email'])` — only those tools.
     * - `requireApproval(fn (array $call) => ...)` — custom per-call predicate.
     * - `requireApproval(false)` — autonomous (the default).
     *
     * @param  bool|array<int, string>|Closure(array{id: string, name: string, arguments: array<string, mixed>}): bool  $tools
     */
    public function requireApproval(bool|array|Closure $tools = true): static
    {
        $this->approvalGate = match (true) {
            $tools === false => null,
            $tools === true => fn (): bool => true,
            is_array($tools) => fn (array $call): bool => in_array($call['name'] ?? '', $tools, true),
            default => $tools,
        };

        return $this;
    }

    public function maxTurns(int $maxTurns): static
    {
        $this->maxTurns = $maxTurns;

        return $this;
    }

    /**
     * Set the backend that memory (and future file-backed features) read from.
     * Defaults to an empty {@see StateBackend}.
     */
    public function backend(Backend $backend): static
    {
        $this->backend = $backend;

        return $this;
    }

    /**
     * Adopt a backend as a fallback, used only when this agent has no backend
     * of its own. A parent agent calls this on its sub-agents (via the `task`
     * tool) so they share its artifact/memory store — mirroring deepagents'
     * shared virtual filesystem — without overriding an explicit `backend()`.
     */
    public function inheritBackend(Backend $backend): static
    {
        $this->backend ??= $backend;

        return $this;
    }

    /**
     * Load `AGENTS.md`-style memory files (read from the backend) into the
     * system prompt at the start of each run. Always-on context, à la deepagents.
     */
    public function memory(string ...$paths): static
    {
        $this->memory = array_values($paths);

        return $this;
    }

    /**
     * Add a loop hook (e.g. context management). Runs around every model turn.
     */
    public function hook(Hook $hook): static
    {
        $this->hooks[] = $hook;

        return $this;
    }

    /**
     * Automatically summarize the run history once it grows past a token budget,
     * keeping the most recent `keepLast` entries verbatim.
     */
    public function summarize(int $triggerTokens = 12000, int $keepLast = 6): static
    {
        $this->summarize = ['trigger' => $triggerTokens, 'keep' => $keepLast];

        return $this;
    }

    /**
     * Keep oversized tool results out of the prompt: anything longer than
     * `maxChars` is offloaded to the storage backend and replaced with a preview
     * the model can follow via `read_artifact` (registered automatically). Use a
     * persistent `backend()` for artifacts that must outlive a single run.
     */
    public function offloadLargeToolResults(int $maxChars = 2000): static
    {
        $this->offloadMaxChars = $maxChars;
        $this->artifactsReadable = true;

        return $this;
    }

    /**
     * Give the agent the `read_artifact` and `write_artifact` tools — a
     * backend-backed scratchpad for large or intermediate content.
     */
    public function withArtifacts(): static
    {
        $this->artifactsReadable = true;
        $this->artifactsWritable = true;

        return $this;
    }

    /**
     * Start a run and drive it to completion — or to the first approval pause.
     */
    public function run(string $message): RunState
    {
        return $this->loop()->advance(RunState::start($this->composeInstructions(), $message));
    }

    /**
     * Resume a previously suspended run once its pending tool calls are approved.
     */
    public function resume(RunState $state): RunState
    {
        return $this->loop()->resume($state);
    }

    /**
     * Continue a conversation: append the user's next message to an existing
     * (completed) run and advance it, so the agent keeps the full prior context.
     * Instructions are taken from the existing state, not re-composed.
     */
    public function continue(RunState $state, string $message): RunState
    {
        $state->history[] = ['role' => 'user', 'content' => $message];
        $state->pendingToolCalls = [];
        $state->finalText = null;
        $state->status = RunState::STATUS_RUNNING;

        return $this->loop()->advance($state);
    }

    /**
     * Assemble the system prompt for a run: BASE prompt, then the caller's
     * instructions, then any loaded memory. Empty/disabled parts are dropped.
     */
    protected function composeInstructions(): string
    {
        $memory = $this->loadMemory();

        $parts = array_filter([
            $this->basePrompt,
            $this->instructions !== '' ? $this->instructions : null,
            $memory !== '' ? "The following project context was loaded from memory and is authoritative:\n\n".$memory : null,
        ]);

        return implode("\n\n", $parts);
    }

    /**
     * Read the configured memory files from the backend, skipping any that are
     * missing or empty.
     */
    protected function loadMemory(): string
    {
        if ($this->memory === []) {
            return '';
        }

        $backend = $this->resolveBackend();
        $blocks = [];

        foreach ($this->memory as $path) {
            $content = $backend->read($path);

            if ($content !== null && trim($content) !== '') {
                $blocks[] = "<memory path=\"{$path}\">\n".trim($content)."\n</memory>";
            }
        }

        return implode("\n\n", $blocks);
    }

    protected function loop(): Loop
    {
        $provider = $this->resolveProvider();
        $model = $this->model ?? $provider->defaultTextModel();
        $backend = $this->resolveBackend();

        $hooks = $this->hooks;

        if ($this->summarize !== null) {
            // Summarization runs before any user hooks so they see the compacted history.
            array_unshift($hooks, new SummarizeHistory(
                $provider,
                $model,
                $this->summarize['trigger'],
                $this->summarize['keep'],
            ));
        }

        if ($this->offloadMaxChars !== null) {
            // Offloading runs first, so oversized tool results are clipped before
            // summarization and any user hooks see the history.
            array_unshift($hooks, new OffloadLargeToolResults($backend, $this->offloadMaxChars));
        }

        $tools = $this->tools;

        if ($this->subAgents !== []) {
            $tools[] = new Task($this->subAgents);
        }

        if ($this->artifactsReadable) {
            $tools[] = new ReadArtifact;
        }

        if ($this->artifactsWritable) {
            $tools[] = new WriteArtifact;
        }

        return new Loop($provider, $model, $tools, $this->approvalGate, $this->maxTurns, $hooks, $backend);
    }

    protected function resolveProvider(): TextProvider
    {
        if ($this->provider instanceof TextProvider) {
            return $this->provider;
        }

        return app(AiManager::class)->textProvider($this->provider);
    }

    /**
     * The shared storage backend for memory and artifacts. Uses the one set via
     * `backend()`; otherwise the configured default (`config/deepagents.php`)
     * resolved through the {@see BackendManager}; otherwise an in-memory
     * {@see StateBackend} (e.g. outside a Laravel app).
     */
    protected function resolveBackend(): Backend
    {
        if ($this->backend !== null) {
            return $this->backend;
        }

        if (function_exists('app') && app()->bound(BackendManager::class)) {
            return $this->backend = app(BackendManager::class)->make();
        }

        return $this->backend = new StateBackend;
    }
}
