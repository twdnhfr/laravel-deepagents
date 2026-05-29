<?php

namespace Twdnhfr\LaravelDeepagents\Tools;

use Twdnhfr\LaravelDeepagents\Contracts\Backend;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;

/**
 * Implemented by tools that read or write the run's storage backend (e.g. the
 * artifact tools). The {@see Loop} injects the
 * backend via {@see withBackend()} immediately before invoking the tool —
 * analogous to {@see RunAware} for run state.
 */
interface BackendAware
{
    public function withBackend(Backend $backend): void;
}
