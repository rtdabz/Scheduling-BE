# System-wide actor identity and approval data integrity audit

Audit baseline: 2026-11-13 (Asia/Shanghai). Remediation: 2026-11-14; implementation and local rollout details are recorded in section 13. Sections 1?12 preserve the original investigation findings and baseline counts.

Scope: the current working copies of `C:/Users/toman/backend` and `C:/Users/toman/wicars-ui`, and the backend's configured local MariaDB database, `scheduling_db`. Backend HEAD at inspection: `ca892221a0f19455d2b802c4abc0b6f0f2657c7c`.

Expanded scope following clarification: every Dean, Secretary, Director, and Program Head lifecycle, including deactivation, archival, hard-deletion evidence, new appointment, reactivation/restoration, role or department/program transfer, and reuse of an existing account for a successor. Sections 3.1 and 12 provide role-specific evidence and a lifecycle matrix. The report now contains 15 numbered findings.

Both repositories already contained substantial uncommitted work. Findings describe the inspected working copies, not an assumed deployed release. Several historical-signatory safeguards are among those existing changes. Deployment state and production data were not verified.

## 1. Conclusion

The strongest confirmed explanation is **historical displays resolving identities through mutable user accounts**, rather than one update changing several approval foreign keys. Changing one account's name changes every displayed event that reads that account's current name. This affects Activity Logs, history actor labels, JSON approval exports, archive provenance, and notification actor relationships.

This problem is confirmed beyond the VPAA: the 791 provable authentication actor-name differences include **54 Dean events, 181 Secretary events, 22 Program Head events, and seven Director events**, in addition to 527 VPAA events. Account deactivation preserves ordinary user relationships, while archival hides the account from several historical readers. Newly assigned officials should receive their own future actions without inheriting their predecessor's completed decisions; current code generally preserves distinct account IDs and frozen signatures, but has gaps in current-official selection, vacancy handling, and status-update concurrency.

The College President has a separate representation: `institution_settings.president_name` and `president_title`, not a President user account or approval foreign key. The VPAA approval endpoint deliberately records both the VPAA recommendation signature and the configured President signature in the same transaction. This is a document-signature convention; it is not an authenticated President approval action.

**No inspected account-update path writes both the VPAA account and President settings. No inspected data shows shared Dean/VPAA approval IDs, mismatched submission/signatory IDs, or approval-log actors disagreeing with their associated approval records.** The reported single-edit change to both already-frozen signature identities was not established. A different deployed revision, a document using current officials, or an unrecorded settings change remains possible; the local evidence cannot choose among those explanations.

Existing integrity problems are nevertheless confirmed:

- 791 authentication events would display an actor name different from contemporaneously stored subject identity evidence under the current formatter.
- 88 history versions have signatory `acted_at` values later than the history event itself, consistent with retrospective copying of a submission's later signature state.
- All six existing semester archives lack signature snapshots, including all 1,399 associated schedule items.
- Twelve authentication rows have lost their subject foreign-key linkage while retaining the original subject IDs in metadata. Two original subject accounts are absent.
- Room-request revocation overwrites approval reviewer fields, and room approvals/rejections lack corresponding permanent scheduling audit events.

These findings establish historical presentation drift, attribution gaps, and temporally inconsistent snapshot data. They do **not** establish malicious tampering or prove that underlying approval decisions were changed.

## 2. Read-only method and limitations

- Inspected routes, request validation, models and model hooks, providers/events, shared services, migrations, seeders, console commands, frontend forms, API consumers, print/export code, and relevant existing tests/documentation.
- Queried MariaDB directly through PDO and the installed dotenv loader. The Laravel application was not bootstrapped, avoiding application observers, caches, notification delivery, and `firstOrCreate` side effects.
- Each database inspection used `SET SESSION TRANSACTION READ ONLY`, `START TRANSACTION WITH CONSISTENT SNAPSHOT`, read queries/metadata inspection, and `ROLLBACK`. No database records or schema were changed.
- Did not execute migrations, seeders, resets, application write endpoints, or tests that create or alter database records. Test coverage was inspected statically; no passing-test claim is made.
- Added only this report. Existing source and user changes were preserved.
- This is a local-system audit, not proof of production state. No browser mutation or rename reproduction was performed. Available backups, signed paper documents, production logs, and deployment history were not obtained.
- Local binary logging is disabled (`@@log_bin = 0`); the audit does not establish whether other backups or external logs exist. An exact historical SQL update cannot be reconstructed from the current database alone.
- Counts were collected in read-only snapshots during this investigation. A running application may subsequently change them.
- Chronology checks normalized SQL event timestamps and ISO UTC signature timestamps to epoch seconds. The inspected database session uses Asia/Shanghai (UTC+08:00), matching the application's default Asia/Manila offset; the five-second tolerance avoids treating ordinary insert timing as contamination.

## 3. Database evidence

MariaDB version: `10.4.32-MariaDB`. Inspected tables use InnoDB. The inspection connection had foreign-key checks enabled. There were no database triggers. Relevant CHECK constraints validate JSON syntax, not actor provenance, workflow transitions, or immutability.

| Record set | Count |
| --- | ---: |
| Users | 25 |
| Schedule submissions | 67 |
| Schedule history versions | 841 |
| Schedule history items | 85,191 |
| Scheduling audit records | 1,562 |
| Authentication audit records | 1,983 |
| Room requests | 15 |
| Room recovery plans | 33 |
| Institution settings rows | 1 |

| Integrity check | Observed result | Interpretation |
| --- | --- | --- |
| Dean/VPAA same reviewer ID on a submission | 0 | No shared identity reference between those stages in checked data. |
| Submission reviewer FK versus corresponding saved signature user ID | 0 mismatches | Saved identity references agree where both are present. |
| Dean approval audit actor versus submission reviewer | 0 mismatches across 53 linked approvals | No actor-ID overwrite detected by this comparison. |
| VPAA approval audit actor versus submission reviewer | 0 mismatches across 49 linked approvals | Same limitation: agreement is not independent proof against coordinated edits. |
| Scheduling audit actor versus linked history actor | 0 mismatches across 875 links | Linked records are internally consistent. Several logs can link to one version. |
| Non-null orphan submission/history actor references | 0 | Current FK targets exist for checked references. |
| Orphan history items / submission-section memberships | 0 / 0 | No broken parent links detected. |
| Scheduling records with null or soft-deleted actor | 0 / 0 | No current missing actor in this source; names are still mutable. |
| Submission signature snapshots | 67 present; 65 frozen | Presence does not establish when a legacy name was captured. |
| VPAA snapshot name versus current VPAA name | Different in all 67 submissions and all 427 versions with signature snapshots | Snapshot rendering and current-account rendering necessarily disagree. This is not evidence that the snapshots themselves were renamed. |
| History versions without signature JSON | 414 | Many are ordinary edits, not approval decisions. All 49 VPAA approval versions have signature JSON. |
| Authentication subject snapshot versus current subject name | 830 differences among 1,971 joinable subjects | Confirmed historical name drift; title/credential changes also count as name differences. |
| Provable authentication actor-display name drift | 791 events: 289 self-actor events and 502 unauthenticated subject-fallback events | The formatter would show a name different from the event's stored identity evidence. The other 39 subject-name differences are not counted as proven actor drift. |
| Authentication actor snapshots | No `_actor` snapshots in all 1,983 records | `_subject` is useful evidence but is not a general replacement for the actor. |
| Authentication subject links already missing | 12 rows, preserving original subject IDs 8 or 15 in `_subject` | Account references have been removed or nulled historically; exact mechanism is unproven. |
| Semester signature coverage | 6 versions; 1,399 items; 0 item signature snapshots | Existing semester archives cannot provide their own saved officials. |
| Duplicate active Dean / department-office / Program Head slots | 0 / 0 / 0 | Existing locking and unique-index design is functioning in current data. |
| Account-backed faculty records | 23 linked rows, 23 distinct user IDs | No duplicate linked user reference detected. `faculties.user_id` has a unique index. |

The VPAA account, ID 1, is referenced by 189 scheduling events, 60 history events, 622 authentication events under the current actor/fallback rules, and 159 notifications. These are exposure counts, not a claim that each event was maliciously altered. Reusing the same person's ID for many actions is expected.

### 3.1 Evidence for Deans, Secretaries, Directors, and Program Heads

| Role | Currently active accounts | Subject-name differences | Proven actor-name differences | Scheduling events referencing accounts currently in this role | History versions referencing accounts currently in this role |
| --- | ---: | ---: | ---: | ---: | ---: |
| Dean | 8 | 65 | 54 | 55 | 54 |
| Secretary | 8 | 200 | 181 | 1,269 | 704 |
| Program Head | 7 | 29 | 22 | 49 | 23 |
| Director | 1 | 9 | 7 | 0 | 0 |
| VPAA | 1 | 527 | 527 | 189 | 60 |

