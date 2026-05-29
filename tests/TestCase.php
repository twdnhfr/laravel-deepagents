<?php

namespace Twdnhfr\LaravelDeepagents\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Twdnhfr\LaravelDeepagents\LaravelDeepagentsServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Twdnhfr\\LaravelDeepagents\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            AiServiceProvider::class,
            LaravelDeepagentsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Fake credentials so provider resolution succeeds; the HTTP layer is
        // faked in the tests, so these keys are never actually used.
        config()->set('ai.providers.anthropic.key', 'test-anthropic-key');
        config()->set('ai.providers.openai.key', 'test-openai-key');
        config()->set('ai.providers.gemini.key', 'test-gemini-key');

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }
}
