<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\Data\Step;
use Throwable;

/**
 * Retry the model call on a transient, non-failoverable error — a dropped
 * connection or timeout. Rate limits and overloads are {@see FailoverableException}s
 * and are deliberately NOT retried here: retrying the same rate-limited provider
 * is pointless, so those are left to {@see FailoverProviders} to route elsewhere.
 *
 * The two predicates are disjoint by design — see [ADR-0005](../../../docs/adr/0005-resilience-at-the-loop-seam.md).
 */
final class RetryModelCall implements ModelMiddleware
{
    /**
     * @param  int  $times  total attempts, including the first (so `2` = one retry)
     * @param  (Closure(Throwable): bool)|null  $retryable  default: only connection errors
     * @param  (Closure(int): void)|null  $sleep  backoff before the next attempt; injectable so tests need not wait
     */
    public function __construct(
        private int $times = 2,
        private ?Closure $retryable = null,
        private ?Closure $sleep = null,
    ) {}

    public function handle(ModelCall $call, Closure $next): Step
    {
        $attempt = 0;

        while (true) {
            try {
                return $next($call);
            } catch (Throwable $e) {
                $attempt++;

                if ($attempt >= $this->times || ! $this->shouldRetry($e)) {
                    throw $e;
                }

                ($this->sleep ?? $this->backoff(...))($attempt);
            }
        }
    }

    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof FailoverableException) {
            return false;
        }

        return $this->retryable !== null
            ? ($this->retryable)($e)
            : $e instanceof ConnectionException;
    }

    private function backoff(int $attempt): void
    {
        usleep((int) (2 ** ($attempt - 1) * 100_000)); // 100ms, 200ms, 400ms, …
    }
}
