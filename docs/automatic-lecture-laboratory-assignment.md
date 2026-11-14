# Automatic lecture and laboratory assignment

Resolution plan recorded 2026-11-12 before implementation.

## Evaluation

1. Curriculum placement already provides authoritative lecture/laboratory minutes through `SchedulingPolicy::placedCourse()` and frontend `placeCourse()`. The existing component-slot helpers preserve imported contact minutes and fall back to course hours; scheduling must not convert laboratory credits a second time.
2. `GenerationCourseSelection`, `CourseScheduleRequirementBuilder`, and `CspSolver` currently require explicit Integrated selection and a major-course classification to create separate components. Regular mixed courses otherwise become a single meeting containing both components' time.
3. Generate Schedule exposes a Class Component selector that does not reliably determine the saved configuration. Manual Plotting opens a mixed course as a single meeting even though the existing Integrated path supports two linked components.
4. Backend `SchedulingPolicy::labRoomTypes()` and frontend `labRoomPolicy` accept legacy Classroom/Either laboratory settings. Manual room defaults additionally fall back from compatible rooms to any physical room. These paths violate the requested laboratory-only invariant.
5. The generator and manual validators already represent laboratory Room TBA as an on-site meeting with a null room. Existing room access, availability, capacity, instructor, section, operating-hour, duration, and conflict constraints should continue to apply. Recommendations must also be able to discover lab placements with Room TBA.

## Resolution

1. Infer Regular course components from the selected curriculum's component slots: positive lecture and laboratory time creates two linked meetings, laboratory-only creates one laboratory meeting, and no laboratory time creates lecture-only. Apply this to all regular course categories; preserve explicit Field, Split Session, Consecutive Days, and existing Integrated/Hybrid choices and their constraints.
2. Reuse the existing Integrated meeting representation for automatic mixed courses. Default both components to on-site; preserve explicitly selected Hybrid delivery. Use the same curriculum minute helpers for component detection and duration allocation in generation and plotting.
3. Enforce laboratory rooms for laboratory components in the shared server and browser policies, regardless of a legacy Classroom/Either setting. Continue applying room ownership, sharing, availability, and conflict validation. Lecture components use the existing lecture rules.
4. Use the existing null-room/on-site TBA representation when no compatible laboratory can be assigned. Never widen laboratory room candidates to lecture classrooms or online delivery. Manual defaults and placement discovery must follow the same rule.
5. Remove manual Class Component selection for Regular courses and show inferred components and durations. Default Manual Plotting to the same linked lecture/laboratory layout. Keep editing and save payloads consistent with the existing API.
6. Add focused regression coverage for unconfigured mixed courses, category independence, curriculum-specific minutes, lab-only/lecture-only courses, strict room eligibility under legacy settings, missing/busy labs and TBA, and generation/manual parity. Run existing scheduling constraint tests and frontend type/build checks relevant to these changes.

## Scope and compatibility

No schema migration or automatic rewrite of existing schedules is planned. Existing schedules remain reviewable/editable; new generation and plotting enforce the new rules. Preserve unrelated work already present in both checkouts. Update the frontend business rules and this workflow's verification record after implementation.

## Implementation (2026-11-12)

- `SchedulingPolicy::hasLectureAndLaboratoryComponents()` is the single eligibility rule (LEC > 0, LAB > 0, not field, any category); `labRoomTypes()` is always `['laboratory']` and `isLabClassroomFallback()` is always false.
- `GenerationCourseSelection::resolve()` adds every eligible course to `selected_split_session_course_ids` with delivery `on-site`, unless it has an explicit Split Session, Consecutive Days rule, course-wide Required Day, or online/field delivery. Requested courses keep an explicit Hybrid. The year-level controller now takes delivery modes from the resolved selection.
- `CourseScheduleRequirementBuilder`, `CspSolver` and `ValidateGenerationConfiguration` no longer require a Major for lecture/laboratory components. Hybrid (online lecture) stays Major-only (`DeliveryModeRule`).
- Retry strategies `clear_bottleneck_split` and `disable_section_hybrid` no longer offer collapsing a lecture/laboratory course into one block; the single-Preferred-Day block explains the two-day need.
- `AvailableSlotFinder` lists on-site Room TBA for a laboratory meeting at times no laboratory room is free, when the caller opts in (`offerRoomTba`: manual slot list and group discovery). Room transfers and session enhancements do not.
- Frontend: lab policy mirrors the backend; the Default LAB Room Requirement selector and Class Component chips are replaced by read-only explanations; Setup Courses seeds eligible courses as Integrated On-site (Regular/Split not offered); Manual Plotting opens them as the linked laboratory + lecture pair with a laboratory room or Room TBA, and never falls back to another room type for a laboratory meeting.

