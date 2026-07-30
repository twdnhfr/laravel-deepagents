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

it('rejects a directory symlink pointing outside the root', function () {
    $outside = sys_get_temp_dir().'/lda_out_'.uniqid();
    mkdir($outside, 0777, true);
    file_put_contents($outside.'/secret.txt', 'secret');
    symlink($outside, $this->root.'/link');

    $backend = new FilesystemBackend($this->root);

    expect(fn () => $backend->read('link/secret.txt'))
        ->toThrow(InvalidArgumentException::class, 'escapes the backend root');
    expect(fn () => $backend->write('link/x.txt', 'x'))
        ->toThrow(InvalidArgumentException::class, 'escapes the backend root');

    expect(file_get_contents($outside.'/secret.txt'))->toBe('secret');
    expect(file_exists($outside.'/x.txt'))->toBeFalse();

    unlink($this->root.'/link');
    unlink($outside.'/secret.txt');
    rmdir($outside);
});

it('rejects a file symlink pointing outside the root', function () {
    $outside = sys_get_temp_dir().'/lda_out_'.uniqid().'.txt';
    file_put_contents($outside, 'secret');
    symlink($outside, $this->root.'/link.txt');

    expect(fn () => (new FilesystemBackend($this->root))->read('link.txt'))
        ->toThrow(InvalidArgumentException::class, 'escapes the backend root');

    unlink($this->root.'/link.txt');
    unlink($outside);
});

it('rejects a dangling symlink without creating the target', function () {
    $target = sys_get_temp_dir().'/lda_missing_'.uniqid().'.txt';
    symlink($target, $this->root.'/dangling.txt');

    expect(fn () => (new FilesystemBackend($this->root))->write('dangling.txt', 'x'))
        ->toThrow(InvalidArgumentException::class, 'escapes the backend root');

    expect(file_exists($target))->toBeFalse();

    unlink($this->root.'/dangling.txt');
});

it('still resolves nested paths when the root itself is a symlink', function () {
    $realRoot = sys_get_temp_dir().'/lda_realroot_'.uniqid();
    mkdir($realRoot, 0777, true);
    $linkRoot = sys_get_temp_dir().'/lda_linkroot_'.uniqid();
    symlink($realRoot, $linkRoot);

    $backend = new FilesystemBackend($linkRoot);
    $backend->write('a/b/c.txt', 'deep');

    expect($backend->read('a/b/c.txt'))->toBe('deep');

    unlink($realRoot.'/a/b/c.txt');
    rmdir($realRoot.'/a/b');
    rmdir($realRoot.'/a');
    unlink($linkRoot);
    rmdir($realRoot);
});
