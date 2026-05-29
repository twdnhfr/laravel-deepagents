<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

/**
 * No-op base for {@see Hook} implementations — override only the point you need.
 */
abstract class LoopHook implements Hook
{
    public function beforeModel(RunState $state): void {}

    public function afterModel(RunState $state): void {}
}
