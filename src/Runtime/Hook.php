<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

/**
 * A hook into the agent loop.
 *
 * Hooks are the package's lightweight answer to deepagents' middleware — the
 * reason we own the loop ([ADR-0001](../../docs/adr/0001-own-the-agent-loop.md)).
 * They run around each model turn and may inspect or mutate the {@see RunState}
 * (e.g. compact the history before it is sent). They operate on the RunState
 * only, never on SDK objects, so they stay provider-agnostic.
 *
 * Extend {@see LoopHook} to override just the point you need.
 */
interface Hook
{
    /**
     * Runs before each model turn — after any prior tool results are on the
     * history, before it is sent to the model. The place for context management
     * (summarization, offloading) and memory/prompt injection.
     */
    public function beforeModel(RunState $state): void;

    /**
     * Runs after each model turn — once the assistant message is on the history.
     * The place for metrics, logging, or stop conditions.
     */
    public function afterModel(RunState $state): void;
}
