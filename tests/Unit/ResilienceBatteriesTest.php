<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\FailoverProviders;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\RetryModelCall;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\RetryTool;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ValidateToolArgs;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

/** Pull the first tool_result string out of a run's history. */
function firstToolResult(RunState $state): string
{
    return collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->first()['result'];
}

// ── FailoverProviders (R1) ──────────────────────────────────────────────────

it('fails over to the next provider on a failoverable error', function () {
    $rateLimited = Sdk::providerAlwaysThrowing(RateLimitedException::forProvider('primary'));
    $working = Sdk::provider([Sdk::turn('answer from fallback', [], FinishReason::Stop)]);

    $failover = new FailoverProviders([
        ['provider' => $rateLimited, 'model' => 'm1'],
        ['provider' => $working, 'model' => 'm2'],
    ]);

    $state = (new Loop($rateLimited, 'm1', modelMiddleware: [$failover]))
        ->advance(RunState::start('sys', 'go'));

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('answer from fallback');
});

it('propagates a non-failoverable error instead of failing over', function () {
    $broken = Sdk::providerAlwaysThrowing(new RuntimeException('real bug'));
    $working = Sdk::provider([Sdk::turn('never reached', [], FinishReason::Stop)]);

    $failover = new FailoverProviders([
        ['provider' => $broken, 'model' => 'm1'],
        ['provider' => $working, 'model' => 'm2'],
    ]);

    expect(fn () => (new Loop($broken, 'm1', modelMiddleware: [$failover]))->advance(RunState::start('sys', 'go')))
        ->toThrow(RuntimeException::class, 'real bug');
});

it('rethrows the last failoverable error when the whole chain is exhausted', function () {
    $a = Sdk::providerAlwaysThrowing(RateLimitedException::forProvider('a'));
    $b = Sdk::providerAlwaysThrowing(RateLimitedException::forProvider('b'));

    $failover = new FailoverProviders([
        ['provider' => $a, 'model' => 'm1'],
        ['provider' => $b, 'model' => 'm2'],
    ]);

    expect(fn () => (new Loop($a, 'm1', modelMiddleware: [$failover]))->advance(RunState::start('sys', 'go')))
        ->toThrow(RateLimitedException::class);
});

// ── RetryModelCall (R1) ─────────────────────────────────────────────────────

it('retries the model call on a transient connection error', function () {
    $provider = Sdk::providerThrowingThen(
        new ConnectionException('connection reset'),
        Sdk::turn('recovered', [], FinishReason::Stop),
    );

    $state = (new Loop($provider, 'm', modelMiddleware: [new RetryModelCall(times: 2, sleep: fn () => null)]))
        ->advance(RunState::start('sys', 'go'));

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('recovered');
});

it('does not retry a failoverable error (leaving it to failover)', function () {
    $provider = Sdk::providerAlwaysThrowing(RateLimitedException::forProvider('p'));

    // times: 5 — but a rate limit must not be retried even once.
    expect(fn () => (new Loop($provider, 'm', modelMiddleware: [new RetryModelCall(times: 5, sleep: fn () => null)]))
        ->advance(RunState::start('sys', 'go')))
        ->toThrow(RateLimitedException::class);
});

// ── ValidateToolArgs (R4) ───────────────────────────────────────────────────

it('blocks a tool call with an unknown parameter and tells the model the right one', function () {
    $spy = new SpyTool; // schema: { query }
    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool', ['quary' => 'typo'])], FinishReason::ToolCalls),
            Sdk::turn('fixed it', [], FinishReason::Stop),
        ]),
        'm',
        [$spy],
        toolMiddleware: [new ValidateToolArgs],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($spy->handled)->toBeFalse();
    expect(firstToolResult($state))->toContain('Unknown parameter')
        ->and(firstToolResult($state))->toContain('quary')
        ->and(firstToolResult($state))->toContain('query');
});

it('blocks a tool call missing a required parameter', function () {
    $needy = new class implements Tool
    {
        public function name(): string
        {
            return 'needy';
        }

        public function description(): string
        {
            return 'Needs a name.';
        }

        public function handle(Request $request): string
        {
            return 'ran';
        }

        public function schema(JsonSchema $schema): array
        {
            return ['name' => $schema->string()->required()];
        }
    };

    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('needy', [])], FinishReason::ToolCalls),
            Sdk::turn('ok', [], FinishReason::Stop),
        ]),
        'm',
        [$needy],
        toolMiddleware: [new ValidateToolArgs],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect(firstToolResult($state))->toContain('Missing required parameter')
        ->and(firstToolResult($state))->toContain('name');
});

it('lets a valid tool call through untouched', function () {
    $spy = new SpyTool;
    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool', ['query' => 'ok'])], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [$spy],
        toolMiddleware: [new ValidateToolArgs],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($spy->handled)->toBeTrue();
    expect(firstToolResult($state))->toBe('EXECUTED');
});

// ── RetryTool (R4) ──────────────────────────────────────────────────────────

it('retries a tool on a transient error then succeeds', function () {
    $flaky = new class implements Tool
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'flaky';
        }

        public function description(): string
        {
            return 'Fails once, then works.';
        }

        public function handle(Request $request): string
        {
            if (++$this->calls < 2) {
                throw new ConnectionException('blip');
            }

            return 'succeeded on attempt '.$this->calls;
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('flaky')], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [$flaky],
        toolMiddleware: [new RetryTool(times: 3, sleep: fn () => null)],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($flaky->calls)->toBe(2);
    expect(firstToolResult($state))->toBe('succeeded on attempt 2');
});
