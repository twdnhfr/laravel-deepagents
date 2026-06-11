<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

use Closure;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request;
use Throwable;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelCall;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelMiddleware;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelPipeline;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ToolInvocation;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ToolMiddleware;
use Twdnhfr\LaravelDeepagents\Tools\BackendAware;
use Twdnhfr\LaravelDeepagents\Tools\RunAware;

/**
 * A turn-by-turn agent loop that this package owns, rather than delegating to
 * the SDK's gateway-internal loop.
 *
 * Each turn is a single `generateText(maxSteps: 0)` call — the uniform
 * single-turn seam established by the spikes: across Anthropic, OpenAI and
 * Gemini, `maxSteps: 0` returns the model's tool-call intention WITHOUT
 * executing it. Owning the loop is what unlocks human-in-the-loop approval and
 * mid-loop context management; the gateway's coarse `Agent::prompt()` cannot,
 * because it always appends a fresh `UserMessage` and runs the loop internally.
 *
 * The loop is stateless across turns: all run state lives in a {@see RunState},
 * so a suspended run can be serialized and resumed in a different process. The
 * loop holds only the (non-serializable) runtime config: provider, model, tools.
 *
 * Modes:
 *   - autonomous (default, `$approvalGate = null`): tool calls execute
 *     immediately and the loop runs to a final answer in a single
 *     {@see advance()} call.
 *   - approval: when `$approvalGate` is set, a turn that contains any tool call
 *     it flags suspends the whole turn before executing; {@see resume()} then
 *     runs the approved calls and continues. The gate is a per-call predicate
 *     (`fn (array $call): bool`), so approval can be all-or-nothing, a tool
 *     allow-list, or arbitrary logic (à la deepagents `interrupt_on`).
 */
class Loop
{
    /**
     * @param  array<int, Tool>  $tools
     * @param  (Closure(array{id: string, name: string, arguments: array<string, mixed>}): bool)|null  $approvalGate
     * @param  array<int, Hook>  $hooks
     * @param  array<int, ModelMiddleware>  $modelMiddleware
     * @param  array<int, ToolMiddleware>  $toolMiddleware
     */
    public function __construct(
        protected TextProvider $provider,
        protected string $model,
        protected array $tools = [],
        protected ?Closure $approvalGate = null,
        protected int $maxTurns = 50,
        protected array $hooks = [],
        protected ?Backend $backend = null,
        protected array $modelMiddleware = [],
        protected array $toolMiddleware = [],
    ) {}

    /**
     * Build a loop for a named provider resolved from the container.
     *
     * @param  array<int, Tool>  $tools
     * @param  (Closure(array{id: string, name: string, arguments: array<string, mixed>}): bool)|null  $approvalGate
     * @param  array<int, Hook>  $hooks
     * @param  array<int, ModelMiddleware>  $modelMiddleware
     * @param  array<int, ToolMiddleware>  $toolMiddleware
     */
    public static function for(string $provider, string $model, array $tools = [], ?Closure $approvalGate = null, array $hooks = [], ?Backend $backend = null, array $modelMiddleware = [], array $toolMiddleware = []): self
    {
        return new self(app(AiManager::class)->textProvider($provider), $model, $tools, $approvalGate, hooks: $hooks, backend: $backend, modelMiddleware: $modelMiddleware, toolMiddleware: $toolMiddleware);
    }

    /**
     * Run model turns until the run completes, or (in approval mode) suspends
     * before a tool call.
     */
    public function advance(RunState $state): RunState
    {
        while ($state->isRunning()) {
            // The turn budget lives on the state, so it spans suspend/resume:
            // an approval pause does not refill it. continue() resets it for a
            // fresh user turn.
            if (++$state->turns > $this->maxTurns) {
                throw LoopException::turnLimitExceeded($this->maxTurns);
            }

            $step = $this->turn($state);

            // A hook (e.g. LoopGuard) may have halted the run during this turn —
            // stop before executing the turn's tool calls. (Read the status
            // directly: turn() mutates it via hooks, so a remembered isRunning()
            // would be stale.)
            if ($step === null || $state->status !== RunState::STATUS_RUNNING) {
                return $state;
            }

            $calls = array_map($this->normalizeCall(...), $step->toolCalls);

            if ($step->finishReason === FinishReason::ToolCalls && filled($calls)) {
                if ($this->needsApproval($calls)) {
                    $state->pendingToolCalls = $calls;
                    $state->status = RunState::STATUS_SUSPENDED;

                    return $state;
                }

                $state->history[] = $this->executeCalls($state, $calls);

                continue;
            }

            $state->status = RunState::STATUS_DONE;
            $state->finalText = $step->text;
        }

        return $state;
    }

