<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstitutionSetting;
use App\Models\SchedulingAuditLog;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InstitutionSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = Cache::remember(
            ApiCache::key('institution.settings'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => $this->signatory(InstitutionSetting::current()),
        );

        return response()->json([
            ...(array) $settings,
            // Applied automatically by employment type; added windows extend it.
            'default_faculty_availability' => [
                'full-time' => SchedulingPolicy::standardFacultyHours('full-time'),
                'part-time' => SchedulingPolicy::standardFacultyHours('part-time'),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'president_name' => ['sometimes', 'required', 'string', 'max:150'],
            'president_title' => ['sometimes', 'required', 'string', 'max:150'],
        ]);

        $settings = DB::transaction(function () use ($request, $validated): InstitutionSetting {
            $settings = InstitutionSetting::query()->whereKey(InstitutionSetting::current()->id)->lockForUpdate()->firstOrFail();
            $before = $this->signatory($settings);
            foreach ($validated as $field => $value) {
                $settings->{$field} = trim($value);
            }
            $settings->save();
            SchedulingAuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'institution_signatories_updated',
                'metadata' => ['target' => ['type' => 'institution_settings', 'id' => $settings->id], 'before' => $before, 'after' => $this->signatory($settings)],
            ]);

            return $settings;
        });
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
