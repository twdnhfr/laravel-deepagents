<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

use Closure;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
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
     */
    public function __construct(
        protected TextProvider $provider,
        protected string $model,
        protected array $tools = [],
        protected ?Closure $approvalGate = null,
        protected int $maxTurns = 50,
        protected array $hooks = [],
        protected ?Backend $backend = null,
    ) {}

    /**
     * Build a loop for a named provider resolved from the container.
     *
     * @param  array<int, Tool>  $tools
     * @param  (Closure(array{id: string, name: string, arguments: array<string, mixed>}): bool)|null  $approvalGate
     * @param  array<int, Hook>  $hooks
     */
    public static function for(string $provider, string $model, array $tools = [], ?Closure $approvalGate = null, array $hooks = [], ?Backend $backend = null): self
    {
        return new self(app(AiManager::class)->textProvider($provider), $model, $tools, $approvalGate, hooks: $hooks, backend: $backend);
    }

    /**
     * Run model turns until the run completes, or (in approval mode) suspends
     * before a tool call.
     */
    public function advance(RunState $state): RunState
    {
        $turns = 0;

        while ($state->isRunning()) {
            if (++$turns > $this->maxTurns) {
                throw LoopException::turnLimitExceeded($this->maxTurns);
            }

            $step = $this->turn($state);

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
     * Approve the pending tool calls, execute them, and continue the loop.
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
     * assistant turn onto the history and returns its {@see Step}.
     */
    protected function turn(RunState $state): Step
    {
        $this->repairHistory($state);

        foreach ($this->hooks as $hook) {
            $hook->beforeModel($state);
        }

        $response = $this->provider->textGateway()->generateText(
            $this->provider,
            $this->model,
            $state->instructions,
            $this->hydrate($state->history),
            $this->tools,
            null,
            new TextGenerationOptions(maxSteps: 0),
        );

        $step = $response->steps->first();

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
     * Execute a batch of tool calls and return the `tool_result` history entry.
     *
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $calls
     * @return array<string, mixed>
     */
    protected function executeCalls(RunState $state, array $calls): array
    {
        $results = array_map(function (array $call) use ($state) {
            $tool = $this->findTool($call['name']);

            if ($tool instanceof RunAware) {
                $tool->withinRun($state);
            }

            if ($tool instanceof BackendAware) {
                $tool->withBackend($this->backend());
            }

            // A tool throwing should not crash the run — hand the error back to
            // the model as the tool result so it can react, retry, or report.
            try {
                $result = (string) $tool->handle(new Request($call['arguments']));
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
        return array_map(fn (array $m) => match ($m['role']) {
            'user' => new UserMessage($m['content']),
            'assistant' => new AssistantMessage(
                $m['content'] ?? '',
                collect($m['toolCalls'] ?? [])->map(
                    fn (array $c) => new ToolCall($c['id'], $c['name'], $c['arguments'], $c['id']),
                ),
            ),
            'tool_result' => new ToolResultMessage(
                collect($m['toolResults'] ?? [])->map(
                    fn (array $r) => new ToolResult($r['id'], $r['name'], $r['arguments'], $r['result'], $r['id']),
                ),
            ),
            default => throw LoopException::unknownMessageRole((string) $m['role']),
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
        if ($this->approvalGate === null) {
            return false;
        }

        foreach ($calls as $call) {
            if (($this->approvalGate)($call)) {
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
