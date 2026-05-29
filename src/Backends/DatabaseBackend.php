<?php

namespace Twdnhfr\LaravelDeepagents\Backends;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;

/**
 * A {@see Backend} backed by a database table (`path` unique, `contents`).
 *
 * Persistent: artifacts survive across requests, so a run can offload a large
 * tool output and read it back after a suspend/resume. Publish and run the
 * package migration (`create_deepagents_artifacts_table`) to create the table.
 */
class DatabaseBackend implements Backend
{
    public function __construct(
        protected string $table = 'deepagents_artifacts',
        protected ?string $connection = null,
    ) {}

    public function read(string $path): ?string
    {
        $value = $this->query()->where('path', $path)->value('contents');

        return $value === null ? null : (string) $value;
    }

    public function write(string $path, string $contents): void
    {
        $this->query()->updateOrInsert(['path' => $path], ['contents' => $contents]);
    }

    public function delete(string $path): void
    {
        $this->query()->where('path', $path)->delete();
    }

    public function exists(string $path): bool
    {
        return $this->query()->where('path', $path)->exists();
    }

    public function list(string $prefix = ''): array
    {
        return $this->query()
            ->when($prefix !== '', fn (Builder $q) => $q->where('path', 'like', $prefix.'%'))
            ->orderBy('path')
            ->pluck('path')
            ->map(fn ($path) => (string) $path)
            ->all();
    }

    protected function query(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }
}
