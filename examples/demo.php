<?php

/*
|--------------------------------------------------------------------------
| Laravel Deep Agents — offline feature demo
|--------------------------------------------------------------------------
|
| Run it:  php examples/demo.php
|
| Everything here is DETERMINISTIC and OFFLINE: a scripted provider returns
| canned model turns, so there are no API keys and no network calls. It exists
| to show the runtime's behaviour — autonomous tool use, planning, human-in-the-
| loop, context management, and sub-agents — not real model output.
|
| To run for real, swap `->provider(new ScriptedProvider([...]))` for
| `->provider('anthropic')` (configured in config/ai.php) inside a Laravel app.
*/

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Support/ScriptedProvider.php';

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Twdnhfr\LaravelDeepagents\Backends\StateBackend;
use Twdnhfr\LaravelDeepagents\Context\SummarizeHistory;
use Twdnhfr\LaravelDeepagents\DeepAgent;
use Twdnhfr\LaravelDeepagents\Examples\ScriptedProvider;
use Twdnhfr\LaravelDeepagents\Runtime\RunState;

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
        line("🔧 get_weather(city: {$city}) → 21°C, sunny");

        return "21°C and sunny in {$city}";
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

/* --------------------------------------------------- 1. autonomous tool use -- */

heading('1. Autonomous run — the agent calls a tool, then answers');

$weather = DeepAgent::make()
    ->provider(new ScriptedProvider([
        ScriptedProvider::turn('', [ScriptedProvider::toolCall('get_weather', ['city' => 'Berlin'])]),
        ScriptedProvider::turn('Wear a light jacket — it’s 21°C and sunny in Berlin.'),
    ]))
    ->instructions('You are a helpful weather assistant.')
    ->tool(new GetWeather);

$state = $weather->run('What should I wear in Berlin today?');
line('status: '.$state->status);
line('answer: '.$state->finalText);

/* --------------------------------------------------------- 2. planning -- */

heading('2. Planning — the built-in write_todos tool');

$planner = DeepAgent::make()
    ->provider(new ScriptedProvider([
        ScriptedProvider::turn('', [ScriptedProvider::toolCall('write_todos', ['todos' => [
            ['content' => 'Gather sources', 'status' => 'completed'],
            ['content' => 'Draft the outline', 'status' => 'in_progress'],
            ['content' => 'Write the article', 'status' => 'pending'],
        ]])]),
        ScriptedProvider::turn('Plan created — starting on the outline.'),
    ]))
    ->withTodos();

$state = $planner->run('Plan a short article on vector databases.');
line('todos on the run state:');
$marks = ['pending' => '[ ]', 'in_progress' => '[~]', 'completed' => '[x]'];
foreach ($state->todos as $todo) {
    line('  '.$marks[$todo['status']].' '.$todo['content']);
}

/* ------------------------------------------------ 3. human-in-the-loop -- */

heading('3. Human-in-the-loop — per-tool approval');

// Only the destructive tool is gated; the safe one runs unattended.
$ops = DeepAgent::make()
    ->provider(new ScriptedProvider([
        ScriptedProvider::turn('', [ScriptedProvider::toolCall('get_weather', ['city' => 'Berlin'])]),
        ScriptedProvider::turn('', [ScriptedProvider::toolCall('delete_stale_records', ['older_than_days' => 365])]),
        ScriptedProvider::turn('Checked the weather and removed the stale records.'),
    ]))
    ->tool(new GetWeather)
    ->tool(new DeleteStaleRecords)
    ->requireApproval(['delete_stale_records']); // allow-list: gate only this tool

$state = $ops->run('Check the Berlin weather, then clean up records older than a year.');
line('(get_weather ran unattended; delete_stale_records is gated)');
line('status: '.$state->status.'  — paused before the gated tool');
foreach ($state->pendingToolCalls as $call) {
    line('pending approval: '.$call['name'].'('.json_encode($call['arguments']).')');
}

$stored = $state->toJson();
line('persisted the suspended run: '.strlen($stored).' bytes of JSON (→ DB / queue / etc.)');
line('— a human approves —');