Name-difference columns group by the stored event-time `_subject.role`. Proven actor differences use only cases where the stored subject was the actual self-actor or the formatter's valid subject fallback. Scheduling/history columns group existing references by the account's current role and indicate exposure, not independently reconstructed historical capacity. Counts are events/versions, not unique affected people, and subject and actor differences overlap.

All 25 current accounts are active, with no current inactive or soft-deleted user rows. Consequently, inactive/archived replacement scenarios below are verified from code and retained lifecycle evidence, not by modifying accounts or asserting an existing inactive user's current PDF is wrong.

Retained lifecycle evidence includes:

- Former Dean account **8**: five authentication rows retain its subject snapshot but have a null subject FK. Its recorded department is 5; the current active Dean there is account **16**, a distinct ID.
- Former Secretary account **15**: seven such rows retain its snapshot but have a null subject FK. Its recorded department is 5; the current active Secretary there is account **21**, a distinct ID.
- Audit events `254` (Dean 8), `610`, and `618` (Secretary 15) record `user_updated` with `active: false`. There are also two `user_archived` events for each former account. These establish recorded inactive states and archival events, not three independently proven active-to-inactive transitions: the audit payload lacks the previous state. The precise later account-removal operation is still unknown.
- Nine Dean-creation events and nine Secretary-creation events reference nine distinct subjects per role, compared with eight current accounts per role. Program Head creation covers seven subjects; Director creation covers one. This identifies historical account turnover without proving an automatic reassignment of old approval records.
- All **127** authentication records whose subject snapshot role is Program Head omit `_subject.program_id`; none has a `before` metadata block. These snapshots alone cannot establish the Program Head's original program assignment or an assignment-change timeline. Other schedule snapshots may retain supporting program IDs.
- Sixty-five submissions reference current Secretary accounts as submitters and two reference current Program Head accounts. Submitter and preparer are different concepts: they must be captured separately. Thirty submissions have stored preparer entries, all referencing accounts currently classified as Secretary but titled `Program Head`; 37 submissions have empty preparer arrays. This is incomplete capacity/provenance evidence, not proof those 37 schedules were never prepared.

## 4. Workflow and identity ownership

| Workflow | Persisted identity | Current behavior / boundary |
| --- | --- | --- |
| Secretary / Director / Program Head preparation and submission | `schedules.created_by`, `schedule_submissions.submitted_by`; prepared signature names in submission JSON | Creator ID is stamped server-side. Submission creates a new revision and initial signature block. Submitter labels can still read current account names. |
| Dean review / return | `dean_reviewed_by`, `dean_reviewed_at`; reviewed signature; scheduling audit and history actor IDs | Actual authenticated actor is recorded. A VPAA acting at the Dean stage is allowed, but the document signature can name the current Dean instead. |
| VPAA approval / return | `vpaa_reviewed_by`, `vpaa_reviewed_at`; recommended signature; audit/history | Final approval stamps the VPAA and President document slots together and freezes the signature block. Current code prevents recalling final VPAA-approved schedules. |
| College President | Name/title in `institution_settings`; copied into `signatories.approved`, with `user_id: null` | No separate President account, authenticated President endpoint, or independent President decision record was found. |
| Recall / resubmission | `withdrawn_by`, `withdrawn_at`; membership state; new submission revision on resubmission | Existing code serializes transitions and freezes a fully recalled signature block. Earlier submissions remain stored. |
| Room transfer / recovery | Creator, Dean reviewer, final reviewer IDs on `room_recovery_plans`; audit rows | Decision writes are locked and server-attributed. Actor names and print officials are not preserved with equivalent signature snapshots. |
| Room borrowing | `room_requests.requested_by`, `reviewed_by`, `reviewed_at` | Current-account relationships supply names. Revocation replaces review fields; original approval must be sought elsewhere. |
| Semester archive | History actor ID; schedule snapshots and metadata | Current working copy preserves future per-section signature snapshots; existing archive data predates or otherwise lacks that evidence. |

Sources: [DepartmentScheduleController](../app/Http/Controllers/Scheduling/DepartmentScheduleController.php), especially lines 874-906, 997-1016, 1093-1104, 1311-1337, and 1446-1461; [ScheduleSignatories](../app/Services/Scheduling/ScheduleSignatories.php), lines 44-130 and 247-276; [Schedule model](../app/Models/Schedule.php), lines 139-141.

## 5. Fix-first register

P1 = high: historical attribution is already unreliable or evidence can be permanently lost. P2 = medium: incomplete retrieval, misleading interpretation, or conditional integrity gaps. No active P0 incident or proven malicious multi-actor rewrite was established.

| Order | ID | Priority | Work to schedule | Completion evidence |
| ---: | --- | --- | --- | --- |
| 1 | DI-15 | P1 | Stop status toggles from overwriting stale identity or assignment fields; protect full updates against lost edits | Concurrent profile changes survive deactivation/reactivation; stale full updates are rejected. |
| 2 | DI-01, DI-04 | P1 | Capture immutable actor/subject identity and identity/assignment-change before/after values for every role; render historical evidence consistently | Rename, archive, replacement, and role-change checks leave old actors unchanged across logs, history, and exports. |
| 3 | DI-03 | P1 | Protect audit/history retention and narrow database privileges | Routine application credentials cannot update/delete completed audit events or destroy their parent history. |
| 4 | DI-05 | P1 | Preserve room approval decisions separately from revocation; audit all room request decisions atomically | Approve then revoke keeps both actors/times and exposes both events in Activity Logs. |
| 5 | DI-02, DI-08 | P1 / P2 | Inventory contaminated or missing legacy snapshots and preserve evidence in a separate reconciliation ledger | Every affected version has a classification and evidence source; no existing history is rewritten. |
| 6 | DI-06 | P1 | Make JSON exports use historical approval identity and correct per-section signature provenance | JSON, history screen, and historical PDF agree without consulting current approver names. |
| 7 | DI-09, DI-10 | P2 | Correct audit filtering/export completeness and unknown-actor handling | An old matching event is found/exported beyond the first 500 rows; missing humans never silently become System or Secretary. |
| 8 | DI-13, DI-14 | P2 | Separate eligible current officials from historical actors and handle vacant offices explicitly | Inactive new accounts cannot shadow active holders; an unfilled Dean slot never claims an inactive official acted. |
| 9 | DI-07, DI-11, DI-12 | P2 | Clarify action actor versus document official, preparer capacity, and official-selection constraints | Delegated Dean actions and configured President signatures have unambiguous provenance. |

Before any later remediation, preserve an independently recoverable database backup and available original exports. This investigation did not create or alter a database backup, repair history, or change workflows. The following sections are the open backlog, not completed fixes.

## 6. Findings

### DI-01 — Historical actor display depends on current accounts (P1, confirmed)

**Root cause.** Actor foreign keys identify an account, while historical rendering treats its current name and role as the identity at action time. `AuthenticationAuditService` saves a subject snapshot but no actor snapshot. Scheduling/history records generally retain actor ID only.

**Evidence.** [ActivityLogController](../app/Http/Controllers/Admin/ActivityLogController.php), lines 60-76, 165, 189-230, loads current `user`, `actor`, and `subject` relationships. Authentication department resolution also prioritizes the current subject's department. [ScheduleHistoryController](../app/Http/Controllers/Scheduling/ScheduleHistoryController.php), lines 52 and 128, returns the current actor. [AuthenticationAuditService](../app/Services/AuthenticationAuditService.php), lines 11-29, stores `_subject` only. [ScheduleHistoryRecorder](../app/Services/ScheduleHistoryRecorder.php), lines 44-56, saves `actor_user_id` without identity fields.

Frontend [ActivityLog.tsx](../../wicars-ui/src/pages/vpaa/ActivityLog.tsx), lines 243-248 and 447-451, and [ScheduleHistory.tsx](../../wicars-ui/src/pages/vpaa/ScheduleHistory.tsx), lines 360-366, display the supplied current actor. [ArchiveProvenance](../app/Services/Archive/ArchiveProvenance.php), lines 66-87, handles archived users but still reads their current names/roles. [SystemNotificationController](../app/Http/Controllers/Admin/SystemNotificationController.php), line 17, also eager-loads current actors; notification text may retain an older name while actor details show a newer one.

**Impact / existing compromise.** The 791 demonstrable actor-name differences are historical presentation changes, not database name overwrites in those audit rows. All saved VPAA signature snapshots differ from the current VPAA name, so even the same action can have different apparent names across screens. Current roles and department transfers can similarly relabel or reclassify old events; no role drift was found in the checked authentication subject snapshots.

