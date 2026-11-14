# Activity Log audit (VPAA): integrity, reliability and presentation

Audited 2026-11-14, before the Activity Log redesign. This document covers **audit integrity and data reliability only**. Visual and interaction changes are recorded separately in `wicars-ui/docs/activity-log-redesign.md`.

It builds on [data-integrity-audit-2026-11-13.md](data-integrity-audit-2026-11-13.md) and [historical-actor-integrity.md](historical-actor-integrity.md). Findings closed there (DI-01 to DI-12) are not repeated. They are only re-checked below.

## 1. Scope

| Layer | Files reviewed |
| --- | --- |
| Endpoint and authorization | `routes/api.php` (`role:vpaa` group), `app/Http/Controllers/Admin/ActivityLogController.php` |
| Storage | `scheduling_audit_logs`, `authentication_audit_logs` (`2026_09_26_100002`, `2026_09_26_100012`), retention migrations `2026_11_13_210000` and `2026_11_14_010000` |
| Models and identity | `SchedulingAuditLog`, `AuthenticationAuditLog`, `Concerns/AppendOnly`, `Support/ActorIdentity`, `Services/AuthenticationAuditService` |
| Writers | All 31 `SchedulingAuditLog::create` call sites, `AuthenticationAuditService::record`, `DepartmentScheduleController::recordWorkflowAudit`, `RoomRequestController::recordDecision` |
| Frontend | `wicars-ui/src/pages/vpaa/ActivityLog.tsx`, the `RoleRoute` guard in `App.tsx`, shared `DataTable` |
| Tests | `ActivityLogTest`, `ActorIdentityIntegrityTest`, `ArchiveRecoveryTest`, `DirectorRoleTest`, `ActivityLog.test.tsx` |

## 2. Re-verified controls

These were checked and hold. No change was needed.

- **Append-only storage.** Both audit tables reject `UPDATE` and `DELETE` in two places: the `AppendOnly` model hooks and native DB triggers (`retain_*_update` / `retain_*_delete`). The trigger migrations' `down()` methods refuse to roll back.
- **Restrictive foreign keys.** User, submission, history-version, department and semester references on `scheduling_audit_logs` use `RESTRICT`. Deleting a live section or recommendation can no longer null out an audit row.
- **Historical actor identity.** `ActorIdentity::capture` stores the actor's id, name, username, role, department, program and active state in `metadata._actor` when the row is inserted. Reads use that snapshot (`provenance: recorded`). They never use the current account. When no snapshot exists, the actor is reported as `unavailable` with only the id. The endpoint does not fabricate a name.
- **Approval chain.** Department workflow approvals, returns and rejections go through `recordWorkflowAudit`. That call links the audit row to a `schedule_history_versions` snapshot and to its `schedule_submission_id`, and adds a `history_group_id`. Room decisions append to `room_requests.actor_snapshots.decisions[]` and also write an audit event.
- **Authorization.** `/api/activity-log` sits in the `role:vpaa` group, and the CSV export uses the same route, so it gets the same check. Tests show that Secretary and Director accounts receive 403. On the frontend, `RoleRoute role="vpaa"` guards the page.
- **List/export parity.** The JSON list and the CSV export run through the same scan and filter loop, so they return the same set of records. Export refuses to run above 10,000 matches. It does not truncate silently (DI-09).

## 3. Fix-first register

Priorities: **P1** can produce a wrong or misleading audit record. **P2** is a reliability or security gap. **P3** is a quality or future improvement.

| ID | Priority | Finding | Status |
| --- | --- | --- | --- |
| AL-01 | P1 | Three writers rely on the DB `CURRENT_TIMESTAMP` default. They include room-request approvals and rejections and schedule-split edits. | Fixed in this change |
| AL-02 | P1 | The UI shows Dean-stage and VPAA-stage approvals and returns with the same label | Fixed in this change |
| AL-03 | P2 | CSV export allows spreadsheet formula injection | Fixed in this change |
| AL-04 | P2 | Keyword search fails on readable event names | Fixed in this change |
| AL-05 | P2 | No actor or recorded-role filter, and the export has no recorded username or identity provenance | Fixed in this change |
| AL-06 | P2 | Unavailable actors and roles show as bare text and raw role keys | Fixed in this change |
| AL-07 | P2 | Each request does a full scan and normalizes every entry in PHP | Open (future) |
| AL-08 | P3 | Department names come from the live record, not from the event | Mitigated in UI; open in data |
| AL-09 | P3 | Viewing or exporting the audit log is not itself audited | Open (recommendation) |
| AL-10 | P3 | Writers can set `created_at` to any value | Open (recommendation) |
| AL-11 | P3 | The department filter means different things for the two sources | Documented; labelled in UI |

## 4. Findings

### AL-01: Event time depends on the database server clock for some writers (P1)

`scheduling_audit_logs.created_at` defaults to `useCurrent()`, and `SchedulingAuditLog` has `$timestamps = false`. Most writers pass `'created_at' => now()`, which uses the app timezone (`Asia/Manila`). Three writers do not:

- `RoomRequestController::recordDecision`: room request **approve / reject / revoke / cancel** decisions
- `ScheduleSplitController` (≈ line 154): **schedule modification** via splits
- `InstitutionSettingsController` (≈ line 49): document signatory changes

