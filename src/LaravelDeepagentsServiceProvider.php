<?php

namespace Twdnhfr\LaravelDeepagents;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Twdnhfr\LaravelDeepagents\Backends\BackendManager;

class LaravelDeepagentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-deepagents')
            ->hasConfigFile()
            ->hasMigration('create_deepagents_artifacts_table');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(BackendManager::class, fn ($app) => new BackendManager(
            $app['config']->get('deepagents', []),
        ));
    }
}
