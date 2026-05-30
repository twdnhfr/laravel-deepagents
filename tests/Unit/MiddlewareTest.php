<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelCall;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ModelMiddleware;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ToolInvocation;
use Twdnhfr\LaravelDeepagents\Runtime\Resilience\ToolMiddleware;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

it('runs model middleware around every turn', function () {
    $mw = new class implements ModelMiddleware
    {
        public int $count = 0;

        public function handle(ModelCall $call, Closure $next): Step
        {
            $this->count++;

            return $next($call);
        }
    };

    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool')], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [new SpyTool],
        modelMiddleware: [$mw],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($state->isDone())->toBeTrue();
    expect($mw->count)->toBe(2); // one wrap per model turn
});

it('lets model middleware retry a failed turn', function () {
    $flaky = new class implements ModelMiddleware
    {
        public int $attempts = 0;

        public function handle(ModelCall $call, Closure $next): Step
        {
            $this->attempts++;

            try {
                return $next($call);
            } catch (RuntimeException) {
                return $next($call); // one retry
            }
        }
    };

    // First gateway call throws, the retry returns a final answer.
    $provider = Sdk::providerThrowingThen(
        new RuntimeException('transient blip'),
        Sdk::turn('recovered', [], FinishReason::Stop),
    );

    $state = (new Loop($provider, 'm', modelMiddleware: [$flaky]))->advance(RunState::start('sys', 'go'));

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('recovered');
    expect($flaky->attempts)->toBe(1);
});

it('runs tool middleware around the tool call and can rewrite its result', function () {
    $wrap = new class implements ToolMiddleware
    {
        public function handle(ToolInvocation $call, Closure $next): string
        {
            return '[wrapped] '.$next($call);
        }
    };

    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool')], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [new SpyTool],
        toolMiddleware: [$wrap],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    $result = collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->first();
    expect($result['result'])->toBe('[wrapped] EXECUTED');
});

it('lets tool middleware short-circuit without calling the tool', function () {
    $spy = new SpyTool;
    $block = new class implements ToolMiddleware
    {
        public function handle(ToolInvocation $call, Closure $next): string
        {
            return 'blocked';
        }
    };

    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool')], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [$spy],
        toolMiddleware: [$block],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($spy->handled)->toBeFalse();
    $result = collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->first();
    expect($result['result'])->toBe('blocked');
});
