<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\Context\OffloadLargeToolResults;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;
use Twdnhfr\LaravelDeepagents\Tools\ReadArtifact;
use Twdnhfr\LaravelDeepagents\Tools\WriteArtifact;

afterEach(fn () => Mockery::close());

it('offloads a large tool result to the backend and clips the inline content', function () {
    $big = str_repeat('x', 5000);
    $backend = new StateBackend;
    $state = new RunState('sys', [
        ['role' => 'tool_result', 'toolResults' => [['id' => 't1', 'name' => 'dump', 'arguments' => [], 'result' => $big]]],
    ]);

    (new OffloadLargeToolResults($backend, maxChars: 2000, previewChars: 100))->beforeModel($state);

    expect($backend->read('tool/t1'))->toBe($big);

    $clipped = $state->history[0]['toolResults'][0]['result'];
    expect(mb_strlen($clipped))->toBeLessThan(2000);
    expect($clipped)->toContain('read_artifact')->toContain('tool/t1');
});

it('leaves small tool results untouched', function () {
    $backend = new StateBackend;
    $state = new RunState('sys', [
        ['role' => 'tool_result', 'toolResults' => [['id' => 't1', 'name' => 'x', 'arguments' => [], 'result' => 'small']]],
    ]);

    (new OffloadLargeToolResults($backend))->beforeModel($state);

    expect($backend->list())->toBe([]);
    expect($state->history[0]['toolResults'][0]['result'])->toBe('small');
});

it('does not re-offload an already-clipped result (idempotent)', function () {
    $backend = new StateBackend;
    $state = new RunState('sys', [
        ['role' => 'tool_result', 'toolResults' => [['id' => 't1', 'name' => 'x', 'arguments' => [], 'result' => str_repeat('y', 5000)]]],
    ]);
    $hook = new OffloadLargeToolResults($backend, maxChars: 2000, previewChars: 100);

    $hook->beforeModel($state);
    $afterFirst = $state->history[0]['toolResults'][0]['result'];
    $hook->beforeModel($state);

    expect($state->history[0]['toolResults'][0]['result'])->toBe($afterFirst);
    expect($backend->list())->toBe(['tool/t1']);
});

it('never re-offloads the output of read_artifact', function () {
    $backend = new StateBackend;
    $state = new RunState('sys', [
        ['role' => 'tool_result', 'toolResults' => [['id' => 'r1', 'name' => 'read_artifact', 'arguments' => [], 'result' => str_repeat('z', 5000)]]],
    ]);

    (new OffloadLargeToolResults($backend, maxChars: 800))->beforeModel($state);

    expect($backend->list())->toBe([]); // not offloaded — the read stays usable
    expect(mb_strlen($state->history[0]['toolResults'][0]['result']))->toBe(5000);
});

it('reads an artifact from the backend, with windowing and not-found handling', function () {
    $backend = new StateBackend(['draft.md' => 'Hello world']);
    $tool = new ReadArtifact;
    $tool->withBackend($backend);

    expect((string) $tool->handle(new Request(['path' => 'draft.md'])))->toBe('Hello world');
    expect((string) $tool->handle(new Request(['path' => 'draft.md', 'offset' => 6, 'limit' => 5])))->toBe('world');
    expect((string) $tool->handle(new Request(['path' => 'nope'])))->toContain('not found');
});

it('windows long content with a continuation hint', function () {
    $tool = new ReadArtifact;
    $tool->withBackend(new StateBackend(['big' => str_repeat('a', 100)]));

    expect((string) $tool->handle(new Request(['path' => 'big', 'offset' => 0, 'limit' => 40])))
        ->toContain('showing chars 0–40 of 100');
});

it('writes an artifact to the backend', function () {
    $backend = new StateBackend;
    $tool = new WriteArtifact;
    $tool->withBackend($backend);

    $out = (string) $tool->handle(new Request(['path' => 'notes.txt', 'content' => 'remember this']));

    expect($backend->read('notes.txt'))->toBe('remember this');
    expect($out)->toContain('Saved artifact');
});

it('artifact tools throw without a backend', function () {
    (new ReadArtifact)->handle(new Request(['path' => 'x']));
})->throws(RuntimeException::class, 'without a backend');

it('clips a large tool result to the backend before the next model turn (end to end)', function () {
    $big = str_repeat('Z', 5000);
    $backend = new StateBackend;

    $dump = new class($big) implements Tool
    {
        public function __construct(private string $out) {}

        public function name(): string
        {
            return 'dump';
        }

        public function description(): string
        {
            return 'Dumps a lot of text.';
        }

        public function handle(Request $request): string
        {
            return $this->out;
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $turn = 0;
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->andReturnUsing(function (...$args) use (&$turn) {
        return ++$turn === 1
            ? Sdk::turn('', [Sdk::toolCall('dump')], FinishReason::ToolCalls)
            : Sdk::turn('done', [], FinishReason::Stop);
    });
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);

    $state = DeepAgent::make()->provider($provider)->model('m')
        ->backend($backend)
        ->tool($dump)
        ->offloadLargeToolResults(2000)
        ->run('dump it');

    expect($state->isDone())->toBeTrue();
    expect($backend->list())->not->toBeEmpty();
    expect($backend->read($backend->list()[0]))->toBe($big); // full output preserved in the backend

    $toolResult = collect($state->history)->where('role', 'tool_result')->flatMap(fn ($m) => $m['toolResults'])->first();
    expect(mb_strlen($toolResult['result']))->toBeLessThan(2000); // inline content clipped before turn 2
    expect($toolResult['result'])->toContain('read_artifact');
});
