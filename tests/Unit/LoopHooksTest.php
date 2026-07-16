<?php

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\LoopHook;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

afterEach(fn () => Mockery::close());

it('calls beforeModel and afterModel once per model turn', function () {
    $hook = new class extends LoopHook
    {
        public int $before = 0;

        public int $after = 0;

        public function beforeModel(RunState $state): void
        {
            $this->before++;
        }

        public function afterModel(RunState $state): void
        {
            $this->after++;
        }
    };

    $loop = new Loop(
        Sdk::provider([
            Sdk::turn('', [Sdk::toolCall('spy_tool')], FinishReason::ToolCalls),
            Sdk::turn('done', [], FinishReason::Stop),
        ]),
        'm',
        [new SpyTool],
        hooks: [$hook],
    );

    $loop->advance(RunState::start('sys', 'go'));

    expect($hook->before)->toBe(2); // one per turn: tool turn + final turn
    expect($hook->after)->toBe(2);
});

it('skips the model call when a beforeModel hook halts the run', function () {
    $gateway = Mockery::mock(StepTextGateway::class);
    $gateway->shouldNotReceive('generateTextStep');

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGenerationLoop')->andReturn(new TextGenerationLoop($gateway));

    $halting = new class extends LoopHook
    {
        public function beforeModel(RunState $state): void
        {
            $state->halt('budget exhausted');
        }
    };

    $state = (new Loop($provider, 'm', hooks: [$halting]))->advance(RunState::start('sys', 'go'));

    expect($state->isHalted())->toBeTrue();
    expect($state->haltReason)->toBe('budget exhausted');
});

it('lets beforeModel compact the history that is actually sent to the model', function () {
    $sentMessageCount = null;

    $gateway = Mockery::mock(StepTextGateway::class);
    $gateway->shouldReceive('generateTextStep')->andReturnUsing(function (...$args) use (&$sentMessageCount) {
        $sentMessageCount = count($args[3]); // the $messages array

        return Sdk::turn('ok', [], FinishReason::Stop);
    });

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGenerationLoop')->andReturn(new TextGenerationLoop($gateway));

    $compactor = new class extends LoopHook
    {
        public function beforeModel(RunState $state): void
        {
            $state->history = array_slice($state->history, -1); // keep only the most recent entry
        }
    };

    $longHistory = [
        ['role' => 'user', 'content' => 'a'],
        ['role' => 'assistant', 'content' => 'b', 'toolCalls' => []],
        ['role' => 'user', 'content' => 'c'],
        ['role' => 'assistant', 'content' => 'd', 'toolCalls' => []],
        ['role' => 'user', 'content' => 'e'],
    ];

    (new Loop($provider, 'm', hooks: [$compactor]))->advance(new RunState('sys', $longHistory));

    expect($sentMessageCount)->toBe(1);
});
