<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

/** An array store that counts round trips: get() for one key, many() for several. */
class CountingCacheStore extends ArrayStore
{
    public int $gets = 0;

    public int $manys = 0;

    public function get($key): mixed
    {
        $this->gets++;

        return parent::get($key);
    }

    public function many(array $keys): array
    {
        $this->manys++;

        // ArrayStore::many() calls get() per key: don't count those.
        $gets = $this->gets;
        $values = parent::many($keys);
        $this->gets = $gets;

        return $values;
    }
}
