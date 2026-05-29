<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\LoopException;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

/** A single-turn gateway response carrying one Step. */
function turnResponse(string $text, array $toolCalls, FinishReason $reason): TextResponse
{
    $step = new Step($text, $toolCalls, [], $reason, new Usage, new Meta);

    return (new TextResponse($text, new Usage, new Meta))->withSteps(collect([$step]));
}

function aToolCall(string $name, array $args = [], string $id = 'tc'): ToolCall
{
    return new ToolCall($id, $name, $args, $id);
}

/**
 * Build a Loop whose gateway returns the given responses in sequence (the last
 * one repeats for any further turns).
 *
 * @param  array<int, TextResponse>  $responses
 * @param  array<int, Tool>  $tools
 */
function loopReturning(array $responses, array $tools = [], bool $requireApproval = false, int $maxTurns = 50): Loop
{
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturn(...$responses);

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    return new Loop($provider, 'test-model', $tools, $requireApproval ? fn (): bool => true : null, $maxTurns);
}

it('runs autonomously: executes a tool then completes', function () {
    $spy = new SpyTool;
    $loop = loopReturning([
        turnResponse('', [aToolCall('spy_tool', ['query' => 'go'])], FinishReason::ToolCalls),
        turnResponse('finished', [], FinishReason::Stop),
    ], [$spy]);

    $state = $loop->advance(RunState::start('sys', 'do it'));

    expect($spy->handled)->toBeTrue();
    expect($spy->receivedQuery)->toBe('go');
    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('finished');
    expect($state->pendingToolCalls)->toBeEmpty();
});

it('handles multiple tool calls in a single turn', function () {
    $a = new SpyTool('spy_a');
    $b = new SpyTool('spy_b');
    $loop = loopReturning([
        turnResponse('', [aToolCall('spy_a', [], 'tc_a'), aToolCall('spy_b', [], 'tc_b')], FinishReason::ToolCalls),
        turnResponse('both done', [], FinishReason::Stop),
    ], [$a, $b]);

    $state = $loop->advance(RunState::start('sys', 'do both'));

    expect($a->handled)->toBeTrue();
    expect($b->handled)->toBeTrue();
    expect($state->isDone())->toBeTrue();

    $toolResultEntries = array_values(array_filter($state->history, fn ($m) => $m['role'] === 'tool_result'));
    expect($toolResultEntries)->toHaveCount(1);
    expect($toolResultEntries[0]['toolResults'])->toHaveCount(2);
});

it('suspends before any tool when approval is required', function () {
    $spy = new SpyTool;
    $loop = loopReturning([
        turnResponse('', [aToolCall('spy_tool', ['query' => 'go'])], FinishReason::ToolCalls),
    ], [$spy], requireApproval: true);

    $state = $loop->advance(RunState::start('sys', 'do it'));

    expect($state->isSuspended())->toBeTrue();
    expect($spy->handled)->toBeFalse();
    expect($state->pendingToolCalls)->toHaveCount(1);
    expect($state->pendingToolCalls[0])->toBe(['id' => 'tc', 'name' => 'spy_tool', 'arguments' => ['query' => 'go']]);
});

it('resumes a suspended run: executes the pending tool and completes', function () {
    $spy = new SpyTool;
    $loop = loopReturning([
        turnResponse('', [aToolCall('spy_tool', ['query' => 'go'])], FinishReason::ToolCalls),
        turnResponse('done now', [], FinishReason::Stop),
    ], [$spy], requireApproval: true);

    $suspended = $loop->advance(RunState::start('sys', 'do it'));
    expect($spy->handled)->toBeFalse();

    $finished = $loop->resume($suspended);

    expect($spy->handled)->toBeTrue();
    expect($finished->isDone())->toBeTrue();
    expect($finished->finalText)->toBe('done now');
    expect($finished->pendingToolCalls)->toBeEmpty();
});

it('refuses to resume a run that is not suspended', function () {
    $loop = loopReturning([]);

    expect(fn () => $loop->resume(RunState::start('sys', 'hi')))
        ->toThrow(LoopException::class, 'only a [suspended] run can be resumed');
});

it('returns a thrown tool error as the tool result and keeps the run going', function () {
    $boom = new class implements Tool
    {
        public function name(): string
        {
            return 'boom';
        }

        public function description(): string
        {
            return 'Always fails.';
        }

        public function handle(Request $request): string
        {
            throw new RuntimeException('kaboom');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $loop = loopReturning([
        turnResponse('', [aToolCall('boom')], FinishReason::ToolCalls),
        turnResponse('I hit an error but recovered.', [], FinishReason::Stop),
    ], [$boom]);

    $state = $loop->advance(RunState::start('sys', 'go'));

    expect($state->isDone())->toBeTrue();
    expect($state->finalText)->toBe('I hit an error but recovered.');

    $results = collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->all();
    expect($results[0]['result'])->toContain('kaboom');
});

it('throws when the model calls a tool that is not registered', function () {
    $loop = loopReturning([
        turnResponse('', [aToolCall('ghost')], FinishReason::ToolCalls),
    ], tools: []);

    expect(fn () => $loop->advance(RunState::start('sys', 'hi')))
        ->toThrow(LoopException::class, 'no such tool');
});

it('enforces the turn limit against a non-terminating tool loop', function () {
    $spy = new SpyTool;
    $loop = loopReturning(
        [turnResponse('', [aToolCall('spy_tool')], FinishReason::ToolCalls)], // repeats forever
        [$spy],
        maxTurns: 3,
    );

    expect(fn () => $loop->advance(RunState::start('sys', 'loop')))
        ->toThrow(LoopException::class, 'turn limit of 3');
});

it('throws when hydrating an unknown message role from history', function () {
    $loop = loopReturning([]);
    $state = new RunState('sys', [['role' => 'wizard', 'content' => 'poof']]);

    expect(fn () => $loop->advance($state))
        ->toThrow(LoopException::class, 'unknown role [wizard]');
});
