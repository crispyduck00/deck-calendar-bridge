# 1.0 release review

This review covers the persistent relation engine and its native Nextcloud 35 / Deck 1.19 integration. It adds targeted regression cases rather than treating a passing syntax check as a synchronization review. Exact build provenance is recorded in the release package's `BUILD_COMMIT`; publication waits for all native CI jobs.

## Findings addressed

| Boundary | Correction and regression coverage |
|---|---|
| Queue mark versus acknowledgement | Both use the same distributed queue mutex; a busy/stale acknowledgement cannot discard an incoming generation or release its writer's lock |
| Repeated scan scheduling | New periodic work gets its enqueue time; old pending work retains its age; actual events have urgent priority |
| Deleted/moved parent columns | Read the live stack identity and deletion state; do not rely on a cached board mapping or card deletion flags alone |
| Rule changes before calendar deletion | Persist and recheck the deletion's rule intent; restored/changed rules cannot authorize an obsolete cancellation |
| Paused rule with deleted source | Source deletion still cleans its verified original; pausing alone retains it |
| Calendar input ambiguity | Refuse recurring/floating/duplicate/invalid sequence data; preserve equivalent explicit time-zone representations without echo PUTs |
| Rule configuration | Enforce active binding invariants on stored snapshots and report malformed/busy administrator saves correctly |
| Operator environment | Diagnose supported versions/dependencies, HTTPS card-link base URL, HOME, process/config ownership, filesystem, native core IDs, locks and rule validity |
| Worker setup | Generate a unit from actual paths and the config owner; document explicit activation, status, event filtering, cron and graceful renewal |
| Public distribution | Current source review plus a history-free export; old Git/PR/log metadata is kept private rather than assumed erased by branch deletion |

## Functional cases

The automated suite covers future-card creation/backfill, historical admission, tag addition/removal, calendar trash/permanent deletion, explicit re-add generations, source/parent deletion, column movement, archived rule cleanup, done keep/delete, optional prefix/categories, initial-only attendees, current attendee/RSVP preservation, actual native category-update payloads, description restoration, cancellations and equality NOOPs.

Time cases include one/two source endpoints, different due anchors, resize into explicit endpoints, fan-out through the card, concurrent Deck/calendar changes, conflicting calendar proposals, late ETag edits, source-write races, equivalent durations/time zones and DST-aware forced date modes. Unsupported formats block visibly instead of being guessed.

Recovery cases include failures before/after creation, applied update/delete replay, uncertain legacy adoption, exact original/participant distinction, native Redis/database lock contention, two long-lived workers plus manual sync, delayed retry without cron, cross-process rule/enable changes, and nondestructive app disable/re-enable.

Lifecycle cases verify preflight/doctor, incorrect HOME, invalid stored rules, an unwritable core ID directory under the real web account, uninstall preview, busy cleanup retaining metadata, exact original deletion with unrelated and participant objects retained, keep-events removal, fresh disabled reinstall and a new installation namespace.

## Practical limits

This is a tested release for the declared platform matrix, not a claim that every external calendar client or server configuration behaves identically. External automatic acceptance, category rendering and mailbox delivery need a deployment test. Database backends outside the listed matrix are not verified. Recurrence, floating time interpretation and reconstructing tags from restored historical events remain outside the model.

The engine converges current intent. App-level locks do not prevent users from editing Deck or calendars during a request; ETags, snapshots, generation checks and re-planning handle those edits. An already delivered invitation cannot be retracted, and deletion/cleanup across calendar, database and filesystem resources is not a single atomic transaction. Use the documented backup/stop/check procedures.

Keeping old migration steps and harmless compatibility job classes is intentional: persisted jobs and upgrade paths may still name them. They are not competing active synchronization engines.
