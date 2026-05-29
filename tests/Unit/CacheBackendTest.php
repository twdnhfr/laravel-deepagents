<?php

use Twdnhfr\LaravelDeepagents\Backends\CacheBackend;

beforeEach(fn () => config()->set('cache.default', 'array'));

it('reads, writes, checks existence and deletes via the cache', function () {
    $backend = new CacheBackend;

    expect($backend->exists('a'))->toBeFalse();
    expect($backend->read('a'))->toBeNull();

    $backend->write('a', 'hello');
    expect($backend->exists('a'))->toBeTrue();
    expect($backend->read('a'))->toBe('hello');

    $backend->delete('a');
    expect($backend->exists('a'))->toBeFalse();
});

it('isolates entries by prefix', function () {
    (new CacheBackend(prefix: 'one:'))->write('k', 'A');
    $two = new CacheBackend(prefix: 'two:');

    expect($two->read('k'))->toBeNull(); // different prefix, not visible
});

it('does not support listing (cache stores cannot enumerate keys)', function () {
    $backend = new CacheBackend;
    $backend->write('x', '1');

    expect($backend->list())->toBe([]);
});
