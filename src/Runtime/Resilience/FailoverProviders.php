<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Step;
use RuntimeException;
use Throwable;

/**
 * Try an ordered chain of providers, failing over to the next on a
 * {@see FailoverableException} (rate limit, overload, insufficient credits) —
 * the same contract `laravel/ai` honours in its own gateway loop. We reuse the
 * SDK's marker exception and {@see ProviderFailedOver} event rather than
 * inventing our own vocabulary (see [ADR-0005](../../../docs/adr/0005-resilience-at-the-loop-seam.md)).
 *
 * A non-failoverable error (a real bug in a tool, a malformed request) is not a
 * reason to switch providers, so it propagates immediately.
 */
final class FailoverProviders implements ModelMiddleware
{
    /**
     * @param  array<int, array{provider: TextProvider, model: string}>  $chain
     */
    public function __construct(private array $chain) {}

    public function handle(ModelCall $call, Closure $next): Step
    {
        $last = null;

        foreach ($this->chain as $target) {
            try {
                return $next($call->withProvider($target['provider'], $target['model']));
            } catch (Throwable $e) {
                if (! $e instanceof FailoverableException) {
                    throw $e;
                }

                $last = $e;
                $this->announce($target['provider'], $target['model'], $e);
            }
        }

        throw $last ?? new RuntimeException('FailoverProviders was configured with an empty provider chain.');
    }

    /**
     * Mirror the SDK: emit ProviderFailedOver for each provider we move past, so
     * listeners on the SDK event see our failovers too. Only real SDK providers
     * are Provider instances; a custom TextProvider is silently skipped.
     */
    private function announce(TextProvider $provider, string $model, FailoverableException $exception): void
    {
        if ($provider instanceof Provider && function_exists('event')) {
            event(new ProviderFailedOver($provider, $model, $exception));
        }
    }
}
