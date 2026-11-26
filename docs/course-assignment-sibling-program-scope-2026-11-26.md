# Sociology / BSCD instructor assignment review (2026-11-26)

Sociology (BAS) and BSCD are programs of CAS. A Sociology instructor may teach a
Sociology course in a BSCD section, but that does not transfer ownership of the
section or authorize assignment of its other courses.

## Findings and fixes

| Priority | Finding | Resolution |
| --- | --- | --- |
| P1 | The local Assign Instructors modal filtered by department only. BAS and BSCD share CAS, so readable BSCD classes could enter Sociology's local assignment list. | Filter local candidates by the offering section's program and current instructor visibility. Cross-program teaching stays in Cross-Department. |
| P2 | Cross-Department compared the teaching program with the course's owner. A Sociology-owned course taught by Sociology in a BSCD section therefore looked local. The list and bulk modal also duplicated this rule. | Preserve the offering program in mapped schedules and use one shared classification helper in the list, grid, and bulk candidates. Compare teaching responsibility with the section's program. |
| Verified | Unrelated BSCD courses must remain unavailable to Sociology, including direct and mixed batch requests. | Retain the existing server visibility and authorization policy. Add a feature regression proving course-level access and atomic rejection of a mixed request. |

The section's `program_id` takes precedence over the schedule's recorded program.
If both are absent, cross-department classification retains its legacy course-owner
fallback. The local Program Head list requires an identified own-program section.
Returned classes continue to use their section-level teaching assignment. Existing
semester, approval, instructor eligibility, and conflict rules remain in force.

## Configuration and verification limits

Read-only inspection of the configured database confirmed BAS program 8 and BSCD
program 9, with active Program Heads, under CAS department 1. The active semester
contained BAS schedules and no BSCD schedules. SCC courses currently have no owning
program or explicit teaching assignment, so the screenshot's exact state could not
be reproduced from that database. A course with no program must receive an explicit
Sociology teaching assignment before Sociology can staff it in a BSCD section;
course names are not used to guess program ownership. No database records were changed.

Regression coverage includes explicit Sociology assignments, majors taught by their
owning program, courses with no owning program, BAS local classes, BSCD courses,
stale schedule ownership, returned classes, List/Grid consistency, and bulk candidates.

Validation: 68 focused frontend tests passed; the two affected scope/rendering
suites were rerun after the last logic change (28 tests passed). The final backend
visibility and cross-department run passed 72 tests with 554 assertions. TypeScript
and the Vite production build passed. Scoped Pint and both repository diff checks
passed. Scoped ESLint found one pre-existing `react-hooks/set-state-in-effect` error
in the unchanged deep-link effect in `InstructorAssignment.tsx`; the other checked
files had no errors. Generated build changes were removed from the working tree.
Live browser verification of the screenshot state and deployment were not performed.
