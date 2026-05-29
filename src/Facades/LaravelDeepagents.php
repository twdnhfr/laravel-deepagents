<?php

namespace Twdnhfr\LaravelDeepagents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Twdnhfr\LaravelDeepagents\LaravelDeepagents
 */
class LaravelDeepagents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Twdnhfr\LaravelDeepagents\LaravelDeepagents::class;
    }
}
