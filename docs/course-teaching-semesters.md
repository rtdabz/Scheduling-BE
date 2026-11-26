# Course teaching assignments by academic semester

Course delegations belong to an academic semester (`semesters.id`), including its
academic year. A new academic semester starts without delegations from other terms.
Returning to a previously selected academic year and semester restores its saved
course and section-level assignments, including any intentional removals. Selecting
the currently active semester leaves all assignments unchanged. Activation does not clear
schedule rows, instructor assignments, section returns, submissions, or history.

## Persistence and compatibility

`course_teaching_assignments` stores one row per `(course_id, semester_id)` and
the receiving department, receiving program, and source program. A missing row or
a null receiving department means no course-level delegation for that semester.
The existing three `courses.teaching_*` columns remain the active semester's
projection for current-workspace SQL and the existing API contract.

`Course::save` synchronizes explicit changes to those fields and the active
semester's row in one transaction. Do not update teaching fields through query
builder/bulk updates. Activation alone uses a bulk update to load the incoming
semester's projection, including archived courses, without rewriting mappings.
Delegation writes and activation take the same ordered semester locks. Assignment
requests from the UI include `expected_active_semester_id`; stale requests fail
with 409. No active semester means assignment mutations fail with 422.

Schedules resolve their course delegation using their own `semester_id`, both in
SQL scopes and serialized course relations. Historical semester-specific course
copies cannot be saved; saving an active view rechecks its semester under the same
lock as activation. Returned sections continue to use their own `section_teaching_*`
fields instead of the course-level assignment. Scheduling snapshots also read the
requested semester's assignment. Never resolve retained schedules against the
active projection.

Returned-section labels include the returning program from the latest recorded
`cross_department_sections_returned` event: the actor's event-time program, or
the section's explicit receiving program before a department-office return.
They do not use the section's home program, the actor's current program, or a
later course assignment. If that history identifies no program, the label keeps
the returning department only. This is a read-only API projection; no migration
or assignment-rule change is required.

When the receiving college/program returns the last section still covered by a
course-level delegation, the return transaction clears that semester's saved
teaching department, teaching program and source program. Course Assignment then
shows Unassigned. Partial returns, courses without scheduled sections, intentional
assignments saved after an earlier return, other semesters, and independent
section-level handoffs remain unchanged. A returned section stays detached and
uses Returned Sections for individual reassignment.
Migration `2026_11_26_140000_clear_fully_returned_course_teaching_assignments`
repairs already exhausted active-semester delegations with the same checks. Run
`php artisan migrate --force` when deploying this change.

Assignment notifications now carry the academic semester ID. Assigning the same
target in a different semester is a new assignment; repeating an unchanged
assignment within one semester remains quiet.

## Migration and rollout

Run `php artisan migrate --force` with the backend deployment before accepting
requests using this code. Pause assignment and activation writes while deploying
the migration and backend together. The migration preserves the active workspace's current
delegations. For inactive semesters with recorded activation/end/archive evidence
and existing non-detached classes, it freezes the effective pre-upgrade delegation
as `is_legacy_snapshot = true`. Future draft classes alone are not evidence of a
previously active semester and do not inherit delegations.

These snapshots preserve the available pre-upgrade interpretation; they cannot
reconstruct the original historical decisions. Existing carryover already present
in the active semester cannot be distinguished reliably from an intentional
assignment and remains available for normal removal/reassignment. The migration
does not create copies for every empty saved semester or invent assignments for
historical periods without reliable activation evidence.

## Verification

`CourseTeachingSemesterTest` covers fresh semesters, separate academic years,
restored assignments and removals, unchanged repeat activation, first/second/Summer
and new-year transitions, semester-specific notifications, stale/no-active writes,
transaction rollback, historical JSON and scheduling snapshots, section override
precedence, and migration behavior for future drafts versus used periods.

Validated on 2026-11-23: 347 focused backend tests (2,756 assertions), 51 frontend
tests, TypeScript, production build, scoped frontend lint, and formatting of new
PHP files passed. The focused backend run excludes the existing user-payload and
live-notification signal assertions that also fail with the original implementations.
The unrelated SessionInterpretation assertion likewise fails with the original
implementation. A full-suite attempt reached the CSP solver's 512 MB memory limit;
the entire backend suite is therefore not reported as passing. Migration and
regression tests used isolated SQLite; no production migration was executed.
