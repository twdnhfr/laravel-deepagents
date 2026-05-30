<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tools\Task;

/** A sub-agent that writes an artifact during its turn, then finishes. */
function subAgentWritingArtifact(string $path, string $content): DeepAgent
{
    return DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('write_artifact', ['path' => $path, 'content' => $content])], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->withArtifacts();
}

afterEach(fn () => Mockery::close());

/** A sub-agent that immediately returns the given text. */
function subAgentReturning(string $text): DeepAgent
{
    return DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn($text, [], FinishReason::Stop)]))
        ->model('m');
}

it('delegates to the named sub-agent and returns its output', function () {
    $task = new Task([
        'researcher' => ['description' => 'Researches topics.', 'agent' => subAgentReturning('the answer is 42')],
    ]);

    $result = $task->handle(new Request(['subagent_type' => 'researcher', 'description' => 'find the answer']));

    expect((string) $result)->toBe('the answer is 42');
});

it('returns a message for an unknown sub-agent', function () {
    $task = new Task(['researcher' => ['description' => 'x', 'agent' => subAgentReturning('y')]]);

    expect((string) $task->handle(new Request(['subagent_type' => 'ghost', 'description' => 'z'])))
        ->toBe('No sub-agent named [ghost] is available.');
});

it('catches a sub-agent failure and returns it as text', function () {
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andThrow(new RuntimeException('boom'));
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    $broken = DeepAgent::make()->provider($provider)->model('m');
    $task = new Task(['x' => ['description' => 'x', 'agent' => $broken]]);

    expect((string) $task->handle(new Request(['subagent_type' => 'x', 'description' => 'go'])))
        ->toBe('Sub-agent failed: boom');
});

it('exposes a task tool listing the sub-agents as the enum', function () {
    $task = new Task([
        'researcher' => ['description' => 'Researches.', 'agent' => subAgentReturning('a')],
        'writer' => ['description' => 'Writes.', 'agent' => subAgentReturning('b')],
    ]);

    expect($task->name())->toBe('task');
    expect((string) $task->description())->toContain('researcher')->toContain('writer');

    $schema = $task->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['subagent_type', 'description']);
    expect($schema['subagent_type']->toArray()['enum'])->toBe(['researcher', 'writer']);
});

it('wires a task tool when sub-agents are registered and completes a delegated run', function () {
    $parentProvider = Sdk::provider([
        Sdk::turn('', [Sdk::toolCall('task', ['subagent_type' => 'helper', 'description' => 'do the thing'])], FinishReason::ToolCalls),
        Sdk::turn('all wrapped up', [], FinishReason::Stop),
    ]);

    $state = DeepAgent::make()
        ->provider($parentProvider)
        ->model('m')
        ->subAgent('helper', 'Helps with things.', subAgentReturning('sub did the work'))
        ->run('please delegate');

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('all wrapped up');

    $toolResults = collect($state->history)
        ->where('role', 'tool_result')
        ->flatMap(fn ($m) => $m['toolResults'])
        ->all();

    expect($toolResults[0]['result'])->toBe('sub did the work');
});

it('shares the parent backend with a sub-agent that has none', function () {
    $backend = new StateBackend;

    $task = new Task(['writer' => ['description' => 'writes', 'agent' => subAgentWritingArtifact('notes.md', 'from sub')]]);
    $task->withBackend($backend);

    $task->handle(new Request(['subagent_type' => 'writer', 'description' => 'write notes']));

    // The sub-agent wrote into the parent's backend instance.
    expect($backend->read('notes.md'))->toBe('from sub');
});

it('does not override a sub-agent that has its own backend', function () {
    $parentBackend = new StateBackend;
    $ownBackend = new StateBackend;

    $sub = subAgentWritingArtifact('notes.md', 'own')->backend($ownBackend);

    $task = new Task(['writer' => ['description' => 'writes', 'agent' => $sub]]);
    $task->withBackend($parentBackend);

    $task->handle(new Request(['subagent_type' => 'writer', 'description' => 'write notes']));

    expect($ownBackend->read('notes.md'))->toBe('own');
    expect($parentBackend->exists('notes.md'))->toBeFalse();
});
