# Consecutive Days: per-day modality audit (2026-11-15)

## Symptom

A Consecutive Days class is plotted (for example Thu/Fri/Sat, all Online). The user
opens one day in the schedule builder and changes its modality, for example to
On-Site. On save, every day of the run changes modality, and the other days'
times, days and rooms are rewritten (sometimes moved to another start time).

Already fixed earlier, and not to be changed: dragging or click-moving one day of
a run moves only that day (`PUT /api/schedules/{id}` → `SameTimePartnerMover::project`
keeps run siblings in place).

## Where the propagation comes from

| # | Priority | Location | Problem |
|---|----------|----------|---------|
| 1 | **P1, root cause** | `wicars-ui/.../hooks/useScheduler.tsx` `handleConfirmSchedule` | When an existing run meeting is edited, the dialog saves the **whole run**: it rebuilds every day from `runStartingOn(modalRun, modalDay1Index)` and posts `/schedules/batch` with one `mode`, one `room_id` and one start time for all of them. Every sibling gets the edited day's modality and room. Siblings that were moved on their own (the earlier fix) are pulled back to aligned days and times. A new `split_group_id` is also minted on each save. |
| 2 | **P1** | same function, the conflict fallback | If any day of the rebuilt run clashes, the dialog looks for a new start time and moves **all** days there, which is the "automatically moves their schedules" part of the symptom. |
| 3 | **P1** | `useScheduler.tsx` placement-session init (`if (run) { … savedRun[0] … }`) | When a run meeting is reopened, the dialog is seeded from the run's **earliest** day (`savedRun[0]`), not from the meeting the user clicked. The dialog therefore shows, and then saves, the wrong day's modality, room and time. |
| 4 | **P1** | `useScheduler.tsx` `modalConflict` | The live conflict check tests the edited time on **every** run day and excludes every run meeting. A one-day edit is judged against days it does not touch, and real clashes with its stationary siblings are hidden. |
| 5 | **P2, server** | `backend/app/Services/Scheduling/Schedule/SameTimePartnerMover.php` `project()` | For a single-meeting update that carries day or time fields, the run's group check still enforces `consecutive_mode` ("Every meeting … must use the same delivery mode"). After fix #1, a one-day modality edit would be refused here. The earlier movement fix already exempted `split_group_same_time` at this exact point; `consecutive_mode` needs the same exemption. |
| 6 | Keep | `MeetingGroupRule::groupMismatches` (`consecutive_mode`, `split_group_same_time`) | Still enforced for **creating** a run (`/schedules/batch`), for generation (`ValidateScheduleCandidate`) and for recommendations (`PlacementGroupValidator`). Not changed: a newly generated or plotted run still starts with one mode and one time. Standing conflict detection does not evaluate meeting groups, so a run with mixed modes does not appear as a conflict. |

## Fix applied (minimum)

1. **Frontend (P1 #1–#4):** when the dialog reopens a meeting of a saved run and the
   run's day count is unchanged, it now edits only that meeting:
   - it is seeded from the clicked meeting (its day, time, duration, mode and room);
   - the live conflict check tests only that day, excluding only that meeting, so
     stationary siblings count as real neighbours (the same logic as `checkMoveConflict`);
   - Confirm sends `PUT /api/schedules/{id}` with only that meeting's
     `day / start_time / end_time / mode / room_id`, the same endpoint the
     independent-move fix uses. Siblings, `split_group_id` and `preferred_pattern`
     are not sent and stay as they are;
   - no automatic relocation: a clash is reported, not resolved by moving days.
   Changing the run's **day count** in the dialog still re-plots the whole run (that is
   a reshape of the run, not a per-day edit).
2. **Backend (P2 #5):** `SameTimePartnerMover::project()` also exempts
   `consecutive_mode` for a saved run's single-meeting update. The rule is still
   enforced when a run is created, generated or recommended.

## Tests

- `backend/tests/Feature/ConsecutiveDaysManualSchedulingTest.php`: changing one
  day's modality (with and without a time change) leaves the siblings' modality, room,
  day and time unchanged; creating a run with mixed modes is still refused.
- `backend/tests/Unit/ConsecutiveDaysPolicyTest.php`: the group rule itself is unchanged.
- `wicars-ui/.../hooks/useScheduler.relocation.test.tsx`: editing one run day's
  modality in the dialog sends a single `PUT` for that meeting and leaves every other
  schedule unchanged.