**Safeguard.** At event creation, capture actor ID, name, username, role/capacity, department/program, timestamp, and explicit actor type. Capture subject identity separately. Prefer existing JSON metadata fields plus a shared recording path over a separate audit subsystem. Read those snapshots in the API, frontend, and export code; keep FKs for account navigation. For old rows, use existing trustworthy snapshots only where actor and subject semantics match, and label unresolved identities explicitly. A subject snapshot for a different person must never become the actor snapshot.

### DI-02 — Legacy signatory backfill contaminates event-time snapshots (P1, existing data confirmed)

**Root cause.** [The signatory migration](../database/migrations/2026_11_07_110000_add_signatories_to_schedule_workflow.php), lines 15-16 and 29-48, reconstructs each submission's signature block once and copies it to every linked historical version with a null block. A submission's later reviewer/time can consequently appear in earlier submission, review, recall, or revision events. Its backfill calls an application service whose behavior has changed since the migration was written.

**Evidence.** Eighty-eight distinct versions contain at least one `acted_at` more than five seconds after the event timestamp: 42 submission versions, 42 Dean approval versions, two recall versions, and two revision-change versions. Example version 9, a submission event, contains a Dean action 5,719 seconds later and a VPAA action 5,745 seconds later. Version 10, a Dean approval event, contains a VPAA action 26 seconds later. Sample affected version IDs: `9, 10, 18, 19, 36, 37, 42, 43, 48, 49, 78, 79`.

**Impact.** Those blocks cannot be treated as a faithful statement of which slots had already been acted on at the event time. Names selected from current officials during backfill are not independently verified historical identities. The original FK/timestamp columns can still preserve useful decision evidence. This does not prove decisions occurred earlier than recorded or that every populated legacy snapshot is wrong.

**Existing protection.** The current working copy's [ScheduleSignatories::stored](../app/Services/Scheduling/ScheduleSignatories.php), lines 105-130, returns unknown frozen slots for closed legacy submissions rather than guessing current officials. This improvement does not change already-populated legacy blocks, and does not repair the 88 detected versions.

**Safeguard.** Inventory legacy blocks with source/confidence and reconstruction date in a separate ledger. Compare each event to independent event-time evidence. Never copy the final submission state into every earlier event, and never silently replace old snapshots with current officials. Any later approved correction should be an additive, attributed correction record retaining the original value. Future migrations should use a stable, explicit migration transformation, not mutable workflow service behavior.

### DI-03 — Permanent retention is not enforced at the database boundary (P1, confirmed exposure)

**Root cause.** Read-only HTTP access does not make audit records append-only. [SchedulingAuditLog](../app/Models/SchedulingAuditLog.php), [AuthenticationAuditLog](../app/Models/AuthenticationAuditLog.php), [ScheduleHistoryVersion](../app/Models/ScheduleHistoryVersion.php), and [ScheduleHistoryItem](../app/Models/ScheduleHistoryItem.php) have ordinary mutable Eloquent behavior. No update/delete guards or database triggers protect these records. The configured application connection has global `ALL PRIVILEGES`.

Actual FK metadata shows `ON UPDATE RESTRICT`, including actor FKs: cascading updates are **not** the observed cause. However:

- Hard-deleting users sets approval, audit, and history actor/subject references to null in most relevant tables.
- Hard-deleting a department or semester cascades into `schedule_submissions` and its membership records.
- Deleting a history version cascades into its history items and nulls related audit links.
- Hard-deleting a generation requester cascades into `schedule_generation_runs`; recommendation actor references generally become null.
- Room request parents can cascade-delete room requests/windows.

Sources: [history-table migration](../database/migrations/2026_09_26_100012_create_schedule_history_tables.php), lines 11-49; [submission migration](../database/migrations/2026_09_26_100011_create_schedule_submissions_tables.php), lines 13-24 and 36-37; users, generation, and room-request migrations; actual `information_schema` constraints and connection grants.

**Existing impact.** Authentication IDs `76, 77, 254, 255, 298, 299, 300, 377, 610, 611, 618, 620` have null subject references, while metadata preserves original subject IDs 8 or 15 and those accounts no longer exist. This demonstrates historical linkage loss, not which code or operator removed it. No non-null orphan actor FKs were detected. Normal user and academic archive endpoints use soft deletion; the cascading loss is a hard-delete/privilege risk, not a claim that ordinary archival currently cascades.

**Safeguard.** Use dedicated least-privilege runtime credentials and separate migration credentials. Preserve historical identity snapshots independently of account lifecycle. Restrict destructive parent deletion where approval retention is required. Make completed audit/history events append-only, with additive corrections. Because workflow recording currently creates a version and then updates its signatories ([DepartmentScheduleController](../app/Http/Controllers/Scheduling/DepartmentScheduleController.php), line 1638), assemble complete snapshots before insertion before introducing strict append-only enforcement. Model guards alone do not stop query-builder or direct SQL writes. Retain recoverable backups; stronger tamper evidence requires a separately protected copy, not a promise that a database administrator can never change a local row.

### DI-04 — Identity-changing writes lack sufficient change evidence (P1, confirmed)

**Root cause / evidence.** [InstitutionSettingsController](../app/Http/Controllers/Admin/InstitutionSettingsController.php), lines 32-50, changes President name/title without an audit event or before/after values. No President/signatory-specific events were found in either audit table. `LiveUpdateRecorder` registers settings changes for live refresh, not durable change history.

[UserController](../app/Http/Controllers/Admin/UserController.php), lines 95-140, and [ProfileController](../app/Http/Controllers/Auth/ProfileController.php), lines 49-65, capture post-update subject identity and operational flags, not old name/role/department values. [FacultyController](../app/Http/Controllers/Faculty/FacultyController.php), lines 299-305, can call [UserFacultyProfileService::pushAffixesToAccount](../app/Services/UserFacultyProfileService.php), lines 139-151, changing an account's display name through faculty title/credential edits without an equivalent identity-change audit event.

**Impact.** A single legitimate profile or linked-faculty edit can change historical actor displays; the logs do not record a complete before/after explanation. For President settings, even the transition itself is absent from the inspected audit sources. The exact update that allegedly changed President identity cannot be established from these records.

**Safeguard.** Atomically audit relevant identity, role, department/program, account-link, and institution-signatory changes with actor snapshot, target snapshot, changed fields, and before/after values. Permit legitimate name corrections, while preserving old event identities. Replacing an officeholder with a different person should use a separate person/account identity rather than repurposing the old person's account.

### DI-05 — Room revocation replaces approval identity and room decisions are not durably audited (P1, confirmed)

**Root cause.** [RoomRequestController::approve](../app/Http/Controllers/Rooms/RoomRequestController.php), lines 301-305, records approval in `reviewed_by`/`reviewed_at`. Its `close` path, lines 372-376, replaces those fields and remarks on revocation with the revoking actor/time. The record represents the latest state rather than preserving each decision.

Approval, rejection, and initial request paths send notifications but do not append matching permanent `room_request_approved`, `room_request_rejected`, or initial-request scheduling audit events. The controller's durable scheduling audit write is for `room_grant_ended` (lines 684-691). Notifications are delivered after commit and can fail or be deduplicated; they are not a transactional audit substitute.

**Existing impact.** There are two revoked requests, IDs 11 and 15. Each retains an approval notification but has zero matching `room_request_approved` scheduling audit records. Across the database, there are zero `room_request_*` scheduling audit events despite approval/rejection notifications and completed requests. Original approval reviewer/time fields are not preserved separately on the request after revocation. Some prior IDs/times may be recoverable from notifications, but their current actor relationships do not supply immutable names.

**Safeguard.** Append an attributed event for each request, approval, rejection, return/cancellation, and revocation inside the business transaction. Keep original approval identity/time distinct from revocation identity/time; continue updating current request status as today. Use existing audit storage where practical, with room-request ID and actor snapshots. Preserve all existing requests and notices.

### DI-06 — Historical JSON exports reconstruct approvers from live accounts (P1, confirmed)

**Evidence.** [ScheduleHistoryExporter::approval](../app/Services/Scheduling/History/ScheduleHistoryExporter.php), lines 170-196, fetches the submission reviewer through current `User::query()->find(...)`, or uses the version's current actor relation, and exports that person's current `name`/`role` as `approved_by`. It can simultaneously export an older signature snapshot under `signatories`.

For semester snapshots it searches current approved/partially-withdrawn submissions and selects one for the supplied sections. That single submission is not guaranteed to represent all archived approval cohorts. `classRow`, lines 120-150, prefers saved labels but falls back to current course/faculty/room data when evidence is missing.

**Impact.** Re-exporting the same historical record can change its reported approver. JSON and historical PDF can disagree about the person. Current enrichment can appear to be historical fact unless explicitly labeled. Given the observed VPAA name differences, the live-account versus signature disagreement is already supported by stored data and code; no export request was executed.

