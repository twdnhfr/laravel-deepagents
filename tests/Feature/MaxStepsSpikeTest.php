<?php

/*
|--------------------------------------------------------------------------
| SPIKE — the single-turn loop seam for an own agent-loop runtime (Path B)
|--------------------------------------------------------------------------
|
| Question this spike answers:
|   "Can we make laravel/ai do exactly ONE model turn and hand us the model's
|    tool-call intention WITHOUT executing the tools — consistently across
|    providers — so a custom runtime can own the loop (HITL, mid-loop context
|    management, checkpointing)?"
|
| Why it matters: laravel/ai runs the model<->tool loop *inside* each provider
| gateway (recursively in `processResponse`). The only public lever over that
| loop is `maxSteps`, resolved per-agent via `TextGenerationOptions::forAgent()`
| (a `maxSteps()` method or `#[MaxSteps]` attribute).
|
| Method: fake the HTTP layer (the gateways use Laravel's `Http` client) so the
| REAL provider parsing/loop code runs against synthetic payloads. The SDK's own
| `FakeTextGateway` is deliberately NOT used — it executes tools directly and
| would not exercise the `maxSteps` guard.
|
| FINDINGS (verified empirically below):
|   - The guard formula differs per provider:
|       Anthropic: `$depth + 1 < maxSteps`            (depth starts at 0)
|       OpenAI:    `$steps->count() < maxSteps`        (step pushed BEFORE guard)
|       Gemini:    `$steps->count() < maxSteps`        (step pushed AFTER guard)
|   - Therefore `maxSteps:1` is NOT uniform: Anthropic/OpenAI skip execution, but
|     Gemini still runs ONE tool round (its count is 0 at the guard). See the
|     dedicated divergence test below.
|   - `maxSteps:0` IS uniform: `1<0`, `1<0`, `0<0` are all false → zero execution,
|     one model turn, tool calls returned. THIS is the seam Path B should use.
|
| This is a THROWAWAY spike kept as a regression guard + executable ADR. It is
| not production code and asserts SDK-internal behaviour Path B depends on.
*/

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\SpyTool;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\StepwiseAgent;

/**
 * Per-provider fixtures: [httpPattern, toolUseResponse, finalStopResponse].
 *
 * The tool name in each payload is `spy_tool` so the gateway's findTool() would
 * match our SpyTool — proving non-execution is about maxSteps, not a name miss.
 */
function spikeFixtures(string $provider): array
{
    return match ($provider) {
        'anthropic' => [
            'https://api.anthropic.com/*',
            [
                'id' => 'msg_1',
                'model' => 'claude-test',
                'type' => 'message',
                'role' => 'assistant',
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'spy_tool',
                    'input' => ['query' => 'hello'],
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ],
            [
                'id' => 'msg_2',
                'model' => 'claude-test',
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'done']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ],
        ],
        'openai' => [
            'https://api.openai.com/*',
            [
                'id' => 'resp_1',
                'model' => 'gpt-test',
                'status' => 'completed',
                'output' => [[
                    'type' => 'function_call',
                    'status' => 'completed',
                    'id' => 'fc_1',
                    'call_id' => 'call_1',
                    'name' => 'spy_tool',
                    'arguments' => json_encode(['query' => 'hello']),
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ],
            [
                'id' => 'resp_2',
                'model' => 'gpt-test',
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'done']],
                ]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ],
        ],
        'gemini' => [
            'https://generativelanguage.googleapis.com/*',
            [
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => ['name' => 'spy_tool', 'args' => ['query' => 'hello']],
                    ]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
            ],
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'done']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
        ],
    };
}

dataset('providers', [
    'anthropic' => ['anthropic', 'claude-sonnet-4-5'],
    'openai' => ['openai', 'gpt-4.1'],
    'gemini' => ['gemini', 'gemini-2.5-pro'],
]);

it('maxSteps=0 is the UNIFORM single-turn seam: tool calls returned, NOT executed', function (string $provider, string $model) {
    [$pattern, $toolUse, $final] = spikeFixtures($provider);

    Http::preventStrayRequests();
    Http::fake([$pattern => Http::sequence()->push($toolUse)->push($final)]);

    $spy = new SpyTool;
    $agent = new StepwiseAgent(tools: [$spy], max: 0);

    $response = $agent->prompt('use the spy tool', provider: $provider, model: $model);

    // The seam: the model wanted to call the tool, but the gateway did NOT run it.
    expect($spy->handled)->toBeFalse('tool must NOT be executed at maxSteps=0');
    expect($response->steps)->toHaveCount(1);

    $step = $response->steps->first();
    expect($step->finishReason)->toBe(FinishReason::ToolCalls);
    expect($step->toolCalls)->toHaveCount(1);
    expect($step->toolCalls[0]->name)->toBe('spy_tool');
    expect($step->toolCalls[0]->arguments)->toBe(['query' => 'hello']);
    expect($step->toolResults)->toBeEmpty('no tool results — nothing ran');

    // Exactly one model turn: the gateway did NOT recurse into a second call.
    Http::assertSentCount(1);
})->with('providers');

it('executes the tool by default when maxSteps is unset (the contrast)', function (string $provider, string $model) {
    [$pattern, $toolUse, $final] = spikeFixtures($provider);

    Http::preventStrayRequests();
    Http::fake([$pattern => Http::sequence()->push($toolUse)->push($final)]);

    $spy = new SpyTool;
    $agent = new StepwiseAgent(tools: [$spy], max: null); // default → gateway auto-loops

    $response = $agent->prompt('use the spy tool', provider: $provider, model: $model);

    // Default behaviour: the gateway runs the tool and loops to a final answer.
    expect($spy->handled)->toBeTrue('tool IS executed by default');
    expect($spy->receivedQuery)->toBe('hello');
    expect($response->text)->toBe('done');

    // Two model turns: initial call → tool → follow-up call.
    Http::assertSentCount(2);
})->with('providers');

it('documents the divergence: maxSteps=1 is NOT uniform — Gemini executes one tool round', function (string $provider, string $model) {
    [$pattern, $toolUse, $final] = spikeFixtures($provider);

    Http::preventStrayRequests();
    Http::fake([$pattern => Http::sequence()->push($toolUse)->push($final)]);

    $spy = new SpyTool;
    $agent = new StepwiseAgent(tools: [$spy], max: 1);

    $agent->prompt('use the spy tool', provider: $provider, model: $model);

    // Gemini pushes its step AFTER the guard, so count()=0 < 1 is true → it runs
    // one tool round and makes a second call. Anthropic/OpenAI do not. This is
    // exactly why Path B must use maxSteps=0, not 1.
    $geminiDiverges = $provider === 'gemini';

    expect($spy->handled)->toBe($geminiDiverges);
    Http::assertSentCount($geminiDiverges ? 2 : 1);
})->with('providers');
