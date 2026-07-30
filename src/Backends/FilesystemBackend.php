<?php

namespace Twdnhfr\LaravelDeepagents\Backends;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;

/**
 * A {@see Backend} backed by real files under a root directory.
 *
 * Used primarily to load memory (`AGENTS.md`) from disk. Paths are resolved
 * relative to the root and may not escape it (`..` is rejected). This is a
 * storage implementation, not an agent-facing file tool — exposing file
 * mutation to the model is a separate, deferred decision (see docs/adoption.md).
 *
 * Deliberate limitations to know about:
 * - The `..` guard is conservative: it also rejects legitimate names that
 *   merely contain two dots (e.g. `notes..md`).
 * - Symlinks inside the root are resolved: a symlink (directory, file, or even
 *   dangling) that points outside the root is rejected rather than followed.
 * - Directories are created with the default permissions (umask applies);
 *   tighten them at the filesystem level if the root holds sensitive data.
 */
class FilesystemBackend implements Backend
{
    public function __construct(protected string $root) {}

    public function read(string $path): ?string
    {
        $full = $this->resolve($path);

        if (! is_file($full)) {
            return null;
        }

        $contents = file_get_contents($full);

        return $contents === false ? null : $contents;
    }

    public function write(string $path, string $contents): void
    {
        $full = $this->resolve($path);
        $dir = dirname($full);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($full, $contents);
    }

    public function delete(string $path): void
    {
        $full = $this->resolve($path);

        if (is_file($full)) {
            unlink($full);
        }
    }

    public function exists(string $path): bool
    {
        return is_file($this->resolve($path));
    }

    public function list(string $prefix = ''): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        $base = rtrim($this->root, '/');
        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
                $paths[] = $relative;
            }
        }

        sort($paths);

        if ($prefix === '') {
            return $paths;
        }

        return array_values(array_filter($paths, fn (string $p) => str_starts_with($p, $prefix)));
    }

    protected function resolve(string $path): string
    {
        $path = ltrim($path, '/');

        if (str_contains($path, '..')) {
            throw new InvalidArgumentException("Path traversal is not allowed: [{$path}].");
        }

        $root = rtrim($this->root, '/');
        $full = $root.'/'.$path;

        $rootReal = realpath($root);

        if ($rootReal === false) {
            return $full; // root not created yet — nothing inside it to escape through
        }

        // Walk to the deepest already-existing segment (never above the root) and
        // resolve it: a symlinked directory or file anywhere in the chain — even a
        // dangling one — must still land inside the root.
        $probe = $full;

        while ($probe !== $root && ! file_exists($probe) && ! is_link($probe)) {
            $probe = dirname($probe);
        }

        $real = realpath($probe);

        if ($real === false || ($real !== $rootReal && ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException("Path escapes the backend root: [{$path}].");
        }

        return $full;
    }
}
