<?php

namespace Twdnhfr\LaravelDeepagents\Tools;

use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/**
 * Implemented by built-in tools that read or mutate the current run.
 *
 * The {@see Loop} calls {@see withinRun()}
 * with the live {@see RunState} immediately before invoking the tool, so the
 * tool can act on (and mutate) run-scoped state — e.g. the todo list. Because
 * the RunState is the same object the loop holds and serializes, any mutation
 * persists across a suspend/resume boundary for free.
 */
interface RunAware
{
    public function withinRun(RunState $state): void;
}
