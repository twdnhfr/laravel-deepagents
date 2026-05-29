<?php

use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Twdnhfr\LaravelDeepagents\Context\SummarizeHistory;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

afterEach(fn () => Mockery::close());

/** A provider whose summarizer call returns a fixed string (and records its input). */
function summarizer(string $returns, ?string &$captured = null): TextProvider
{
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturnUsing(function (...$args) use ($returns, &$captured) {
        // $args[3] is the messages array; the transcript is the user message content.
        $captured = $args[3][0]->content ?? null;

        return new TextResponse($returns, new Usage, new Meta);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    return $provider;
}

it('leaves the history untouched when under the token budget', function () {
    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'hello'],
        ['role' => 'assistant', 'content' => 'hi', 'toolCalls' => []],
    ]);
    $before = $state->history;

    (new SummarizeHistory(summarizer('SUMMARY'), 'm', triggerTokens: 100_000))->beforeModel($state);

    expect($state->history)->toBe($before);
});

it('compacts older history into a summary and keeps the most recent entries', function () {
    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'one'],
        ['role' => 'assistant', 'content' => 'two', 'toolCalls' => []],
        ['role' => 'user', 'content' => 'three'],
        ['role' => 'assistant', 'content' => 'four', 'toolCalls' => []],
        ['role' => 'user', 'content' => 'five'],
    ]);

    (new SummarizeHistory(summarizer('CONDENSED'), 'm', triggerTokens: 1, keepLast: 2))->beforeModel($state);

    expect($state->history)->toHaveCount(3); // summary + last 2
    expect($state->history[0])->toBe(['role' => 'user', 'content' => "Summary of the earlier conversation:\nCONDENSED"]);
    expect($state->history[1])->toBe(['role' => 'assistant', 'content' => 'four', 'toolCalls' => []]);
    expect($state->history[2])->toBe(['role' => 'user', 'content' => 'five']);
});

it('never starts the kept window on a tool_result (keeps tool pairs intact)', function () {
    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'do it'],
        ['role' => 'assistant', 'content' => '', 'toolCalls' => [['id' => 't1', 'name' => 'spy', 'arguments' => []]]],
        ['role' => 'tool_result', 'toolResults' => [['id' => 't1', 'name' => 'spy', 'arguments' => [], 'result' => 'ok']]],
        ['role' => 'user', 'content' => 'thanks'],
        ['role' => 'assistant', 'content' => 'welcome', 'toolCalls' => []],
    ]);

    // Naive cut at count-keepLast = 2 would land on the tool_result; safeCut walks back to the assistant.
    (new SummarizeHistory(summarizer('S'), 'm', triggerTokens: 1, keepLast: 3))->beforeModel($state);

    expect($state->history[0]['content'])->toStartWith('Summary of the earlier conversation:');
    expect($state->history[1]['role'])->toBe('assistant'); // the tool-call assistant, not the tool_result
    expect($state->history[2]['role'])->toBe('tool_result');
});

it('summarizes the older entries, not the kept ones', function () {
    $captured = null;
    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'OLD_MESSAGE'],
        ['role' => 'assistant', 'content' => 'mid', 'toolCalls' => []],
        ['role' => 'user', 'content' => 'KEPT_MESSAGE'],
    ]);

    (new SummarizeHistory(summarizer('S', $captured), 'm', triggerTokens: 1, keepLast: 1))->beforeModel($state);

    expect($captured)->toContain('OLD_MESSAGE');
    expect($captured)->not->toContain('KEPT_MESSAGE');
});
