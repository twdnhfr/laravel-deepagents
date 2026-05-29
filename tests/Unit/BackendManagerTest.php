<?php

use Twdnhfr\LaravelDeepagents\Backends\BackendManager;
use Twdnhfr\LaravelDeepagents\Backends\CacheBackend;
use Twdnhfr\LaravelDeepagents\Backends\DatabaseBackend;
use Twdnhfr\LaravelDeepagents\Backends\FilesystemBackend;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;

it('builds each configured backend', function () {
    $manager = new BackendManager([
        'backend' => 'state',
        'backends' => [
            'filesystem' => ['root' => sys_get_temp_dir().'/x'],
            'database' => ['table' => 'artifacts'],
            'cache' => ['store' => 'array', 'ttl' => 10, 'prefix' => 'p:'],
        ],
    ]);

    expect($manager->make())->toBeInstanceOf(StateBackend::class); // the configured default
    expect($manager->make('filesystem'))->toBeInstanceOf(FilesystemBackend::class);
    expect($manager->make('database'))->toBeInstanceOf(DatabaseBackend::class);
    expect($manager->make('cache'))->toBeInstanceOf(CacheBackend::class);
});

it('returns a fresh instance each call', function () {
    $manager = new BackendManager(['backend' => 'state']);

    expect($manager->make())->not->toBe($manager->make());
});

it('throws on an unknown backend', function () {
    (new BackendManager)->make('nope');
})->throws(InvalidArgumentException::class, 'Unknown deepagents backend');

it('is bound in the container', function () {
    expect(app(BackendManager::class))->toBeInstanceOf(BackendManager::class);
});
