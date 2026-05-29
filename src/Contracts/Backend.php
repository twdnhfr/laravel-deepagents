<?php

namespace Twdnhfr\LaravelDeepagents\Contracts;

use Twdnhfr\LaravelDeepagents\Backends\StateBackend;

/**
 * Pluggable file storage for agent tools.
 *
 * A backend is the seam behind the filesystem tools (read/write/edit/ls/glob/
 * grep) and, later, shell execution. Swapping the implementation swaps where
 * the agent's "files" live without changing the tools:
 *
 *   - {@see StateBackend} — in-memory, kept
 *     inside the run state (the safe default; no side effects).
 *   - a disk-backed backend (Laravel `Storage`) — real files. *(planned)*
 *   - a sandbox backend — adds shell execution. *(planned)*
 *
 * Paths are opaque keys using POSIX-style forward slashes.
 */
interface Backend
{
    /**
     * Read a file's contents, or null if it does not exist.
     */
    public function read(string $path): ?string;

    /**
     * Create or overwrite a file.
     */
    public function write(string $path, string $contents): void;

    /**
     * Delete a file. No-op if it does not exist.
     */
    public function delete(string $path): void;

    public function exists(string $path): bool;

    /**
     * List known paths, sorted, optionally filtered by a path prefix.
     *
     * @return array<int, string>
     */
    public function list(string $prefix = ''): array;
}