    /**
     * Execute the pending tool calls and continue the loop. Calls without a
     * recorded decision (or explicitly approved/edited ones) run as requested;
     * rejected calls are skipped and their reason becomes the tool result.
     */
    public function resume(RunState $state): RunState
    {
        if (! $state->isSuspended()) {
            throw LoopException::notSuspended($state->status);
        }

        $state->history[] = $this->executeCalls($state, $state->pendingToolCalls);
        $state->pendingToolCalls = [];
        $state->status = RunState::STATUS_RUNNING;

        return $this->advance($state);
    }

    /**
     * Run a single model turn: one `generateText(maxSteps: 0)` call. Records the
     * assistant turn onto the history and returns its {@see Step} — or null when
     * a `beforeModel` hook ended the run, in which case the model is never called.
     */
    protected function turn(RunState $state): ?Step
    {
        $this->repairHistory($state);

        foreach ($this->hooks as $hook) {
            $hook->beforeModel($state);
        }

        // A beforeModel hook may have halted (or otherwise ended) the run —
        // skip the model call entirely rather than paying for a turn whose
        // result would be discarded.
        if ($state->status !== RunState::STATUS_RUNNING) {
            return null;
        }

        $step = $this->generate(new ModelCall(
            $this->provider,
            $this->model,
            $state->instructions,
            $this->hydrate($state->history),
            $this->tools,
        ));

        $state->history[] = [
            'role' => 'assistant',
            'content' => $step->text,
            'toolCalls' => array_map($this->normalizeCall(...), $step->toolCalls),
        ];

        foreach ($this->hooks as $hook) {
            $hook->afterModel($state);
        }

        return $step;
    }

    /**
     * Run the model call through the {@see ModelMiddleware} pipeline (retry,
     * failover, …) via the shared {@see ModelPipeline}.
     */
    protected function generate(ModelCall $call): Step
    {
        return ModelPipeline::run($this->modelMiddleware, $call);
    }

    /**
     * Run a tool invocation through the {@see ToolMiddleware} pipeline. The tail
     * is the actual `$tool->handle()` call; middleware wrap it onion-style for
     * validation, retry, timeouts, etc.
     */
    protected function execute(ToolInvocation $invocation): string
    {
        $core = fn (ToolInvocation $i): string => (string) $i->tool->handle($i->request);

        $pipeline = array_reduce(
            array_reverse($this->toolMiddleware),
            fn (Closure $next, ToolMiddleware $mw): Closure => fn (ToolInvocation $i): string => $mw->handle($i, $next),
            $core,
        );

        return $pipeline($invocation);
    }

    /**
     * Execute a batch of tool calls and return the `tool_result` history entry.
     * A call the human rejected (via {@see RunState::reject()}) is not executed;
     * its reason goes back to the model as the tool result instead, in the same
     * batched entry so the turn's call/result pairing stays intact.
     *
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>, decision?: string, reason?: string}>  $calls
     * @return array<string, mixed>
     */
    protected function executeCalls(RunState $state, array $calls): array
    {
        $results = array_map(function (array $call) use ($state) {
            if (($call['decision'] ?? null) === RunState::DECISION_REJECTED) {
                return [
                    'id' => $call['id'],
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                    'result' => 'The user rejected this tool call: '.($call['reason'] ?? 'No reason given.'),
                ];
            }

            $tool = $this->findTool($call['name']);

            if ($tool instanceof RunAware) {
                $tool->withinRun($state);
            }

            if ($tool instanceof BackendAware) {
                $tool->withBackend($this->backend());
            }

            // A tool throwing should not crash the run — hand the error back to
            // the model as the tool result so it can react, retry, or report.
            // The try/catch stays outermost so it also catches a middleware throw.
            try {
                $result = $this->execute(new ToolInvocation(
                    $tool,
                    $call['name'],
                    $call['arguments'],
                    new Request($call['arguments']),
                ));
            } catch (Throwable $e) {
                $result = 'The tool failed with an error: '.$e->getMessage();
            }

            return [
                'id' => $call['id'],
                'name' => $call['name'],
                'arguments' => $call['arguments'],
                'result' => $result,
            ];
        }, $calls);

        return ['role' => 'tool_result', 'toolResults' => $results];
    }

