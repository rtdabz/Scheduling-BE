# Course Assignment: Program Head access audit (2026-11-15)

## Symptom

A Program Head's schedule is approved by the VPAA, but Course Assignment
(`/program_head/course-assignments`, API `course-teaching-assignments`) stays locked
with *"Cross-Department assignments will be available once all schedules are approved
by the VPAA."* It opens only after **every** Program Head in the department has
finished, so one program that is late blocks all the others.

## How access is decided (before this change)

| Layer | Check |
|---|---|
| Route | `capability:schedule.assign_instructor_cross_department` (`routes/api.php`). Unchanged. |
| Approval gate | `CrossDepartmentApprovalGate::isOpen($departmentId)` derives each **active section of the whole department** in the active semester and opens only when all are `approved` (`approved`, `faculty_assignment`, `reassignment`, `finalized`). |
| API flag | `cross_department_unlocked` in `GET /departments/{id}/schedule-status` (used by the frontend route guard `CrossDepartmentApprovalRoute` and the sidebar lock) and in `GET /course-teaching-assignments`. |
| Writes | `update`, `batch`, `destroy` and `batchDestroy` call `approvalGate->refusal($departmentId)` and return 422 `cross_department_awaiting_vpaa_approval`. |
| Program scope | The **listing** is scoped to the Program Head's program curricula (plus shared curricula). The **writes** are not scoped at all. |

Submission is per program: `submitSchedules` limits a Program Head to
`writableProgramIds()` (their own program), and the VPAA approves each submission on
its own. A department-wide gate therefore cannot match the per-program workflow.

## Findings, by priority

| # | Priority | Location | Problem | Status |
|---|---|---|---|---|
| 1 | **P1, root cause** | `CrossDepartmentApprovalGate::isOpen` / `refusal` | The gate is department-wide. A Program Head whose program is fully VPAA-approved stays locked while a sibling program is in Draft, submitted or Dean review. | **Fixed** |
| 2 | **P1** | `DepartmentScheduleController::scheduleStatus` (`cross_department_unlocked`) | It computes the same department-wide flag, and the frontend guard and sidebar read this value. Fixing only the API writes would leave the page locked in the UI. | **Fixed** |
| 3 | **P1, needed by #1** | `CourseTeachingAssignmentController` writes | The writes have no program boundary. Once access opens per program, an approved Program Head could change a **sibling program's** course whose own schedule is still awaiting approval, which bypasses that program's gate. The listing already hides those courses, but the API accepts them. | **Fixed** (sibling programs only, see "Scope rule") |
| 4 | P2, decision needed | `InstructorAssignmentController::index` → `approvalGate->awaitingApproval()` (uncommitted work in progress) | Cross-Department hides a source college's delegated classes until the **whole** source college is VPAA-approved. A course that an approved Program Head delegates is saved, but the receiving college does not see its classes until every sibling program of the source college is approved too. Making this per source program would match #1. It was **not changed**: it is a separate function, its comment ties it to "the same gate", and the per-row filter (`VISIBLE_STATUSES` = VPAA-approved rows only) already keeps unapproved classes hidden. | Open |
| 5 | P2 | `CrossDepartmentApprovalGate::isOpenFor` for the Secretary and Director | The department office keeps the department-wide rule. The office manages programs without a Program Head (`writableProgramIdsFor`), so a per-program rule for the office would have to cover several programs. Kept as it was, so existing behaviour is preserved. | Open (by design for now) |
| 6 | P3 | Program Head without `program_id` | Such an account keeps the department-wide gate and gets no write scope (the same as before). `submitSchedules` already refuses these accounts, so they cannot reach an approved state on their own. A data-integrity follow-up could refuse them here as well. | Open |
| 7 | P3 | `RoomRequestController` (room borrowing) | Uses `isOpen()` department-wide. Not related to Course Assignment and not changed. | Keep |

## Fix applied

1. **Gate (`CrossDepartmentApprovalGate`)**
   - New `isOpenFor(User)`. For a Program Head with a program, the gate opens when every
     **active section of their program** in the active semester is VPAA-approved. If
     their program has **no** active sections this semester, there is nothing of its own
     to approve, and the department-wide rule applies (the previous behaviour).
   - `refusal()` now takes the acting `User` and uses `isOpenFor()`.
   - `isOpen($departmentId)` is unchanged, so room borrowing, `awaitingApproval()`,
     `awaitingReapproval()` and the department office behave as before.
2. **Flags.** `GET /course-teaching-assignments` and `GET /departments/{id}/schedule-status`
   (for a Program Head of that department) report `cross_department_unlocked` from
   `isOpenFor()`. No frontend change is needed: the guard and sidebar already read the
   flag.
3. **Scope rule (writes).** A Program Head receives **403** with
   `CourseTeachingAssignmentController::OUTSIDE_PROGRAM_MESSAGE` and
   `outside_program_course_ids` when a course belongs only to a **sibling program** of
   their department. "Belongs to a sibling" means the course is placed in a sibling
   program's active curriculum, or `courses.program_id` is a sibling program, **and** it
   is not in the head's own or shared active curricula and not owned by the head's own
   program. The following stay allowed, as before:
   - courses in the department's shared curricula (`program_id` null);
   - courses that belong to another college ("decision 3" in `CourseTeachingAssignmentTest`:
     any college may record who teaches a delegable course);
   - existing restrictions: major delegation rules, the instructor-assigned lock and
     the capability middleware.

The submission, Dean and VPAA approval endpoints, recall, and the scheduling functions
were not changed.

## Tests

- New: `tests/Feature/ProgramHeadCourseAssignmentAccessTest.php`. It covers:
  - an approved program opens access while a sibling program is pending;
  - the pending Program Head stays locked;
  - one pending section of the head's own program keeps access locked;
  - access closes again when the program's schedule is returned;
  - a program with no sections falls back to the department rule;
  - the office keeps the department-wide rule;
  - sibling-program courses are refused on all four write endpoints;
  - shared courses stay manageable.
- Existing suites `CrossDepartmentApprovalGateTest`, `CourseTeachingAssignmentTest`,
  `CrossDepartmentInstructorAssignmentTest` and `DepartmentProgramModuleLockTest` pass
  unchanged.

## Note for the frontend

`useDepartmentScheduleStatus` caches by department id
(`department-schedule-status:{id}`). The response is now user-specific for Program
Heads. The cache is cleared on login and logout (`clearDataCache`), so different users
on one browser do not share it.
