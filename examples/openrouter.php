<?php

/*
|--------------------------------------------------------------------------
| Laravel Deep Agents — LIVE demo against OpenRouter
|--------------------------------------------------------------------------
|
| Run it:  cp .env.example .env  (set OPENROUTER_API_KEY), then:
|          php examples/openrouter.php
|
| Unlike demo.php (offline, scripted), this calls a REAL model through
| OpenRouter. It boots just enough of Laravel (a container + the Http facade)
| to use the SDK's OpenRouter provider directly, then drives DeepAgents through
| the package's own loop: autonomous tool use, human-in-the-loop, sub-agents and
| memory — all against a live model, so real model decisions drive the flow.
*/

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

/* --- load .env (OPENROUTER_API_KEY, OPENROUTER_MODEL) --- */
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env = fn (string $k, ?string $default = null): ?string => $_ENV[$k] ?? $_SERVER[$k] ?? (getenv($k) ?: $default);

$key = $env('OPENROUTER_API_KEY');
$model = $env('OPENROUTER_MODEL', 'openai/gpt-5.4-nano');

if (! $key) {
    fwrite(STDERR, "Set OPENROUTER_API_KEY in .env (package root).\n");
    exit(1);
}

/* --- minimal Laravel bootstrap so the SDK's Http facade works --- */
$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance(HttpFactory::class, new HttpFactory);

$provider = new OpenRouterProvider(
    ['name' => 'openrouter', 'driver' => 'openrouter', 'key' => $key],
    new Dispatcher($container),
);

function heading(string $title): void
{
    echo "\n\033[1;36m== {$title} ==\033[0m\n";
}

function line(string $text): void
{
    echo "  {$text}\n";
}

/* ------------------------------------------------------------------ tools -- */

class GetWeather implements Tool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Get the current weather for a city.';
    }

    public function handle(Request $request): string
    {
        $city = (string) ($request['city'] ?? 'unknown');
        line("🔧 get_weather(city: {$city}) → 12°C, light rain");

        return "12°C with light rain in {$city}";
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->description('The city name.')->required()];
    }
}

class DeleteStaleRecords implements Tool
{
    public function name(): string
    {
        return 'delete_stale_records';
    }

    public function description(): string
    {
        return 'Permanently delete records older than the given number of days.';
    }

    public function handle(Request $request): string
    {
        $days = (int) ($request['older_than_days'] ?? 0);
        line("🔧 delete_stale_records(older_than_days: {$days}) → deleted 12 records");

        return 'Deleted 12 records.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['older_than_days' => $schema->integer()->description('Age threshold in days.')->required()];
    }
}

echo "Model: {$model} (via OpenRouter)\n";

/* ---------------------------------------------------- 1. autonomous tool -- */

heading('1. Autonomous — the model calls a tool, then answers');

try {
    $state = DeepAgent::make()
        ->provider($provider)->model($model)
        ->instructions('You are a concise assistant. Use get_weather for weather questions, then answer.')
        ->tool(new GetWeather)
        ->maxTurns(6)
        ->run('What should I wear in Berlin today?');

    line('answer: '.$state->finalText);
} catch (Throwable $e) {
    line('failed: '.$e->getMessage());
}

/* ------------------------------------------------ 2. human-in-the-loop -- */

heading('2. Human-in-the-loop — per-tool approval');

try {
    // Only the destructive tool is gated; get_weather runs unattended.
    $ops = DeepAgent::make()
        ->provider($provider)->model($model)
        ->instructions('First call get_weather for Berlin. Then call delete_stale_records for records older than 365 days. Use the tools; do not refuse.')
        ->tool(new GetWeather)
        ->tool(new DeleteStaleRecords)
        ->requireApproval(['delete_stale_records']) // allow-list: gate only this tool
        ->maxTurns(8);

    $state = $ops->run('Check the Berlin weather, then clean up records older than a year.');

    if ($state->isSuspended()) {
        $alreadyRan = collect($state->history)
            ->where('role', 'tool_result')
            ->flatMap(fn ($m) => $m['toolResults'])
            ->pluck('name')
            ->all();

        if ($alreadyRan !== []) {
            line('ran unattended (not gated): '.implode(', ', $alreadyRan));
        }

        line('paused for approval — a turn with any gated tool suspends as a whole:');
        foreach ($state->pendingToolCalls as $call) {
            line('  pending: '.$call['name'].'('.json_encode($call['arguments']).')');
        }

        $stored = $state->toJson();
        line('persisted suspended run: '.strlen($stored).' bytes — a human approves —');
        $state = $ops->resume(RunState::fromJson($stored));
        line('answer: '.$state->finalText);
    } else {
        line('(model finished without hitting a gated tool: '.$state->finalText.')');
    }
} catch (Throwable $e) {
    line('failed: '.$e->getMessage());
}

/* --------------------------------------------------------- 3. sub-agents -- */

heading('3. Sub-agents — delegate to an isolated researcher');

try {
    $researcher = DeepAgent::make()
        ->provider($provider)->model($model)
        ->instructions('You are a focused researcher. Answer in one factual sentence.')
        ->maxTurns(4);

    $lead = DeepAgent::make()
        ->provider($provider)->model($model)
        ->instructions('For any factual question you MUST delegate to the "researcher" sub-agent via the task tool, then relay its answer.')
        ->subAgent('researcher', 'Researches a topic and returns one concise sentence.', $researcher)
        ->maxTurns(6);

    $state = $lead->run('What is a vector database?');
    line('lead answer: '.$state->finalText);
} catch (Throwable $e) {
    line('failed: '.$e->getMessage());
}

/* ----------------------------------------------------------- 4. memory -- */

heading('4. Memory — AGENTS.md steers the model');

try {
    $state = DeepAgent::make()
        ->provider($provider)->model($model)
        ->instructions('You are a helpful assistant.')
        ->backend(new StateBackend([
            'AGENTS.md' => "Always reply in German, regardless of the question's language. Keep it to one sentence.",
        ]))
        ->memory('AGENTS.md')
        ->maxTurns(4)
        ->run('What is the capital of France?');

    line('answer (should be German, per memory): '.$state->finalText);
} catch (Throwable $e) {
    line('failed: '.$e->getMessage());
}

echo "\n\033[1;32mDone.\033[0m\n\n";
