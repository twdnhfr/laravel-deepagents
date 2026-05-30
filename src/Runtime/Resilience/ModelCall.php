<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;

/**
 * The model call a single turn is about to make, passed through the
 * {@see ModelMiddleware} pipeline. Middleware may inspect it, swap its
 * provider/model (failover), retry it, or short-circuit it.
 *
 * Immutable: {@see withProvider()} returns a copy, so a middleware can re-issue
 * the call against a different provider without mutating shared state.
 */
final class ModelCall
{
    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     */
    public function __construct(
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?string $instructions,
        public readonly array $messages,
        public readonly array $tools,
    ) {}

    public function withProvider(TextProvider $provider, string $model): self
    {
        return new self($provider, $model, $this->instructions, $this->messages, $this->tools);
    }
}