**Safeguard.** Export the event-time actual actor and recorded document officials separately. Use historical snapshots and per-item/per-section approval provenance; mark unresolved labels and any current enrichment as such. Do not choose a later submission to fill a missing historical approver. Keep the original export schema/data available when introducing corrected semantics.

### DI-07 — A signature official can be mistaken for the authenticated action actor (P2, confirmed semantics)

[ScheduleSignatories::stamp](../app/Services/Scheduling/ScheduleSignatories.php), lines 70-80, can name the current Dean when a VPAA performs the Dean step. [approveByVpaa](../app/Http/Controllers/Scheduling/DepartmentScheduleController.php), lines 1451-1456, stamps both `recommended` and `approved`; the latter uses President settings with `user_id: null`. Both receive the VPAA transaction's action time.

The actual `dean_reviewed_by` or `vpaa_reviewed_by` is the authenticated actor, so the columns and signature block can intentionally represent different concepts. A President `acted_at` does not prove the President logged in or approved. A frozen but unacted slot can also contain an expected official, not a completed decision.

Preserve the current approval sequence. Label configured document signatories, expected officials, and actual actors separately. Record delegation/authority explicitly when VPAA performs the Dean stage. Any independent President approval stage would require a separate future business decision; it is not proposed as an implicit change in this audit.

### DI-08 — Existing semester archives lack officials; fallback rules are inconsistent (P2, confirmed legacy gap)

All six `schedule_semester_archived` versions lack version signature JSON, and none of their 1,399 items has an embedded signature snapshot. One of 59 recall versions also lacks version signature JSON. This is incomplete evidence, not proof that every ordinary edit requires all document signatures.

The current [ScheduleSemesterArchiver](../app/Services/ScheduleSemesterArchiver.php), lines 22-32 and 51-57, already copies per-section officials for future archives. [ScheduleHistory.tsx](../../wicars-ui/src/pages/vpaa/ScheduleHistory.tsx), lines 193-227, sets `historicalDocument: true`; [schedulePdf.ts](../../wicars-ui/src/pages/ClassSchedules/SchedulerPanel/schedulePdf.ts), lines 185-191 and 246, therefore suppresses current-officeholder fallback and shows `Not recorded`. That historical PDF path is protected and must not be reported as currently substituting today's officials.

Other output paths have weaker guarantees: JSON export can use a current submission (DI-06); [teachingLoadSignatories.ts](../../wicars-ui/src/pages/ClassSchedules/SchedulerPanel/teachingLoadSignatories.ts), lines 25-39, combines saved names with current officials when any row lacks a block. Ordinary schedule PDFs can also combine signed and unsigned cohorts. [RoomRecoveryService::transfers](../app/Services/RoomRecoveryService.php), lines 885-950, supplies current `preview_users`, current creator name, and schedule previews without a transfer signature snapshot. Transfer printouts consequently have no equivalent permanent record of their own officials.

Use explicit historical/draft output semantics everywhere, preserve per-section decision cohorts, and show unknown historical officials as unknown. A current title combined with several historical names should not imply one person approved all included rows. Existing documents and missing snapshots must remain unchanged unless later reconciliation is separately authorized and supported.

### DI-09 — Audit pagination, search, and export can hide older evidence (P2, confirmed)

[ActivityLogController](../app/Http/Controllers/Admin/ActivityLogController.php), lines 58-108, limits each source to `min(10000, max(500, page * per_page + per_page))` candidates before applying some category/status/search/department filters in PHP. Lines 110-145 count and paginate that incomplete candidate collection. CSV uses the same collection.

At the default page size, only the newest 500 candidates from each source are considered unless earlier SQL filters narrow the query. Both sources already exceed 500 records. A sparse filtered page can therefore omit an older match, totals can change with the page requested, and export can omit records while appearing complete. The export's 10,000-row guard does not correct prior truncation.

Apply full supported filters before counting/pagination, combine sources with consistent ordering, and export the complete selected result or disclose an explicit limit. Test a matching old event beyond 500 newer non-matching events in each source. Treat absence from the current UI/export as insufficient proof that an event never existed.

### DI-10 — Unavailable actors can disappear or be mislabeled as System (P2, confirmed)

Default actor relationships on audit/history/submission/room-request models exclude soft-deleted users. Archive provenance explicitly uses `withTrashed`, so screens disagree. Hard-deleted actors cannot be recovered by `withTrashed`.

[ActivityLogController](../app/Http/Controllers/Admin/ActivityLogController.php), lines 193-195, omits the emitted `user_archived` event from its user-management classification. If its actor relationship disappears, it can attribute the action to the subject. Other system/migration events can also fall into inappropriate subject fallback. The frontend and CSV display null actors as System without evidence that an automated actor performed the action.

Existing authentication event 300, `invitation_accepted`, has neither actor nor subject FK but retains a subject snapshot; the current normalization loses that identity and renders the actor as System. No current scheduling actor is missing, so this is not a claim that present scheduling rows already exhibit soft-delete loss.

Use explicit actor provenance/type and event-specific fallback rules. Preserve genuine unauthenticated login/reset subject identity when that subject performed the action. Distinguish unavailable historical human identity from a system action, and keep the actual emitted event vocabulary aligned with readers.

### DI-11 — Preparer capacity and fallback provenance are incomplete (P2, confirmed design gap)

[ScheduleSignatories::preparers](../app/Services/Scheduling/ScheduleSignatories.php), lines 208-240, uses creators' current roles to exclude approvers, falls back to the current Program Head when no eligible creator remains, and labels every returned preparer `Program Head`, including a Secretary/Director creator. Saved official records contain a document title but not an independent actual role snapshot.

Changing a preparer's role before submission can therefore change selection of that preparer; legacy/open reconstruction can substitute a current officeholder. Drafts legitimately follow current officials, so this is not evidence of a frozen signature being overwritten. The printed capacity can nevertheless be misleading if interpreted as the creator's actual role.

Preserve creator ID and role/capacity at the relevant event, distinguish required document title from actual account role, and mark fallback officials as expected signers rather than established historical preparers. Never replace an unknown past preparer with a current officeholder without evidence.

### DI-12 — Official selection lacks complete singleton and semantic constraints (P2, confirmed exposure; no duplicate observed)

Dean and Secretary/Director/Program Head slots have application locking and database uniqueness. The global VPAA slot is outside those scope indexes; [ScheduleSignatories::currentVpaa](../app/Services/Scheduling/ScheduleSignatories.php), lines 255-259, chooses the latest active VPAA by ID. [InstitutionSetting::current](../app/Models/InstitutionSetting.php), lines 20-29, uses `firstOrCreate([])` without a singleton unique key. Concurrent creation of the initial setting, or another writer, could create multiple rows and make `first()` ambiguous.

There is presently one active VPAA and one settings row; this did not cause a demonstrated current duplicate. Signature JSON has syntax checks but no database relationship or write-once enforcement for embedded user IDs, names, titles, or frozen state. Actor-model fillable fields are not themselves proof of an exploitable request path.

Use an explicit singleton constraint for global officials/settings where required, and validate signature schema and action consistency at the trusted write boundary. Preserve assignment-at-time evidence rather than requiring a historical actor's current role to match their old role. Avoid speculative new identity abstractions when immutable snapshots and existing role-slot rules suffice.

### DI-13 — Inactive new or former officials remain eligible in current-document fallback lists (P2, confirmed code defect; current affected document not established)

[InitialDataController](../app/Http/Controllers/Scheduling/InitialDataController.php), lines 531-533 and 573-581, returns Dean relationships and role-user lists without an `is_active` filter. The returned user summary also omits `is_active`, preventing the frontend from checking eligibility. [ScheduleHistoryController](../app/Http/Controllers/Scheduling/ScheduleHistoryController.php), lines 59-64, has a similarly unfiltered auxiliary user list, although the historical PDF path does not use it to replace recorded officials.

[schedulePdf.ts](../../wicars-ui/src/pages/ClassSchedules/SchedulerPanel/schedulePdf.ts), lines 143-144, 201-202, and 229-230, selects current officials with the first matching role/department/program. [TeachingLoad.tsx](../../wicars-ui/src/pages/ClassSchedules/SchedulerPanel/TeachingLoad.tsx), lines 157-175, similarly selects a Dean, Program Head, Secretary, or Director without an activation check. `initialDataMapper.ts`, line 378, passes the API list through; `UserSummary` in `types.ts`, lines 48-56, has no activation field.

