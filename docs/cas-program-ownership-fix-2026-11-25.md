# CAS program ownership authorization review (2026-11-25)

## Actual configuration checked

The configured local MySQL database, `scheduling_db`, was inspected directly. No account,
Program Head assignment, curriculum, schedule, or room-request record was changed for this review.

| CAS record | Observed configuration |
|---|---|
| Department | CAS, id 1 |
| BAS | Program 8; active, non-archived Program Head account 18; active curriculum 5 |
| BSCD | Program 9; active, non-archived Program Head account 19; active curriculum 6 |
| Secretary | Active account 17, department 1; canonical writable program ids `[]` |
| Existing schedules | 132 BAS rows with program 8 recorded; no current BSCD rows |

The two Program Heads resolve to writable ids `[8]` and `[9]`, respectively. The assignments
are present and correctly recognized by `ScheduleAuthorizationService::writableProgramIdsFor()`.
Changing CAS configuration would therefore conceal the defect instead of fixing it.

## Root cause

The earlier implementation already rejected explicit headed-program targets on several API paths,
but its completion review did not check the real CAS configuration or all alternative ownership paths.

1. Import, request, reassignment, and reuse entry points still relied on broad role capabilities in
   places. An office user could open controls even when its writable program list was empty. A broad
   capability grants access to a workflow; it does not grant ownership of each record in it.
2. Import reused the department-level helper's intentional allowance for `program_id = null`.
   Omitting the target could therefore create a shared curriculum while a department had Program
   Heads. An import now needs an explicit unheaded program in that situation.
3. Reassignment's older schedule check resolved only schedule/section program ids, then treated a
   missing program as department-owned. Legacy rows could still identify a headed program through
   their curriculum or shared course. Legacy department-level room requests also remained cancellable
   by the office after a head was assigned.
4. Reuse checked the mapped destination sections without first authorizing every saved source row.
   Moving an old section or omitting legacy program ids could hide original ownership. History also
   lacked a current per-entry reuse flag, so mixed-program history appeared reusable to the office.

## Authorization changes and endpoint trace

The single source of permission rules remains `writableProgramIdsFor()` / `programIsWritable()`.
An active, non-archived Program Head excludes its program from office writes. No separate list of
Program Heads or competing role rule was introduced.

| Workflow / API (all paths have `/api` prefix) | Authoritative check |
|---|---|
| `POST /room-requests` | Existing validated program target and canonical program ownership; a Program Head requests only for their own program. Office program omission is allowed only with no headed programs. |
| `POST /room-requests/{id}/cancel` | Persisted `requesting_program_id`; legacy null requests require no headed programs. The list exposes the same decision as `can_cancel`. |
| `POST /curriculum-imports/validate`, `POST /curriculum-imports` | Both validation and commit reject headed targets and null/omitted targets when any department program is headed. A successful draft validation cannot authorize a later commit after assignment. |
| `POST /room-recovery/preview` | Persisted source schedules and linked meetings must all belong to writable programs. |
| `POST /room-recovery/plans/{id}/apply`, `POST /room-recovery/plans/{id}/submit` | Re-resolve and authorize all current rows inside the existing transfer process before mutation, regardless of which route or preview was used. |
| `POST /schedule-history/{version}/reuse` | Authorize every persisted historical source row, repeat the check inside the existing scheduling lock/transaction, then authorize actual mapped destination sections before creation. Mixed protected/unprotected history is rejected atomically. |
| `GET /schedule-history`, `GET /schedule-history/{version}` | Return `can_reuse` from current ownership and the existing creation capability. Only immutable ownership identities are cached; permissions are recomputed on each response. |

The focused `programOperationAccessAmong()` / `programOperationRowsWritable()` helpers resolve
reassignment/reuse ownership in batches. Recorded ownership and current section ownership are resolved
independently, each using its curriculum when its program id is missing; neither can erase the other.
If neither resolves, resolution falls back to course ownership.
The last-resort course check also reuses `headManagedCourseIds()` to detect shared courses used by a
headed program, including archived courses when resolving legacy rows. This archive option defaults
off for existing callers. Explicitly identified unheaded schedules remain writable even if their
course is shared with a headed program. Unresolved office rows are refused while any program is headed.

The global department-level `officeMayManageProgram(null)` behavior and normal scheduling
authorization paths were not changed. The stricter resolution is limited to the requested operations.

## Interface and dynamic changes

The existing `useProgramAccess` hook refreshes `/me` after user/department events, focus, and the
fallback poll, including a fresh read after an older pending read. Fully headed office departments
see disabled request/import/reassignment/reuse entry points or the existing locked-module view.
Mixed departments keep unheaded targets available. Import retains restricted file-detected targets
and requires an explicit writable target instead of silently converting to a shared import.

History uses each entry's server `can_reuse` flag. User changes invalidate loaded flags and pending
reuse confirmations; stale responses cannot restore the old decision. Reassignment similarly uses
server `can_reassign` flags and invalidates previews/selections on changes. The backend rechecks each
write independently, including direct API requests made without any interface.

Dean/VPAA viewing, review stages, room lending decisions, VPAA notifications, and Program Head
ownership remain governed by their existing permissions. No schema migration, approval-process
change, or scheduling algorithm change was required by this follow-up.

## Files changed in this follow-up

