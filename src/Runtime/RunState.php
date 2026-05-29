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

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $pendingToolCalls
     * @param  array<int, array{content: string, status: string}>  $todos
     */
    public function __construct(
        public string $instructions,
        public array $history = [],
        public array $pendingToolCalls = [],
        public string $status = self::STATUS_RUNNING,
        public ?string $finalText = null,
        public array $todos = [],
    ) {}

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

    public function jsonSerialize(): array
    {
        return [
            'instructions' => $this->instructions,
            'history' => $this->history,
            'pendingToolCalls' => $this->pendingToolCalls,
            'status' => $this->status,
            'finalText' => $this->finalText,
            'todos' => $this->todos,
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
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
}
