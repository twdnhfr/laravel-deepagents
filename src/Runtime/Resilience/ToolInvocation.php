<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A single tool invocation the loop is about to execute, passed through the
 * {@see ToolMiddleware} pipeline. Middleware may validate the arguments, retry
 * the call, time it out, or rewrite the result.
 */
final class ToolInvocation
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly Tool $tool,
        public readonly string $name,
        public readonly array $arguments,
        public readonly Request $request,
    ) {}
}
