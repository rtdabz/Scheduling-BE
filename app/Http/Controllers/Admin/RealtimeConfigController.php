<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

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
