<?php

namespace Twdnhfr\LaravelDeepagents\Commands;

use Illuminate\Console\Command;

class LaravelDeepagentsCommand extends Command
{
    public $signature = 'laravel-deepagents';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
