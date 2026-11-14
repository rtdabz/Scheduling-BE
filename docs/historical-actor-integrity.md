# Historical actor integrity: deployment and operations

Implemented locally on 2026-11-14. The [audit and prioritized findings](data-integrity-audit-2026-11-13.md) retain the investigation baseline, confirmed legacy gaps, implementation status, and verification results.

## Identity rules

- An account identifies one person. Create a separate account for a successor; deactivate or archive the predecessor. Never rename an existing account to represent a different person.
- Current assignment, role, availability and display name remain editable through authorized account/profile workflows. Historical events use their insertion-time identity snapshot, including role, department, program and active state.
- Status changes use `PATCH /api/user/{id}/status` with only `is_active`. Full account updates and profile updates require `expected_version`, obtained as `account_version` from the latest account/profile response. A stale edit returns 409 and must be reloaded.
- Actual action actors and document-signature officials are separate facts. VPAA delegation preserves the actual VPAA actor. A configured President signature does not establish a President login or a separate approval action.
- Missing historical identity is unavailable. Legacy self-action subject snapshots can establish an actor when their ID and event semantics agree. An archived subject is not automatically the administrator who archived it.
- Corrections require a new attributed event referring to the original. Do not change or delete original history, clear actor references, backfill names from current users, or disable retention triggers.

## Database safeguards and normal workflows

Both audit tables, schedule history versions, and history items accept inserts and reads. Model hooks and native SQLite/MariaDB/MySQL triggers reject updates and deletes. Historical parent/account FKs restrict cascading deletion and key updates. A schedule history item's original schedule ID intentionally remains independent of the live schedule table.

Submission and room-request containers cannot be deleted. Their states can progress; completed actor IDs/times, recorded actor snapshots and acted/frozen signature slots cannot be replaced. Room revocation/cancellation appends a separate decision while retaining its original approval. Submitted transfer plans cannot be converted back to disposable previews; expired unsubmitted previews can still be pruned.

Deleting or regenerating live schedules and deleting eligible live sections still works. Audit references to disposable live sections/recommendations retain their recorded IDs without an FK that clears the audit row. Their event snapshots remain the authority for historical details. Normal soft archival, role-slot rules, scheduling, review, rejection, recall, resubmission and room reassignment are preserved.

Notification read correction (2026-11-14): MariaDB 10.4 compared unchanged `OLD`/`NEW` JSON actor-type strings as unequal inside the notification retention trigger, blocking both read endpoints. [The forward correction](../database/migrations/2026_11_14_030000_fix_notification_read_identity_guard.php) compares extracted JSON as binary values with null-safe equality. SQLite also checks JSON types to retain the distinction between missing paths and explicit nulls. Actor IDs and snapshots remain protected; read timestamps and unrelated metadata can change.

The correction was applied locally after a full backup and restored-copy verification. Restricted-account API checks returned 200 for both read endpoints, with unread counts changing from 6 to 5 to 0; actor changes remained blocked. Verification writes were rolled back, and all 1,086 original notification records matched their pre-migration fingerprint. Regression coverage is in [SystemNotificationReadIntegrityTest](../tests/Feature/SystemNotificationReadIntegrityTest.php); notification authorization checks passed on SQLite and MariaDB. Private backup and verification evidence are under `storage/app/private/notification-read-fix-20261114-134817/`.

## Deployment outside this local installation

Back up the complete database and capture original-column fingerprints before migration. Verify on a restored copy first. Deploy the backend and frontend together because account/profile writes now require a version token.

Use a separate administrative database identity for these migrations:

```powershell
php artisan migrate --force `
  --path=database/migrations/2026_11_13_200000_add_historical_actor_snapshots.php `
  --path=database/migrations/2026_11_13_210000_enforce_historical_actor_retention.php `
  --path=database/migrations/2026_11_13_220000_protect_recorded_approval_identities.php `
  --path=database/migrations/2026_11_14_000000_preserve_completed_identity_evidence.php `
  --path=database/migrations/2026_11_14_010000_finalize_historical_identity_guards.php `
  --path=database/migrations/2026_11_14_020000_retain_remaining_workflow_actors.php `
  --path=database/migrations/2026_11_14_030000_fix_notification_read_identity_guard.php
