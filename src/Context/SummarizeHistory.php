<?php

namespace Twdnhfr\LaravelDeepagents\Context;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Twdnhfr\LaravelDeepagents\Runtime\Hook;
use Twdnhfr\LaravelDeepagents\Runtime\LoopHook;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/**
 * Context management: compact the run history once it grows past a token budget.
 *
 * Runs as a {@see Hook} before each model
 * turn. When the estimated token count exceeds `triggerTokens`, the older part
 * of the history is rendered to a transcript, summarized by the model, and
 * replaced with a single summary message; the most recent `keepLast` entries
 * are kept verbatim. This is the payoff of owning the loop
 * ([ADR-0001](../../docs/adr/0001-own-the-agent-loop.md)).
 *
 * The kept window never starts on a `tool_result`, so an assistant tool call is
 * never separated from its result (which providers reject).
 *
 * Token counting is a deliberately cheap approximation (~4 chars/token); tune
 * `triggerTokens` to your model's real window.
 */
class SummarizeHistory extends LoopHook
{
    public function __construct(
        protected TextProvider $provider,
        protected string $model,
        protected int $triggerTokens = 12000,
        protected int $keepLast = 6,
    ) {}

    public function beforeModel(RunState $state): void
    {
        if ($this->estimateTokens($state->history) < $this->triggerTokens) {
            return;
        }

        $cut = $this->safeCut($state->history);

        if ($cut <= 0) {
            return; // nothing older to summarize
        }

        $older = array_slice($state->history, 0, $cut);
        $kept = array_slice($state->history, $cut);

        $summary = $this->summarize($this->transcript($older));

        $state->history = [
            ['role' => 'user', 'content' => "Summary of the earlier conversation:\n".$summary],
            ...$kept,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    protected function estimateTokens(array $history): int
    {
        $chars = 0;

        foreach ($history as $message) {
            $chars += strlen((string) ($message['content'] ?? ''));

            foreach (is_array($message['toolCalls'] ?? null) ? $message['toolCalls'] : [] as $call) {
                $chars += strlen((string) json_encode($call));
            }

            foreach (is_array($message['toolResults'] ?? null) ? $message['toolResults'] : [] as $result) {
                $chars += is_array($result) ? strlen((string) ($result['result'] ?? '')) : 0;
            }
        }

        return intdiv($chars, 4);
    }

    /**
     * The index where the kept window starts. Walks back off any leading
     * `tool_result` so tool-call/result pairs stay intact.
     *
     * @param  array<int, array<string, mixed>>  $history
     */
    protected function safeCut(array $history): int
    {
        $cut = max(0, count($history) - $this->keepLast);

        while ($cut > 0 && ($history[$cut]['role'] ?? null) === 'tool_result') {
            $cut--;
        }

        return $cut;
    }

    /**
     * Render older history entries to a plain-text transcript for summarization.
     *
     * @param  array<int, array<string, mixed>>  $older
     */
    protected function transcript(array $older): string
    {
        $lines = [];

        foreach ($older as $message) {
            switch ($message['role'] ?? '') {
                case 'user':
                    $lines[] = 'User: '.(string) ($message['content'] ?? '');
                    break;

                case 'assistant':
                    $calls = is_array($message['toolCalls'] ?? null) ? $message['toolCalls'] : [];
                    $names = array_filter(array_map(
                        fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : '',
                        $calls,
                    ));
                    $suffix = $names === [] ? '' : ' [called: '.implode(', ', $names).']';
                    $lines[] = 'Assistant: '.(string) ($message['content'] ?? '').$suffix;
                    break;

                case 'tool_result':
                    foreach (is_array($message['toolResults'] ?? null) ? $message['toolResults'] : [] as $result) {
                        if (is_array($result)) {
                            $lines[] = 'Tool ('.(string) ($result['name'] ?? '').'): '.(string) ($result['result'] ?? '');
                        }
                    }
                    break;
            }
        }

        return implode("\n", $lines);
    }

    protected function summarize(string $transcript): string
    {
        $response = $this->provider->textGateway()->generateText(
            $this->provider,
            $this->model,
            'You compress conversation history. Produce a concise summary that preserves decisions, facts, '.
            'identifiers and any open tasks, so the assistant can continue without the original messages.',
            [new UserMessage("Summarize the following conversation excerpt:\n\n".$transcript)],
            [],
            null,
            new TextGenerationOptions(maxSteps: 0),
        );

        return trim($response->text);
    }
}
