<?php

namespace Twdnhfr\LaravelDeepagents\Backends;

use Illuminate\Contracts\Support\Arrayable;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/**
 * The default backend: files live in memory.
 *
 * It has no side effects on the host system, and it is serializable
 * ({@see toArray()} / {@see fromArray()}) so a run's files can travel with the
 * {@see RunState} across a suspend/resume
 * boundary, just like the rest of the run.
 *
 * @implements Arrayable<string, array<string, string>>
 */
class StateBackend implements Arrayable, Backend
{
    /**
     * @param  array<string, string>  $files
     */
    public function __construct(protected array $files = []) {}

    public function read(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function list(string $prefix = ''): array
    {
        $paths = array_keys($this->files);
        sort($paths);

        if ($prefix === '') {
            return $paths;
        }

        return array_values(array_filter($paths, fn (string $p) => str_starts_with($p, $prefix)));
    }

    /**
     * @return array{files: array<string, string>}
     */
    public function toArray(): array
    {
        return ['files' => $this->files];
    }

    /**
     * @param  array{files?: array<string, string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['files'] ?? []);
    }
}
