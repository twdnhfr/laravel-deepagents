<?php

namespace Twdnhfr\LaravelDeepagents\Runtime\Resilience;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use ReflectionProperty;
use Throwable;

/**
 * Validate the model's tool arguments against the tool's own schema BEFORE the
 * tool runs. On a mismatch — unknown parameters, or missing required ones — it
 * returns a corrective message INSTEAD of calling the tool, so the model fixes
 * the call on its next turn rather than the tool erroring on bad input.
 *
 * Provider-agnostic: it uses only {@see Tool::schema()}.
 * The corrective message is neutral English; a host that wants another language
 * ships its own {@see ToolMiddleware}.
 */
final class ValidateToolArgs implements ToolMiddleware
{
    public function handle(ToolInvocation $call, Closure $next): string
    {
        $schema = $this->schemaOf($call);

        if ($schema === null || $schema === []) {
            return $next($call); // no schema to validate against — let it run
        }

        $expected = array_keys($schema);
        $unknown = array_values(array_diff(array_keys($call->arguments), $expected));
        $missing = array_values(array_diff($this->requiredKeys($schema), array_keys($call->arguments)));

        if ($unknown === [] && $missing === []) {
            return $next($call);
        }

        return $this->message($call->name, $expected, $unknown, $missing);
    }

    /**
     * The tool's schema as a `name => Type` map, or null if it can't be produced.
     *
     * @return array<string, mixed>|null
     */
    private function schemaOf(ToolInvocation $call): ?array
    {
        try {
            return $call->tool->schema(new JsonSchemaTypeFactory);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The names of the schema's required fields.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function requiredKeys(array $schema): array
    {
        $required = [];

        foreach ($schema as $key => $type) {
            if ($this->isRequired($type)) {
                $required[] = (string) $key;
            }
        }

        return $required;
    }

    /**
     * Whether a schema field is marked required. The flag is a protected property
     * on the framework's Type, so we read it reflectively and degrade to "not
     * required" if the shape ever changes — unknown-parameter detection still works.
     */
    private function isRequired(mixed $type): bool
    {
        if (! $type instanceof Type) {
            return false;
        }

        try {
            return (new ReflectionProperty($type, 'required'))->getValue($type) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $unknown
     * @param  array<int, string>  $missing
     */
    private function message(string $tool, array $expected, array $unknown, array $missing): string
    {
        $parts = ["Invalid arguments for tool [{$tool}]."];

        if ($unknown !== []) {
            $parts[] = 'Unknown parameter(s): '.implode(', ', $unknown).'.';
        }

        if ($missing !== []) {
            $parts[] = 'Missing required parameter(s): '.implode(', ', $missing).'.';
        }

        $parts[] = 'Expected parameter(s): '.($expected === [] ? '(none)' : implode(', ', $expected)).'.';

        return implode(' ', $parts);
    }
}