For these rows, MySQL's session `time_zone` (`SYSTEM`) sets the timestamp, because `config/database.php` sets no connection timezone. Laravel then reads the naive value as `Asia/Manila`. Locally the DB host runs at UTC+8, so the values happen to match. On a UTC database server, these approvals would show 8 hours early. They would then sort out of order against the other events, and they would disagree with `actor_snapshots.decisions[].occurred_at`, which uses `now()`.

**Fix:** The `SchedulingAuditLog` `creating` hook now sets `created_at` with `now()` when a writer omits it. Existing rows are not changed, because they are immutable. Rows written before this fix on a non-UTC+8 database cannot be corrected in place. A correction would need a separate, attributed correction event, under the existing policy.

### AL-02: The approval stage is hidden in the UI (P1)

`schedule_approved_by_dean` and `schedule_approved_by_vpaa` both showed as "Schedule Approved". The same was true for the returned events. Someone reading the log could not tell which office approved a submission, even though the record keeps that distinction.

**Fix:** Each event now has its own label ("Schedule Approved by Dean", "Schedule Returned by VPAA", …). The raw event key appears in the detail drawer.

### AL-03: CSV formula injection (P2)

The CSV export wrote actor names, labels and metadata as they were stored. A value starting with `=`, `+`, `-`, `@`, tab or CR runs as a formula when the file is opened in Excel or Sheets. One example is an account display name, which account owners can edit through their profile.

**Fix:** The export prefixes such cells with `'`. It also adds `Entry ID`, `Username`, `Identity` (recorded / legacy snapshot / unavailable / system) and readable department and role columns. Raw IDs are kept for traceability.

### AL-04: Keyword search did not match the names users see (P2)

Search matched against the JSON-encoded entry. Event and category keys are stored as `schedule_approved_by_vpaa`, so a search for "schedule approved" returned nothing.

**Fix:** The search text now also includes each event and category key with underscores replaced by spaces. Metadata remains searchable, including recorded actor snapshots, section codes, IP and user agent.

### AL-05: No actor or recorded-role filtering (P2)

An earlier change removed the actor filter (`ea5d40a`). The event filter and the term filter were removed in the same change. The API still accepted `actor_id`, but the UI offered no way to choose an actor.

**Fix:**
- The API accepts `role` (`vpaa`, `dean`, `secretary`, `director`, `program_head`, `system`, `unavailable`). It matches the role **recorded at event time**, not the account's current role.
- The response adds `meta.facets.actors`, the recorded identities present under the other active filters. The actor dropdown therefore includes archived and deactivated accounts. It never offers anyone who has no matching events.
- The `actor_id` filter now runs in the PHP filter loop instead of SQL, so the facet list stays complete. The scan already read every authentication row, so performance is unchanged in practice (see AL-07).
- `meta.summary` returns counts for the filtered set: total, approved, returned, rejected, failed and distinct actors. The summary cards use these counts. They are not estimated from the current page.

### AL-06: Unavailable identity and roles were shown without detail (P2)

Actors without a recorded snapshot showed only as "Actor unavailable / unknown", and the user id that was recorded was dropped. Roles showed as raw keys, for example `program_head` displayed as "Program_head".

**Fix:** The drawer shows "Actor unavailable · User #id" and the identity provenance. Roles use the shared `roleLabel`. The UI never fills in a name from the current account.

### AL-07: Every request scans the full audit history (P2, open)

`index()` streams every row from both tables using `lazyById(500)`, normalizes each row, JSON-encodes it to test the search, and keeps a heap of `page × per_page` entries. The cost grows linearly with total history on every page view, and later pages cost more. Up to roughly 100k rows this works. It will not scale over multiple academic years.

**Recommendation:** Store `category`, `status`, recorded `actor_role` and a normalized search text in indexed columns. Write them at insert time; generated columns would work too. Then query both tables with a SQL `UNION ALL` using keyset pagination on (`created_at`, `id`). Legacy rows need an insert-only backfill table or view, because the audit tables are immutable. Do not run `UPDATE` on them.

### AL-08: Department names come from the live record (P3)

Audit rows store `department_id` but not the department's name at event time. The UI resolves the name from `/departments`. If a department is renamed, older events show the new name. If a department is archived, they fall back to `#id`.

**Mitigation:** The drawer prefers `metadata.department_name` when the event recorded it. Otherwise it shows the live name and marks it as the current name. **Recommendation:** add a department snapshot to `ActorIdentity::capture`, matching the actor snapshot.

### AL-09: Reading or exporting the audit log is not audited (P3)

Export produces up to 10,000 audit rows, including IP addresses and user agents, and leaves no record of who exported them. **Recommendation:** append an `activity_log_exported` event with the filters used and the row count.

### AL-10: `created_at` is client-settable (P3)

`created_at` is in `$fillable`, so any writer can back-date an event. All current writers pass `now()`. **Recommendation:** after AL-01, remove `created_at` from `$fillable` and always assign it in the `creating` hook. This changes test fixtures that back-date events, so it is left for a separate change.

### AL-11: The department filter has two meanings (P3)

For scheduling events, `department_id` is the department where the action took place. For account events, it is the *subject* account's department, which comes from `_subject`. The filter combines the two. The drawer now labels this field "Affected department". This is documented rather than changed, so that filter results stay as they were.

## 5. Verification

- `php artisan test --filter=ActivityLog`: existing tests, plus new coverage for the role filter, actor facets, the summary, readable-name search, CSV sanitization and the `created_at` default.
- `npx vitest run src/pages/vpaa/ActivityLog.test.tsx`: updated for the restored Actor and Role filters, stage labels and the drawer.
