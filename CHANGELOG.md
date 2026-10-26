# Change history

## 1.1.3

- Publish a clean public repository from VS Code on Windows without GitHub CLI, with explicit first-commit, repository creation, CI, release and archive checks.
- Replace installation shell variables with complete example commands and a clear table of installation paths and accounts.
- Explain the process-local HOME setting and distinguish Windows publication from Linux server installation, workers and uninstall commands.
- Keep synchronization behavior unchanged; this release improves onboarding and operator documentation.

## 1.1.2

- Wait for the first CI run without resolving a workflow that GitHub may not have indexed yet; select the expected workflow and exact commit from returned runs.
- Exercise an initially empty run list on both Windows shells before successful/failing release verification.
- Explain temporary process-scoped script execution on Windows without changing persistent execution policy.

## 1.1.1

- Prepare a complete history-free public source distribution with Windows PowerShell publication, CI/release verification and a documented private-archive handover.
- Add developer onboarding, explicit synchronization/review principles, AI-assistance disclosure and issue/PR templates.
- Test publication on Windows PowerShell 5.1 and PowerShell 7, including preview, private metadata refusal, junctions, failure handling, checksum and build provenance.
- Use consistent UTF-8/LF source files and check release metadata/documentation before packaging.
- Include the AI disclosure in the app package and provide current SPDX/license and issue-tracker metadata. App Store submission remains separate.

## 1.1.0

- Choose no title prefix, custom text, or the card's current Deck list/column name per rule, with a literal configurable separator.
- Update list-based titles when cards move or lists are renamed; retain existing relation identities and time synchronization behavior.
- Preserve existing literal prefix punctuation and spaces exactly on upgrade, without a manual rule conversion.
- Cover title modes, Unicode/empty separators, legacy settings, event-driven card movement, scanned list renames and concurrent reverse time edits.

## 1.0.0

First official release for Nextcloud 35 and Deck 1.19.

- Serialize queue acknowledgement with incoming marks so concurrent changes cannot lose a generation. Schedule periodic work fairly without pushing older pending cards behind rescanned low IDs.
- Read current parent-column identity/deletion directly; clean linked events when a source column is deleted or moved to an unmatched board.
- Revalidate saved rule intent immediately before deletion; stale removal/done/target-change decisions do not send an incorrect cancellation. Deleted source cards still clean paused rules.
- Refuse ambiguous managed fields, floating calendar times and invalid/exhausted scheduling sequences instead of guessing values. Equivalent explicit time-zone representations remain a NOOP.
- Validate stored rule invariants and report environment versions, enabled dependencies, effective HTTPS card-link base URL, HOME, permissions, native ID generation, locking and rule configuration.
- Generate the worker service for the actual installation paths and config owner. Expand installation, worker/cron operation, recovery, external-calendar usage and removal instructions.
- Include dry-run repository cleanup with private backups and a history-free public-export tool. Keep historical private repository metadata out of public exports.
- Publish the installable ZIP and checksum only after the complete native test matrix passes.

## 0.4.3

- Keep event descriptions equal to the exact Deck card link and stable field-ownership notice. Never export card notes or checklists. Forward-only day rules have an accurate notice.
- Add optional `invitations.notifyOnCategoryChanges`, default false. Request native scheduling only for changed categories, using current event attendees and preserving RSVP and delivery opt-outs.
- Treat category ordering as equality. Enabling notifications or replaying an already applied update sends no additional invitation.
- Translate documentation, admin instructions and diagnostics to English with generic installation examples. Consolidate obsolete release-candidate documents into current configuration, testing and release instructions.
- Extend real DAV/SMTP verification for description restoration, category mail disabled/enabled, current recipients, actual invitation payloads and unchanged replay.

## 0.4.2

- Reschedule busy event jobs, observe cross-process rule/enable changes in long-lived workers, and isolate installation identities from old mutex keys.
- Diagnose config-owner, filesystem, core Snowflake ID and lock permissions before app activation and through `deckcalendarbridge:doctor`.
- Provide an explicitly activated systemd worker, fresh-install documentation, and previewable complete uninstall with delete-events or keep-events alternatives.
- Verify two concurrent native workers, manual sync, disable/re-enable, uninstall/reinstall and real web-user execution.

## 0.4.0–0.4.1

- Replace the previous synchronization paths with one persistent relation reconciler for event jobs, scans, manual execution and interrupted-write recovery.
- Add bidirectional times, Deck conflict priority, exact original-event/trigger-tag deletion, future-card backfill, ETag protection and optional initial attendees.
- Defer contended card/scanner locks without discarding queued work or releasing another owner's lock.

## Earlier versions

The 0.3.x engines and their write flags are superseded. Migration schemas and legacy relation adoption remain for existing installations; obsolete execution paths are retained only as harmless queued-job compatibility stubs.
