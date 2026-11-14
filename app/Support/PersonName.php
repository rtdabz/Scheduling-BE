<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Shared rules and formatting for people's names on accounts and instructor profiles.
 *
 * The title (honorific prefix) and credentials are display-only: duplicate detection and
 * surname sorting keep using the structured first/middle/last/suffix columns.
 */
final class PersonName
{
    /** The single source of selectable titles; the UI reads them from the API. */
    public const HONORIFIC_PREFIXES = ['Dr.', 'Prof.', 'Engr.', 'Atty.', 'Arch.'];

    public const AFFIX_FIELDS = ['honorific_prefix', 'credentials'];

    public const HONORIFIC_PREFIX_MAX = 20;

    public const CREDENTIALS_MAX = 100;

    /**
     * Rules for the optional title and credentials. `sometimes` keeps an omitted field out of
     * the validated data so partial updates leave it unchanged; explicit null clears it.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function affixRules(): array
    {
        return [
            'honorific_prefix' => ['sometimes', 'nullable', 'string', 'max:'.self::HONORIFIC_PREFIX_MAX, Rule::in(self::HONORIFIC_PREFIXES)],
            'credentials' => ['sometimes', 'nullable', 'string', 'max:'.self::CREDENTIALS_MAX, 'regex:/^[\pL\pM\pN .,&\'()\/-]+$/u'],
        ];
    }

    /** @return array<string, string> */
    public static function affixMessages(): array
    {
        return [
            'honorific_prefix.in' => 'Choose a title from the list.',
            'credentials.regex' => 'Credentials may contain letters, numbers, spaces, and . , & \' ( ) / - only.',
            'credentials.max' => 'Credentials may not be longer than '.self::CREDENTIALS_MAX.' characters.',
        ];
    }

    /** Trims and collapses inner whitespace; blank becomes null. Capitalization is kept. */
    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $clean === '' ? null : $clean;
    }

    /** Like clean(), and drops leading/trailing commas that would dangle after the name. */
    public static function cleanCredentials(mixed $value): ?string
    {
        $clean = self::clean($value);

        return $clean === null ? null : self::clean(trim($clean, " ,"));
    }

    /**
     * "Dr. Juan P. Dela Cruz Jr., DM". The middle name is shown as an initial unless
     * $fullMiddleName is set (archive identity needs it to tell namesakes apart).
     */
    public static function format(
        ?string $honorificPrefix,
        ?string $firstName,
        ?string $middleName,
        ?string $lastName,
        ?string $suffix = null,
        ?string $credentials = null,
        bool $fullMiddleName = false,
    ): string {
        $middle = self::clean($middleName);
        if ($middle !== null && ! $fullMiddleName) {
            $middle = mb_strtoupper(mb_substr($middle, 0, 1)).'.';
        }

        $name = implode(' ', array_filter([
            self::clean($honorificPrefix),
            self::clean($firstName),
            $middle,
            self::clean($lastName),
            self::clean($suffix),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        $credentials = self::cleanCredentials($credentials);
        if ($credentials === null) {
            return $name;
        }

        return $name === '' ? $credentials : "{$name}, {$credentials}";
    }
}
