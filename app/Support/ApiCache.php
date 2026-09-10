<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ApiCache
{
    public const LOOKUP_TTL_SECONDS = 300;

    public static function key(string $name, array $parts = []): string
    {
        $version = Cache::get(self::versionKey($name), 1);
        $suffix = $parts === [] ? '' : ':' . md5(json_encode($parts));

        return "api:lookup:{$name}:v{$version}{$suffix}";
    }

    public static function forgetGroup(string $name): void
    {
        $versionKey = self::versionKey($name);
        // Seed the key once, then increment atomically so concurrent writes
        // cannot overwrite one another's invalidation version.
        Cache::add($versionKey, 1);
        Cache::increment($versionKey);
    }

    public static function forgetGroups(array $names): void
    {
        foreach ($names as $name) {
            self::forgetGroup($name);
        }
    }

    private static function versionKey(string $name): string
    {
        return "api:lookup-version:{$name}";
    }

}
