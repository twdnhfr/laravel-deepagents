<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Throwable;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;

/**
 * Retry a tool invocation on a transient error. What counts as "transient" is
 * host-defined (the default is a dropped connection); a tool that fails for a
 * deterministic reason should not be retried, so the predicate is injectable.
 *
 * After the last attempt the error propagates — the {@see Loop}'s
 * own try/catch then turns it into a tool result for the model.
 */
final class RetryTool implements ToolMiddleware
{
    /**
     * @param  int  $times  total attempts, including the first
     * @param  (Closure(Throwable): bool)|null  $retryable  default: only connection errors
     * @param  (Closure(int): void)|null  $sleep  backoff before the next attempt; injectable so tests need not wait
     */
    public function __construct(
        private int $times = 3,
        private ?Closure $retryable = null,
        private ?Closure $sleep = null,
    ) {}

    public function handle(ToolInvocation $call, Closure $next): string
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
        return $this->retryable !== null
            ? ($this->retryable)($e)
            : $e instanceof ConnectionException;
    }

    private function backoff(int $attempt): void
    {
        usleep((int) (2 ** ($attempt - 1) * 100_000)); // 100ms, 200ms, 400ms, …
    }
}
