<?php

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Controller;
use App\Services\Scheduling\Schedule\ScheduleResetRejected;
use App\Services\Scheduling\Schedule\ScheduleResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permanent Reset. Separate from `/schedules/batch` (generation replacement) and from
 * Delete/Archive: it accepts only a semester and explicitly selected sections, and the server
 * resolves which editable (draft, completed, or revision) schedules those contain.
 */
class ScheduleResetController extends Controller
{
    public function __construct(private readonly ScheduleResetService $reset) {}

    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validated($request, requireToken: false);

        return $this->respond(fn (): array => $this->reset->preview($request, $validated['semester_id'], $validated['section_ids']));
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $this->validated($request, requireToken: true);

        return $this->respond(fn (): array => $this->reset->reset(
            $request,
            $validated['semester_id'],
            $validated['section_ids'],
            $validated['expected_token'],
        ));
    }

    /** @return array{semester_id: int, section_ids: list<int>, expected_token?: string} */
    private function validated(Request $request, bool $requireToken): array
    {
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', 'min:1'],
            'section_ids' => ['required', 'array', 'min:1', 'max:500'],
            'section_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_token' => [$requireToken ? 'required' : 'prohibited', 'string', 'size:64'],
        ]);
        $validated['semester_id'] = (int) $validated['semester_id'];
        $validated['section_ids'] = array_map('intval', $validated['section_ids']);

        return $validated;
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (ScheduleResetRejected $rejected) {
            return response()->json($rejected->payload, $rejected->status);
        } catch (ScheduleConflictException $exception) {
            // The semester scheduling lock timed out behind another schedule write.
            return response()->json([...$exception->payload(), 'code' => 'reset_busy'], 409);
        }
    }
}
