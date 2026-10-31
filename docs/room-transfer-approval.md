# Dean and VPAA room transfer approval

Room Transfer follows **Secretary → Dean → VPAA → applied schedule changes**. It reuses `room_recovery_plans`, the existing `ConfirmModal`, scheduling locks and validators, schedule history, in-app notifications, and both roles' existing Schedule Approval pages.

## Behavior

- Preview is read-only and expires after 20 minutes. The Secretary selects **Submit for approval** and confirms in the existing modal: **Are you sure you want to submit this room transfer for approval?** Cancel or Escape dismisses confirmation without submitting; the transfer review remains open.
- Confirmed submission sets `status=pending_dean` (**Pending Dean Approval**), stores the immutable selected group keys and submission time, and notifies the owning department's Dean. Schedules, timestamps, instructors, history and Sunday settings remain unchanged.
- Dean and VPAA room transfers share the existing **Pending Approval** table with regular schedule submissions. **Request Type** identifies Room Transfers; the same status tabs, department/status filters, sorting, pagination, and confirmation modals remain in use. Viewing a transfer opens its original-at-submission and proposed room/day/time details. Only the owning department's Dean with `schedule.approve_dean` may decide the Dean stage. The Dean stage rechecks the proposal (see below) so a request that can no longer be applied is not forwarded. Approval records `dean_reviewed_by/at`, sets `status=pending_vpaa` (**Pending VPAA Approval**) and notifies the VPAA. It does not modify schedules or Sunday settings.
- Linked online sessions are listed in `changes` beside the meeting they belong to (room `Online`) and labelled **Linked online session** in the Changes Summary, View Details and the Secretary's table. Counts of affected schedules count physical meetings; linked sessions are counted separately.
- The transfer PDF uses the same builder and section selection as Schedule Approval. `preview_schedules` supplies every class of each affected section for the request's semester, with the proposed room/day/time applied and linked online sessions printed as `Online`. The PDF is identical in format to a Schedule Approval PDF, with no change highlighting; reviewers see the original and proposed placements in the Changes Summary and View Details.
- Transfer details use `ScheduleApprovalPreviewModal` and the existing PDF builder with a compact Changes Summary. **View Details** opens `ScheduleChangesModal`, extracted from regular Schedule Approval's revision-diff modal, with the same columns, formatting, sorting and pagination. Closing details restores the preview and keyboard focus without regenerating its PDF.
- Rejection uses `ScheduleRejectionModal`, extracted from the existing Schedule Approval return-for-revision form. Both paths retain the same form layout and controls, with request-specific copy and the existing decision endpoints. Transfer approval still uses the existing `ConfirmModal` and retains its Secretary → Dean → VPAA sequence.
- The read-only response supplies proposed schedule rows, course/section metadata, the request's semester/department, and `preview_users`: current active Secretary, Program Head and Dean records for the request's department, plus institution-wide VPAA records. Personnel entries retain their real IDs, names, roles, department IDs and program IDs; the common PDF builder always signs "Prepared by" as **Program Head**, naming the Program Head of each printed program (joined with " / " when several programs print, blank when none is assigned), even when the Secretary entered or submitted the schedule. The Secretary is never used as preparer. Historical requests render independently of the active-term queue. The PDF has no change highlighting, transfer banner or explanatory line; approval and rejection endpoints are unchanged.
- Only the VPAA with `schedule.approve_vpaa` may decide the final stage, after Dean approval has been recorded. VPAA's queue contains Dean-approved requests. Neither reviewer needs schedule authoring permission.
- Final approval locks the semester, plan, department, rooms and schedules, then checks the source hash and revalidates the complete selected batch against a fresh scheduling snapshot (current bookings, room status and access, instructor availability and department rules). Unrelated activity elsewhere in the semester, such as another department's approval or another transfer being applied, does not invalidate a request. Schedule changes, any necessary Sunday setting, history, final decision metadata and requester notification commit together. A validation or persistence failure rolls back the entire decision.
- Submitted requests do not expire with their preview. Changed source meetings, or occupancy, room access or rules that conflict with the proposed placements, block approval at either stage. Such a request remains pending and can be rejected so a new proposal can be prepared.
- Rejection at either stage requires a reason, records `rejected_by_dean` or `rejected_by_vpaa`, reviewer/time and reason, and notifies the requester; a VPAA decision also notifies the Dean who forwarded the request. It never writes schedules. Pending and reviewed requests are visible in the requester's Room Transfer modal, with pending meetings disabled for another submission.
- Identical submission/decision retries do not duplicate writes or notifications. A pending request cannot change its group selection. Overlapping pending requests for the same meetings, or whose proposed placements conflict (same room, section or instructor time), are rejected under the scheduling lock. An approved request cannot be rejected, and a rejected request cannot be approved or resubmitted.
- Department users see their department's requests; program heads retain read access to their own historical requests. Only Secretaries may submit new requests. Dean decisions are restricted to their owning department; VPAA decisions are restricted to Dean-approved requests. Notification links open the next approver's queue or requester status, including verified historical semesters.

- When a request moves classes to Sunday for a department with Sunday classes off, `enables_sunday` is returned and both reviewers see that final approval enables Sunday classes.
- Transfers applied before the approval workflow (`status=applied`) remain listed as history.
- Unsubmitted previews are pruned one day after expiry by the daily `model:prune` schedule; submitted requests are kept.

## API

| Endpoint | Authorization / result |
| --- | --- |
| `GET /api/room-recovery/transfers` | `schedule.view`; scoped pending/approved/rejected requests, department ID, Dean review time, and physical before/after details with meeting mode |
| `POST /api/room-recovery/plans/{plan}/submit` | Secretary plus `schedule.update`; creator-owned plan and existing ownership/program checks; body `group_keys`; returns `pending_dean`, `applied=false` |
| `POST /api/room-recovery/plans/{plan}/apply` | Compatibility alias for submission; cannot apply changes directly |
| `POST /api/room-recovery/plans/{plan}/approve` | Department Dean plus `schedule.approve_dean` forwards to VPAA; VPAA plus `schedule.approve_vpaa` applies the stored selection after Dean approval |
| `POST /api/room-recovery/plans/{plan}/reject` | Role, department and stage checks as above; body `reason` (nonblank, maximum 1,000 characters) |

`GET /api/room-recovery` additionally returns requests for its selected semester and `pending_transfer_id` / `pending_transfer_status` on pending schedule rows. `pending_count` continues to count unresolved room problems, including those awaiting either approval.

## Deployment and verification

Run the additive migration before deploying the matching frontend:

```powershell
php artisan migrate --path=database/migrations/2026_10_23_130000_add_room_transfer_approval_to_recovery_plans.php
php artisan migrate --path=database/migrations/2026_10_23_140000_add_dean_review_to_room_transfers.php
```

The first migration labels previously applied plans with the historical `applied` status. The Dean migration returns existing unreviewed `pending_approval` requests to `pending_dean` and notifies their department's Dean. Existing VPAA rejections become `rejected_by_vpaa`; already applied/approved schedules are unchanged. No retroactive Dean approval is implied.

Relevant checks:

```powershell
php artisan test --compact tests/Feature/RoomRecoveryTest.php tests/Feature/RoomRequestTest.php tests/Feature/CrudIntegrityGuardsTest.php
```

From `C:/Users/toman/wicars-ui`:

```powershell
npm test -- src/components/rooms/RoomRecovery.test.tsx src/components/rooms/RoomTransferRequests.test.tsx src/pages/shared/ScheduleApprovalPage.test.tsx src/lib/notificationLink.test.ts src/pages/shared/Rooms.test.tsx
npm run build
```
