<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;

/**
 * Middleware around a single tool invocation — the resilience seam established
 * by [ADR-0005](../../../docs/adr/0005-resilience-at-the-loop-seam.md). Wraps
 * `$tool->handle()` so it can be retried, validated, timed out, or
 * short-circuited with a substitute result.
 *
 * The {@see Loop} keeps its own try/catch
 * around the whole pipeline, so a thrown tool error still becomes a tool result
 * rather than crashing the run.
 */
interface ToolMiddleware
{
    /**
     * Run, retry, or short-circuit the tool. `$next` calls `$tool->handle()` and
     * returns its string result; return a string of your own instead to skip it.
     *
     * @param  Closure(ToolInvocation): string  $next
     */
    public function handle(ToolInvocation $call, Closure $next): string;
}
