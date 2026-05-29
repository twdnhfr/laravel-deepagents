<?php

namespace Twdnhfr\LaravelDeepagents\Tests\Fixtures;

use Laravel\Ai\AnonymousAgent;

/**
 * A minimal agent whose only job is to expose a configurable `maxSteps()`.
 *
 * `TextGenerationOptions::forAgent()` resolves maxSteps from a `maxSteps()`
 * method (preferred) or a `#[MaxSteps]` attribute. Returning null falls through
 * to the gateway's default ("auto-loop"), which lets us test both branches with
 * one fixture.
 */
class StepwiseAgent extends AnonymousAgent
{
    public function __construct(iterable $tools = [], private ?int $max = null)
    {
        parent::__construct(
            'You are a test agent. When asked, call the spy_tool tool.',
            [],
            $tools,
        );
    }

    public function maxSteps(): ?int
    {
        return $this->max;
    }
}
