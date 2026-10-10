<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/** A cache store that is down for reads. */
class FailingCacheStore extends ArrayStore
{
    public function get($key): mixed
    {
        throw new RuntimeException('cache down');
    }
}