    /**
     * Rebuild SDK Message objects from the serialized history.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, Message>
     */
    protected function hydrate(array $history): array
    {
        return array_map(function (array $m): Message {
            /** @var array<int, array{id: string, name: string, arguments: array<string, mixed>}> $calls */
            $calls = is_array($m['toolCalls'] ?? null) ? $m['toolCalls'] : [];

            /** @var array<int, array{id: string, name: string, arguments: array<string, mixed>, result: string}> $results */
            $results = is_array($m['toolResults'] ?? null) ? $m['toolResults'] : [];

            return match ($m['role']) {
                'user' => new UserMessage((string) $m['content']),
                'assistant' => new AssistantMessage(
                    (string) ($m['content'] ?? ''),
                    collect($calls)->map(
                        fn (array $c) => new ToolCall($c['id'], $c['name'], $c['arguments'], $c['id']),
                    ),
                ),
                'tool_result' => new ToolResultMessage(
                    collect($results)->map(
                        fn (array $r) => new ToolResult($r['id'], $r['name'], $r['arguments'], $r['result'], $r['id']),
                    ),
                ),
                default => throw LoopException::unknownMessageRole((string) $m['role']),
            };
        }, $history);
    }

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    protected function normalizeCall(ToolCall $call): array
    {
        return ['id' => $call->id, 'name' => $call->name, 'arguments' => $call->arguments];
    }

    /**
     * Repair dangling tool calls: any assistant turn whose tool calls are not
     * immediately followed by a `tool_result` gets a synthetic one inserted.
     * Providers reject a tool call without a matching result, so this keeps an
     * externally-restored or hand-edited history sendable. (Normal runs never
     * produce a dangling call, so this is a no-op for them.)
     */
    protected function repairHistory(RunState $state): void
    {
        $repaired = [];

        foreach ($state->history as $i => $entry) {
            $repaired[] = $entry;

            $calls = $entry['toolCalls'] ?? null;

            if (($entry['role'] ?? null) !== 'assistant' || ! is_array($calls) || $calls === []) {
                continue;
            }

            $next = $state->history[$i + 1] ?? null;

            if (is_array($next) && ($next['role'] ?? null) === 'tool_result') {
                continue;
            }

            $repaired[] = [
                'role' => 'tool_result',
                'toolResults' => array_map(fn ($call) => [
                    'id' => is_array($call) ? (string) ($call['id'] ?? '') : '',
                    'name' => is_array($call) ? (string) ($call['name'] ?? '') : '',
                    'arguments' => is_array($call) && is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                    'result' => '[no result recorded — the tool call was not completed]',
                ], $calls),
            ];
        }

        $state->history = $repaired;
    }

    /**
     * Whether a turn must pause for approval — true if the gate flags any of its
     * calls. The whole turn is then suspended so its tool results stay batched.
     *
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $calls
     */
    protected function needsApproval(array $calls): bool
    {
        $gate = $this->approvalGate;

        if ($gate === null) {
            return false;
        }

        foreach ($calls as $call) {
            if ($gate($call)) {
                return true;
            }
        }

        return false;
    }

    protected function backend(): Backend
    {
        return $this->backend ??= new StateBackend;
    }

    protected function findTool(string $name): Tool
    {
        foreach ($this->tools as $tool) {
            $toolName = is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool);

            if ($toolName === $name) {
                return $tool;
            }
        }

        throw LoopException::unknownTool($name);
    }
}
