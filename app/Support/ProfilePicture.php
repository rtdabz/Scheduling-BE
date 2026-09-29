<?php

namespace App\Support;

/**
 * Validation for a profile picture stored inline as a data URL. The picker
 * resizes to a ~30 KB JPEG, so the cap only leaves headroom for a PNG; without
 * it the column accepted any string of any size.
 */
final class ProfilePicture
{
    public const MAX_LENGTH = 1_500_000;

    /** @return list<string> */
    public static function rules(bool $sometimes = false): array
    {
        return [
            ...($sometimes ? ['sometimes'] : []),
            'nullable',
            'string',
            'max:'.self::MAX_LENGTH,
            'regex:/^data:image\/(png|jpe?g|webp);base64,[A-Za-z0-9+\/=]+$/',
        ];
    }
}
