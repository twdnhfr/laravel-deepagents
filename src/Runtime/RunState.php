<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

use JsonSerializable;

/**
 * The serializable state of a single agent run.
 *
 * Everything needed to pause a run before a tool executes, hand a human an
 * approvable payload, persist it (DB column / cache / queue job), and resume it
 * later from nothing but this object. It deliberately holds NO live objects
 * (providers, tools, closures) — only plain, JSON-round-trippable data:
 *
 *   - `history`          the conversation so far, as role-tagged plain arrays
 *                        (`user` / `assistant` / `tool_result`); the {@see Loop}
 *                        hydrates real SDK Message objects from these on demand.
 *   - `pendingToolCalls` tool calls the model wants to make but that have NOT
 *                        run yet — the human-approvable payload while suspended.
 *                        Record per-call decisions with {@see approve()},
 *                        {@see edit()} and {@see reject()} before resuming.
 *   - `status`          `running` | `suspended` | `done`.
 *
 * Runtime configuration (provider, model, tools) lives on the {@see Loop}, not
 * here, because it is rebuilt per process rather than persisted.
 */
class RunState implements JsonSerializable
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DONE = 'done';

    public const STATUS_HALTED = 'halted';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>, decision?: string, reason?: string}>  $pendingToolCalls
     * @param  array<int, array{content: string, status: string}>  $todos
     * @param  int  $turns  model turns consumed against the loop's `maxTurns` budget; persists
     *                      across suspend/resume so an approval pause cannot refill the budget,
     *                      and resets when `continue()` starts a fresh user turn
     * @param  string|null  $id  a stable identifier for this run, generated at construction and
     *                           serialized with the state. Used to namespace run-scoped backend
     *                           writes (offloaded tool results) so concurrent or successive runs
     *                           sharing a persistent backend cannot collide.
     */
    public function __construct(
        public string $instructions,
        public array $history = [],
        public array $pendingToolCalls = [],
        public string $status = self::STATUS_RUNNING,
        public ?string $finalText = null,
        public array $todos = [],
        public ?string $haltReason = null,
        public int $turns = 0,
        public ?string $id = null,
    ) {
        $this->id ??= bin2hex(random_bytes(8));
    }

    /**
     * Begin a fresh run from a system prompt and the user's first message.
     */
    public static function start(string $instructions, string $userMessage): self
    {
        return new self($instructions, [
            ['role' => 'user', 'content' => $userMessage],
        ]);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isHalted(): bool
    {
        return $this->status === self::STATUS_HALTED;
    }

    /**
     * Stop the run cleanly with a reason — a terminal state distinct from `done`.
     * A hook calls this (e.g. {@see LoopGuard} on a no-progress loop); the
     * {@see Loop} sees the run is no longer running and returns it.
     */
    public function halt(string $reason): void
    {
        $this->status = self::STATUS_HALTED;
        $this->haltReason = $reason;
    }

    /**
     * Explicitly approve pending tool calls. Optional — a pending call without a
     * decision is executed on `resume()` anyway; use this when the host wants
     * self-documenting, explicit decisions.
     */
    public function approve(string ...$ids): void
    {
        foreach ($ids as $id) {
            $call = &$this->pendingCall($id);
            $call['decision'] = self::DECISION_APPROVED;
            unset($call['reason']);
        }
    }

    /**
     * Approve a pending tool call with corrected arguments — `resume()` executes
     * it with these instead of what the model asked for. The model's original
     * arguments remain visible on the assistant message in the history.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function edit(string $id, array $arguments): void
    {
        $call = &$this->pendingCall($id);
        $call['arguments'] = $arguments;
        $call['decision'] = self::DECISION_APPROVED;
        unset($call['reason']);
    }

    /**
     * Reject a pending tool call: `resume()` will not execute it. Instead the
     * reason is handed back to the model as the tool's result, so it can adjust
     * its plan — the human-in-the-loop equivalent of deepagents' `respond`.
     */
    public function reject(string $id, string $reason = 'No reason given.'): void
    {
        $call = &$this->pendingCall($id);
        $call['decision'] = self::DECISION_REJECTED;
        $call['reason'] = $reason;
    }

    /**
     * The pending tool call with the given id, by reference for decisions to
     * write through. Decisions only make sense on a suspended run.
     *
     * @return array{id: string, name: string, arguments: array<string, mixed>, decision?: string, reason?: string}
     */
    protected function &pendingCall(string $id): array
    {
        if (! $this->isSuspended()) {
            throw LoopException::decisionRequiresSuspension($this->status);
        }

        foreach ($this->pendingToolCalls as $index => $call) {
            if ($call['id'] === $id) {
                return $this->pendingToolCalls[$index];
            }
        }

        throw LoopException::unknownPendingCall($id);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'instructions' => $this->instructions,
            'history' => $this->history,
            'pendingToolCalls' => $this->pendingToolCalls,
            'status' => $this->status,
            'finalText' => $this->finalText,
            'todos' => $this->todos,
            'haltReason' => $this->haltReason,
            'turns' => $this->turns,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this, $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['instructions'],
            $data['history'] ?? [],
            $data['pendingToolCalls'] ?? [],
            $data['status'] ?? self::STATUS_RUNNING,
            $data['finalText'] ?? null,
            $data['todos'] ?? [],
            $data['haltReason'] ?? null,
            $data['turns'] ?? 0,
            $data['id'] ?? null,
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
}
