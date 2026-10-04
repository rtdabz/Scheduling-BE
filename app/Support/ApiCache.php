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

    /**
     * @param  list<string>  $groups
     */
    public static function compositeKey(string $name, array $groups, array $parts = []): string
    {
        $versionKeys = [];
        foreach ($groups as $group) {
            $versionKeys[$group] = self::versionKey($group);
        }

        $stored = $versionKeys === [] ? [] : Cache::many(array_values($versionKeys));

        $stamp = [];
        foreach ($versionKeys as $group => $versionKey) {
            $stamp[$group] = $stored[$versionKey] ?? 1;
        }

        return self::key($name, $parts + ['_group_versions' => $stamp]);
    }

    public static function forgetGroup(string $name): void
    {
        $versionKey = self::versionKey($name);
        Cache::add($versionKey, 1);
        Cache::increment($versionKey);

        app(LiveUpdates::class)->touchCacheGroup($name);
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
