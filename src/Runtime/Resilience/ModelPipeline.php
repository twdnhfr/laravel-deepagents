<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\Step;
use Twdnhfr\LaravelDeepagents\Context\SummarizeHistory;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;

/**
 * Composes a {@see ModelMiddleware} stack around the canonical
 * `generateText(maxSteps: 0)` call and runs it — the single place a model call
 * leaves this package. Both the {@see Loop} (each turn) and internal callers
 * like {@see SummarizeHistory} (the compaction call) go through here, so
 * retry/failover policy applies uniformly to every model call of a run.
 *
 * Middleware compose onion-style: the first in the array is the outermost.
 */
final class ModelPipeline
{
    /**
     * @param  array<int, ModelMiddleware>  $middleware
     */
    public static function run(array $middleware, ModelCall $call): Step
    {
        $core = fn (ModelCall $c): Step => $c->provider->textGateway()->generateText(
            $c->provider,
            $c->model,
            $c->instructions,
            $c->messages,
            $c->tools,
            null,
            new TextGenerationOptions(maxSteps: 0),
        )->steps->first();

        $pipeline = array_reduce(
            array_reverse($middleware),
            fn (Closure $next, ModelMiddleware $mw): Closure => fn (ModelCall $c): Step => $mw->handle($c, $next),
            $core,
        );

        return $pipeline($call);
    }
}
