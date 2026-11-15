# Course Assignment: own-program restriction audit (2026-11-15)

Related: [course-assignment-access-audit-2026-11-15.md](course-assignment-access-audit-2026-11-15.md)
covers *when* Course Assignment opens (the VPAA approval gate) and *which courses* a
Program Head may manage. This document covers the **target**: which college or program
a course may be assigned to.

## Required behaviour

The rule applies to Program Heads and to the department office (Secretary, Director).

| Department has | Rule |
|---|---|
| One program | The user's own department is not offered as a target at all. Its courses are managed inside the department, not through Course Assignment. |
| Two or more programs | The user's own program can never be a target, however many programs there are. Other programs of the department, and every other college, stay available. |

The same checks run in the frontend and the backend, so the restriction cannot be
bypassed with a direct API call.

**Scope.** The rule covers the user's **own department's courses**: courses whose
`department_id` is the user's department, or unowned courses (`department_id` null)
placed in one of its active curricula. These are the courses the Course Assignment
page lists. Claiming *another* college's course for your own college ("decision 3" in
`CourseTeachingAssignmentTest`: any college may record that it teaches a delegable
course) is a separate, existing workflow and is not affected. Without this scope, a
one-program college such as CAS could no longer claim a course for itself.

## How the user's own program is found

The user's own program is resolved on every request from `ScheduleAuthorizationService::writableProgramIdsFor()`,
which already defines program ownership for scheduling:

| Role | Own program(s) |
|---|---|
| Program Head | `users.program_id` (only when it belongs to the user's department). |
| Secretary, Director | Every program of the department **without an active Program Head**. Office accounts never carry a `program_id` (`Admin/UserController` sets it to null), so this is the only available source. |

This set changes as Program Heads are added or deactivated, so nothing is hard-coded.

## How the existing logic behaved (before this change)

| Layer | Behaviour |
|---|---|
| `CourseTeachingAssignmentController::update` / `batch` | Accepted any department and any program as the target, **including the user's own program and own department**. The only target rule was `SchedulingPolicy::majorDelegationRefusal`: a *major* sent to its own college needs some program, but that program could be the course's own program. |
| `index` | Returned every department and every program; nothing told the client which targets are restricted. |
| Frontend `CourseTeachingAssignments.tsx` | Listed every college (own one marked "Yours") and every program of the selected college. The only own-program check was the status `Already its program`, for majors only, and it compared against the *course's* program, not the user's. |
| Existing data | `GEE 9` (course 199, department 7, which has one program) is assigned to its own department. Courses 393 and 394 (`GEC 1`, `GEC 2`) were assigned by BSIT's head to sibling program 11, which is valid. |

## Findings, by priority

| # | Priority | Location | Problem | Status |
|---|---|---|---|---|
| 1 | **P1** | `CourseTeachingAssignmentController::update`, `batch` | The backend accepts the user's own program as the target. Any user with the capability can do this with a direct API call. | **Fixed** |
| 2 | **P1** | Same | In a one-program department, the backend accepts the user's own department as the target. | **Fixed** |
| 3 | **P1** | Same | In a multi-program department, a target of "own department, any program" is accepted. That leaves the course with the department, including the user's own program, so it gets around rule #1. A specific sibling program is now required. A major sent to its own college keeps the existing message (`majorDelegationRefusal` runs first). | **Fixed** |
| 4 | **P1** | `index` | The client is not told which targets are restricted, so the frontend cannot apply the rule. The response now includes `assignment_restrictions`. | **Fixed** |
| 5 | **P2** | Frontend `CourseTeachingAssignments.tsx` | Own department or own program is offered as a target, and its courses show as `Available`. | **Fixed** |
| 6 | P2 | Program Head without a `program_id` | Their own program cannot be identified. Their own department is treated as excluded (fail closed) instead of being left open. | **Fixed** |
| 7 | P2 | Office user who manages **every** program of a multi-program department | Every program of their own department is their own, so their own department is excluded as in the one-program case. | **Fixed** |
| 8 | P3 | Existing data: course 199 `GEE 9` → own department 7 | It was saved before the rule existed. It is not changed automatically: removing it (`DELETE`, "Remove assignment") still works, while saving it again to the same target is refused. A registrar or secretary should review it. | Open (data review) |
| 9 | P3 | Users without the rule (VPAA, any other role that holds the capability) | `assignment_restrictions` is `null` and nothing changes for them. These roles have no own program. | By design |

## What did not change

- Route capability `schedule.assign_instructor_cross_department` and the VPAA approval
  gate (`CrossDepartmentApprovalGate`).
- The rule that a Program Head may not manage a sibling program's courses
  (`refuseOutsideProgram`, finding 3 of the related audit).
- Major delegation rules, the instructor-assigned lock, notifications and the recorded
  source program.
- Removing an assignment (`destroy`, `batchDestroy`). Moving a course *away from* a
  restricted target is always allowed.

## Implementation

- **Backend.** `CourseTeachingAssignmentController::targetRestrictions(User)` is the
  single source of the rule. It returns `department_id`, `own_program_ids`,
  `own_department_excluded` and `requires_program`. `ownTargetRefusal()` uses it in
  `update` and `batch` (422, with `reason` set to `own_department`,
  `own_department_program_required` or `own_program`). The same data is returned to the
  client as `assignment_restrictions` in `GET /course-teaching-assignments`.
- **Frontend.** `CourseTeachingAssignments.tsx` reads `assignment_restrictions`. It
  hides an excluded own department, removes own programs from the program list, replaces
  "Any Program" with a required choice inside the user's own department, and leaves
  courses unselectable until a valid target is chosen. The backend still checks every
  request.

## Tests

- New: `tests/Feature/CourseAssignmentOwnProgramRestrictionTest.php`
  - one-program department: the Program Head and the Secretary are refused their own department (single and batch, and through a program id);
  - multi-program department: the Program Head is refused their own program and allowed a sibling program;
  - a department with five programs still refuses the Program Head's own program;
  - "own department, any program" is refused;
  - the Secretary is refused programs the office manages and allowed a program that has a Program Head;
  - a program whose Program Head is deactivated becomes the office's own program, so the rule follows changes;
  - a Program Head without a program is refused their own department;
  - other colleges stay allowed, and removing an assignment still works;
  - `assignment_restrictions` reports the rule, and is `null` for the VPAA.
- Frontend: `CourseTeachingAssignments.test.tsx` covers the hidden own department and the filtered program list.