```

The migrations add nullable JSON columns without rewriting legacy records, replace unsafe FK actions, add singleton constraints, and install native retention guards. Duplicate active VPAA/settings rows stop the migration for review; they are never merged or deleted. These evidence-preserving migrations intentionally reject rollback. Apply a reviewed forward correction if necessary; never discard retained snapshot columns to roll back an application release.

Use table-specific runtime grants:

| Table group | Runtime privileges |
| --- | --- |
| `authentication_audit_logs`, `scheduling_audit_logs`, `schedule_history_versions`, `schedule_history_items` | SELECT, INSERT |
| `schedule_submissions`, `room_requests` | SELECT, INSERT, UPDATE |
| `login_geolocations` (approximate login location; pruned by `model:prune`) | SELECT, INSERT, DELETE |
| `migrations` | SELECT |
| Other operational tables | Only required SELECT, INSERT, UPDATE, DELETE privileges; native guards still apply |

The runtime account must have no global or schema-wide grants, DDL, DROP/TRUNCATE, ALTER, TRIGGER, FILE, SUPER or GRANT OPTION. Keep administrative credentials outside the application runtime configuration. Provision explicit grants for any future new table as part of its deployment. Retain the account that defines the database triggers, or deliberately transfer trigger ownership with administrative review.

After deployment, clear application/config caches and gracefully restart managed workers. Check `CURRENT_USER()` through a newly bootstrapped application connection; changing `.env` does not establish that an existing worker has reconnected. Verify ordinary insert/update workflows under the restricted identity, and confirm no-op audit UPDATE/DELETE statements are denied. Verify original-column fingerprints before leaving maintenance. The local installation completed these steps and passed the fingerprint comparison across 11 tables.

## Read-only inventory and remaining priorities

```powershell
php artisan integrity:audit-actors --output=storage/app/private/actor-integrity-ledger.json
```

This command reads records and optionally writes a private JSON report. It never changes database records. Chronology checks compare whole seconds because stored history timestamps do not preserve subsecond precision.

1. Preserve and independently retain SQL backups, rollout fingerprints and the issue ledger. Copy critical evidence to storage the runtime account cannot overwrite; configure retention and restore checks.
2. Reconcile the 88 chronologically inconsistent legacy versions against independently dated documents or original exports. The local ledger identifies 147 affected signature slots. Preserve the suspect originals and record any proven correction separately.
3. Review the six unsigned legacy semester archives and missing former-account identities. Recover only from contemporaneous evidence. A current officeholder, current role, or matching name cannot establish a past actor.
4. Repeat the verified deployment and credential separation in each other environment. Production was not accessed or deployed during this local task.
5. For larger audit volumes, replace full-source scans with indexed normalized event queries while preserving complete filters/totals. Consider independently verifiable event digests or external immutable retention for protection against privileged database administrators.

Do not reset or reseed the database, restore an old backup over active records, rewrite frozen signatures, or reassign a predecessor's history to a successor as a repair technique.

## Wider follow-up

The [system-wide follow-up ledger](system-wide-actor-integrity-follow-up-2026-11-14.md) records additional fixes in reports, teaching history, queued generation, archive transactions, import confirmations and frontend mapping/printing/caches. Local rollout now includes seven integrity migrations and 24 native guards, with original-column fingerprints preserved across 14 tables.

Generation/recovery/import origins capture prospective actor snapshots. Their original actor IDs and snapshots cannot be replaced, including legacy nulls. Import confirmations and record JSON cannot be edited or deleted; parent FKs restrict cascading loss. Ordinary generation status/result updates, recovery metadata updates and insertion-time import summary completion remain allowed. Runtime table grants already cover these existing tables; no schema privileges are required.

The read-only inventory now includes these three origin tables. It reports 602 workflow rows without actor snapshots locally (115 previously covered rows plus 487 origin rows). Existing import confirmation fields remain original evidence even where the new full actor snapshot is absent.
