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
        $currentVersion = (int) Cache::get($versionKey, 1);

        Cache::forever($versionKey, $currentVersion + 1);
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
