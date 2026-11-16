# Cross-Department and Course Assignment workflow audit (2026-11-16)

Scope: the two requests below. I reviewed the existing workflows first, listed what is
wrong in priority order, and then fixed items 1 to 3 in that order. Items 4 and later are
recorded for future work and were not changed.

1. **Instructor removal (Course Assignment).** When an instructor is removed, the
   assignment lock tied to that instructor should be released, so the course can be
   reassigned.
2. **Return unassigned sections (Cross-Department).** A receiving department that cannot
   staff every delegated section can hand the unstaffed ones back to the originating
   department.

## How the workflows work today

| Concept | Where it lives | Notes |
|---|---|---|
| Delegation ("Course Assignment") | `courses.teaching_department_id`, `teaching_program_id`, `teaching_source_program_id`, written only by `CourseTeachingAssignmentController` | **Per course, not per section.** Every section's class of that course, in every department, goes to the teaching department. |
| Who assigns a class's instructor | `SchedulingPolicy::assignedTeachingDepartmentId` / `majorTeachingDepartmentId` / `requiredTeachingProgramId`, re-derived in `InstructorAssignmentController`, `ScheduleAuthorizationService`, `ScheduleController` (private copy), `DepartmentAssignmentRule`, `ScheduleConflictScanner`, `InitialDataController`, `SystemNotificationService`, and on the frontend in `facultyEligibility.ts`, `useScheduler.tsx`, `SchedulerPanel/index.tsx`, `InstructorAssignment.tsx` and `CrossDepartmentAssignments.tsx` | All of these resolve per course. |
| Course Assignment lock | `CourseTeachingAssignmentController::classesWithInstructor` → `instructor_assigned_classes` | A course cannot be moved to another teaching college while any of its classes in the active semester has a `faculty_id`. The UI shows it under "locked". |
| Handoff "done" lock | `schedules.faculty_assignment_done` | Set by *Mark done* (`batch-faculty-done`) and by finalizing. While it is set, the Cross-Department page and *Clear section* treat the row as read-only, and the owning department can see the teaching department's instructor. |
| Instructor removal paths | `PATCH instructor-assignments/{id}`, `POST instructor-assignments/clear`, `PATCH schedules/batch-faculty`, `PUT schedules/{id}`, and archiving the instructor (`DELETE faculties/{id}`) | Archiving deliberately **keeps** `schedules.faculty_id` so a restore brings the assignment back (`InstructorManagementTest::test_archive_reports_affected_schedules_and_preserves_assignments_for_restore`). |

## Findings, by priority

