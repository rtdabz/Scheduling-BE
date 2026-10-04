<?php

namespace App\Support;

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
