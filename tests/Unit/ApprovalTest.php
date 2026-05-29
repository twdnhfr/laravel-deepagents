<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\DeepAgent;
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
