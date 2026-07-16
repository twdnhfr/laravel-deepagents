<?php

use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Twdnhfr\LaravelDeepagents\Context\SummarizeHistory;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelCall;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelMiddleware;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\RetryModelCall;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;

afterEach(fn () => Mockery::close());

/** A provider whose summarizer call returns a fixed string (and records its input). */
function summarizer(string $returns, ?string &$captured = null): TextProvider
{
    $gateway = Mockery::mock(StepTextGateway::class);
    $gateway->shouldReceive('generateTextStep')->andReturnUsing(function (...$args) use ($returns, &$captured) {
        // $args[3] is the messages array; the transcript is the user message content.
        $captured = $args[3][0]->content ?? null;

        return Sdk::turn($returns, [], FinishReason::Stop);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGenerationLoop')->andReturn(new TextGenerationLoop($gateway));

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

it('runs the summarization call through the model middleware pipeline', function () {
    // The summarize call hits a transient connection error first; with the
    // run's retry middleware passed in, the hook retries instead of crashing.
    $provider = Sdk::providerThrowingThen(
        new ConnectionException('blip'),
        Sdk::turn('RECOVERED', [], FinishReason::Stop),
    );

    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'one'],
        ['role' => 'user', 'content' => 'two'],
    ]);

    $hook = new SummarizeHistory(
        $provider,
        'm',
        triggerTokens: 1,
        keepLast: 1,
        modelMiddleware: [new RetryModelCall(times: 2, sleep: fn () => null)],
    );

    $hook->beforeModel($state);

    expect($state->history[0]['content'])->toBe("Summary of the earlier conversation:\nRECOVERED");
});

it('summarization without middleware propagates a model error (no silent retry)', function () {
    $provider = Sdk::providerAlwaysThrowing(new ConnectionException('down'));

    $state = new RunState('sys', [
        ['role' => 'user', 'content' => 'one'],
        ['role' => 'user', 'content' => 'two'],
    ]);

    expect(fn () => (new SummarizeHistory($provider, 'm', triggerTokens: 1, keepLast: 1))->beforeModel($state))
        ->toThrow(ConnectionException::class);
});

it('DeepAgent wires the run middleware into the summarize hook', function () {
    $spy = new class implements ModelMiddleware
    {
        public int $calls = 0;

        public function handle(ModelCall $call, Closure $next): Step
        {
            $this->calls++;

            return $next($call);
        }
    };

    $provider = Sdk::provider([
        Sdk::turn('SUM', [], FinishReason::Stop),  // the compaction call
        Sdk::turn('done', [], FinishReason::Stop), // the actual turn
    ]);

    $state = DeepAgent::make()
        ->provider($provider)
        ->model('m')
        ->basePrompt(null)
        ->summarize(1, 0)
        ->modelMiddleware($spy)
        ->run(str_repeat('x', 400));

    // Both model calls of this run — summarization and the turn — went
    // through the same middleware stack.
    expect($spy->calls)->toBe(2);
    expect($state->finalText)->toBe('done');
    expect($state->history[0]['content'])->toStartWith('Summary of the earlier conversation:');
});
