<?php

namespace Twdnhfr\LaravelDeepagents\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool that records whether it was actually executed.
 *
 * The whole spike hinges on this flag: at maxSteps=1 the provider gateway must
 * hand us the model's tool-call intention WITHOUT ever calling handle().
 */
class SpyTool implements Tool
{
    public bool $handled = false;

    public ?string $receivedQuery = null;

    public function __construct(public string $toolName = 'spy_tool') {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): Stringable|string
    {
        return 'Records whether it was executed. Call it with any query.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->handled = true;
        $this->receivedQuery = $request['query'] ?? null;

        return 'EXECUTED';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Any query string.'),
        ];
    }
}
