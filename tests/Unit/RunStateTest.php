<?php

use Twdnhfr\LaravelDeepagents\Runtime\RunState;

it('starts a fresh run as running with the user message in history', function () {
    $state = RunState::start('be helpful', 'hello there');

    expect($state->instructions)->toBe('be helpful');
    expect($state->status)->toBe(RunState::STATUS_RUNNING);
    expect($state->isRunning())->toBeTrue();
    expect($state->isSuspended())->toBeFalse();
    expect($state->isDone())->toBeFalse();
    expect($state->pendingToolCalls)->toBeEmpty();
    expect($state->finalText)->toBeNull();
    expect($state->history)->toBe([['role' => 'user', 'content' => 'hello there']]);
});

it('reports each status correctly', function (string $status, string $method) {
    $state = new RunState('x', status: $status);

    expect($state->{$method}())->toBeTrue();
})->with([
    'running' => [RunState::STATUS_RUNNING, 'isRunning'],
    'suspended' => [RunState::STATUS_SUSPENDED, 'isSuspended'],
    'done' => [RunState::STATUS_DONE, 'isDone'],
]);

it('serializes to the expected shape', function () {
    $state = new RunState(
        instructions: 'sys',
        history: [['role' => 'user', 'content' => 'hi']],
        pendingToolCalls: [['id' => 'tc1', 'name' => 'tool', 'arguments' => ['a' => 1]]],
        status: RunState::STATUS_SUSPENDED,
        finalText: null,
    );

    expect($state->jsonSerialize())->toBe([
        'instructions' => 'sys',
        'history' => [['role' => 'user', 'content' => 'hi']],
        'pendingToolCalls' => [['id' => 'tc1', 'name' => 'tool', 'arguments' => ['a' => 1]]],
        'status' => RunState::STATUS_SUSPENDED,
        'finalText' => null,
        'todos' => [],
    ]);
});

it('round-trips losslessly through JSON', function () {
    $state = new RunState(
        instructions: 'sys',
        history: [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => '', 'toolCalls' => [['id' => 'tc1', 'name' => 'search', 'arguments' => ['q' => 'x']]]],
            ['role' => 'tool_result', 'toolResults' => [['id' => 'tc1', 'name' => 'search', 'arguments' => ['q' => 'x'], 'result' => 'hit']]],
        ],
        pendingToolCalls: [['id' => 'tc2', 'name' => 'write', 'arguments' => ['path' => '/a']]],
        status: RunState::STATUS_SUSPENDED,
        finalText: 'partial',
        todos: [['content' => 'do the thing', 'status' => 'in_progress']],
    );

    $restored = RunState::fromJson($state->toJson());

    expect($restored)->toEqual($state);
});

it('applies defaults for omitted optional fields in fromArray', function () {
    $state = RunState::fromArray(['instructions' => 'only required']);

    expect($state->instructions)->toBe('only required');
    expect($state->history)->toBe([]);
    expect($state->pendingToolCalls)->toBe([]);
    expect($state->status)->toBe(RunState::STATUS_RUNNING);
    expect($state->finalText)->toBeNull();
    expect($state->todos)->toBe([]);
});

it('throws on malformed JSON', function () {
    RunState::fromJson('{not valid json');
})->throws(JsonException::class);
