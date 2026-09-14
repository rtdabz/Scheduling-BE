<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * What the browser needs to open the live-updates socket.
 *
 * Served by the API so the Reverb app key has one source of truth (.env)
 * instead of being duplicated into the UI build. The key is public by design;
 * the secret never leaves the server. A null host means "same origin as the
 * page", which the Vite dev server proxies to Reverb.
 */
class RealtimeConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $enabled = config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));

        return response()->json([
            'enabled' => $enabled,
            'key' => $enabled ? (string) config('broadcasting.connections.reverb.key') : null,
            'host' => config('reverb.public.host'),
            'port' => config('reverb.public.port'),
            'scheme' => config('reverb.public.scheme'),
        ]);
    }
}
