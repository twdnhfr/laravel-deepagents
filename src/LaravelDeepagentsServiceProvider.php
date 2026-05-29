<?php

namespace Twdnhfr\LaravelDeepagents;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Twdnhfr\LaravelDeepagents\Backends\BackendManager;
use Twdnhfr\LaravelDeepagents\Commands\LaravelDeepagentsCommand;

class LaravelDeepagentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-deepagents')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_deepagents_artifacts_table')
            ->hasCommand(LaravelDeepagentsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(BackendManager::class, fn ($app) => new BackendManager(
            $app['config']->get('deepagents', []),
        ));
    }
}
