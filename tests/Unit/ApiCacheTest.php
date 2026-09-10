<?php

namespace Tests\Unit;

use App\Support\ApiCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiCacheTest extends TestCase
{
    public function test_group_invalidation_advances_the_lookup_version(): void
    {
        Cache::forget('api:lookup-version:rooms.index');

        $initialKey = ApiCache::key('rooms.index');
        ApiCache::forgetGroup('rooms.index');
        $firstInvalidatedKey = ApiCache::key('rooms.index');
        ApiCache::forgetGroup('rooms.index');
        $secondInvalidatedKey = ApiCache::key('rooms.index');

        $this->assertSame('api:lookup:rooms.index:v1', $initialKey);
        $this->assertSame('api:lookup:rooms.index:v2', $firstInvalidatedKey);
        $this->assertSame('api:lookup:rooms.index:v3', $secondInvalidatedKey);
    }
}
