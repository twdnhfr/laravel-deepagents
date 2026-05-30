<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Laravel\Ai\Responses\Data\Step;
use Twdnhfr\LaravelDeepagents\Runtime\Hook;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;

/**
 * Middleware around a single model turn's `generateText` call — the resilience
 * seam established by [ADR-0005](../../../docs/adr/0005-resilience-at-the-loop-seam.md).
 * Wrapping the call (rather than running between calls, as a {@see Hook}
 * does) is what lets it be retried, routed to a fallback provider, logged, or
 * short-circuited.
 *
 * Composes onion-style: the first middleware added is the outermost. Middleware
 * are runtime config on the {@see Loop}, never
 * serialized — so they must hold no per-run state.
 */
interface ModelMiddleware
{
    /**
     * Run, retry, re-route, or skip the model call. `$next` performs the actual
     * `generateText(maxSteps: 0)` for the given {@see ModelCall} and returns its
     * {@see Step}; call it to proceed — possibly more than once, or with a
     * different provider via {@see ModelCall::withProvider()}.
     *
     * @param  Closure(ModelCall): Step  $next
     */
    public function handle(ModelCall $call, Closure $next): Step;
}
