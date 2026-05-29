<?php

namespace Twdnhfr\LaravelDeepagents\Examples;

use BadMethodCallException;
use Closure;
use Generator;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;

/**
 * An offline, scripted text gateway for the demo: returns programmed turns in
 * order (the last one repeats if the script runs out). No network, no keys.
 */
class ScriptedGateway implements TextGateway
{
    private int $cursor = 0;

    /** @param array<int, TextResponse> $turns */
    public function __construct(private array $turns) {}

    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        return $this->turns[$this->cursor++] ?? $this->turns[array_key_last($this->turns)];
    }

    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        throw new BadMethodCallException('Streaming is not part of this demo.');
    }

    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        return $this;
    }
}

/**
 * A drop-in {@see TextProvider} backed by {@see ScriptedGateway}. Pass canned
 * turns; build them with the static {@see turn()} / {@see ToolCall()} helpers.
 */
class ScriptedProvider implements TextProvider
{
    private ScriptedGateway $gateway;

    /** @param array<int, TextResponse> $turns */
    public function __construct(array $turns, private string $model = 'demo-model')
    {
        $this->gateway = new ScriptedGateway($turns);
    }

    /**
     * Build one canned turn. With tool calls the finish reason is ToolCalls,
     * otherwise Stop.
     *
     * @param  array<int, ToolCall>  $toolCalls
     */
    public static function turn(string $text, array $toolCalls = []): TextResponse
    {
        $reason = $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCalls;
        $step = new Step($text, $toolCalls, [], $reason, new Usage, new Meta);

        return (new TextResponse($text, new Usage, new Meta))->withSteps(collect([$step]));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments = [], string $id = 'call_1'): ToolCall
    {
        return new ToolCall($id, $name, $arguments, $id);
    }

    public function textGateway(): TextGateway
    {
        return $this->gateway;
    }

    public function useTextGateway(TextGateway $gateway): self
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function defaultTextModel(): string
    {
        return $this->model;
    }

    public function cheapestTextModel(): string
    {
        return $this->model;
    }

    public function smartestTextModel(): string
    {
        return $this->model;
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new BadMethodCallException('Use DeepAgent::run() in this demo, not the SDK prompt().');
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new BadMethodCallException('Streaming is not part of this demo.');
    }
}
