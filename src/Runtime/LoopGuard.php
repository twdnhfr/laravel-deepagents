<?php

namespace Twdnhfr\LaravelDeepagents\Runtime;

/**
 * Stops a run that is making no progress: when the model proposes the same tool
 * call (name + arguments) `repeats` turns in a row, the run is {@see RunState::halt()}ed
 * with a reason instead of churning until `maxTurns` throws.
 *
 * Runs as an `afterModel` hook — the assistant turn (with its tool calls) is on
 * the history by then, but the repeated call has not executed yet, so the
 * {@see Loop} stops *before* running it again.
 */
final class LoopGuard extends LoopHook
{
    public function __construct(private int $repeats = 3) {}

    public function afterModel(RunState $state): void
    {
        $signatures = [];

        foreach ($state->history as $entry) {
            if (($entry['role'] ?? null) !== 'assistant') {
                continue;
            }

            $calls = $entry['toolCalls'] ?? [];

            if ($calls !== []) {
                $signatures[] = $this->signature($calls);
            }
        }

        $streak = $this->trailingStreak($signatures);

        if ($streak >= $this->repeats) {
            $state->halt("No progress: the same tool call repeated {$streak} times in a row.");
        }
    }

    /**
     * How many of the most recent entries share the latest signature.
     *
     * @param  array<int, string>  $signatures
     */
    private function trailingStreak(array $signatures): int
    {
        if ($signatures === []) {
            return 0;
        }

        $latest = end($signatures);
        $streak = 0;

        for ($i = count($signatures) - 1; $i >= 0 && $signatures[$i] === $latest; $i--) {
            $streak++;
        }

        return $streak;
    }

    /**
     * A stable fingerprint of a turn's tool calls: name + arguments, ignoring the
     * per-call id and argument key order.
     *
     * @param  array<int, array<string, mixed>>  $calls
     */
    private function signature(array $calls): string
    {
        $normalized = array_map(function (array $call): array {
            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            $this->sortKeysRecursive($arguments);

            return ['name' => $call['name'] ?? '', 'arguments' => $arguments];
        }, $calls);

        return json_encode($normalized);
    }

    /**
     * @param  array<mixed>  $array
     */
    private function sortKeysRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortKeysRecursive($value);
            }
        }

        ksort($array);
    }
}
