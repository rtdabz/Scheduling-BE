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