Known limits: a course-wide Required Day keeps one meeting (lecture and laboratory need two days). Existing schedules are not rewritten. A department with lecture/laboratory courses but no lecture room now gets a generation bottleneck (with an Integrated Hybrid suggestion for majors) instead of placing the lecture in a laboratory.

## Follow-up (2026-11-12): no Split Session for laboratory courses, Default Settings removed

- `SchedulingPolicy::balancedSplitEligible()` (and the browser's `isBalancedSplitSchedulingEligible()`) is false for any course with laboratory units, laboratory-only included. The generator ignores such a request, `ValidateGenerationConfiguration` and `MeetingGroupRule` refuse it, and Setup Courses no longer offers the Split box.
- The Setup Courses **Default Settings** panel (gear button, `CourseDefaultsSidebar`, the "Defaults" chip, the "Custom" badge and the `customizedCourseIds` draft field) is removed with its help and tour entries. The Friday + Saturday pairing stays in Configuration.

## Follow-up (2026-11-12): Regular is the class type for laboratory courses

Lecture + laboratory courses stay **Regular**; the server's automatic inclusion (above) assigns their lecture and laboratory sessions, so the frontend no longer seeds or requires Integrated. Integrated stays optional for Hybrid or Lecture/Laboratory Required Days. Manual Plotting labels the automatic pair **Regular**, and choosing it splits a combined block saved before this change. Regular delivery for these courses is On-Site only. No backend change was needed.

## Follow-up (2026-11-12): Regular keeps its full duration (supersedes the automatic split)

A Regular course is never converted into Split or Integrated. A course with laboratory units, even with lecture units too, stays one meeting of its full duration (2 LEC + 3 LAB = one 5-hour meeting) in a laboratory room, or Room TBA when none is free; never a lecture classroom. The automatic inclusion in `GenerationCourseSelection::resolve()` and the Manual Plotting pair default were removed, and the retry strategies `clear_bottleneck_split` / `disable_section_hybrid` were restored, since turning Integrated back into Regular is valid again. Integrated remains an explicit, optional choice for any category (Hybrid stays Major-only). Still in force: lab rooms only, Room TBA (including manual placement discovery), no Split Session for laboratory courses, and Default Settings removed.

## Follow-up (2026-11-13): laboratory courses cannot use Field placement

Laboratory requirements take precedence over a saved Field classification or
room type. Generate Schedule no longer lists Field rooms for these courses;
Manual Plotting hides Field mode and stages old Field laboratory meetings as
on-site laboratory placements for correction. Course validation, generation
preflight, preferred-room validation, shared room rules, conflict checks and
recommendation discovery enforce this without an opt-in switch. Regular and
Integrated shapes, contact time, Hybrid lecture delivery, strict laboratory
rooms and Room TBA are unchanged. Existing schedules and historical records are
not rewritten automatically.

## Verification

- Backend: every scheduling, room, curriculum and generation feature file run individually, plus `tests/Unit` — all pass except two pre-existing failures unrelated to this change (`CspOnlineCapacityTest` calls an old private signature; `SchedulingConstraintCatalogTest` lacks `integrated_component_required_day`) and the timing-sensitive `test_a_long_solver_call_is_cut_short…`, which passes and fails intermittently. New regressions: `GenerationCourseSelectionTest` (automatic components, category independence, explicit Hybrid, explicit single-meeting shapes, field) and `AvailableSlotFinderTest` (Room TBA, never a lecture room under a legacy Either setting, transfers excluded).
- Frontend: `tsc -p tsconfig.app.json` clean; scheduler and lab-policy suites pass except pre-existing failures in `withdrawalEligibility.test.ts`, `roomTransferPreview.test.ts`, and the Configure "Sections (2/2)" check, which come from other uncommitted work.
- Running all backend scheduling files in one PHP process exceeds the 512 MB memory limit; per-file runs do not.
