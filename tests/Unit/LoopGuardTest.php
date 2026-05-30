<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\LoopGuard;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

it('halts a run that repeats the same tool call', function () {
    $spy = new SpyTool;
    $loop = new Loop(
        // The same tool call, returned forever.
        Sdk::provider([Sdk::turn('', [Sdk::toolCall('spy_tool', ['q' => 'x'])], FinishReason::ToolCalls)]),
        'm',
        [$spy],
        hooks: [new LoopGuard(3)],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($state->isHalted())->toBeTrue();
    expect($state->haltReason)->toContain('No progress');
    // It stopped before executing the third (halting) call — only the first two ran.
    $toolResults = collect($state->history)->where('role', 'tool_result');
    expect($toolResults)->toHaveCount(2);
});

it('does not halt when the tool calls differ', function () {
    $spy = new SpyTool;
    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool', ['q' => 'a'])], FinishReason::ToolCalls),
            Sdk::turn('', [Sdk::toolCall('spy_tool', ['q' => 'b'])], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [$spy],
        hooks: [new LoopGuard(2)],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('done');
});

it('ignores argument key order when comparing calls', function () {
    $spy = new SpyTool;
    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool', ['a' => 1, 'b' => 2])], FinishReason::ToolCalls),
            Sdk::turn('', [Sdk::toolCall('spy_tool', ['b' => 2, 'a' => 1])], FinishReason::ToolCalls),
        ]),
        'm',
        [$spy],
        hooks: [new LoopGuard(2)],
    );

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($state->isHalted())->toBeTrue();
});
