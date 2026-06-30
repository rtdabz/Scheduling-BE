# Issues & Improvements — Action List

A prioritized, checkable list of bugs and improvements for the scheduling system.
Use this to decide what to tackle first. Items are ordered roughly by the
recommended sequence (fix blockers → build core feature → polish).

Legend:
- **Severity**: Critical / High / Medium / Low
- **Effort**: S (small) / M (medium) / L (large)
- **Blocks**: what cannot work until this is fixed

---

## Phase 1 — Blockers (app does not function correctly until these are fixed)

- [ ] **1. Missing `ScheduleController` import in `routes/api.php`**
  - Severity: Critical | Effort: S
  - Routes reference `ScheduleController::class` but there is no
    `use App\Http\Controllers\ScheduleController;`. This throws during route
    registration and brings down the **entire `role:vpaa` group** (departments,
    rooms, faculties, subjects, schedules).
  - Blocks: every admin route in that group.

- [ ] **2. Broken model class references (singular vs plural)**
  - Severity: Critical | Effort: M
  - Actual model classes are plural: `Terms`, `Sections`, `Subjects`, `Rooms`,
    `Departments`. Code references nonexistent singular classes:
    - `Schedule.php` relationships use `Term::class`, `Section::class`,
      `Subject::class`, `Room::class`, `Department::class`.
    - `Sections.php` uses `Department::class`, `Term::class`.
    - `SectionsController` imports `Section`, `Department`, `Term`.
  - Result: `Class "App\Models\Term" not found` at runtime. `ScheduleController`
    and `SectionsController` are completely non-functional.
  - Decision needed: rename models to singular (cleaner, Laravel convention) OR
    point all references to the existing plural class names. Renaming is the
    long-term fix but touches more files.
  - Blocks: all schedule and section endpoints.

- [ ] **3. `SectionsController` and `TermsController` not registered in routes**
  - Severity: High | Effort: S
  - Controllers exist but no routes point to them, so sections and terms cannot
    be managed via the API.

## Phase 2 — Core feature (the reason the system exists)

- [ ] **4. Implement conflict detection**
  - Severity: Critical | Effort: L
  - Currently absent entirely. On create/update of a schedule, check existing
    schedules in the **same term and day** with overlapping times
    (`new_start < existing_end AND new_end > existing_start`) for:
    - Room double-booking
    - Faculty teaching two places at once
    - Section booked into two classes at once
  - Then either reject with `422` listing conflicts, or save with
    `status = 'conflict'` and return the conflicting records.
  - Suggested approach: a dedicated `ConflictDetectionService` wired into
    `ScheduleController::store()` and `update()`.

- [ ] **5. Implement recommendation logic**
  - Severity: High | Effort: L
  - Suggest free rooms of the correct type, open faculty time slots, or
    alternative time blocks when a conflict is found. Depends on item 4.

- [ ] **6. Enforce faculty `max_units`**
  - Severity: Medium | Effort: M
  - Faculty model has `max_units` but nothing checks total assigned units when
    scheduling. Fits naturally alongside conflict detection.

## Phase 3 — Security & correctness

- [ ] **7. Privilege escalation on user management**
  - Severity: High | Effort: S
  - `UserController` routes are under `auth:sanctum` but outside any `role:`
    guard, so any authenticated user can list/create users (including elevated
    roles). Move under `role:vpaa` or add policy checks.

- [ ] **8. `TermsController::store()` has no `return`**
  - Severity: High | Effort: S
  - Creates the term but returns empty `200` with no body. Return the created
    record (e.g. `201` with the model).

- [ ] **9. Consolidate the role system**
  - Severity: High | Effort: M
  - `User` mixes Spatie `HasRoles` with a raw `role` column; `RoleMiddleware`
    only reads the `role` column. Pick one approach to avoid permission desync.

## Phase 4 — Consistency & hardening

- [ ] **10. Day enum mismatch**
  - Severity: Medium | Effort: S
  - Migration allows `Sunday`; `ScheduleController` validation only allows
    `Monday`–`Saturday`. Align them.

- [ ] **11. Add `$casts` for schedule times**
  - Severity: Medium | Effort: S
  - Cast `start_time` / `end_time` so overlap logic is reliable (avoid raw
    string comparisons).

- [ ] **12. Add DB indexes on `schedules`**
  - Severity: Medium | Effort: S
  - Index the columns used for conflict queries: `term_id`, `day`, `room_id`,
    `faculty_id`, `section_id`. Improves performance as data grows.

- [ ] **13. Standardize validation & stop using `$request->all()`**
  - Severity: Medium | Effort: M
  - Some controllers use `Validator::make`, others `$request->validate`. Some
    pass `$request->all()` to create/update. Standardize on `$request->validate`
    and pass `$request->validated()`.

- [ ] **14. `DepartmentsController` returns Blade/redirects in some methods**
  - Severity: Medium | Effort: S
  - `trash()`, `restore()`, `forceDelete()` should return JSON for a pure API.

- [ ] **15. Use `apiResource` instead of `resource`**
  - Severity: Low | Effort: S
  - `Route::resource('departments', ...)` creates unused `create`/`edit` HTML
    routes. Switch to `apiResource`.

- [ ] **16. Namespace/naming consistency**
  - Severity: Low | Effort: M
  - `UserController` lives in `App\Http\Controllers\Api` while others are in
    `App\Http\Controllers`. Normalize.

---

## Suggested order

1. Items 1–3 first — without them the app does not boot/route correctly.
2. Items 4–6 next — the core conflict detection + recommendation feature.
3. Items 7–9 — security and correctness before any real use.
4. Items 10–16 — consistency and hardening, can be done incrementally.
