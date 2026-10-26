# Test evidence and reproduction

The suite combines focused semantic scenarios with real Nextcloud integration. No test uses a production Nextcloud installation.

Windows CI separately runs `tests/publication_powershell.py` under actual Windows PowerShell 5.1 and PowerShell 7. It uses real local Git commits with mocked network commands to verify no-call preview, link/metadata/privacy refusal, generic attribution, one-commit history, stopped execution on creation/CI/tag/checksum failure, and a release receipt matching actual ZIP provenance. Paths contain spaces and source text includes Unicode. No live GitHub repository is created/deleted by those tests. The source README and AI disclosure distinguish automated evidence from independent review.

## Pure / deterministic tests

- Real Sabre VObject serialization, line folding, Unicode, timezones, local all-day exclusive ends and DST.
- Category ownership preservation, category-order equality, optional prefix and initial attendee composition.
- Stable managed description restoration, accurate forward-only notices, optional category notifications, current recipients and scheduling opt-outs.
- Card/calendar fan-out, field-level time conflict handling and equality NOOP.
- Exact tag/event lifecycle, archive/done behavior, future admission and explicit new generation.
- Interrupted create before/after DAV, update after DAV and delete after DAV: converge without repeating an applied mutation.
- A reverse source race preserves the newer Deck value.
- A calendar edit arriving after observation retains its ETag protection and is considered on retry instead of being overwritten with a newly read version.

## Native suite

`tests/integration/setup.sh` installs pinned Nextcloud 35.0.1 and Deck 1.19.0 into a disposable directory. SQLite is the default; `DCB_TEST_DATABASE=mysql` uses the dedicated `dcb_test` MariaDB fixture with `DCB_TEST_DB_PASSWORD`. `tests/integration/nc35.php` runs actual Deck services/mappers, native event dispatch, DAV ACL/Scheduling and a loopback SMTP receiver. The receiver never forwards mail.

It verifies a card owned by a separate Deck editor and calendar organizers without Deck READ. That editor is actually denied access to the target calendar through DAV, while configured delegation succeeds. A calendar move updates the card and the second organizer. Actual calendar ETags remain unchanged on NOOP, and a stale ETag produces a real 412.

Other covered native scenarios: trigger categories, preserved location/private card description, native trash and permanent deletion -> exact tag deletion, explicit tag re-add, configurable done, archive keep, rule cleanup of archived sources, future backfill, no historical creation, future movement, initial REQUEST, edited participant/RSVP preservation, actual CANCEL and no resend on replay. A legacy unbound `uncertain` revision-4 row is recovered with its original UID, and an already existing uncertain original is adopted without a duplicate. A forged unsaved delegation is refused.

Independent native locking providers deliberately contend for a card and the scanner. Tests verify that busy cards retain their pending generation, another card in the batch still synchronizes, cursors do not advance on a busy scanner, the contender never releases the owner's lock, and deferred work drains after release.

GitHub Actions runs SQLite on PHP 8.3 and PHP 8.4 with native database locking plus MariaDB 10.11 on PHP 8.4 with real Redis-backed `MemcacheLockingProvider`, exercising both the source row-locking path and production cache locking. Set `DCB_TEST_LOCKING=redis` with a disposable local Redis at port 6379 to reproduce that fixture. Local development has also executed the native suite on PHP 8.3 / real NC35.0.1 + Deck1.19; the GitHub run is the PHP8.4 result of record. This is not a claim of installation on a production server or of an Outlook end-client test.

To reproduce on a machine with PHP extensions and Composer:

```bash
sh tests/integration/setup.sh /absolute/path/to/an/empty-disposable-directory
```

The setup refuses an existing NC config. Standalone test scripts require the disposable marker. All accounts/addresses/passwords in this fixture are deliberate non-secret test data under `example.invalid`.

## Native description and category delivery

The loopback SMTP sink parses actual calendar attachments. Tests edit an original description through DAV, verify one native restoration update, then verify unchanged ETag/no resend. Card notes remain unchanged. Category updates with notifications disabled send no external email. Enabling the option without a category change sends nothing. Subsequent category removal/addition sends exactly one update per actual change to the edited current attendee, not the old rule recipient. Attachments contain actual current categories and the stable description; RSVP remains intact, and native scheduling consumes the transient resend parameter. Repeated reconciliation sends no second update.

## Actual worker and install lifecycle

`worker.py` starts real long-lived `occ background-job:worker` processes while separate PHP processes perform actual Deck service calls and native DAV edits. It verifies both original calendar creates from a Deck event, calendar -> card -> other-calendar fan-out without a manual reconcile call, externally changed rules, old-v1-lock isolation, two workers alongside manual execution, retry after lock release without cron, live disable/re-enable flags, and exact reverse tag removal from a calendar DELETE.

`lifecycle.py` validates the doctor, normal app disable/re-enable, read-only uninstall preview, default original-event deletion with an unrelated appointment preserved, unchanged Deck tags, removal of app files/jobs/tables/config/migration markers, fresh reinstall with a new namespace and disabled sync, and uninstall retaining all appointments.

The Redis/MariaDB CI job copies tracked source to a disposable directory, gives the fixture to the real `www-data` OS user, and runs both process suites under that user with an explicit HOME. No symlink points an uninstall test at the real checkout. Packaging occurs from the original untouched checkout. The authoritative per-build results are the associated CI run or the supplied evidence file; the presence of a workflow definition alone is not a claim that a particular build passed it.

The web-user lifecycle suite also makes the real core Snowflake sequence directory unwritable temporarily and verifies that the doctor reports the exact failing ID-generation check before restoring the original mode. The disposable installer keeps its temp directory inside the fixture so ownership transfer covers core sequence files as well.

## First official release regression coverage

Native tests now hold the independent queue writer mutex while acknowledging, verify generation preservation, and verify that older periodic work precedes freshly scanned lower card IDs. They also verify stale rule deletion refusal before DAV, unchanged rule snapshots after invalid bindings, deleted source cleanup under paused rules, fresh parent identity after column movement, and periodic cleanup after column deletion.

The doctor is tested with a missing HOME and deliberately invalid stored rules as well as the existing core-ID permission failure. The generated service is parsed by systemd's verifier. Deterministic calendar cases cover equivalent explicit TZID values, floating/duplicate/invalid properties and sequence exhaustion without overflowing or blocking an independent forward projection.

`python3 tests/maintenance_tools.py` is offline: it checks cleanup scope, redacted pattern findings and a real temporary Git export with old deleted content that must not reach the public tree. Fake GitHub commands refuse mutation during dry-run. No live GitHub resource is created or deleted by these tests.

`python3 tools/check_public_tree.py` checks tracked text for credential patterns. An operator may add private forbidden terms through an out-of-tree `--deny-file`. This complements the manual release/source/history review; do not commit private review lists or backups.

The native worker fixture includes both actual job filters. It first creates a qualifying card while intake is disabled, verifies no queue/relation exists, re-enables intake and requires the native timed scan to discover it without manually invoking scanner/reconciler. It then verifies normal event-driven creation separately.

## Configurable title prefixes

Title-policy tests cover none/custom/list modes, literal Unicode separators and spaces, empty values, legacy normalization, idempotent saves, invalid input and combined title limits. Reconciliation cases change modes on existing originals and combine a list rename with a reverse calendar time edit, verifying shared-time fan-out, stable identities and unchanged replay. Native Deck tests create a list-based event, move its card through the actual service event, rename the column and recover its title through the scanner, then verify the same original object/UID and an unchanged ETag on replay. The long-lived worker tests also load an explicit custom mode and separator saved by another process.
