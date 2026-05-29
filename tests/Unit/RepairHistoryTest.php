<?php

use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;

afterEach(fn () => Mockery::close());

it('inserts a synthetic tool_result for a dangling assistant tool call', function () {
    $sentMessageCount = null;

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturnUsing(function (...$args) use (&$sentMessageCount) {
        $sentMessageCount = count($args[3]); // messages

        return Sdk::turn('done', [], FinishReason::Stop);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    // History ends with an assistant tool call that has NO matching tool_result.
    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'do it'],
        ['role' => 'assistant', 'content' => '', 'toolCalls' => [['id' => 't1', 'name' => 'spy', 'arguments' => []]]],
    ]);

    (new Loop($provider, 'm'))->advance($state);

    // A synthetic tool_result was inserted right after the dangling assistant turn.
    expect($state->history[2]['role'])->toBe('tool_result');
    expect($state->history[2]['toolResults'][0]['id'])->toBe('t1');
    expect($state->history[2]['toolResults'][0]['result'])->toContain('no result recorded');

    // The model therefore received user + assistant(tool_use) + tool_result = 3 messages.
    expect($sentMessageCount)->toBe(3);
});

it('does not touch a history whose tool calls already have results', function () {
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturn(Sdk::turn('done', [], FinishReason::Stop));
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'do it'],
        ['role' => 'assistant', 'content' => '', 'toolCalls' => [['id' => 't1', 'name' => 'spy', 'arguments' => []]]],
        ['role' => 'tool_result', 'toolResults' => [['id' => 't1', 'name' => 'spy', 'arguments' => [], 'result' => 'real']]],
    ]);

    (new Loop($provider, 'm'))->advance($state);

    // No extra tool_result was inserted: still exactly one, with the real result.
    $toolResults = array_values(array_filter($state->history, fn ($m) => $m['role'] === 'tool_result'));
    expect($toolResults)->toHaveCount(1);
    expect($toolResults[0]['toolResults'][0]['result'])->toBe('real');
});
