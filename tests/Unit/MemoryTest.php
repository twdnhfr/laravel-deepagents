<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Tests\Fixtures\Sdk;

afterEach(fn () => Mockery::close());

function agentWithMemory(StateBackend $backend, string $instructions = 'You are helpful.'): DeepAgent
{
    return DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn('ok', [], FinishReason::Stop)]))
        ->model('m')
        ->instructions($instructions)
        ->backend($backend);
}

it('injects a memory file into the run instructions, keeping the user instructions', function () {
    $state = agentWithMemory(new StateBackend(['AGENTS.md' => 'Always answer in British English.']))
        ->memory('AGENTS.md')
        ->run('hi');

    expect($state->instructions)
        ->toContain('You are helpful.')
        ->toContain('Always answer in British English.')
        ->toContain('AGENTS.md');
});

it('concatenates multiple memory files and skips missing or empty ones', function () {
    $state = agentWithMemory(new StateBackend(['a.md' => 'Fact A.', 'b.md' => 'Fact B.', 'empty.md' => '   ']))
        ->memory('a.md', 'missing.md', 'empty.md', 'b.md')
        ->run('hi');

    expect($state->instructions)->toContain('Fact A.')->toContain('Fact B.');
    expect($state->instructions)->not->toContain('missing.md');
});

it('keeps the user instructions when no memory is configured', function () {
    $state = agentWithMemory(new StateBackend, 'Just this.')->run('hi');

    expect($state->instructions)->toContain('Just this.');
});

it('uses memory even with empty base instructions', function () {
    $state = DeepAgent::make()
        ->provider(Sdk::provider([Sdk::turn('ok', [], FinishReason::Stop)]))
        ->model('m')
        ->backend(new StateBackend(['AGENTS.md' => 'Be terse.']))
        ->memory('AGENTS.md')
        ->run('hi');

    expect($state->instructions)->toContain('Be terse.');
});
