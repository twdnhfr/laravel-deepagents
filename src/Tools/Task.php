<?php

namespace Twdnhfr\LaravelDeepagents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/**
 * The `task` tool: delegate an isolated, self-contained task to a sub-agent.
 *
 * Each call runs a nested {@see DeepAgent} to completion in its own
 * {@see RunState} — a fresh context window
 * with no access to the parent conversation — and returns its final text. This
 * is how a deep agent keeps a heavy subtask's tokens out of the main thread.
 *
 * Sub-agents are expected to run autonomously (don't put them in approval mode);
 * gate the *delegation* on the parent instead. A sub-agent failure is returned
 * as text, never thrown, so it cannot crash the parent run.
 */
class Task implements BackendAware, Tool
{
    protected ?Backend $backend = null;

    /**
     * @param  array<string, array{description: string, agent: DeepAgent}>  $subAgents
     */
    public function __construct(protected array $subAgents) {}

    public function withBackend(Backend $backend): void
    {
        $this->backend = $backend;
    }

    public function name(): string
    {
        return 'task';
    }

    public function description(): Stringable|string
    {
        $catalogue = implode("\n", array_map(
            fn (string $name, array $spec) => "- {$name}: {$spec['description']}",
            array_keys($this->subAgents),
            array_values($this->subAgents),
        ));

        return 'Delegate an isolated, self-contained task to a sub-agent and get back its result. The sub-agent '.
            'runs in its own context window with no access to this conversation, so pass a complete, standalone '.
            "task description.\n\nAvailable sub-agents:\n".$catalogue;
    }

    public function handle(Request $request): Stringable|string
    {
        $name = (string) ($request['subagent_type'] ?? '');

        if (! isset($this->subAgents[$name])) {
            return "No sub-agent named [{$name}] is available.";
        }

        $agent = $this->subAgents[$name]['agent'];

        // Share the parent's storage backend so the sub-agent reads and writes
        // the same artifacts/memory store — the equivalent of deepagents'
        // shared virtual filesystem. A sub-agent with its own backend keeps it.
        if ($this->backend !== null) {
            $agent->inheritBackend($this->backend);
        }

        try {
            $state = $agent->run((string) ($request['description'] ?? ''));

            return $state->finalText ?? '(the sub-agent returned no output)';
        } catch (Throwable $e) {
            return 'Sub-agent failed: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subagent_type' => $schema->string()
                ->enum(array_keys($this->subAgents))
                ->description('Which sub-agent to delegate to.')
                ->required(),
            'description' => $schema->string()
                ->description('A complete, self-contained description of the task, including the expected output.')
                ->required(),
        ];
    }
}
