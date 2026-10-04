<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstitutionSetting;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class InstitutionSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = Cache::remember(
            ApiCache::key('institution.settings'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => $this->signatory(InstitutionSetting::current()),
        );

        return response()->json($settings);
    }

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
        ApiCache::forgetGroups(['institution.settings', 'initial.data']);

        return response()->json([
            'message' => 'Signatory updated successfully.',
            'settings' => $this->signatory($settings),
        ]);
    }

    private function signatory(InstitutionSetting $settings): array
    {
        return $settings->only(['id', 'president_name', 'president_title', 'created_at', 'updated_at']);
    }
}
