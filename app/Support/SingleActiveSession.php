<?php

namespace App\Support;

use App\Events\SessionReplaced;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

class SingleActiveSession
{
    private const REPLACED_KEY = 'auth:replaced-token:';

    public function start(User $user, string $name): NewAccessToken
    {
        $previousIds = $user->tokens()->pluck('id')->all();
        $user->tokens()->whereIn('id', $previousIds)->delete();

        $ttl = now()->addMinutes((int) (config('sanctum.expiration') ?: 1440));
        foreach ($previousIds as $id) {
            Cache::put(self::REPLACED_KEY.$id, true, $ttl);
        }

        $token = $user->createToken($name);

        if ($previousIds !== []) {
            $this->notifyPreviousDevices($user, (int) $token->accessToken->getKey());
        }

        return $token;
    }

    public function wasReplaced(?string $bearerToken): bool
    {
        $id = Str::before((string) $bearerToken, '|');

        return ctype_digit($id) && Cache::has(self::REPLACED_KEY.$id);
    }

    private function notifyPreviousDevices(User $user, int $activeTokenId): void
    {
        $connection = (string) config('broadcasting.default');
        if ($connection === '' || $connection === 'null') {
            return;
        }

        try {
            event(new SessionReplaced($user->id, $activeTokenId));
        } catch (Throwable $e) {
            Log::notice('session_replaced_broadcast_failed', ['error' => $e->getMessage()]);
        }
    }
}
