# Role-based scheduling notifications

`SystemNotificationAudience` owns the event allowlist and department/program scope. Both delivery paths (`notifyRoles` and direct/queued `createForUsers`) enforce it. Unknown event types are denied until explicitly registered.

| Event | Department office and affected Program Heads | Dean of the department | VPAA |
| --- | --- | --- | --- |
| Draft edits, program rooms, instructor assignment/release/completion, teaching delegation | Yes | No | No |
| Submission or resubmission | Status | Review | No |
| Dean return | Revision/status | Status | No |
| Dean approval | Status | Status | Final review |
| VPAA return or final approval | Status | Status | Status |
| Recall from Dean review | Status | Queue changed | No |
| Recall from VPAA review | Status | Status | Queue changed |
| Room schedule reassignment submitted | No | Review | No |
| Room schedule reassignment approved by Dean | No | No | Final review |
| Room schedule reassignment final decision | Current department office and the requesting Program Head | Current department Dean after VPAA decision | No extra self-notice |
| Room disruption | Affected department office and programs | Alert if awaiting Dean review, or already finally approved | Alert if awaiting VPAA review, or already finally approved |

Secretary and Director share the department office workflow. Program Heads receive program-specific events only for their assigned program. Department-wide policy changes can reach all Program Heads in that department. The office receives relevant department progress even when a Program Head owns the affected program; this does not grant editing or approval authority.

Cross-department instructor changes inform both the offering department's affected program and the assigned teaching department's responsible program/office. Teaching-department notices identify the delegated course without exposing unrelated progress totals from the offering department.

Room lending retains its separate existing workflow: the owning department office decides requests; the requesting office receives the result; VPAA receives informational lending progress. These VPAA messages do not ask for approval. Program Heads and Deans do not receive room lending notices.

## Authorization and consistency

- Recipients must be active, unarchived accounts. Delivery re-reads users so queued recipient models cannot retain old privileges. Queued lending notices also retain their intended role audience, and obsolete pending-review requests are dropped.
- Event department and affected program IDs are captured in notification metadata. Submission reviews use only included sections; recalled programs do not receive another program's remaining approval notices. The recipient's role and Program Head assignment are captured too, so transfers outside the original scope revoke old feed access. Switching between the equivalent Secretary and Director office roles preserves relevant history.
- Lists, total unread counts, individual read actions, and read-all use `SystemNotification::visibleTo`. Counts are computed over the whole authorized unread set, independently of the displayed page limit.
- New notifications and read changes invalidate the recipient's private channel. Broadcasts contain no notification payload, and a user can authorize only their own channel. General scheduling broadcasts do not populate notification feeds.
- Notification event IDs survive queue retries and prevent duplicate delivery even after a notice has been read. A unique `(user_id, event_id)` database key and native `firstOrCreate` also prevent concurrent deliveries of one event. Separate workflow actions get separate IDs; identical message text does not collapse different submissions, semesters, or rapid edits. No-op edits and assignment confirmations do not emit new events.
- The existing bell UI is retained. Its data and badge reset when account, role, department, or program changes; old HTTP responses cannot replace a newer authorized result. Sidebar approval and room badges are also scoped to the current account and department.
- The pending department badge follows the actual approval stage. VPAA counts both ordinary Dean approvals and Room TBA conditional approvals; Dean counts submitted schedules in their own department. Other roles cannot request this approval count.

## Existing records and deployment

Run `php artisan migrate` to apply `2026_11_13_120000_backfill_system_notification_audience` and `2026_11_13_121000_add_system_notification_event_id` before running the updated code. They add scope metadata to existing notifications and a nullable event ID with a recipient/event unique key, without deleting history or changing read state. Old role-inappropriate records remain stored but are excluded by backend authorization. Legacy Program Head activity without a recoverable scope is excluded rather than guessed.

Deploy the backend and frontend together and restart long-running queue workers with the deployment's normal queue restart procedure. Schedule generation (saved or preview) does not create notifications; older generation notices are hidden from the feed.

## Verification

`SystemNotificationAuthorizationTest` covers the role/stage matrix, foreign departments and sibling programs, inactive and reassigned recipients, historical records, read/count authorization, private broadcasts, delayed queue delivery, retries, saved generation, program rooms, partial recalls, and the real submission/return/resubmission/final approval workflow for Secretary and Director offices.

Frontend notification hook tests cover authoritative counts, private invalidation, out-of-order responses, identity and program changes, read updates, and request failures. Notification links, bell presentation, and the existing live-update tests remain in place.

While tracing actual workflow notifications, the submission audit call was corrected to pass its signatory snapshot into history creation. Updating the snapshot after creation violated the append-only history model and prevented submission/approval actions from reaching their notification triggers.