$state = $ops->resume(RunState::fromJson($stored));
line('status: '.$state->status);
line('answer: '.$state->finalText);

/* ------------------------------------------------ 4. context management -- */

heading('4. Context management — summarize a long history');

$longRun = new RunState('You are a travel assistant.', [
    ['role' => 'user', 'content' => 'I am planning a week in Berlin in October and need help.'],
    ['role' => 'assistant', 'content' => 'Happy to help — let us start with the weather and what to pack.', 'toolCalls' => []],
    ['role' => 'user', 'content' => 'What will the weather be like?'],
    ['role' => 'assistant', 'content' => 'October in Berlin is cool, around 10-14°C, with frequent light rain.', 'toolCalls' => []],
    ['role' => 'user', 'content' => 'Good. What should I pack for that?'],
    ['role' => 'assistant', 'content' => 'A waterproof jacket, layers, an umbrella and comfortable walking shoes.', 'toolCalls' => []],
    ['role' => 'user', 'content' => 'And which neighbourhoods should I stay in?'],
    ['role' => 'assistant', 'content' => 'Mitte for sights, Kreuzberg or Neukölln for food and nightlife.', 'toolCalls' => []],
]);

$summarizer = new ScriptedProvider([
    ScriptedProvider::turn('The user is planning a week in Berlin in October; the assistant covered cool/rainy weather, packing (waterproof jacket, layers, umbrella), and neighbourhoods (Mitte, Kreuzberg, Neukölln).'),
]);

line('history before: '.count($longRun->history).' entries');
(new SummarizeHistory($summarizer, 'demo-model', triggerTokens: 50, keepLast: 2))->beforeModel($longRun);
line('history after:  '.count($longRun->history).' entries (1 summary + last 2 kept verbatim)');
line('summary entry:  '.str_replace("\n", ' ', $longRun->history[0]['content']));
line('(in a real run, ->summarize() fires this automatically before each turn once the budget is hit)');

/* --------------------------------------------------------- 5. sub-agents -- */

heading('5. Sub-agents — delegate an isolated task');

$researcher = DeepAgent::make()
    ->provider(new ScriptedProvider([
        ScriptedProvider::turn('A vector database indexes embeddings for fast similarity search (e.g. pgvector, Pinecone).'),
    ]))
    ->instructions('You are a focused research assistant.');

$lead = DeepAgent::make()
    ->provider(new ScriptedProvider([
        ScriptedProvider::turn('', [ScriptedProvider::toolCall('task', [
            'subagent_type' => 'researcher',
            'description' => 'Explain what a vector database is, in one sentence.',
        ])]),
        ScriptedProvider::turn('Added a one-line explanation of vector databases to your notes.'),
    ]))
    ->subAgent('researcher', 'Researches a topic and returns a concise summary.', $researcher);

$state = $lead->run('Add a one-line explanation of vector databases to my notes.');

$delegated = collect($state->history)
    ->where('role', 'tool_result')
    ->flatMap(fn ($m) => $m['toolResults'])
    ->first();

line('researcher (isolated run) returned: '.$delegated['result']);
line('lead agent answer: '.$state->finalText);

/* ----------------------------------------------------------- 6. memory -- */

heading('6. Memory & prompt assembly — BASE prompt + AGENTS.md');

$writer = DeepAgent::make()
    ->provider(new ScriptedProvider([ScriptedProvider::turn('Understood — ready to help.')]))
    ->instructions('You are a writing assistant.')
    ->backend(new StateBackend(['AGENTS.md' => "Use British English.\nPrefer concise answers."]))
    ->memory('AGENTS.md');

$state = $writer->run('Ready?');
line('system prompt the model actually receives (BASE → instructions → memory):');
foreach (explode("\n", $state->instructions) as $promptLine) {
    line('  │ '.$promptLine);
}

echo "\n\033[1;32mDone.\033[0m All offline via a scripted provider — swap in ->provider('anthropic') to run for real.\n\n";
