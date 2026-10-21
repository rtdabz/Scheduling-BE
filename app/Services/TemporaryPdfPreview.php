<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class TemporaryPdfPreview
{
    public const MINUTES = 30;

    private const MAX_PER_USER = 5;

    public function store(int $userId, string $token, string $filename, string $pdf): string
    {
        return Cache::lock('pdf-preview-owner-lock:'.$userId, 10)->block(5, function () use ($userId, $token, $filename, $pdf): string {
            $ownerKey = 'pdf-preview-owner:'.$userId;
            $ids = Cache::get($ownerKey, []);
            while (count($ids) >= self::MAX_PER_USER) {
                Cache::forget('pdf-preview:'.array_shift($ids));
            }
            $id = (string) Str::uuid();
            Cache::put('pdf-preview:'.$id, Crypt::encryptString(json_encode([
                'user_id' => $userId,
                'token' => $token,
                'filename' => $filename,
                'pdf' => base64_encode($pdf),
            ], JSON_THROW_ON_ERROR)), now()->addMinutes(self::MINUTES));
            // Retain the bounded index so lazy-expiry cache stores can also
            // remove old payload keys when another preview is created.
            Cache::forever($ownerKey, [...$ids, $id]);

            return $id;
        });
    }

    /** @return array{user_id: int, token: string, filename: string, pdf: string}|null */
    public function get(string $id): ?array
    {
        $value = Cache::get('pdf-preview:'.$id);

        return is_string($value) ? json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function forget(string $id): void
    {
        $entry = $this->get($id);
        Cache::forget('pdf-preview:'.$id);
        if ($entry) {
            Cache::lock('pdf-preview-owner-lock:'.$entry['user_id'], 10)->block(5, function () use ($entry, $id): void {
                $ownerKey = 'pdf-preview-owner:'.$entry['user_id'];
                $ids = array_values(array_diff(Cache::get($ownerKey, []), [$id]));
                if ($ids === []) {
                    Cache::forget($ownerKey);
                } else {
                    Cache::forever($ownerKey, $ids);
                }
            });
        }
    }
}
