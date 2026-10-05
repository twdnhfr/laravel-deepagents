<?php

namespace Twdnhfr\LaravelDeepagents\Examples;

use BadMethodCallException;
use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * An offline, scripted text gateway for the demo: returns programmed turns in
 * order (the last one repeats if the script runs out). No network, no keys.
 */
class ScriptedGateway implements StepTextGateway
{
    private int $cursor = 0;

    /** @param array<int, StepResponse> $turns */
    public function __construct(private array $turns) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        return $this->turns[$this->cursor++] ?? $this->turns[array_key_last($this->turns)];
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        throw new BadMethodCallException('Streaming is not part of this demo.');
    }
}

/**
 * A drop-in {@see TextProvider} backed by {@see ScriptedGateway}. Pass canned
 * turns; build them with the static {@see turn()} / {@see ToolCall()} helpers.
 */
class ScriptedProvider implements TextProvider
{
    private StepTextGateway $gateway;

    /** @param array<int, StepResponse> $turns */
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
    public static function turn(string $text, array $toolCalls = []): StepResponse
    {
        $reason = $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCalls;

        return new StepResponse($text, $toolCalls, $reason, new TextUsage, new Meta);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments = [], string $id = 'call_1'): ToolCall
    {
        return new ToolCall($id, $name, $arguments, $id);
    }

    public function textGenerationLoop(): TextGenerationLoop
    {
        return new TextGenerationLoop($this->gateway);
    }

    public function useTextGateway(StepTextGateway $gateway): self
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function driver(): string
    {
        return 'scripted';
    }

    public function providerCredentials(): array
    {
        return [];
    }

    public function additionalConfiguration(): array
    {
        return [];
    }

    public function withHeaders(array $headers): static
    {
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
