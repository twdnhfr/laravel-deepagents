<?php

namespace Twdnhfr\LaravelDeepagents\Backends;

use InvalidArgumentException;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;

/**
 * Builds a {@see Backend} from the `config/deepagents.php` configuration.
 *
 * Bound as a singleton by the service provider; `make()` returns a *fresh*
 * backend instance each call (so stateful backends like {@see StateBackend}
 * aren't shared across agents).
 */
class BackendManager
{
    /**
     * @param  array<string, mixed>  $config  the `deepagents` config array
     */
    public function __construct(protected array $config = []) {}

    public function make(?string $name = null): Backend
    {
        $name ??= (string) ($this->config['backend'] ?? 'state');

        $backends = is_array($this->config['backends'] ?? null) ? $this->config['backends'] : [];
        $options = is_array($backends[$name] ?? null) ? $backends[$name] : [];

        return match ($name) {
            'state' => new StateBackend,
            'filesystem' => new FilesystemBackend((string) ($options['root'] ?? sys_get_temp_dir().'/deepagents')),
            'database' => new DatabaseBackend(
                (string) ($options['table'] ?? 'deepagents_artifacts'),
                isset($options['connection']) ? (string) $options['connection'] : null,
            ),
            'cache' => new CacheBackend(
                isset($options['store']) ? (string) $options['store'] : null,
                (int) ($options['ttl'] ?? 86400),
                (string) ($options['prefix'] ?? 'deepagents:artifact:'),
            ),
            default => throw new InvalidArgumentException("Unknown deepagents backend [{$name}]."),
        };
    }
}
