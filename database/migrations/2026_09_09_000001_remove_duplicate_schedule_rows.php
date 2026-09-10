<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapses schedule rows that describe the same meeting twice.
 *
 * A meeting is identified by where and when a section meets: term, section,
 * course, day, start time and room. The two halves of a split course differ by
 * day or start time, so they are never merged by this. Repeated saves left the
 * same meeting stored many times over, and the generator counts every stored
 * row against room and section capacity, so the duplicates manufactured
 * conflicts that pushed courses onto Room TBA and online delivery.
 *
 * Only live rows are considered. `schedules` is soft-deleted, and a regenerated
 * section leaves its superseded rows behind as tombstones that share the whole
 * natural key. Grouping across those tombstones is not merely wasteful -- the
 * oldest row in such a group is almost always deleted, so keeping MIN(id) would
 * retain the tombstone and destroy the live meeting. Deleted rows are already
 * invisible to the generator and to every read path, so they are left alone.
 *
 * The oldest surviving row in each group is kept, so ids referenced elsewhere
 * survive wherever possible. This is not reversible: the deleted rows carried
 * no information the kept row does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('schedules')
            ->whereNull('deleted_at')
            ->select('term_id', 'section_id', 'course_id', 'day', 'start_time', 'room_id')
            ->selectRaw('MIN(id) AS keep_id, COUNT(*) AS total')
            ->groupBy('term_id', 'section_id', 'course_id', 'day', 'start_time', 'room_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $doomed = DB::table('schedules')
                ->whereNull('deleted_at')
                ->where('term_id', $group->term_id)
                ->where('section_id', $group->section_id)
                ->where('course_id', $group->course_id)
                ->where('day', $group->day)
                ->where('start_time', $group->start_time)
                ->where('id', '!=', $group->keep_id)
                ->when(
                    $group->room_id === null,
                    fn ($query) => $query->whereNull('room_id'),
                    fn ($query) => $query->where('room_id', $group->room_id),
                )
                ->pluck('id');

            if ($doomed->isEmpty()) {
                continue;
            }

            // History items name the row they describe but hold no foreign key,
            // so nothing would stop them pointing at an id that no longer
            // exists. Repoint them at the surviving row: it records the same
            // meeting, so the audit trail stays readable.
            DB::table('schedule_history_items')
                ->whereIn('original_schedule_id', $doomed)
                ->update(['original_schedule_id' => $group->keep_id]);

            // Split metadata hangs off the schedule row; drop it with its owner
            // so no orphan split records are left behind.
            DB::table('schedule_splits')->whereIn('schedule_id', $doomed)->delete();
            DB::table('schedules')->whereIn('id', $doomed)->delete();
        }
    }

    public function down(): void
    {
        // Duplicate rows carried nothing the surviving row does not, so there is
        // nothing meaningful to restore.
    }
};
