<?php

namespace App\Http\Controllers;

use App\Models\InstitutionSetting;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class InstitutionSettingsController extends Controller
{
    /**
     * Readable by every signed-in role: the print builders that stamp these
     * names run from the Dean, Secretary and Program Head screens too.
     */
    public function show(): JsonResponse
    {
        $settings = Cache::remember(
            ApiCache::key('institution.settings'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => InstitutionSetting::current(),
        );

        return response()->json($settings);
    }

    /** VPAA-only, enforced by the route group. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'president_name' => ['sometimes', 'required', 'string', 'max:150'],
            'president_title' => ['sometimes', 'required', 'string', 'max:150'],
        ]);

        $settings = InstitutionSetting::current();

        foreach ($validated as $field => $value) {
            $settings->{$field} = trim($value);
        }

        $settings->save();
        ApiCache::forgetGroup('institution.settings');

        return response()->json([
            'message' => 'Signatory updated successfully.',
            'settings' => $settings,
        ]);
    }
}
