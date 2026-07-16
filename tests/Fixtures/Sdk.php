<?php

namespace Twdnhfr\LaravelDeepagents\Tests\Fixtures;

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;

/**
 * Test helpers for building canned SDK responses and a mocked TextProvider, so
 * the runtime can be exercised without HTTP or real provider parsing.
 */
class Sdk
{
    /**
     * A single-turn gateway step response.
     *
     * @param  array<int, ToolCall>  $toolCalls
     */
    public static function turn(string $text, array $toolCalls, FinishReason $reason): StepResponse
    {
        return new StepResponse($text, $toolCalls, $reason, new Usage, new Meta);
    }

    public static function toolCall(string $name, array $args = [], string $id = 'tc'): ToolCall
    {
        return new ToolCall($id, $name, $args, $id);
    }

    /**
     * A mocked TextProvider whose gateway returns the given responses in order
     * (the last repeats for any further turns).
     *
     * @param  array<int, StepResponse>  $responses
     */
    public static function provider(array $responses, string $defaultModel = 'default-model'): TextProvider
    {
        $gateway = Mockery::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->andReturn(...$responses);

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldReceive('textGenerationLoop')->andReturn(new TextGenerationLoop($gateway));
        $provider->shouldReceive('defaultTextModel')->andReturn($defaultModel);

        return $provider;
    }

    /**
     * A mocked TextProvider whose gateway throws on its first `generateTextStep` call
     * and then returns the given responses in order — for exercising retry
     * middleware around the model call.
     *
     * @param  array<int, StepResponse>  $thenReturn
     */
    public static function providerThrowingThen(\Throwable $throw, StepResponse ...$thenReturn): TextProvider
    {
        $calls = 0;

        $gateway = Mockery::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->andReturnUsing(function () use (&$calls, $throw, $thenReturn) {
            if ($calls++ === 0) {
                throw $throw;
            }

            return $thenReturn[min($calls - 2, count($thenReturn) - 1)];
        });

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldReceive('textGenerationLoop')->andReturn(new TextGenerationLoop($gateway));
        $provider->shouldReceive('defaultTextModel')->andReturn('default-model');

        return $provider;
    }

    /**
     * A mocked TextProvider whose gateway throws the given exception on every
     * `generateTextStep` call — for exercising failover and retry middleware.
     */
    public static function providerAlwaysThrowing(\Throwable $e, string $defaultModel = 'default-model'): TextProvider
    {
        $gateway = Mockery::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->andThrow($e);

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldReceive('textGenerationLoop')->andReturn(new TextGenerationLoop($gateway));
        $provider->shouldReceive('defaultTextModel')->andReturn($defaultModel);

        return $provider;
    }
}
