<?php

use Twdnhfr\LaravelDeepagents\Backends\StateBackend;

it('reads, writes, overwrites and deletes files', function () {
    $backend = new StateBackend;

    expect($backend->exists('a.txt'))->toBeFalse();
    expect($backend->read('a.txt'))->toBeNull();

    $backend->write('a.txt', 'hello');
    expect($backend->exists('a.txt'))->toBeTrue();
    expect($backend->read('a.txt'))->toBe('hello');

    $backend->write('a.txt', 'world');
    expect($backend->read('a.txt'))->toBe('world');

    $backend->delete('a.txt');
    expect($backend->exists('a.txt'))->toBeFalse();
});

it('deleting a missing file is a no-op', function () {
    $backend = new StateBackend;

    $backend->delete('nope.txt');

    expect($backend->list())->toBe([]);
});

it('lists paths sorted and filtered by prefix', function () {
    $backend = new StateBackend([
        'src/b.php' => '',
        'src/a.php' => '',
        'docs/x.md' => '',
    ]);

    expect($backend->list())->toBe(['docs/x.md', 'src/a.php', 'src/b.php']);
    expect($backend->list('src/'))->toBe(['src/a.php', 'src/b.php']);
    expect($backend->list('none/'))->toBe([]);
});

it('round-trips through toArray/fromArray', function () {
    $backend = new StateBackend(['a.txt' => '1', 'b.txt' => '2']);

    $restored = StateBackend::fromArray($backend->toArray());

    expect($restored->toArray())->toBe(['files' => ['a.txt' => '1', 'b.txt' => '2']]);
    expect($restored->read('b.txt'))->toBe('2');
});

it('fromArray tolerates missing files key', function () {
    expect(StateBackend::fromArray([])->list())->toBe([]);
});