**Concrete lifecycle scenario.** Creating an inactive account while another active official holds the same slot is intentionally allowed (`UserRoleSlotTest`, lines 64-68). If the inactive account is newer, the initial-data list can put it first. An unsigned document can then name the inactive Dean or fallback preparer even though an active official exists. A replacement later deactivated can produce the same problem. Normal soft-deleted accounts are excluded by Eloquent's default scope; this defect concerns inactive, non-archived accounts.

**Existing protections / limits.** `ScheduleSignatories::currentDean`, `ReportsController`'s report user list, and `DepartmentsController`'s official lists do filter active users. Historical PDFs explicitly using `historicalDocument: true` and populated frozen signature blocks do not substitute these candidates. All current local users are active, so no existing wrong inactive-official PDF was established.

**Safeguard.** Provide consistent active-only *current officeholder* candidates. Preserve historical actor/creator lookup separately, including inactive or archived people where supported by recorded evidence. A blanket active-user filter on historical relationships would hide former officials and worsen the original problem.

### DI-14 — A vacant Dean office can retain and later stamp an inactive predecessor (P2, confirmed conditional path)

[ScheduleSignatories::refreshed](../app/Services/Scheduling/ScheduleSignatories.php), lines 188-190, replaces an unacted Dean slot with `currentDean(...) ?? previousSlot`. Deactivating or archiving the Dean with no active replacement therefore leaves the old expected official in that pending slot. A newly assigned active Dean replaces only the unacted slot; an already acted or frozen slot remains correctly unchanged.

There is a further delegation edge: a VPAA may perform Dean review. `stamp`, lines 70-73, selects the active Dean, then falls back to the existing slot if none exists and gives it a new `acted_at`. Thus the document can receive a fresh Dean signature timestamp bearing an inactive/archived predecessor's name. The actual action FK still records the VPAA, so this is incorrect document-official provenance rather than a forged authenticated Dean login. Current checked Dean actions do not demonstrate that this path has occurred.

Preserve the original expected-official snapshot as evidence, but resolve a vacant current office explicitly as unassigned. For delegated action, record the actual actor and authority, and do not promote an ineligible expected official into a newly acted slot. Retain the existing VPAA/Dean workflow and historical frozen signatures.

### DI-15 — Deactivation/reactivation requests can overwrite newer identity or assignment values (P1, confirmed lost-update vulnerability; occurrence unverified)

[Users.tsx::toggleActive](../../wicars-ui/src/pages/vpaa/Users.tsx), lines 665-689, sends the cached row's name, email, role, department, and program in a full `PUT /user/{id}` while changing `is_active`. The intention is a status change, but [UserController::update](../app/Http/Controllers/Admin/UserController.php), lines 95-140, writes all those validated fields. There is no expected version or updated-at precondition in this request contract. The user's own profile can be edited separately through `ProfileController`.

**Concrete lifecycle scenario.** A Secretary, Program Head, Director, or Dean updates their name after the administrator loaded Users. The administrator then deactivates that stale row. The full status-toggle payload can restore the older name. A stale assignment or role can similarly replace a newer administrative edit when accepted by existing validation. Every current-user-based historical display then follows the overwritten value. This can occur even if requests execute sequentially; a transaction or row lock alone does not make an old payload current.

This is a confirmed unsafe write contract, not proof that any particular existing rename was caused by a status toggle. Department/program slot locks prevent duplicate active holders but do not detect stale names or assignments. Existing audit records store post-update values and lack sufficient before/after evidence to attribute the observed historical drift to this exact path.

**Safeguard.** Make status-only operations update only status on the server while retaining authorization, active-role-slot locking, token revocation, and faculty synchronization. Add optimistic concurrency for full account/profile/assignment edits so stale requests return a conflict. Do not change historical approval IDs or substitute a successor's identity as part of account status changes.

## 7. Additional inconsistencies and conditional risks

- **Old final approvals were later withdrawn.** Forty submissions have both VPAA decision timestamps and `status = withdrawn`; withdrawal dates range from 2026-09-10 through 2026-11-09. Current code refuses recall after final VPAA approval. These records are inconsistent with today's finality policy but may reflect earlier authorized workflows. Preserve and classify them; do not relabel them as tampering or reset their status.
- **Latest membership is used as signatory provenance.** `ScheduleSignatories::attachToSchedules`, lines 149-157, and `InitialDataController`, lines 473-504 and 654-669, choose the latest section submission without filtering membership state in those selection queries. Revisions/withdrawals can make latest membership differ from the approval being represented. Current checked signed live rows were consistent: all 109 were in `faculty_assignment` with an approved, included latest submission. No existing wrong association was established. Explicit approval-version provenance is safer than assuming latest always means the relevant decision.
- **Missing history links are not uniformly data loss.** There are 687 scheduling audit records without a history-version link. Many setup, archive, and report events have no schedule snapshot to link. The formatter's broad `legacy_history` flag must not be interpreted as proof all these records are corrupted.
- **Documentation promises exceed implementation.** The frontend's `docs/plan/vpaa_activity_log_workflow.md` describes immutable actor/role records and complete server-side filtering. Current mutable joins and candidate truncation do not meet those guarantees. Update that documentation after remediation is validated; retain this dated report as the investigation baseline.

## 8. Affected module and storage inventory

`BE` below means `C:/Users/toman/backend`; `FE` means `C:/Users/toman/wicars-ui`.

| Module / workflow | Tables and models | Principal inspected code / consumers | Integrity status |
| --- | --- | --- | --- |
| Account/profile identity | `users` / `User`; `faculties` / `Faculty`; `model_has_roles`, `roles` | BE `Admin/UserController`, `Auth/ProfileController`, user requests, `UserFacultyProfileService`, `AdministrativeRoleSlots`; FE `vpaa/Users`, profile forms | Updates target one account; linked faculty synchronization is deliberate. Historical consumers remain mutable. |
| Institution President | `institution_settings` / `InstitutionSetting` | BE `InstitutionSettingsController`, `ScheduleSignatories`; FE `vpaa/Settings`, institution settings utility | Separate from VPAA user; no durable identity-change audit; singleton constraint absent. |
| Schedule submission and decisions | `schedule_submissions` / `ScheduleSubmission`; `schedule_submission_sections` / `ScheduleSubmissionSection`; `schedules` / `Schedule` | BE `DepartmentScheduleController`, `ScheduleSignatories`, `SubmissionStatusResolver`, authorization services; FE shared approval page/list/details/preview, Dean and Secretary views | Server-attributed, locked transitions; frozen signatures protected in current normal workflow. Actor displays and legacy provenance gaps remain. |
| Initial schedule payload / signing | Same submission/schedule tables; `sections`, `semesters` | BE `InitialDataController`, `ScheduleController`, `ReportsController`; FE `initialDataMapper`, scheduler types, print components | Current relationships and latest submission decorate payloads; JSON signatures and actor relationships have different identity semantics. |
| Schedule history / reuse / export | `schedule_history_versions` / `ScheduleHistoryVersion`; `schedule_history_items` / `ScheduleHistoryItem` | BE `ScheduleHistoryRecorder`, `ScheduleHistoryController`, `ScheduleSemesterArchiver`, `ApprovedScheduleHistory`, `ScheduleHistoryExporter`, revision recording; FE `vpaa/ScheduleHistory` | Historical schedule rows preserved separately; actor labels mutable, legacy signature blocks inconsistent or absent, JSON export can rehydrate current approvers. Reuse creates a new draft rather than replacing history. |
| Scheduling activity / archive audit | `scheduling_audit_logs` / `SchedulingAuditLog` | BE workflow controllers, report/CRUD recorders, `ArchiveAuditRecorder`, `ArchiveProvenance`, `ActivityLogController`; FE Activity Log and administrative activity panels | Actor-ID only, mutable display, no database append-only boundary, candidate-limited reads. |
| Account/authentication trail | `authentication_audit_logs` / `AuthenticationAuditLog` | BE `AuthenticationAuditService`, Auth/User/Profile controllers, migration audit writes; FE Activity Log | Subject snapshots exist; actor snapshots absent; 791 provable display differences and 12 lost subject links. |
| Room borrowing | `room_requests` / `RoomRequest`; `room_request_windows` / `RoomRequestWindow` | BE `RoomRequestController`; FE `shared/RoomRequests`, room request modals/utilities | Reviewer/time overwritten by revocation; approval/rejection audit coverage missing; current-user names. |
| Room transfers / recovery | `room_recovery_plans` / `RoomRecoveryPlan`; `room_recovery_cases` / `RoomRecoveryCase` | BE `RoomRecoveryService`, `RoomRecoveryController`; FE shared approval page, room reassignment components | Transactional review IDs; creator name and print officials resolved live; no equivalent permanent transfer signature block. Pruning is limited to expired unsubmitted previews. |
| Notifications | `system_notifications` / `SystemNotification` | BE `SystemNotificationService`, `SystemNotificationController`, delivery job; FE notifications and activity feeds | Stored messages can preserve old names, actor relationships remain live. Delivery/deduplication is unsuitable as sole approval audit. |
| Generation/recommendation provenance | `schedule_generation_runs` / `ScheduleGenerationRun`; `schedule_recommendations`; related scheduling audits | BE generation/commit/recommendation services, jobs and controllers | User references share lifecycle risk; no evidence of a writer changing Dean/VPAA/President references together. |
| Parent/relationship retention | `departments`, `programs`, `semesters`, `sections`, `courses`, `rooms`, faculty tables | Their models/controllers, archive services, migrations | Mostly soft archives at HTTP level; hard-delete cascades/nulling can remove approval context. Schedule-history original schedule IDs intentionally are provenance values without live-schedule FKs. |
| UI exports | Signature JSON plus current-user/settings payloads | FE `schedulePdf.ts`, `teachingLoadSignatories.ts`, `TeachingLoad.tsx`, `InstructorTeachingLoadButton.tsx`, approval previews; BE JSON exporter | Historical PDF guards work; JSON/current/mixed report paths need consistent identity and missing-data rules. |
| Model hooks / events / maintenance | Above tables; no actor rewrite trigger | BE `User::saved`, `Schedule` hooks, `AppServiceProvider`, `LiveUpdateRecorder`, archive hook, events, console schedule | User hook only scopes faculty-role synchronization to that user. Live events refresh caches, not audit identity snapshots. No observer/trigger was found that renames all approvers. |

