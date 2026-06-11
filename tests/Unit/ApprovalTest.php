<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\LoopException;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

it('auto-runs a non-gated tool with an allow-list policy', function () {
    $safe = new SpyTool('safe');

    $state = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('safe')], FinishReason::ToolCalls),
            Sdk::turn('ok', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($safe)
        ->requireApproval(['danger'])
        ->run('x');

    expect($state->isDone())->toBeTrue();
    expect($safe->handled)->toBeTrue();
});

it('suspends a gated tool with an allow-list policy', function () {
    $danger = new SpyTool('danger');

    $state = DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn('', [Sdk::toolCall('danger')], FinishReason::ToolCalls)]))
        ->model('m')
        ->tool($danger)
        ->requireApproval(['danger'])
        ->run('x');

    expect($state->isSuspended())->toBeTrue();
    expect($state->pendingToolCalls[0]['name'])->toBe('danger');
    expect($danger->handled)->toBeFalse();
});

it('supports a closure approval policy', function () {
    $state = DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn('', [Sdk::toolCall('admin_delete')], FinishReason::ToolCalls)]))
        ->model('m')
        ->tool(new SpyTool('admin_delete'))
        ->requireApproval(fn (array $call): bool => str_starts_with($call['name'], 'admin_'))
        ->run('x');

    expect($state->isSuspended())->toBeTrue();
});

it('suspends the whole turn when one of several calls is gated, then resumes all', function () {
    $safe = new SpyTool('safe');
    $danger = new SpyTool('danger');

    $agent = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('safe', [], 'c1'), Sdk::toolCall('danger', [], 'c2')], FinishReason::ToolCalls),
            Sdk::turn('all done', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($safe)
        ->tool($danger)
        ->requireApproval(['danger']);

    $state = $agent->run('x');
    expect($state->isSuspended())->toBeTrue();
    expect($state->pendingToolCalls)->toHaveCount(2);
    expect($safe->handled)->toBeFalse();
    expect($danger->handled)->toBeFalse();

    $state = $agent->resume(RunState::fromJson($state->toJson()));
    expect($state->isDone())->toBeTrue();
    expect($safe->handled)->toBeTrue();
    expect($danger->handled)->toBeTrue();
});

it('gates every tool with no-argument requireApproval()', function () {
    $state = DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn('', [Sdk::toolCall('anything')], FinishReason::ToolCalls)]))
        ->model('m')
        ->tool(new SpyTool('anything'))
        ->requireApproval()
        ->run('x');

    expect($state->isSuspended())->toBeTrue();
});

it('runs autonomously with requireApproval(false)', function () {
    $tool = new SpyTool('go');

    $state = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('go')], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($tool)
        ->requireApproval(false)
        ->run('x');

    expect($state->isDone())->toBeTrue();
    expect($tool->handled)->toBeTrue();
});

it('rejects one call of a batch: the other executes, the reason goes back as the result', function () {
    $safe = new SpyTool('safe');
    $danger = new SpyTool('danger');

    $agent = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('safe', [], 'c1'), Sdk::toolCall('danger', [], 'c2')], FinishReason::ToolCalls),
            Sdk::turn('adjusted', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($safe)
        ->tool($danger)
        ->requireApproval();

    $state = $agent->run('x');

    $state->approve('c1');
    $state->reject('c2', 'Deleting is off limits today.');

    $state = $agent->resume($state);

    expect($state->isDone())->toBeTrue();
    expect($safe->handled)->toBeTrue();
    expect($danger->handled)->toBeFalse();

    // One batched tool_result entry, in the original call order.
    $results = collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->all();
    expect($results)->toHaveCount(2);
    expect($results[0])->toMatchArray(['id' => 'c1', 'result' => 'EXECUTED']);
    expect($results[1]['id'])->toBe('c2');
    expect($results[1]['result'])->toBe('The user rejected this tool call: Deleting is off limits today.');
});

it('rejects every pending call: nothing executes and the model reacts to the reasons', function () {
    $danger = new SpyTool('danger');

    $agent = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('danger')], FinishReason::ToolCalls),
            Sdk::turn('understood, standing down', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($danger)
        ->requireApproval();

    $state = $agent->run('x');
    $state->reject('tc');

    $state = $agent->resume($state);

    expect($danger->handled)->toBeFalse();
    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('understood, standing down');

    $results = collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->all();
    expect($results[0]['result'])->toBe('The user rejected this tool call: No reason given.');
});

it('edits a pending call: the tool runs with the corrected arguments', function () {
    $tool = new SpyTool('lookup');

    $agent = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('lookup', ['query' => 'drop everything'])], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($tool)
        ->requireApproval();

    $state = $agent->run('x');
    $state->edit('tc', ['query' => 'list everything']);

    $state = $agent->resume($state);

    expect($tool->handled)->toBeTrue();
    expect($tool->receivedQuery)->toBe('list everything');

    // The model's original request stays untouched on the assistant message.
    $assistant = collect($state->history)->firstWhere('role', 'assistant');
    expect($assistant['toolCalls'][0]['arguments'])->toBe(['query' => 'drop everything']);
});

it('decisions survive a JSON round-trip across the process boundary', function () {
    $safe = new SpyTool('safe');
    $danger = new SpyTool('danger');

    $agent = DeepAgent::make()
        ->provider(Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('safe', [], 'c1'), Sdk::toolCall('danger', [], 'c2')], FinishReason::ToolCalls),
            Sdk::turn('ok', [], FinishReason::Stop),
        ]))
        ->model('m')
        ->tool($safe)
        ->tool($danger)
        ->requireApproval();

    $state = $agent->run('x');
    $state->reject('c2', 'nope');

    // Persist with the decisions recorded, restore elsewhere, then resume.
    $state = $agent->resume(RunState::fromJson($state->toJson()));

    expect($safe->handled)->toBeTrue();
    expect($danger->handled)->toBeFalse();
});

it('refuses a decision on a run that is not suspended', function () {
    $state = RunState::start('sys', 'hi');

    expect(fn () => $state->reject('tc'))
        ->toThrow(LoopException::class, 'only a [suspended] run has calls awaiting approval');
});

it('refuses a decision for an unknown pending call id', function () {
    $state = new RunState('sys', [], [
        ['id' => 'c1', 'name' => 'tool', 'arguments' => []],
    ], RunState::STATUS_SUSPENDED);

    expect(fn () => $state->approve('ghost'))
        ->toThrow(LoopException::class, 'No pending tool call with id [ghost]');
});
