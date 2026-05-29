<?php

use Twdnhfr\LaravelDeepagents\Backends\FilesystemBackend;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/lda_fs_'.uniqid();
    mkdir($this->root, 0777, true);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->root);
});

it('writes, reads, checks existence and deletes (including nested paths)', function () {
    $backend = new FilesystemBackend($this->root);

    expect($backend->exists('docs/AGENTS.md'))->toBeFalse();
    expect($backend->read('docs/AGENTS.md'))->toBeNull();

    $backend->write('docs/AGENTS.md', 'project context');
    expect($backend->exists('docs/AGENTS.md'))->toBeTrue();
    expect($backend->read('docs/AGENTS.md'))->toBe('project context');

    $backend->delete('docs/AGENTS.md');
    expect($backend->exists('docs/AGENTS.md'))->toBeFalse();
});

it('lists files relative to the root, sorted and prefix-filtered', function () {
    $backend = new FilesystemBackend($this->root);
    $backend->write('AGENTS.md', '1');
    $backend->write('docs/a.md', '2');
    $backend->write('docs/b.md', '3');

    expect($backend->list())->toBe(['AGENTS.md', 'docs/a.md', 'docs/b.md']);
    expect($backend->list('docs/'))->toBe(['docs/a.md', 'docs/b.md']);
});

it('rejects path traversal', function () {
    (new FilesystemBackend($this->root))->read('../secrets.txt');
})->throws(InvalidArgumentException::class, 'Path traversal');
