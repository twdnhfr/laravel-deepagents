<?php

namespace Twdnhfr\LaravelDeepagents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/**
 * The `write_todos` tool: the agent's own planning scratchpad.
 *
 * The model passes the complete, updated todo list on every call (it rewrites
 * the list rather than patching it), mirroring how long-horizon agents keep a
 * visible plan. The list is stored on the {@see RunState}, so it survives
 * suspend/resume and is available to the host app for display.
 */
class WriteTodos implements RunAware, Tool
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];

    protected ?RunState $run = null;

    public function withinRun(RunState $state): void
    {
        $this->run = $state;
    }

    public function name(): string
    {
        return 'write_todos';
    }

    public function description(): Stringable|string
    {
        return 'Record and update your plan as a todo list. Pass the COMPLETE updated list every '.
            'time (it replaces the previous one). Use it to break a task into steps and track progress: '.
            'mark a step `in_progress` before starting it and `completed` when done.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->run === null) {
            throw new RuntimeException('The write_todos tool was invoked outside of a run.');
        }

        $todos = [];

        foreach ((array) ($request['todos'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = $item['status'] ?? null;

            $todos[] = [
                'content' => (string) ($item['content'] ?? ''),
                'status' => is_string($status) && in_array($status, self::STATUSES, true)
                    ? $status
                    : self::STATUS_PENDING,
            ];
        }

        $this->run->todos = $todos;

        return $this->render($todos);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'todos' => $schema->array()
                ->items($schema->object([
                    'content' => $schema->string()
                        ->description('A concise description of the step.')
                        ->required(),
                    'status' => $schema->string()
                        ->enum(self::STATUSES)
                        ->description('One of: pending, in_progress, completed.')
                        ->required(),
                ]))
                ->description('The complete, updated todo list. Replaces the previous list entirely.')
                ->required(),
        ];
    }

    /**
     * @param  array<int, array{content: string, status: string}>  $todos
     */
    protected function render(array $todos): string
    {
        if ($todos === []) {
            return 'Todo list cleared.';
        }

        $marks = [
            self::STATUS_PENDING => '[ ]',
            self::STATUS_IN_PROGRESS => '[~]',
            self::STATUS_COMPLETED => '[x]',
        ];

        $lines = array_map(fn (array $t) => $marks[$t['status']].' '.$t['content'], $todos);

        return "Todos updated:\n".implode("\n", $lines);
    }
}
