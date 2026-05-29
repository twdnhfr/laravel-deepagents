<?php

use Illuminate\Support\Facades\Schema;
use Twdnhfr\LaravelDeepagents\Backends\DatabaseBackend;

beforeEach(function () {
    Schema::dropIfExists('deepagents_artifacts');
    (include __DIR__.'/../../database/migrations/create_deepagents_artifacts_table.php.stub')->up();
});

it('writes, reads, overwrites, checks existence and deletes', function () {
    $backend = new DatabaseBackend;

    expect($backend->exists('a'))->toBeFalse();
    expect($backend->read('a'))->toBeNull();

    $backend->write('a', 'one');
    expect($backend->read('a'))->toBe('one');

    $backend->write('a', 'two'); // updateOrInsert on the unique path
    expect($backend->read('a'))->toBe('two');
    expect($backend->exists('a'))->toBeTrue();

    $backend->delete('a');
    expect($backend->exists('a'))->toBeFalse();
});

it('lists paths sorted and prefix-filtered', function () {
    $backend = new DatabaseBackend;
    $backend->write('tool/b', '');
    $backend->write('tool/a', '');
    $backend->write('notes.md', '');

    expect($backend->list())->toBe(['notes.md', 'tool/a', 'tool/b']);
    expect($backend->list('tool/'))->toBe(['tool/a', 'tool/b']);
});
