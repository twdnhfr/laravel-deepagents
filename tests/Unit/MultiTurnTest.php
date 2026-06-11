<?php

use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\LoopException;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;

afterEach(fn () => Mockery::close());

it('continue() carries the full prior conversation into the next turn', function () {
    $sentCounts = [];

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturnUsing(function (...$args) use (&$sentCounts) {
        $sentCounts[] = count($args[3]); // number of messages sent this turn

        return Sdk::turn('reply '.(count($sentCounts)), [], FinishReason::Stop);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    $agent = DeepAgent::make()->provider($provider)->model('m')->basePrompt(null);

    $first = $agent->run('first question');
    $second = $agent->continue($first, 'second question');

    // Turn 1 sent just the user message; turn 2 sent user + assistant + user.
    expect($sentCounts)->toBe([1, 3]);

    // History accumulated across both turns: user, assistant, user, assistant.
    expect($second->history)->toHaveCount(4);
    expect($second->history[2])->toBe(['role' => 'user', 'content' => 'second question']);
    expect($second->isDone())->toBeTrue();
    expect($second->finalText)->toBe('reply 2');
});

it('continue() reuses the existing run instructions, not fresh ones', function () {
    $instructionsSeen = [];

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturnUsing(function (...$args) use (&$instructionsSeen) {
        $instructionsSeen[] = $args[2]; // instructions

        return Sdk::turn('ok', [], FinishReason::Stop);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    $agent = DeepAgent::make()->provider($provider)->model('m')->basePrompt(null)->instructions('Be a pirate.');

    $second = $agent->continue($agent->run('hi'), 'again');

    expect($instructionsSeen[0])->toBe('Be a pirate.');
    expect($instructionsSeen[1])->toBe('Be a pirate.'); // same instructions on the continued turn
});

it('continue() refuses a suspended run — resume() it instead', function () {
    $suspended = new RunState('sys', [
        ['role' => 'user', 'content' => 'hi'],
        ['role' => 'assistant', 'content' => '', 'toolCalls' => [['id' => 'tc', 'name' => 'a_tool', 'arguments' => []]]],
    ], [
        ['id' => 'tc', 'name' => 'a_tool', 'arguments' => []],
    ], RunState::STATUS_SUSPENDED);

    expect(fn () => DeepAgent::make()->continue($suspended, 'never mind'))
        ->toThrow(LoopException::class, 'Cannot continue a suspended run');
});

it('continue() resets the turn budget for the fresh user turn', function () {
    $provider = Sdk::provider([
        Sdk::turn('first answer', [], FinishReason::Stop),
        Sdk::turn('second answer', [], FinishReason::Stop),
    ]);

    $agent = DeepAgent::make()->provider($provider)->model('m')->basePrompt(null);

    $first = $agent->run('one');
    expect($first->turns)->toBe(1);

    $second = $agent->continue($first, 'two');

    // Only the turns of the new user request count against the budget.
    expect($second->turns)->toBe(1);
    expect($second->finalText)->toBe('second answer');
});