Other structural/cache/session/permission tables were inspected for relevant relationships through schema metadata. No approval identity storage was found in caches, queues, or session tables that independently explains a multi-actor rewrite.

## 9. Causes checked and evidence against false positives

1. **Shared mutable user reference:** confirmed as the mechanism for changing many historical labels belonging to the same account. Shared Dean/VPAA IDs were not found. The President has no account FK in this workflow.
2. **Unrelated updates overwriting approver IDs:** not found in inspected account/profile/faculty/settings writers. Updates target a route-bound/authenticated account or its unique linked faculty row. Signatory/approval writes occur in explicit workflow paths and the legacy migration.
3. **Mass assignment:** account requests validate fields, controllers use explicit update arrays, and the frontend Users form sends an explicit payload to `/user/{editingId}` and replaces only that user's UI state (FE `Users.tsx`, lines 518-537). President settings PATCH includes only President name/title. Actor IDs are assigned server-side. A broad `$fillable` or `RoomRecoveryPlan::$guarded = []` merits disciplined use but does not demonstrate a reachable actor-injection endpoint here.
4. **Cascading updates / triggers:** actual actor FKs use `ON UPDATE RESTRICT`; no triggers exist. Current evidence does not support an `ON UPDATE CASCADE` explanation. Cascading deletes/nulling are real separate retention concerns.
5. **Role change:** current profile/role joins can change historical labels; frozen signature blocks normally do not. User-role synchronization is scoped to the same account, and current role pivots match stored account roles.
6. **Frontend shared object mutation:** no multi-user overwrite was found in the checked edit payload/state path. Shared API data can still display live backend identity and stale caches can delay visible changes; neither proves history rows were rewritten.
7. **Approval concurrency / scope:** department workflow locks, transaction rollback, stage rechecks, revision allocation, explicit submission targeting, and server-side attribution are present. Manual schedule status APIs reject bypassing approval. These protections must be retained during remediation.

## 10. Safe remediation design and acceptance checks

Keep scheduling and approval transitions intact. Separate three concepts explicitly: immutable action actor, immutable document official when recorded, and current account/officeholder. Add snapshots to the existing recording boundaries and make all historical consumers use them consistently. Use actor/account IDs as stable references, not names or roles as identity keys.

Do not backfill unknown actor names from current users or copy today's President into old records. Preserve the 88 inconsistent blocks and missing legacy data as evidence. Maintain a separate reconciliation ledger with record ID, event time, original value, suspected issue, proposed interpretation, supporting source, confidence, and reviewer decision. Use additive correction events for approved future repairs.

Future regression checks, on an isolated disposable test database only:

1. Approve through Secretary/Director/Program Head, Dean, and VPAA; rename each account and change President settings. Historical actor names/roles and all recorded signature slots remain unchanged in API, UI, CSV, JSON, and PDF.
2. Change only a linked faculty title/credential. Preserve its legitimate profile synchronization and record the account identity transition without changing historical actors.
3. Transfer an account between roles/departments/programs and archive it. Old events keep actor identity, capacity, and department; current account links show current state separately.
4. Attempt client-supplied creator, reviewer, actor, or signature IDs through every relevant write endpoint. Trusted server attribution wins or the request is rejected.
5. Race Dean/VPAA decisions against recall/resubmission. The losing request does not stamp an actor; business writes and complete historical snapshots roll back together on audit failure.
6. When VPAA performs Dean review, retain the VPAA as actual actor and clearly identify the document's Dean official. A President document slot never falsely claims an authenticated President action.
7. Approve then revoke a room grant with a different authorized user. Both decisions and their actor snapshots/times survive. Notification failure cannot remove the audit event.
8. Archive a semester with several approval cohorts. Each item retains the appropriate signature block; missing legacy data remains explicitly unavailable in every export.
9. Search and export an old matching audit record behind more than 500 newer non-matches in both sources. Totals are stable across pagination and exported matches are complete.
10. Soft-delete and, where policy permits, attempt hard deletion of historical actors/parents. Protected history survives; unavailable actors are never silently labeled System.
11. Verify the runtime database account cannot update/delete completed audit/history records. Validate legitimate creation after eliminating create-then-update snapshot assembly.
12. Test legacy migration/reconciliation against copied data with later reviewer timestamps. Earlier events do not acquire future decisions or unverified current officeholders.

Relevant existing coverage was inspected in `ScheduleSignatoryHistoryTest`, `ScheduleWorkflowIntegrityTest`, `ScheduleRecallReviewRaceTest`, `ScheduleApprovalTargetingTest`, `ActivityLogTest`, `UserFacultyProfileLinkingTest`, `RoomRequestTest`, and related frontend signatory/approval tests. Those files include useful signature, authorization, transaction, and concurrency scenarios, but the examined Activity Log tests do not establish immutable actor names after account changes. No test suite was executed during this initial investigation.

## 11. Read-only follow-up queries

Run only through a read-only connection/transaction. These examples inspect existing evidence; they do not repair or delete anything.

```sql
-- Stored subject identity versus current account identity; do not equate all subjects with actors.
SELECT a.id, a.event, a.created_at, a.actor_user_id, a.subject_user_id,
       JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$._subject.name')) AS recorded_subject_name,
       u.name AS current_subject_name
FROM authentication_audit_logs a
JOIN users u ON u.id = a.subject_user_id
WHERE JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$._subject.name')) <> u.name;

-- Historical subject references already lost, with preserved original IDs.
SELECT id, event, created_at, actor_user_id, subject_user_id,
       JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._subject.id')) AS recorded_subject_id
FROM authentication_audit_logs
WHERE subject_user_id IS NULL;

-- Inspect event time and stored decision times without mutating snapshots.
SELECT id, action, created_at,
       JSON_UNQUOTE(JSON_EXTRACT(signatories, '$.reviewed.acted_at')) AS dean_acted_at,
       JSON_UNQUOTE(JSON_EXTRACT(signatories, '$.recommended.acted_at')) AS vpaa_acted_at,
       JSON_UNQUOTE(JSON_EXTRACT(signatories, '$.approved.acted_at')) AS document_approved_at
FROM schedule_history_versions
WHERE signatories IS NOT NULL
ORDER BY id;

-- Legacy finality-policy inconsistency; preserve these rows.
SELECT id, status, vpaa_reviewed_by, vpaa_reviewed_at, withdrawn_by, withdrawn_at
FROM schedule_submissions
WHERE status = 'withdrawn' AND vpaa_reviewed_at IS NOT NULL;

-- Room workflow audit coverage must be assessed separately from notification delivery.
SELECT action, COUNT(*) AS events
FROM scheduling_audit_logs
WHERE action LIKE 'room_request%' OR action LIKE 'room_grant%'
GROUP BY action;
```

An absence of mismatches today does not prove prior rows were never changed together. Verify any alleged specific incident against the deployed code revision, original signed/exported documents, time-appropriate backups, and independent logs. Preserve original evidence and uncertainty throughout that comparison.

## 12. Expanded audit: former, deactivated, and newly assigned officials

### 12.1 Distinguish account state from historical identity

Deactivation means `users.is_active = false`. It does not soft-delete the account, change its primary key, or automatically remove it from a normal `belongsTo(User)` relationship. Archival means setting `deleted_at`, which excludes the account from normal Eloquent user relationships while retaining its raw row and FKs. Hard deletion is a third operation and can null historical FKs. The 12 already-unlinked authentication subjects are evidence of linkage loss beyond ordinary soft archival; the precise operation was not established.

