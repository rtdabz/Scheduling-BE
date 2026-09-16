<?php

namespace App\Services\Scheduling\Engine\Rules;

/**
 * Per-instance memo for reference-entity lookups, shared by the rule engine
 * and the rule classes it delegates to.
 *
 * `validate()` is called once per operation in a batch save, and a batch
 * almost always reuses the same semester, section, room and department. Without
 * this, a 40-operation batch re-ran the same handful of primary-key lookups
 * 40 times (audit finding #8). The engine is resolved per request, so the
 * memo cannot outlive the data it caches.
 */
final class RuleLookupCache
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $resolver
     * @return TValue
     */
    public function remember(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->entries)) {
            $this->entries[$key] = $resolver();
        }

        return $this->entries[$key];
    }
}
