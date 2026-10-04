<?php

namespace App\Services\Scheduling\Engine\Rules;

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