| # | Priority | Location | Problem | Status |
|---|---|---|---|---|
| 1 | **P1** (request 1) | `CourseTeachingAssignmentController::classesWithInstructor`, `sectionAssignments`, `presentIncoming` | When an instructor is archived, the rows keep `faculty_id` (by design, see above). The lock still counts them, so the course stays **locked to an instructor who no longer exists**. Nobody can clear it: the teaching college cannot see the archived instructor, and the owner may not touch the teaching college's assignment. The section status reads "Assigned", and the incoming status reads "Ready for teaching", for classes that have no live instructor. | **Fixed.** Rows whose instructor is archived no longer count as assigned anywhere in Course Assignment. `faculty_id` is still preserved for restore. |
| 2 | **P1** (request 1) | Every removal path: `InstructorAssignmentController::update`, `ScheduleController::update` / `batchFaculty` | Clearing an instructor never resets `faculty_assignment_done`. A row can end up "done" with no instructor. The data-integrity audit already flags this state as `faculty_done_without_faculty`. On such a row the Cross-Department page stays read-only, *Clear section* skips it, and the stage check refuses a new instructor ("marked done … Choose Edit Assignments") until someone reopens the whole list. | **Fixed.** Any write that leaves a row without an instructor also clears its done flag in the same update. |
| 3 | **P1** (request 2) | Delegation model (per course) | There is no way for a receiving department to give back sections it cannot staff. The only options are for the owner to remove the whole course's delegation, which the lock (finding 1) blocks once any section is staffed, or to leave the sections unstaffed. Finalizing then fails with "instructors are missing". | **Fixed.** New per-class return; see "Return unassigned sections" below. |
| 4 | P3 | `ScheduleController::scheduleIdsAssignableByDepartment` (private), plus `scheduleBelongsToDepartment`, `scheduleIdsBelongToDepartment` and `sectionIdsBelongToDepartment` | Dead code. Nothing calls these, because the live checks are in `ScheduleAuthorizationService`. The private copy still encodes the old per-course rule and could mislead a future edit. It was not changed. | Open. Delete these methods. |
| 5 | P2 | The teaching-department formula (`isMajor ? majorTeachingDepartmentId : assignedTeachingDepartmentId ?? department_id`) | Copied in about six backend places and four frontend places. The fix for finding 3 routes the touched backend sites through `SchedulingPolicy::courseForSchedule()`, but the copies remain. | Partly addressed. |
| 6 | P3 | `classesWithInstructor` counts rows in **every** status | A Draft, rejected or revision row that keeps an instructor still holds the lock, although Cross-Department hides those rows (`VISIBLE_STATUSES`). In practice Course Assignment writes are gated on full VPAA approval, so this is rarely reachable. The lock also protects against a stale instructor from the old teaching college. | Open. Decide whether a retained instructor on a non-visible row should be released when the delegation changes. |
| 7 | P3 | Delegation is global per course | A shared course, such as a GE course in several departments' curricula, is delegated for **every** department's sections, not only the owner's. This is long-standing behaviour and the return feature respects it. | Keep (by design). Documented here. |
| 8 | P3 | Cross-Department instructor drafts (`instructor_assignment_drafts`, context `cross_department:{id}`) | A draft may still list a class that was later returned. Saving the draft cannot assign it, because the server refuses the teaching college once the class is returned. The frontend drops rows that are no longer in the list. | Keep. The server enforces it. |

## Fix 1: archived instructors no longer hold the Course Assignment lock

`CourseTeachingAssignmentController` now counts a class as staffed only when the
`faculty_id` points at a faculty record that is not archived:

- `classesWithInstructor` joins `faculties` and requires `faculties.deleted_at IS NULL`.
  This releases the lock for `update`, `batch`, `destroy` and `batchDestroy`.
- `sectionAssignments` treats an archived instructor's meeting as unassigned. The status
  then reads "Awaiting instructor" (or "Partially assigned") instead of "Assigned".
- `presentIncoming` counts those meetings as still pending, so the status reads
  "Instructor assignment pending".

Unchanged: archiving still keeps `schedules.faculty_id`, so restoring the instructor brings
the assignment back. The other rules and permissions are not affected.

## Fix 2: removing an instructor clears the "done" flag

Any write that sets `faculty_id` to `null` now also sets `faculty_assignment_done = false`
on the same rows:

- `InstructorAssignmentController::update` (Cross-Department single class)
- `ScheduleController::batchFaculty` (Cross-Department / scheduler batch)
- `ScheduleController::update` (Schedule Management single row)

(`clearSectionInstructors` already only acts on rows that are not done.) Assigning an
instructor does not change the flag, so *Mark done* and *Edit Assignments* work as before.

## Fix 3: return unassigned sections

> **Superseded in part** by `cross-department-section-reassignment-2026-11-16.md`. A returned
> section is now *detached* for good: any non-null `delegation_returned_from_department_id`
> counts, regardless of the course's current teaching department. Course-level changes no
> longer clear the marks, and the originating department can hand the section on by itself.
> The rule "a mark applies only while it names the current teaching department" below, and
> the clearing of marks, no longer hold.

### Data

New nullable columns on `schedules`:

| Column | Meaning |
|---|---|
| `delegation_returned_from_department_id` | The teaching department that returned this class. |
| `delegation_returned_at` | When it was returned. |

