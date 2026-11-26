# Instructor program assignment

How an instructor's `faculties.program_id` is set, what NULL means, and every place that depends on it.
Reviewed 2026-11-15.

## Rule

| Department has | Add/Edit Instructor form | Saved `program_id` |
| --- | --- | --- |
| No program | Only "No Specific Program" | NULL |
| One program | That program, selected and locked | That program, filled in by `Faculty::saving` even when NULL is submitted |
| Several programs | A program, or "No Specific Program" | The chosen id, or NULL |

`department_id` is always required: the DB column is `NOT NULL`, instructor create requires it, instructor edit uses `sometimes|required`, and user accounts (which create linked profiles) require it. Leaving the program empty never empties the department.

**NULL means the instructor belongs to the department, not to every program.** It does not grant access across programs or across departments:

- A NULL-program instructor **can** teach courses that require no specific program, in sections of **any program of their own department**: department majors with no program, GE/minor courses, and service courses delegated to the department.
- The department checks (`major_faculty_department_alignment`, `service_subject_faculty_department_alignment`) run before and independently of the program check, so NULL never lets an instructor teach outside their department.
- A NULL-program instructor **cannot** teach courses tied to a program (`teaching_program_id`, or a major's `program_id`). `(int) null` is `0`, which never matches.
- Program Heads only see and assign instructors of their own program. They only assign program-tied courses, so a NULL-program instructor would be ineligible for those courses anyway.

## Course Assignment visibility (2026-11-25)

`ProgramCourseVisibility` applies a semester-specific selection rule to courses handed from
program A to a different program B. The recorded `teaching_source_program_id` identifies A,
falling back to the course's `program_id` for older assignments. Both programs must have an
active, non-archived Program Head account belonging to their department. If either has no such
head, existing visibility is retained. Department-only assignments, same-program assignments,
and unresolved source programs retain their existing behavior.

When both programs have heads, the source department office (Secretary or Director) and source
Program Head no longer receive the course as a course-list or scheduling selection. Program
Heads' course lists are also scoped to their program's courses/curricula and received assignments.
The receiving Program Head retains instructor access to the existing source timetable. The
Course Assignment list and assignment/removal process remain available under their existing checks.

The backend filters course and instructor payloads and rejects hidden selections through manual,
batch, generation, and reuse paths. Existing duplicate, class, and conflict checks remain in force.
Existing timetable rows and conflict occupancy remain intact; ordinary moves of retained rows
and legitimate classes in the receiving program keep their previous validation.

Initial data returns excluded courses needed by retained rows as `schedule_course_references`.
These are read-only lookups (`delegated_only = true`), never course-bank/generation options.
Schedules carry `instructor_assignment_visible`; returned classes retain their independent
section-level instructor assignment. Other programs' shared-room rows remain conflict inputs,
with instructor selection hidden from unrelated Program Heads. Access decisions and course pagination run after the payload
cache so head changes take effect without retaining a cached permission decision. Frontend user
and assignment events refresh the relevant lists and caches are scoped by program.

Regression coverage: `tests/Feature/ProgramDelegatedCourseVisibilityTest.php`, frontend initial-data
mapping, assignment modal, scheduler hooks, and existing Course Assignment/reuse/initial-data tests.

## Receiving-program instructor access (2026-11-26)

Cross-Department staffing follows each schedule's persisted semester-specific receiving program,
including an independently returned section's `section_teaching_*` assignment. A Program Head
receives only their program's assigned classes. A Secretary or Director receives program-specific
assignments only for programs their office may manage (no active Program Head), using the existing
`writableProgramIds` policy. Department-only delegations retain their existing office access.
This receiving check does not require a source program or source Program Head; the two-head
exception above remains a course-list/scheduling-selection rule, not permission to staff another
program's delegated classes.

The instructor list, offering departments, pending badge, single/bulk assignment, clearing,
recommendations and returns use this same access check. Initial data retains conflict/timetable
facts with `instructor_assignment_visible = false`; the UI excludes them from delegated choices
and bulk actions. Actor-scoped caches and user/assignment refresh events prevent reuse of another
program's pending count or stale selections. Course Assignment itself and conflict checks are unchanged.

Verified locally on 2026-11-26: 203 backend tests (1,314 assertions), including the receiving
visibility cases; 53 frontend tests, run with one worker and a 15-second timeout after host-load
timeouts at the default limit. Playwright used the real localhost frontend/API with an isolated
SQLite fixture: the CAS Secretary and Sociology Program Head saw no ComDev delegation and their
direct assignment requests returned 403; the ComDev Program Head saw GEC 1 and assigned its
instructor through the UI. Temporary servers, credentials and fixture database were removed.
TypeScript and the production build passed; scoped Pint and diff checks passed. ESLint comparison
found no new errors; eight existing errors remain in the affected frontend files.
Production deployment and live verification remain outstanding.

## Dependency map

| # | Area | File | Status |
| --- | --- | --- | --- |
| 1 | DB column | `database/migrations/2026_09_26_100006_create_faculty_tables.php` | Already `nullable()`, `nullOnDelete`. No migration needed. |
| 2 | Sole-program auto-fill | `app/Models/Faculty.php` (`saving` hook, `soleProgramIdOf`) | OK |
| 3 | Backfill of sole-program departments | `database/migrations/2026_11_14_050000_backfill_faculty_sole_program.php` | OK. Only touches departments with exactly one program. |
| 4 | Create/update validation | `app/Http/Controllers/Faculty/FacultyController.php` `programRule()` | OK: `nullable`, and the program must belong to the instructor's department |
| 5 | Department change on update | `FacultyController::update` | **Fixed.** A program left over from the old department used to be kept. |
| 6 | Account → instructor profile sync | `app/Services/UserFacultyProfileService.php` `sync()` | **Fixed.** Every account save used to wipe the instructor's program. |
| 7 | Account creation | `UserFacultyProfileService::createFor` | OK: copies the account's program (set only for Program Heads, NULL otherwise, then auto-filled) |
| 8 | User role validation | `app/Http/Requests/User/{Store,Update}UserRequest.php` | OK. A program is required only for the Program Head *role*; this is unrelated to the instructor's program. |
| 9 | Scheduling rule engine | `app/Services/Scheduling/Engine/Rules/DepartmentAssignmentRule.php` | OK: enforces programs only when the course requires one |
| 10 | Manual instructor assignment | `app/Http/Controllers/Faculty/InstructorAssignmentController.php` | OK: same check, plus Program Head scoping |
| 11 | Recommendation/CSP snapshot | `app/Services/Scheduling/Support/SchedulingSnapshotRepository.php` | OK: passes NULL through |
| 12 | Faculty list / load scoping | `app/Services/FacultyLoadService.php`, `FacultyController::index` / `guardDepartment` | OK: Program Heads see their own program only |
| 13 | Reports | `FacultyLoadService::getForReport`, `ReportsController` | OK: a NULL-program instructor appears in a program report when teaching that program's sections |
| 14 | Frontend form | `wicars-ui/src/pages/shared/Faculty.tsx` | OK. The option was relabelled "No Specific Program" and the hint now explains it. |
| 15 | Frontend eligibility | `wicars-ui/src/pages/ClassSchedules/SchedulerPanel/facultyEligibility.ts` | OK: mirrors #9 |

## Priority (what to fix first)

1. **High: account sync wiped the instructor's program (#6). Fixed.**
   `sync()` runs on Admin user edit, account status toggle, archive restore, and **the user editing their own profile**. It copied `users.program_id` onto the instructor profile, and that value is NULL for every Dean, Secretary and Director account. Any such account that teaches a program's majors lost that program and became ineligible for those courses.
   Now a Program Head's program is still applied (it is authoritative). Other accounts keep the instructor's own program, which is cleared only when the department changes.
2. **Medium: department change kept the old program (#5). Fixed.**
   A VPAA could move an instructor to another department without sending `program_id`, which left a program belonging to a different department. Now `program_id` is reset to NULL in that case, and the model picks the new department's program if it has only one. The UI always sends `program_id`, so this protects other API callers.
3. **Low: label (#14). Done.**
4. **Low: open, not fixed.** When editing an instructor whose program was archived, the select shows "No Specific Program" (the archived id is not an option) but still submits the archived id, so the save fails validation. The workaround is to pick a program or "No Specific Program" explicitly. Fixing it means deciding whether to clear the program silently, which changes the stored record, so the fix was left out of this change.

## Tests

`tests/Feature/InstructorManagementTest.php`:

- `test_department_stays_required_when_the_program_is_left_empty`
- `test_an_instructor_may_have_no_specific_program_when_the_department_has_several`
- `test_the_only_program_of_a_department_is_selected_automatically`
- `test_moving_an_instructor_to_another_department_drops_the_old_program`
- `test_account_sync_keeps_the_instructor_program_of_a_non_program_head`
- `test_account_sync_applies_a_program_heads_program`

Eligibility for NULL-program instructors is already covered in `tests/Feature/MajorInstructorRestrictionTest.php`. Its fixture uses NULL-program instructors in a BSIT section:

- `test_a_major_accepts_an_instructor_of_its_own_department`: same department, NULL program → allowed.
- `test_a_major_refuses_an_instructor_from_another_department`: other department, NULL program → refused (NULL does not bypass the department).
- `test_a_program_bound_major_refuses_an_instructor_with_no_program`: a program-tied course still requires that program.
- `test_a_minor_course_is_unaffected_by_the_program_restriction`.
