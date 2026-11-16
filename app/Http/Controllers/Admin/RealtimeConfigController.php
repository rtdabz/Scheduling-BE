<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RealtimeConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $enabled = config('broadcasting.default') === 'pusher'
            && filled(config('broadcasting.connections.pusher.key'));

        return response()->json([
            'enabled' => $enabled,
            'key' => $enabled ? (string) config('broadcasting.connections.pusher.key') : null,
            'cluster' => $enabled ? (string) config('broadcasting.connections.pusher.options.cluster') : null,
        ]);
    }
}