Who returned it is recorded in `scheduling_audit_logs` (action
`cross_department_sections_returned`), which already keeps actor snapshots.

A class counts as **returned** while `delegation_returned_from_department_id` equals the
course's current `teaching_department_id`. If the owner later moves or clears the
delegation, the mark no longer matches and the class follows the course-level rule again.
`CourseTeachingAssignmentController` also clears the marks whenever it changes a course's
teaching department, so no stale mark survives.

### Rule

A returned class behaves exactly as if its course had never been delegated. This is
`SchedulingPolicy::courseForSchedule()`: it returns a copy of the course with
`teaching_department_id` and `teaching_program_id` cleared. The existing
offering-department rules therefore decide who assigns the instructor. Those rules are
unchanged, including the Program Head rules for majors.

### Endpoint

`POST /api/instructor-assignments/return-sections` with
`{ "schedule_ids": [ ... ] }`, under the same capability group as the other
instructor-assignment routes.

- Each id expands to its whole class: same semester, section and course, across every
  meeting row.
- Refused (422 with `blocked` per class) when any meeting of the class has an instructor
  whose record is not archived. **Only unstaffed sections can be returned. A staffed
  section stays with the receiving department.**
- Refused (403) unless the caller's department currently teaches the class through
  delegation and may manage it (`userCanManageInstructor`, which keeps the Program Head
  and major-course rules). The originating department cannot use it on its own classes.
- Refused (422) unless the class is in an instructor-assignable status and not marked done.
- Everything is written in one transaction, with a history version and a
  `SchedulingAuditLog` entry. Caches are invalidated, and the originating department's
  Secretary and Program Head are notified (`cross_department_sections_returned`).

### Sites updated to honour a returned class

Backend: `InstructorAssignmentController` (index, update, permission check),
`ScheduleAuthorizationService` (readable scope, assignable check),
`ScheduleController` (Program Head check in `batchFaculty`, release notification,
reassignment "done" reset), `DepartmentScheduleController` (withdraw "done" reset and the
receiver's `cross_department_pending` count), `InitialDataController` (teaching department
scope, instructor masking), `DepartmentAssignmentRule`, `ScheduleConflictScanner`,
`SystemNotificationService` (no receiver notices or completion checks for returned
classes), `ScheduleResetService` and `CourseTeachingAssignmentController` (incoming counts,
the section statuses "Returned to your department" and "Returned · Assigned", clearing
marks).

Course Assignment lock: a returned class staffed by the originating department **still
counts**. Moving the delegation clears the return marks, which would hand that instructor's
class to the new teaching college, so the lock keeps protecting it.

Frontend (`wicars-ui`): schedules carry `delegation_returned_from_department_id`.
`facultyEligibility.ts` adds `isDelegationReturned` and `subjectForSchedule`, the per-class
effective subject. They are used by `useScheduler` (*can manage*, the restriction message,
Program-Head-only majors, and the delegated save path), `FacultyModal`, `AutoAssignModal`,
the Schedule Management own-department filter (`SchedulerPanel/index.tsx`) and the
Cross-Department list (`CrossDepartmentAssignments.tsx`, `InstructorAssignment.tsx`). The
Cross-Department workspace has a **Return Unassigned (n)** button with a confirmation step.
The new notification links to the originating department's Instructor Assignment page.

## Follow-ups

- Finding 4: delete the dead private authorization helpers in `ScheduleController`.
- The frontend `withdrawalEligibility.test.ts` was already failing on `main` (5 cases) and
  is unrelated to this work.
- Finding 5: route the remaining copies of the teaching-department formula through
  `SchedulingPolicy`.
- Finding 6: decide how retained instructors on rows that are not visible should be
  handled when a delegation changes.
- An "undo return" for the originating department. Today the owner can delegate the
  course again: moving the delegation clears the marks, and re-saving to the same college
  keeps them.
