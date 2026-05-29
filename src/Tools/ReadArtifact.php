<?php

namespace Twdnhfr\LaravelDeepagents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Twdnhfr\LaravelDeepagents\Context\OffloadLargeToolResults;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;

/**
 * Read an artifact from the storage backend — e.g. a large tool output offloaded
 * by {@see OffloadLargeToolResults}. Supports a
 * character window (`offset`/`limit`) so the model can page through big content
 * without pulling it all back into the prompt.
 */
class ReadArtifact implements BackendAware, Tool
{
    protected ?Backend $backend = null;

    protected int $defaultLimit = 4000;

    public function withBackend(Backend $backend): void
    {
        $this->backend = $backend;
    }

    public function name(): string
    {
        return 'read_artifact';
    }

    public function description(): Stringable|string
    {
        return 'Read a stored artifact by its path (e.g. a large tool output that was offloaded). '.
            'Use offset/limit to read a window of long content.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->backend === null) {
            throw new RuntimeException('The read_artifact tool was invoked without a backend.');
        }

        $path = (string) ($request['path'] ?? '');
        $content = $this->backend->read($path);

        if ($content === null) {
            return "Artifact \"{$path}\" not found.";
        }

        $offset = max(0, (int) ($request['offset'] ?? 0));
        $limit = (int) ($request['limit'] ?? $this->defaultLimit);
        $limit = $limit > 0 ? $limit : $this->defaultLimit;

        $slice = mb_substr($content, $offset, $limit);
        $total = mb_strlen($content);
        $end = $offset + mb_strlen($slice);

        if ($end < $total) {
            return $slice."\n…[showing chars {$offset}–{$end} of {$total}; call read_artifact with offset {$end} for more]";
        }

        return $slice;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The artifact path.')->required(),
            'offset' => $schema->integer()->description('Start character offset (default 0).'),
            'limit' => $schema->integer()->description('Maximum characters to return (default 4000).'),
        ];
    }
}
