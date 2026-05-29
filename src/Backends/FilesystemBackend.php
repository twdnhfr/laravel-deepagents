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

        return rtrim($this->root, '/').'/'.$path;
    }
}