Creating a successor account gives it a distinct ID. Changing the old account's name to the successor's name instead repurposes the same mutable identity and changes all historical displays depending on it. The UI permits legitimate name edits, so code cannot infer whether a rename is a correction for the same person or substitution of a different person. Preserve snapshots in either case; use a new account for a genuinely different officeholder. Role-based usernames, even when automatically numbered, are not a substitute for stable person/account identity.

### 12.2 Role-specific lifecycle matrix

| Role | Deactivate or archive former holder | Assign a newly created holder | Effect on completed versus pending records | Reactivation / restoration |
| --- | --- | --- | --- | --- |
| Dean | Deactivation retains actor relationships; archival hides the actor from default relationships. Current-Dean lookup excludes both inactive and archived accounts. | New account occupies the department's active Dean slot; may review existing pending department work. | Saved acted/frozen Dean signatures stay with the former official. Only an unacted expected Dean slot follows the new active Dean. Without a replacement, old-slot fallback is unsafe (DI-14). | Another active Dean in that department blocks reactivation and archival restore through slot checks. |
| Secretary | Deactivation preserves old submitter/creator IDs; archival makes default submitter/actor relationships disappear. Archived creators can still be found by the backend's `withTrashed` preparer lookup. | New account occupies the department-office slot and can operate on existing work according to capabilities and department scope. | The successor does not automatically become the original submitter or creator. Old frozen preparer blocks remain. Current-role fallback and missing-identity labels can still substitute or mislabel people. | Reactivation/restore is blocked if an active Secretary or Director holds the department office. |
| Director | Same deactivation/archive distinction as Secretary; its linked faculty profile is synchronized or retained according to that operation. | Shares the Secretary department-office slot; a Director and Secretary cannot both be active in that office. | Same creator/submitter protections and display gaps as Secretary. A missing Director submitter can be labeled `Secretary` by the shared UI fallback. | Same shared-office conflict checks; legacy Director role review also remains enforced. |
| Program Head | Deactivation preserves ID; archival hides default actor/submitter relationships but not archived creator lookup using `withTrashed`. | New account is constrained to a program in the selected department; only one active Program Head per program. | Existing creator IDs and frozen names do not become the new Program Head's. When no eligible creator is found, current-Program-Head fallback can name the successor; it must not imply that the successor authored old work. | Reactivation/restore is blocked when the program's active Program Head slot is occupied. Other programs in the same department have independent slots. |

The unchanged frozen/acted signature behavior is established by source inspection and existing test scenarios, not by executing lifecycle mutations during this audit. Closed legacy records without trustworthy signatures must remain unknown rather than being filled from new appointments.

### 12.3 Cross-role edge cases and affected displays

| Scenario | Verified behavior | Integrity assessment |
| --- | --- | --- |
| Deactivate a person after they completed an action | Most actor relations still resolve the same account; token revocation and active middleware prevent future use through normal authenticated paths. | Historical presence is appropriate. Continued historical visibility must not be mistaken for permission to act now. Name/role remain mutable unless snapshotted. |
| Archive a person after approval/submission | Default log, submission, room-review, and history actor relations can resolve null; archived-aware preparer/provenance paths can still show the person. | Same person's attribution differs across screens. Use recorded identity; do not globally filter historical actors to active users. |
| Missing former Secretary/Director/Program Head submitter | Shared approval page uses `submission.submitter?.name ?? 'Secretary'` (`ScheduleApprovalPage.tsx`, line 441). | Can assert the wrong role when identity is unavailable. Preserve submitter name/role snapshots and use an explicit unavailable label. Actual current archived-submitter incidence was not established. |
| New inactive account exists beside active predecessor | Role-slot policy permits inactive accounts; some current-official lists contain both without activation metadata. | An unsigned current-document fallback can select the newer inactive account (DI-13). This is not approval authority or proof a frozen snapshot changed. |
| Old Dean leaves before review | Pending unacted slot follows a new active Dean if present; otherwise retains old expected official. VPAA delegated review can stamp that fallback. | Vacancy and delegated action must not invent a new action by the predecessor (DI-14). |
| Old Dean leaves after reviewing, before VPAA finalization | `acted_at` prevents ordinary refresh of the Dean slot; a new Dean's appointment does not replace that completed review. | Correct existing protection. The separate live reviewer relationship may still display renamed/current account details or disappear on archival. |
| Secretary/Director/Program Head leaves before submission | Backend preparer lookup retains the creator through inactive/archived account lookup, provided current role is not excluded. The browser's unsigned-document creator lookup uses its ordinary user list instead. | Backend and frontend can disagree about an archived preparer. Preserve author provenance separately from expected current signers. |
| Creator becomes Dean or VPAA before submission | Preparer reconstruction excludes their current approver role, even if they originally entered the work as Secretary/Director/Program Head. | Can lose the original author and fall back to a later Program Head (DI-11). A current role is not the role at authorship. |
| Account moves department/program or role | Account update changes current assignment and linked faculty fields, but does not itself rewrite old submission actor IDs or frozen names. Current-account readers see the new role/department. | Preserve action-time assignment, including `program_id`. New scope-based access to pending work is not evidence the new holder performed old actions. |
| Administrator toggles status from an old Users row | Frontend sends old identity/assignment values along with status, without an expected version. | Can undo newer edits and make related historical labels change together (DI-15). Live refresh reduces some stale windows but does not guarantee write freshness. |
| Restore/reinstate predecessor after successor appointment | Restore locks the office scope and rechecks active occupancy; activation uses the same slot rules and unique-index backstop. Restore policy blocks the archived holder while that slot is filled even when the archived account was inactive. | Correct protection against duplicate active officials. Keep these checks when narrowing status writes. |

Principal lifecycle sources: [UserController](../app/Http/Controllers/Admin/UserController.php), lines 34-79, 95-140, 156-172, and 258-276; [ArchiveController](../app/Http/Controllers/Admin/ArchiveController.php), lines 186-210; [ArchiveRestoreChecks](../app/Services/Archive/ArchiveRestoreChecks.php), lines 117-143; [User](../app/Models/User.php), lines 30-35 and 136-154; [UserFacultyProfileService](../app/Services/UserFacultyProfileService.php), lines 65-84 and 105-130; [EnsureUserIsActive](../app/Http/Middleware/EnsureUserIsActive.php), lines 15-31. `AuthController` also checks inactive status in ordinary login, Google login, and related authentication flows.

### 12.4 Required evidence and acceptance criteria for every role

Apply the following matrix independently to Dean, Secretary, Director, and Program Head, with the correct department or program scope. Existing tests cover some Dean and Secretary scenarios, but coverage must not be inferred for every role merely because they share code.

1. Record a preparation/submission/review action by account A. Deactivate A and appoint distinct account B. Old IDs, actor name, actual role/capacity, department/program, decision time, and stored signatures remain attributable to A. A new action must record B.
2. Repeat with archival of A. Historical API, Activity Logs, approval history, room requests, archive provenance, CSV, JSON, teaching loads, and PDFs retain A's recorded identity or explicitly identify missing evidence. They never silently call A `System`, call a Director/Program Head `Secretary`, or substitute B.
3. Deactivate the Dean before review with no successor. Then inspect the expected-official slot and perform permitted delegated review only in isolated tests. The office is visibly vacant and no new action is falsely stamped as the predecessor's.
4. Deactivate the Dean after review but before VPAA finalization. Appoint a new Dean; the completed Dean review remains A's, while future unacted reviews may use B.
5. Create inactive B while active A holds the same role slot. Current unsigned documents choose A or an explicit vacancy, never B. A historical document that legitimately records an inactive A must still show A.
6. For Secretary, Director, and Program Head creators, deactivate/archive A before submission and change A's role before submission in separate cases. Preserve the original author and capacity; do not infer authorship from the successor's role. Capture the actual submitter separately.
7. For a Program Head, test program transfer and replacement within one program while another program's holder remains active. Historical program attribution stays with the event; current role-slot and writable-program restrictions remain enforced.
8. Attempt to reactivate or restore A while B holds the same office. Reject the conflict without modifying history or B's account. Include Secretary-to-Director and Director-to-Secretary replacement cases.
9. Load a stale Users row for A, then update A's profile or assignment through a separate request. Status toggling must preserve the newer fields; a stale full update must be rejected rather than silently winning.
10. For any genuinely different successor, create a new account instead of renaming A. Verify old approvals and historical actors keep A, while new/current-officeholder selectors use B only where appropriate.

