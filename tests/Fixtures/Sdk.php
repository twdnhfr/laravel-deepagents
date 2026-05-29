<?php

namespace Twdnhfr\LaravelDeepagents\Tests\Fixtures;

use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery;

/**
 * Test helpers for building canned SDK responses and a mocked TextProvider, so
 * the runtime can be exercised without HTTP or real provider parsing.
 */
class Sdk
{
    /**
     * A single-turn gateway response carrying exactly one Step.
     *
     * @param  array<int, ToolCall>  $toolCalls
     */
    public static function turn(string $text, array $toolCalls, FinishReason $reason): TextResponse
    {
        $step = new Step($text, $toolCalls, [], $reason, new Usage, new Meta);

        return (new TextResponse($text, new Usage, new Meta))->withSteps(collect([$step]));
    }

    public static function toolCall(string $name, array $args = [], string $id = 'tc'): ToolCall
    {
        return new ToolCall($id, $name, $args, $id);
    }

    /**
     * A mocked TextProvider whose gateway returns the given responses in order
     * (the last repeats for any further turns).
     *
     * @param  array<int, TextResponse>  $responses
     */
    public static function provider(array $responses, string $defaultModel = 'default-model'): TextProvider
    {
        $gateway = Mockery::mock(TextGateway::class);
        $gateway->shouldReceive('generateText')->andReturn(...$responses);

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldReceive('textGateway')->andReturn($gateway);
        $provider->shouldReceive('defaultTextModel')->andReturn($defaultModel);

        return $provider;
    }
}
