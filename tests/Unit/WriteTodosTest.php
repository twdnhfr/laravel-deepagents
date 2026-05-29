<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tools\WriteTodos;

afterEach(fn () => Mockery::close());

it('writes the todo list onto the run state and renders it', function () {
    $tool = new WriteTodos;
    $state = RunState::start('sys', 'go');
    $tool->withinRun($state);

    $output = $tool->handle(new Request(['todos' => [
        ['content' => 'research', 'status' => 'completed'],
        ['content' => 'draft', 'status' => 'in_progress'],
        ['content' => 'review', 'status' => 'pending'],
    ]]));

    expect($state->todos)->toBe([
        ['content' => 'research', 'status' => 'completed'],
        ['content' => 'draft', 'status' => 'in_progress'],
        ['content' => 'review', 'status' => 'pending'],
    ]);
    expect((string) $output)->toBe("Todos updated:\n[x] research\n[~] draft\n[ ] review");
});

it('normalizes an unknown status to pending', function () {
    $tool = new WriteTodos;
    $tool->withinRun($state = RunState::start('sys', 'go'));

    $tool->handle(new Request(['todos' => [['content' => 'x', 'status' => 'banana']]]));

    expect($state->todos)->toBe([['content' => 'x', 'status' => 'pending']]);
});

it('reports an empty list as cleared', function () {
    $tool = new WriteTodos;
    $tool->withinRun(RunState::start('sys', 'go'));

    expect((string) $tool->handle(new Request(['todos' => []])))->toBe('Todo list cleared.');
});

it('throws when invoked outside a run', function () {
    (new WriteTodos)->handle(new Request(['todos' => []]));
})->throws(RuntimeException::class, 'outside of a run');

it('persists todos written during a run through the loop', function () {
    $provider = Sdk::provider([
        Sdk::turn('', [Sdk::toolCall('write_todos', ['todos' => [
            ['content' => 'step one', 'status' => 'in_progress'],
        ]])], FinishReason::ToolCalls),
        Sdk::turn('done', [], FinishReason::Stop),
    ]);

    $state = DeepAgent::make()
        ->provider($provider)
        ->model('m')
        ->withTodos()
        ->run('plan it');

    expect($state->isDone())->toBeTrue();
    expect($state->todos)->toBe([['content' => 'step one', 'status' => 'in_progress']]);

    // Survives the serialization boundary.
    expect(RunState::fromJson($state->toJson())->todos)->toBe($state->todos);
});
