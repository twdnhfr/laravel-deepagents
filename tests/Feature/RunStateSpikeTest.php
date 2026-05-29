<?php

/*
|--------------------------------------------------------------------------
| SPIKE — HITL resume via a serializable RunState (Path B, step 2)
|--------------------------------------------------------------------------
|
| Question this spike answers:
|   "Can we pause the loop BEFORE a tool runs, serialize the whole run to JSON,
|    rebuild it in a fresh context (a later HTTP request / queue job), and resume
|    to completion — executing the tool only after approval?"
|
| This is the second, harder half of the Path B question. The first spike
| (MaxStepsSpikeTest) found the single-turn seam (`maxSteps:0`). This one drives
| that seam turn-by-turn through the package's `Runtime\Loop`, whose ONLY
| persistent state is a plain `Runtime\RunState` value object.
|
| Method: same Http::fake approach against the REAL gateway. The loop calls
| `TextGateway::generateText()` directly (the public `textGateway()` seam) so it
| fully controls the message history across turns — which `Agent::prompt()`
| cannot do, since it always appends a fresh UserMessage.
|
| What "pass" proves:
|   1. The loop stops at the tool boundary with NOTHING executed.
|   2. The entire run survives `json_encode` → `json_decode` → `fromArray`.
|   3. A fresh resume() executes the approved tool and runs to a final answer.
|   Verified on Anthropic + Gemini (Gemini being the provider that needed
|   maxSteps:0 rather than 1).
*/

use Illuminate\Support\Facades\Http;
use Twdnhfr\LaravelDeepagents\Runtime\Loop;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;

/** @return array{0: string, 1: array, 2: array} [httpPattern, toolUseResponse, finalResponse] */
function hitlFixtures(string $provider): array
{
    return match ($provider) {
        'anthropic' => [
            'https://api.anthropic.com/*',
            [
                'id' => 'msg_1', 'model' => 'claude-test', 'type' => 'message', 'role' => 'assistant',
                'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'spy_tool', 'input' => ['query' => 'hello']]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ],
            [
                'id' => 'msg_2', 'model' => 'claude-test', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'all done']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ],
        ],
        'gemini' => [
            'https://generativelanguage.googleapis.com/*',
            [
                'candidates' => [[
                    'content' => ['parts' => [['functionCall' => ['name' => 'spy_tool', 'args' => ['query' => 'hello']]]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
            ],
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'all done']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
        ],
    };
}

dataset('hitl_providers', [
    'anthropic' => ['anthropic', 'claude-sonnet-4-5'],
    'gemini' => ['gemini', 'gemini-2.5-pro'],
]);

it('pauses before a tool, survives JSON serialization, and resumes to completion', function (string $providerName, string $model) {
    [$pattern, $toolUse, $final] = hitlFixtures($providerName);

    Http::preventStrayRequests();
    Http::fake([$pattern => Http::sequence()->push($toolUse)->push($final)]);

    $spy = new SpyTool;
    $loop = Loop::for($providerName, $model, [$spy], approvalGate: fn (): bool => true);

    // ── Turn 1: run until the model wants the tool ──────────────────────────
    $state = RunState::start('You are a test agent. Call spy_tool when asked.', 'please use the spy tool');
    $state = $loop->advance($state);

    expect($state->status)->toBe('suspended');
    expect($state->pendingToolCalls[0]['name'])->toBe('spy_tool');
    expect($state->pendingToolCalls[0]['arguments'])->toBe(['query' => 'hello']);
    expect($spy->handled)->toBeFalse('must pause BEFORE executing the tool');
    Http::assertSentCount(1);

    // ── Cross a serialization boundary (DB column / queue job / HTTP body) ───
    $json = json_encode($state);
    expect($json)->toBeString();

    $decoded = json_decode($json, true);
    expect($decoded['status'])->toBe('suspended');
    expect($decoded['pendingToolCalls'][0]['name'])->toBe('spy_tool'); // approvable payload

    // A fresh context picks up the approval and rebuilds the run from JSON only.
    $restored = RunState::fromArray($decoded);

    // ── Human approved → resume → tool executes, loop finishes ───────────────
    $finished = $loop->resume($restored);

    expect($spy->handled)->toBeTrue('tool executes ONLY after approval + resume');
    expect($spy->receivedQuery)->toBe('hello');
    expect($finished->status)->toBe('done');
    expect($finished->finalText)->toBe('all done');
    expect($finished->pendingToolCalls)->toBeEmpty();

    // Exactly two model turns total: one before the pause, one after resume.
    Http::assertSentCount(2);
})->with('hitl_providers');

it('runs autonomously to completion in a single advance() when approval is off', function (string $providerName, string $model) {
    [$pattern, $toolUse, $final] = hitlFixtures($providerName);

    Http::preventStrayRequests();
    Http::fake([$pattern => Http::sequence()->push($toolUse)->push($final)]);

    $spy = new SpyTool;
    $loop = Loop::for($providerName, $model, [$spy]); // requireApproval defaults to false

    $state = $loop->advance(
        RunState::start('You are a test agent. Call spy_tool when asked.', 'please use the spy tool')
    );

    // No pause: the loop executed the tool itself and ran to the final answer.
    expect($state->status)->toBe('done');
    expect($state->finalText)->toBe('all done');
    expect($state->pendingToolCalls)->toBeEmpty();
    expect($spy->handled)->toBeTrue();
    Http::assertSentCount(2);
})->with('hitl_providers');
