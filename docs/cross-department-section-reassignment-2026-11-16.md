# Returned sections: section-level reassignment (2026-11-16)

Follow-up to `cross-department-course-assignment-audit-2026-11-16.md`. That change let a
receiving department return the sections it cannot staff. A returned section went back to
the originating department for good. This change makes a returned section **reusable**:
the originating department can hand that one section to another eligible department through
Course Assignment, while every other section keeps following the course-level assignment.

## How it worked before this change

| Concept | Where | Behaviour |
|---|---|---|
| Course-level delegation | `courses.teaching_department_id` / `teaching_program_id` | Applies to every class of the course, in every department. |
| Returned class | `schedules.delegation_returned_from_department_id` (+ `_at`) | Counted only while it equalled the course's **current** `teaching_department_id`. The class then fell back to the offering department's own rules. |
| Clearing returns | `CourseTeachingAssignmentController::clearReturnMarks` | Any change of the course's teaching department wiped every return mark of the course. |
| Course Assignment lock | `classesWithInstructor` | Counts **every** class of the course with a live instructor. |
| Per-class rule | `SchedulingPolicy::courseForRow` | A returned class gets a copy of the course with the teaching fields cleared. |

## Findings, by priority

| # | Priority | Location | Problem | Status |
|---|---|---|---|---|
| 1 | **P1** | Course Assignment (`batch`, `update`) | There is no section-level assignment. A returned section can only be staffed by the originating department. Course Assignment works per course, and the course is already "assigned", so the section cannot be offered to anyone else. | **Fixed:** section-level assignment for returned sections. |
| 2 | **P1** | `clearReturnMarks` on any course-level change | Moving or clearing the course's delegation **re-attaches every returned section** to the new teaching department. A section returned by B silently goes to D with the rest. This breaks section-level control: "only the explicitly returned section" changes, and nothing else. | **Fixed:** a returned section is detached from the course-level delegation until the owner reassigns it, and course-level changes no longer touch it. |
| 3 | **P1** | `classesWithInstructor` / `refuseIfInstructorAssigned` | The course lock counts every staffed class. A returned section staffed by the owner, or later by another department, would **lock the whole course**, so the owner could not move the remaining sections. | **Fixed:** the course lock counts only classes that follow the course-level delegation. A detached section has its own lock: it can be reassigned only while it has no instructor. |
| 4 | P2 | `SchedulingPolicy::isDelegationReturned` | "Returned" was defined as "equals the current course teaching department", so its meaning changed whenever the course changed (the root of #2). | **Fixed:** a non-null `delegation_returned_from_department_id` now means "detached". |
| 5 | P2 | `InitialDataController` course scope, `CourseTeachingAssignmentController` incoming list | The receiving side finds delegated courses only through `courses.teaching_department_id`. A department that receives a single section would not get the course in its scheduler payload or its incoming list. | **Fixed.** |
| 6 | P2 | Per-class SQL checks (`Schedule::scopeDelegatedTo`, the assignable check, the "keep done" rules, pending counts, program-head filters) | They read only the course-level fields. | **Fixed:** they read a per-class effective teaching department. |
| 7 | P3 | Section-level completion notice | "Cross-department assignments completed" is computed per course-level delegation. A department staffing a single section sends no completion notice. | Open. The per-assignment notices still go out. |
| 8 | P3 | Assigned-courses summary (Course Assignment) | It groups by the course-level teaching department. A section handed to another department appears in the new *Returned sections* panel and on its chip, but not under that department's heading. | Open. |

## Model after this change

Per class (every meeting row of one section's course carries the same values):

| Column | Meaning |
|---|---|
| `delegation_returned_from_department_id`, `delegation_returned_at` | Set by **Return**: the department that last returned the class. **Non-null means the class is detached** from the course-level delegation for good. |
| `section_teaching_department_id`, `section_teaching_program_id`, `section_teaching_assigned_at` (new) | Set by Course Assignment for a detached class: who teaches **this section**. Null means the offering department teaches it. |

Effective teaching assignment of a class (`SchedulingPolicy::courseForRow`):

1. Not detached: the course-level fields (unchanged behaviour).
2. Detached: `section_teaching_department_id` and `section_teaching_program_id`, which may be
   null for "the offering department's own rules".

Every existing rule (offering department, majors, Program Head program, instructor
eligibility) then runs unchanged on that effective course.

Duplicate assignment is impossible by construction. A class has exactly one effective
teaching department: one column, set under a row lock, and the course-level fields no
longer apply to it.

## Workflow

1. **Return.** The receiving department returns unstaffed sections. This works for
   course-level and section-level receivers alike. The class is detached and any
   section-level target is cleared. Staffed sections are refused (unchanged).
2. **Restore.** The class appears in the originating department's Course Assignment under
   *Returned sections* as available.
3. **Reassign.** `POST /course-teaching-assignments/sections` with
   `{schedule_ids, teaching_department_id, teaching_program_id?}` hands the class to an
   eligible department or program. Program Heads may use it for their own program's
   sections only. `POST /course-teaching-assignments/sections/remove` takes the class back.
   Both are refused when the class:
   - is not detached (use course-level Course Assignment instead),
   - has a live instructor, is marked done, or is not in an instructor-assignable status,
   - or fails the existing rules: approval gate, own-department/program targets, major
     delegation, Program Head scope.
4. The new receiver sees the class in Cross-Department and staffs it under the normal rules,
   or returns it again, which repeats the cycle.

Unaffected: other sections of the course, their instructors, course-level assignments,
permissions and the approval workflow.
