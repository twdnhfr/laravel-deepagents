<?php

use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

it('exposes a chainable fluent builder', function () {
    $agent = DeepAgent::make();

    expect($agent->provider('anthropic'))->toBe($agent)
        ->and($agent->model('m'))->toBe($agent)
        ->and($agent->instructions('sys'))->toBe($agent)
        ->and($agent->tools([]))->toBe($agent)
        ->and($agent->tool(new SpyTool))->toBe($agent)
        ->and($agent->requireApproval())->toBe($agent)
        ->and($agent->maxTurns(10))->toBe($agent);
});

it('runs a configured agent to completion', function () {
    $spy = new SpyTool;
    $provider = Sdk::provider([
        Sdk::turn('', [Sdk::toolCall('spy_tool', ['query' => 'go'])], FinishReason::ToolCalls),
        Sdk::turn('here is your answer', [], FinishReason::Stop),
    ]);

    $state = DeepAgent::make()
        ->provider($provider)
        ->model('claude-test')
        ->instructions('be helpful')
        ->tool($spy)
        ->run('do the thing');

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('here is your answer');
    expect($spy->handled)->toBeTrue();
    expect($spy->receivedQuery)->toBe('go');
});

it('threads the configured instructions into the run', function () {
    $provider = Sdk::provider([Sdk::turn('hi', [], FinishReason::Stop)]);

    $state = DeepAgent::make()
        ->provider($provider)
        ->model('m')
        ->instructions('YOU ARE A PIRATE')
        ->run('hello');

    expect($state->instructions)->toContain('YOU ARE A PIRATE');
    expect($state->history[0])->toBe(['role' => 'user', 'content' => 'hello']);
});

it('suspends for approval then resumes to completion', function () {
    $spy = new SpyTool;
    $provider = Sdk::provider([
        Sdk::turn('', [Sdk::toolCall('spy_tool', ['query' => 'go'])], FinishReason::ToolCalls),
        Sdk::turn('all set', [], FinishReason::Stop),
    ]);

    $agent = DeepAgent::make()
        ->provider($provider)
        ->model('m')
        ->tool($spy)
        ->requireApproval();

    $suspended = $agent->run('please');

    expect($suspended->isSuspended())->toBeTrue();
    expect($suspended->pendingToolCalls)->toHaveCount(1);
    expect($spy->handled)->toBeFalse();

    // The suspended state is serializable — a real app would persist it here.
    $restored = RunState::fromJson($suspended->toJson());

    $finished = $agent->resume($restored);

    expect($spy->handled)->toBeTrue();
    expect($finished->isDone())->toBeTrue();
    expect($finished->finalText)->toBe('all set');
});

it('falls back to the provider default model when none is set', function () {
    $captured = null;

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturnUsing(function (...$args) use (&$captured) {
        $captured = $args[1]; // model is the 2nd positional argument

        return Sdk::turn('ok', [], FinishReason::Stop);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);
    $provider->shouldReceive('defaultTextModel')->andReturn('provider-default');

    DeepAgent::make()->provider($provider)->run('hi'); // no ->model(...)

    expect($captured)->toBe('provider-default');
});
