<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;

afterEach(fn () => Mockery::close());

/** Configure an agent, run it, and return the composed system prompt. */
function composedInstructions(callable $configure): string
{
    $agent = DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn('ok', [], FinishReason::Stop)]))
        ->model('m');

    $configure($agent);

    return $agent->run('hi')->instructions;
}

it('prepends the BASE prompt before the user instructions by default', function () {
    $instructions = composedInstructions(fn (DeepAgent $a) => $a->instructions('Be a pirate.'));

    expect($instructions)->toContain('capable, autonomous agent')->toContain('Be a pirate.');
    expect(strpos($instructions, 'capable, autonomous agent'))
        ->toBeLessThan(strpos($instructions, 'Be a pirate.'));
});

it('includes the BASE prompt even with no user instructions', function () {
    expect(composedInstructions(fn (DeepAgent $a) => $a))->toContain('capable, autonomous agent');
});

it('omits the BASE prompt with basePrompt(null)', function () {
    expect(composedInstructions(fn (DeepAgent $a) => $a->basePrompt(null)->instructions('Only this.')))
        ->toBe('Only this.');
});

it('replaces the BASE prompt with a custom one', function () {
    $instructions = composedInstructions(fn (DeepAgent $a) => $a->basePrompt('CUSTOM BASE')->instructions('Role.'));

    expect($instructions)
        ->toContain('CUSTOM BASE')
        ->toContain('Role.')
        ->not->toContain('capable, autonomous agent');
});

it('orders the prompt as BASE -> instructions -> memory', function () {
    $instructions = composedInstructions(fn (DeepAgent $a) => $a
        ->instructions('ROLE')
        ->backend(new StateBackend(['AGENTS.md' => 'MEM']))
        ->memory('AGENTS.md'));

    expect(strpos($instructions, 'capable'))->toBeLessThan(strpos($instructions, 'ROLE'));
    expect(strpos($instructions, 'ROLE'))->toBeLessThan(strpos($instructions, 'MEM'));
});