Future lifecycle recording should retain actor and subject snapshots, old/new activation and archival states, old/new role, department and program, and any explicit predecessor/successor linkage supported by the business workflow. A generic `user_updated` event with only post-update values cannot prove which lifecycle transition occurred. No existing lifecycle or approval records were changed to add this information during the investigation.


## 13. Remediation and prioritized follow-up (2026-11-14)

The user subsequently authorized implementation. The earlier read-only investigation restriction was superseded by ?Fix it now.? Existing history was not repaired, deleted, merged, reset, or retrospectively populated with current names. The following changes apply to Dean, Secretary, Director, Program Head, VPAA, and configured President signatories. The President remains a document-signatory setting; no President login or additional approval stage was introduced.

### 13.1 Implementation status by original finding

| Finding | Priority | Safeguard implemented | Remaining evidence or operational work |
| --- | --- | --- | --- |
| DI-03 | First: prevent irreversible loss | Model and native database append-only guards on both audit tables and history versions/items; retained parent/account FKs restrict delete/update; approval containers cannot be deleted. Recorded creator IDs/snapshots, completed actor IDs/times, acted signature slots, frozen blocks, notification actors, and existing room decisions are protected. Live section/recommendation audit references retain their IDs without a destructive SET NULL cascade. | Administrators with schema privileges can bypass any in-database guard. Maintain independently retained backups and separate administrative credentials. |
| DI-01, DI-06, DI-10 | First: stop false attribution | Event-time actor snapshots are captured centrally at insertion and read by Activity Log, CSV, history, JSON export, archive provenance, revision changes, semester transition history, conflict-resolution history, and notifications. Queued notices capture identity before delivery. Legacy self-actions can use their original subject snapshot when it establishes the actor; administrative actions cannot. Missing identity stays unavailable, never today's official or an invented System actor. | Legacy identities without contemporaneous evidence cannot be recovered from this database alone. |
| DI-15 | First: stop unrelated account overwrites | Status changes use a dedicated PATCH endpoint accepting only `is_active`. Account/profile edits require an expected identity version, checked against a locked fresh row; stale writes return 409. Frontend sends the version and invalidates the previous Users cache format. | Other independently deployed clients must send the new account/profile version token. |
| DI-04 | Next: preserve change evidence | Profile/account status and identity audit metadata retain before/after values including program and active state; faculty-affix changes are audited. President signatory changes write an atomic before/after audit. | No missing historical before-values were invented. |
| DI-05 | Next: preserve separate decisions | Request submission, approval, rejection, cancellation, and revocation append durable actor-stamped events. Revocation retains the original approval reviewer/time. Decision arrays accept append operations and reject edits/removal of earlier entries. | Legacy requests have no original actor-name snapshots and remain marked unavailable. |
| DI-02 | Next: contain known historical inconsistency | Removed retrospective signatory backfill from the earlier migration source; new history signatures are supplied at insert. No later update to an existing event is permitted. Added read-only `integrity:audit-actors` inventory and an issue ledger. | 88 original versions contain 147 signature slots dated after their history event. Preserve these as suspect evidence. Reconcile only against independent contemporaneous documents; any future correction must be a separate attributed record, never an overwrite. |
| DI-08 | Next: preserve archive/transfer identities | History exports use their own event and per-section signatures; old missing identities never use a later submission or current official. New room transfers retain their own preparer/review/signatory block. Historical PDFs and teaching-load exports show unavailable names when missing. | Six old semester archives and their 1,399 items lack signatures. No reconstruction from current officials was attempted. |
| DI-07, DI-11 | Then: make capacity explicit | Signature slots retain actual action actor and direct/delegated/document-signatory capacity. Preparers retain their role at schedule creation and their submitted document name. Missing preparers never select a replacement Program Head. Transfer preparers use the transfer originator snapshot. | An old signature label alone is not proof that the named official authenticated or performed an approval. |
| DI-12, DI-13, DI-14 | Then: enforce eligible officials | Database singleton constraints enforce one settings row and one active nonarchived VPAA. Existing department/program office-slot rules remain. Current official lists exclude inactive/archived accounts; a vacant delegated Dean slot records the vacancy and actual VPAA actor, and cannot later acquire a replacement Dean. | Role changes, activation, restoration and new appointments remain subject to existing office-slot rules. |
| DI-09 | Then: complete evidence retrieval | Removed the 500-candidate source truncation. Filters/totals evaluate all source records using chunked iteration and a bounded selection of recent results; CSV requires narrower filters above 10,000 matching entries. | Large installations should consider indexed normalized event storage or database pagination, preserving identical filter semantics. |

### 13.2 Data already affected: preserved, not silently repaired

The initial investigation established 791 provable actor-name differences between original self-action snapshots and the names shown through current accounts. Reading those original subject snapshots now preserves their recorded identity. This did not establish that approval actor IDs were overwritten: the checked Dean/VPAA and audit/history ID comparisons had zero mismatches.

The private read-only inventory of the SQL backup identifies 2,496 event records whose original actor name cannot be established by the supported evidence rule, 115 workflow rows without actor snapshots, the 88 chronology-inconsistent versions above, and six unsigned semester archives. These are missing-evidence counts, not a claim that every listed record was maliciously altered. Former Dean/Secretary accounts missing from the database remain separate from their successors; an administrative archive subject is never mislabeled as its own actor.

The baseline SQL backup contains 1,986 authentication events: three additional events occurred after the initial 1,983-event fingerprint. A separately restored baseline matched the migrated verification copy across every original column and row of all 11 checked tables. The copy's user/account and authentication differences from the earlier fingerprint were therefore already present in the backup, rather than introduced by a migration.

### 13.3 Verification and rollout evidence

- 607 targeted backend tests, 6,511 assertions passed on isolated SQLite, covering account/profile freshness, all office-role lifecycles, approval/rejection/withdrawal, Director parity, room requests/transfers, history/reuse/export, archival, semester transitions, notifications and ordinary schedule/section deletion/reset.
- 107 frontend tests across Users, Activity Log, Schedule History, shared approval flows, historical schedule PDF signatures, teaching-load signatures, room transfer PDFs, and notifications passed. Production TypeScript/Vite build passed; scoped ESLint found zero errors and four existing React/TanStack warnings.
- 17 direct MariaDB guard checks passed on the restored verification database; probes were rolled back. A restricted-account check confirmed normal account/audit/history insert transactions work and audit UPDATE/DELETE is denied (10 checks, rolled back).
- The additive integrity migrations were verified on a full restored MariaDB copy. All 11 tables matched the original SQL backup for their original columns and rows. A forward reconciliation migration also handles databases with an earlier revision of the guards already installed; 184 targeted backend tests (2,607 assertions) passed after adding that migration.
- Final local rollout completed at 2026-11-14 02:04:18 +08:00. All five integrity migrations are recorded in `scheduling_db`; every original column and row in the 11 fingerprinted tables matched immediately before/after. New nullable snapshot columns intentionally remain NULL for legacy rows. The application uses a dedicated per-table runtime account without schema privileges or audit/history UPDATE/DELETE grants. Maintenance ended and caches/workers received a refresh/restart signal.
- The final pre-upgrade backup is `before-final-guards.sql` (164,540,771 bytes), SHA-256 `b0180a9349d21aee0b416843ea578fa90548ea4bcf8a3e0eff2ffd3c7301855c`. At the final upgrade the preserved totals were 67 submissions, 842 history versions, 85,231 items, 1,563 scheduling logs, and 2,008 authentication logs. Changes since the earlier audit/backup predated the maintenance window and are included in the fresh backup and matching before/after fingerprints.
- SQL backups, hashes, original-record fingerprints, native guard results, test output, and the detailed ID ledger are stored under `storage/app/private/integrity-backups/2026-11-13/`; these contain private evidence and are not public/downloadable application artifacts.

See [Historical actor integrity operations](historical-actor-integrity.md) for the runtime credential boundary, deployment commands, and remaining work. No remote/production database was inspected or deployed as part of this local remediation.


The final consumer sweep also covered submission revision-change feeds (`DepartmentScheduleController::submissionChanges`), conflict-resolution receipts (`ConflictResolutionLog`), and semester activation history (`SemesterController::activationHistory`). These now use recorded actors (or an original recorded actor-name field), and do not rehydrate historical identities from live users. The forward migration explicitly reconciles the old optional section/recommendation FK behavior and refreshes completed-slot/notification/transfer guards without changing historical rows.

## 14. System-wide follow-up

The subsequent [system-wide follow-up ledger](system-wide-actor-integrity-follow-up-2026-11-14.md) documents eleven further findings, implemented fixes, affected modules/tables, native verification, preservation fingerprints and remaining priorities. It also distinguishes proven display/retention vulnerabilities from unproven historical tampering. Original findings and evidence counts above remain the investigation baseline.