This lists the follow-up changes, not unrelated edits already present in both working trees.

Backend:

- `app/Services/Scheduling/Schedule/ScheduleAuthorizationService.php`: focused ownership resolution.
- `app/Http/Controllers/Academic/CurriculumImportController.php`: ambiguous-target refusal.
- `app/Http/Controllers/Rooms/RoomRequestController.php`: legacy requester cancellation check.
- `app/Services/RoomRecoveryService.php`: persisted-row checks for preview and transfer.
- `app/Services/Scheduling/History/ScheduleReuseService.php`: source and destination authorization.
- `app/Services/Scheduling/History/ScheduleHistorySummaries.php`: compact immutable ownership facts.
- `app/Http/Controllers/Scheduling/ScheduleHistoryController.php`: current per-entry reuse flags.
- `routes/api.php`: updated the reassignment authorization comment; endpoint behavior is unchanged.
- `tests/Feature/ProgramHeadManagedOperationsTest.php`, `tests/Feature/ScheduleHistoryReuseTest.php`:
  office matrix, direct requests, legacy ownership, dynamic assignments, and other-role regressions.
- This report and `docs/program-head-managed-office-access-2026-11-24.md`.

Frontend:

- `src/pages/curriculum/CurriculumImportPage.tsx`, `CurriculumListPage.tsx`, and
  `src/components/curriculum/import/ImportCurriculumDetailsModal.tsx`: import entry/target restrictions.
- `src/pages/shared/RoomRequests.tsx`: request entry restrictions.
- `src/components/rooms/RoomReassignmentPanel.tsx`: server-owned reassignment controls.
- `src/pages/vpaa/ScheduleHistory.tsx`: per-entry reuse flags and dynamic invalidation; this page is
  shared across roles despite its directory name.
- Corresponding import/list/request/history tests; existing hook/reassignment tests were also run.
- Relevant import, room-request, schedule-history, and dashboard documentation.

## Verification

Tests cover both Secretary and Director: no heads, one head, multiple heads, mixed assignments,
assignment, deactivation, reactivation, archival, positive unheaded writes, negative headed writes,
null imports, legacy schedule ownership, shared/archived course fallback, both reassignment submission
routes, atomic mixed history rejection, Program Head isolation, and unchanged Dean/VPAA review.

Automated commands and results:

```powershell
# Backend: focused matrix plus import, history, requests, recovery, and Director parity regressions.
php artisan test --compact --stop-on-failure `
  tests/Feature/ProgramHeadManagedOperationsTest.php `
  tests/Feature/ScheduleHistoryReuseTest.php `
  tests/Feature/ScheduleHistoryTest.php `
  tests/Feature/RoomRecoveryTest.php `
  tests/Feature/RoomRequestTest.php `
  tests/Feature/CurriculumImportTest.php `
  tests/Feature/DirectorParity/DirectorRoomRecoveryTest.php `
  tests/Feature/DirectorParity/DirectorRoomRequestTest.php `
  tests/Feature/DirectorParity/DirectorCurriculumImportTest.php

# Frontend: live permissions and the four workflows.
npx vitest run --maxWorkers=1 --testTimeout=20000 `
  src/pages/curriculum/CurriculumImportPage.test.tsx `
  src/pages/curriculum/CurriculumListPage.test.tsx `
  src/pages/shared/RoomRequests.test.tsx `
  src/pages/vpaa/ScheduleHistory.test.tsx `
  src/components/rooms/RoomReassignmentPanel.test.tsx `
  src/hooks/useProgramAccess.test.tsx `
  src/lib/currentUser.test.ts `
  src/lib/storedUser.programAccess.test.ts

npm run build -- --outDir node_modules/.cache/program-access-build
```

- Backend: **393 tests passed, 4,914 assertions** (89.48 seconds), including the final independent
  recorded/current curriculum ownership checks. The focused office/reuse tests are included in this
  total; they are not additional tests to add to it.
- Frontend: **80 tests passed**, 8 files. An earlier run with parallel workers alongside the build
  exceeded test timeouts and caused follow-on failures; the final run above passed with one worker
  and no changes to source test timeouts.
- TypeScript and production build: passed; output was isolated under ignored `node_modules/.cache`
  so tracked deployment artifacts were not changed.
- Scoped ESLint: zero errors, two existing `CurriculumListPage.tsx` warnings (archive-confirmation
  dependency and TanStack compiler compatibility). Unrelated table/archive behavior was preserved.
- Pint on the seven PHP implementation and two test files listed above: passed. A separate check of
  the comment-only `routes/api.php` edit reports existing import-order / fully-qualified strict-type
  formatting issues; those unrelated regions were not reformatted. Both repositories' `git diff --check`
  passed (frontend reports existing line-ending normalization notices).

The configured CAS controller/service probes returned 403 for BAS and BSCD Room Requests, import
validation and import commit; BAS reassignment; an unscoped import; and reuse of approved history 924.
All 12 returned CAS history entries had `can_reuse: false`. Schedule, curriculum, room-request, and
import counts remained unchanged. BSCD has no current schedule row, so its reassignment rejection
was verified by the API feature-test matrix rather than by mutating or inventing live CAS data.
These live probes called controllers/services with the actual Secretary and database configuration;
the automated feature tests exercised HTTP API routes. No authenticated browser walkthrough was run.
