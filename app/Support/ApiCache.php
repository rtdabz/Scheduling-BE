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
     * A key versioned by several groups at once.
     *
     * Each group contributes its own version, so a write can bump only the
     * groups it actually touched instead of discarding a whole payload for
     * every caller. The base `$name` version stays in the key, which keeps
     * `forgetGroup($name)` working as a bulk invalidation for the write paths
     * that cannot be narrowed safely.
     *
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

        // Folded into the hashed parts rather than the readable prefix so the
        // key length stays independent of how many groups a payload spans.
        return self::key($name, $parts + ['_group_versions' => $stamp]);
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
