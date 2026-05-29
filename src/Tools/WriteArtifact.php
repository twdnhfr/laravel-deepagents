<?php

namespace Twdnhfr\LaravelDeepagents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Twdnhfr\LaravelDeepagents\Contracts\Backend;

/**
 * Save content to the storage backend — the agent's scratchpad for large or
 * intermediate output (a draft, gathered notes, a generated document). The
 * content is stored in the backend (not sent back to the model) and can be
 * fetched later with {@see ReadArtifact}.
 */
class WriteArtifact implements BackendAware, Tool
{
    protected ?Backend $backend = null;

    public function withBackend(Backend $backend): void
    {
        $this->backend = $backend;
    }

    public function name(): string
    {
        return 'write_artifact';
    }

    public function description(): Stringable|string
    {
        return 'Save content to an artifact at the given path for later reference, instead of keeping it in the '.
            'conversation. Read it back with read_artifact.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->backend === null) {
            throw new RuntimeException('The write_artifact tool was invoked without a backend.');
        }

        $path = trim((string) ($request['path'] ?? ''));

        if ($path === '') {
            return 'A non-empty artifact path is required.';
        }

        $content = (string) ($request['content'] ?? '');
        $this->backend->write($path, $content);

        return "Saved artifact \"{$path}\" (".mb_strlen($content).' chars).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Where to store the artifact (e.g. "draft.md").')->required(),
            'content' => $schema->string()->description('The content to store.')->required(),
        ];
    }
}
