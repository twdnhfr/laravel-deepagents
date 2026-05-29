<?php

namespace Twdnhfr\LaravelDeepagents\Context;

use Twdnhfr\LaravelDeepagents\Contracts\Backend;
use Twdnhfr\LaravelDeepagents\Runtime\LoopHook;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/**
 * Context management: keep oversized tool results out of the prompt.
 *
 * Runs before each model turn. Any `tool_result` whose content exceeds
 * `maxChars` is written to the storage {@see Backend} (keyed by a stable path)
 * and replaced inline with a short head preview plus a pointer the model can
 * follow with the `read_artifact` tool. The bulk lives in the backend, so the
 * `RunState` blob stays small; use a persistent backend if artifacts must
 * survive across suspend/resume.
 *
 * This bounds the per-turn token cost of a single huge tool output without
 * losing the data — complementary to {@see SummarizeHistory}, which compacts the
 * whole history once the overall budget is hit.
 *
 * Naturally idempotent: once offloaded, the inline preview is short, so it is
 * not offloaded again.
 */
class OffloadLargeToolResults extends LoopHook
{
    /**
     * @param  array<int, string>  $exempt  tool names whose output is never offloaded
     */
    public function __construct(
        protected Backend $backend,
        protected int $maxChars = 2000,
        protected int $previewChars = 400,
        protected array $exempt = ['read_artifact'],
    ) {}

    public function beforeModel(RunState $state): void
    {
        foreach ($state->history as $i => $entry) {
            if (($entry['role'] ?? null) !== 'tool_result' || ! is_array($entry['toolResults'] ?? null)) {
                continue;
            }

            $results = $entry['toolResults'];
            $changed = false;

            foreach ($results as $j => $result) {
                if (! is_array($result)) {
                    continue;
                }

                // Never re-offload the output of an artifact read — that would
                // immediately clip the content the model just asked to see.
                if (in_array($result['name'] ?? '', $this->exempt, true)) {
                    continue;
                }

                $content = (string) ($result['result'] ?? '');

                if (mb_strlen($content) <= $this->maxChars) {
                    continue;
                }

                $path = 'tool/'.($result['id'] ?? "{$i}-{$j}");
                $this->backend->write($path, $content);

                $results[$j]['result'] = mb_substr($content, 0, $this->previewChars)
                    ."\n…[truncated — full output (".mb_strlen($content).' chars) saved as artifact "'
                    .$path.'". Call read_artifact with path "'.$path.'" to read it.]';

                $changed = true;
            }

            if ($changed) {
                $state->history[$i]['toolResults'] = $results;
            }
        }
    }
}
