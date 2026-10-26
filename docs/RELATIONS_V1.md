# One canonical relation reconciler

## Authority

The administrator's saved board/tag rule delegates a specific card-to-original-calendar projection and its limited inverse. Ordinary users do not gain any new generic Deck or calendar permission. Reverse actions may change only start/due or the exact bound trigger tag, plus normal Deck modification/activity/event metadata. No title, description, owner, done state, archive state or other tag is reverse-written.

`SyncListener` records exact deletion observations and enqueues trusted Deck/DAV events without changing cards or appointments. `Scanner` only enqueues bounded source/relationship pages, including removed rules and archived/deleted sources. `Runner` serializes a card and protects dirty generations. `Reconciler` is the sole decision/mutation authority, with narrowly scoped Deck/calendar adapters.

## Persistent identity

Each lifecycle stores card/rule/generation, original board/label, principal, calendar URI, UID and planned ICS filename before DAV. Stable UIDs include the Nextcloud instance namespace to avoid collisions between independent installations. A confirmed deletion followed by explicit tag re-addition creates one new generation; retries do not.

An idempotent install/post-migration repair step initializes a persisted random installation namespace. Nextcloud skips migration data hooks on a fresh app install, so namespace initialization cannot rely on those hooks. New UIDs and bridge mutexes include that namespace; existing stored UIDs are preserved. A fresh reinstall cannot collide with old retained events or old v1 Redis mutexes. Disabling/re-enabling preserves the namespace.

Rule reads refresh Nextcloud's in-process configuration cache: a native long-running worker otherwise continues using stale rules or enable flags. A deferred event job schedules another native job with the queue's retry deadline. Native cron, the dedicated worker and manual execution use the same reconciler.

The last common time baseline stores Deck start/due, canonical calendar start/end and timing-recipe revision. The ETag versions the calendar object, not the whole source or the field ownership.

| Source dates changed | Calendar dates changed | Result |
|---|---|---|
| no | no | no time action |
| yes | no | Deck -> calendars |
| no | one unambiguous proposal | calendar -> card -> other calendars |
| yes | conflicting calendar change | Deck wins |
| no | conflicting calendar proposals | common Deck state wins |

Title/category changes are independent of the time decision. RSVP/location/alarm changes do not become time edits. Event descriptions are managed as the exact card link plus a stable ownership notice; card notes/checklists are never exported. A source race rejects stale reverse updates. Database row locking and fresh snapshots protect the limited mapper writes; Deck's changed-property mapper avoids broad card replacement.

## Managed descriptions and scheduling

A pure description policy generates the exact card URL and ownership notice for both initial serialization and later reconciliation. The notice distinguishes normal bidirectional times from forward-only day modes. Only differing values cause a write; no timestamps, counters or changing text are used to manufacture notifications.

Category comparison ignores order. `invitations.notifyOnCategoryChanges` defaults to false. If enabled and actual category values change, the outgoing native DAV body requests `SCHEDULE-FORCE-SEND=REQUEST` on current server-scheduled attendees. Native scheduling consumes that transient parameter. Existing attendees, RSVP and explicit client/none scheduling choices are preserved. No separate email sender, global significance override or participant-list reconstruction is introduced.

An already applied update is adopted on recovery. Once the desired description/categories are present, reconciliation performs no PUT, so it cannot repeat a category notification. Native scheduling still determines recipient delivery, global settings and past-event mail suppression.

## Write outcomes and recovery

`prepared` means reserved, `active` means verified object and baseline, `uncertain` means a durable attempted operation needs factual outcome inspection, and `deleted` means confirmed closure. An attempt retains its type, source/time intent and rule hash. The object URI is never lost just because a create failed.

On retry, read the exact original. Adopt an already applied create/update; verify missing create before retry; confirm a completed delete instead of replaying it. A real identity mismatch blocks only the affected relation. Known ETag conflicts cause fresh planning. Persistent diagnostic codes are visible in settings; protected logs retain exception types/codes and location without dumping calendar bodies.

## Lifecycle priority

Rule removal/target migration and deleted-source cleanup run before ordinary planning, including on archives. Archive alone keeps events. Done follows per-rule policy. Disabled rules retain existing appointments and pause. A true manual original-calendar deletion removes exactly its matching trigger if Deck has not since contradicted the common baseline. The bridge's own event deletion is recognized by its persisted attempt and never loops into tag deletion.

Fully historical first-time creation is skipped. Future movement creates later. For forced date-only modes, today's local date qualifies; for timed rules the source timestamp must be at/after now. Existing links are retained when time passes. Clock-discarding day modes are explicitly forward-only.

## Native contracts verified in integration

- Nextcloud 35 `CalendarImpl::getPermissions()` maps DAV write to CREATE/UPDATE, not automatically generic DELETE. Actual DAV ACL checks govern object unbind.
- Moving an object to trash changes its filename to `*-deleted.ics` while retaining row/UID/calendar identity. This must not be confused with a calendar move.
- Embedded DAV supports normal scheduling and If-Match/If-None-Match. All three mutations use that stack.
- Deck 1.19 has an explicit CLI PermissionService context setter for fixture/service use. The bridge itself uses scoped mapper operations because the calendar owner deliberately may lack Deck ACL. Normal change helper, activity and typed CardUpdated events are emitted after source writes.

## Remaining client checks

The included native suite tests real NC35/Deck/DAV/SMTP behavior, not the user's host. Deployment must still verify local cron/worker configuration, real external mailbox delivery/Outlook display, and the database backend used there. Recurrence and restoring historical closed appointments are outside the automatic reverse model; do not guess new tags from those objects.

Explicit uninstall is a separate CLI operation, never an app-disable repair step. It quiesces new work, retains the previous rules while a cleanup is incomplete, uses the existing original-event cleanup path, and only then removes its own app files, jobs, five tables, migration records and configuration. The keep-events alternative removes metadata while preserving appointments and all Deck tags.

## Queue and deletion hardening in 1.0

Queue acknowledgement shares the mark mutex, closing the SELECT/DELETE/UPDATE interleaving that could lose an incoming generation. A failed mutex acquisition acknowledges nothing. New scan work uses its current eligible timestamp while older pending work retains its earlier value; urgent events use priority zero. This provides age ordering without a new queue schema.

The source adapter reads a card's current parent column and deletion state directly. Column deletion does not necessarily delete every child row or emit a card event, so relation scans recover that case. Deletion attempts also carry the current normalized rule hash (or a deliberate absent-rule marker). The native adapter rechecks it before DAV deletion, except authoritative deleted-source cleanup, which remains valid for a paused rule.
