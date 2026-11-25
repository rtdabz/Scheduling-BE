# Program-level access for the Secretary and Director (2026-11-24)

Follow-up correction (2026-11-25): the [CAS ownership review](cas-program-ownership-fix-2026-11-25.md)
supersedes the three-workflow completion claims below for import, requests, reassignment, and reuse.
It records the actual CAS configuration, remaining bypasses, endpoint fixes, and final verification.

The department office (Secretary or Director) manages each program of its department on its own
terms: full management while the program has no active Program Head, view-only once it has one.
A department with both kinds of program keeps full access to the first and is never locked as a
whole.

## Source of truth

`ScheduleAuthorizationService::writableProgramIdsFor()` lists the programs the office manages:
every program of its department without an **active, non-archived** Program Head. It is computed
on every request, so assigning, deactivating, reactivating or archiving a Program Head takes
effect immediately. `/api/me` returns it as `can_edit_program_ids`, and the frontend reads that.

New helpers on the same service, used by every write below:

| Helper | Meaning |
|---|---|
| `officeMayManageProgram($request, $programId)` | False only for the office and a program with a Program Head. Records without a program are department-level and stay with the office. Other roles are unaffected. |
| `headManagedProgramIds($request)` | For the office: its department's programs with a Program Head. Empty for other roles. |
| `headManagedCourseIds($request, $ids)` | Courses a Program Head's program uses: owned by it, placed in one of its curricula, or placed in a shared curriculum one of its active sections follows. |

## Rules

| Area | Program without a Program Head | Program with a Program Head |
|---|---|---|
| Sections | Full management | View only (create, batch create, edit, delete, assign curriculum refused) |
| Curriculum | Full management | View only (create, edit, status, archive, duplicate, course entries refused); import into it refused |
| Courses | Full management | View only. A course record is shared by every curriculum that places it, so a GE or minor course a Program Head's program also uses is protected too (decision: shared courses must not bypass ownership). |
| Schedule management | Full management | View only (already enforced by `scheduleIdsWritable` / `sectionIdsWritable`, including generation jobs) |
| Course Assignment | Full management | Locked: its curricula, sections and incoming hand-offs are not listed. A shared course it uses is listed with `program_head_managed: true`, and its course-wide assignment is refused. Section-level actions stay open for the office's own sections. When **every** program has a Program Head the module returns 403 (`reason: program_head_managed`). |
| Room Requests | Office requests for it | The Program Head requests for it |

Existing shared (program-less) curricula, Cross-Department, Facilities, Instructors, Dashboard,
Reports, and Schedule History viewing keep their existing permissions. New shared imports require
no headed programs; Schedule History reuse requires ownership of all source and destination programs.

## Room requests

Reverses `2026_10_04_100001_remove_program_head_room_requests` (decision: Program Heads request,
the office reviews).

- `room_requests.requesting_program_id` (nullable) records the program a request is for.
- Program Heads hold `room.request` again and always request for their own program.
- The office requests for a program without a Program Head. It may leave the program out (a
  department-level request) only while no program of its department has a Program Head.
- Only the requesting program's owner may cancel or give back (`can_cancel` in the listing).
- Lending decisions stay with the owning department's office; grants stay department-wide; the
  VPAA notifications are unchanged. Approve, reject and revoke notices also reach the requesting
  program's Program Head.
- The migration keeps the SQLite triggers that guard recorded approval decisions: SQLite rebuilds
  the table to add the foreign key, which would otherwise drop them.

## Tests

- New: `tests/Feature/ProgramHeadManagedOfficeAccessTest.php` (both office roles): mixed
  department, every area above, the module lock, dynamic changes on deactivation, reactivation
  and archival, and unchanged Program Head and Dean access.
- Updated to the new rules: `RoomRequestTest` (Program Head requests; office program choice),
  `RoleCapabilityDefaultsTest`, `CourseAssignmentOwnProgramRestrictionTest` (the single-program
  secretary case now deactivates the Program Head first, since the office is otherwise locked out).

## Three-workflow completion review (2026-11-25)

Curriculum Import, Room Requests, and Reassign Room Schedule use the existing program ownership
rules independently for each program. This review preserves the earlier work in other modules.

- Import validation and commits both check the target program. The interface retains every program
  for file detection, including restricted programs, so detection never silently becomes a shared
  department curriculum. Restricted options are disabled, and open drafts recheck live permissions.
  Correction: a null import target is now refused if any department program has a Program Head;
  choose an explicit unheaded program. Existing shared-record management is unchanged.
- Room request creation and cancellation use `ScheduleAuthorizationService` for program ownership,
  including Program Heads. An open request form refreshes permissions after account changes, blocks
  writes while permissions or programs are loading or unavailable, and preserves the original target
  if it becomes restricted. It never automatically switches the request to another program.
- Both reassignment submission routes (`apply` and `submit`) recheck the current schedules' program
  ownership. A Program Head assignment invalidates frontend selections, previews, and confirmations.
  Draft application and Dean/VPAA review keep their existing workflows.
- Import and request forms refresh `/api/me` on `users`/`departments` events, focus, and a one-minute
  fallback poll. A refresh following an older pending `/me` read waits for a new read, so the earlier
  response cannot restore obsolete permissions. Reassignment consumes the API's `can_reassign` flags.

`ProgramHeadManagedOperationsTest` covers both office roles with no, one, multiple, and mixed Program
Head assignments; assignment, deactivation, reactivation, and archival; direct import commits and both
reassignment routes after assignment; request cancellation; Program Head isolation; and unchanged
Dean/VPAA review. The focused matrix passes 21 tests (274 assertions). The broader authorization,
curriculum import, room request, recovery, capability, and Director parity selection passes 370 tests.

Frontend regression coverage includes open-form assignment changes, restored access after head
changes, program loading failures, all-managed departments, retained import detection, invalidated
reassignment confirmations and late previews, and permission refreshes during an earlier `/me` read.
The room-request program migration is already applied in the local database (batch 45).

Verification: the frontend's 10 focused suites pass 62 tests with one worker and a 20-second default
test timeout, covering the existing import and room workflows as well as these changes. Scoped ESLint,
TypeScript compilation, and the production build pass. The touched PHP controller and operations
matrix also pass Pint. Earlier parallel frontend attempts exceeded test timeouts; the final combined
run above passed without changing the existing per-test timeouts.
