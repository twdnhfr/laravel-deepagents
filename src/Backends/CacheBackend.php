<?php

namespace Twdnhfr\LaravelDeepagents\Backends;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;

/**
 * A {@see Backend} backed by a Laravel cache store, with a TTL.
 *
 * Good for transient artifacts that should expire (offloaded tool output during
 * a session). Note: cache stores cannot enumerate their keys, so {@see list()}
 * is unsupported and returns an empty array — fine for artifacts, which are
 * always addressed by an exact path.
 */
class CacheBackend implements Backend
{
    public function __construct(
        protected ?string $store = null,
        protected int $ttl = 86400,
        protected string $prefix = 'deepagents:artifact:',
    ) {}

    public function read(string $path): ?string
    {
        $value = $this->cache()->get($this->prefix.$path);

        return is_string($value) ? $value : null;
    }

    public function write(string $path, string $contents): void
    {
        $this->cache()->put($this->prefix.$path, $contents, $this->ttl);
    }

    public function delete(string $path): void
    {
        $this->cache()->forget($this->prefix.$path);
    }

    public function exists(string $path): bool
    {
        return $this->cache()->has($this->prefix.$path);
    }

    /**
     * Unsupported: cache stores cannot list their keys. Always returns [].
     */
    public function list(string $prefix = ''): array
    {
        return [];
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
