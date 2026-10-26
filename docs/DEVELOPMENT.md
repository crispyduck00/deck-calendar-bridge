# Development and design principles

This guide is for the source repository. The installable app ZIP intentionally omits test fixtures, publishing tools and development dependencies. Start with the [behavior contract](CONFIGURATION.md), [architecture](RELATIONS_V1.md), [AI disclosure](../AI_USAGE.md) and [test evidence](TESTING.md).

## Supported environment

The declared application target is Nextcloud 35 with Deck 1.19.x. Native CI pins Nextcloud 35.0.1 and Deck 1.19.0, tests PHP 8.3/8.4 with SQLite, and PHP 8.4 with MariaDB 10.11 and Redis. Other combinations need explicit validation before support is claimed.

Windows PowerShell can publish the clean source, manage GitHub and edit the project. Running the Nextcloud app and its full native integration environment still requires the documented Linux environment. Windows publication does not require WSL, Bash, PHP or Python. The tests for that publication path run on actual Windows in CI.

The normal development checkout needs Git. Local checks additionally use Node.js, Python 3.10+ and PHP 8.3+ with Composer/Sabre VObject. The complete PHP/native checks can run through GitHub Actions when those tools are not installed locally.

## Source map

| Area | Responsibility |
|---|---|
| `lib/AppInfo/Application.php` | Dependency aliases and exact Deck/calendar event subscriptions |
| `lib/Listener/SyncListener.php` | Verify observed identities and enqueue; no nested cross-app writes |
| `lib/Sync/Scanner.php` | Bounded recovery/backfill pages; enqueue only |
| `lib/Sync/Queue.php`, `Runner.php` | Durable generations, retries and resource ownership |
| `lib/Sync/Reconciler.php` | Single authority for planning, mutations and interrupted-write recovery |
| `lib/Sync/Planning.php`, `lib/Service/` | Pure time/title/category/identity policy |
| `lib/Sync/DelegatedDeck.php`, `NativeCalendar.php` | Narrow administrator-delegated adapters and native DAV scheduling |
| `lib/Sync/Store.php`, `lib/Migration/` | Persistent identities, revisions and compatible schema evolution |
| `lib/Controller/`, `templates/`, `js/`, `css/` | Administrator-only settings, previews and diagnostics |
| `bin/`, `packaging/`, `docs/` | Environment checks, generated worker and operator instructions |
| `tests/`, `.github/workflows/ci.yml` | Reproducible semantic, native, Windows and lifecycle checks |
| `tools/` | Source/privacy review and optional repository publication/maintenance |

## Principles that changes must preserve

1. Keep one reconciler for events, scans, manual execution and recovery. A new listener or endpoint must not become another synchronization engine.
2. Identify the exact original using the saved organizer/calendar/UID/object binding. Never infer ownership from a title or a same-UID attendee copy.
3. Save mutation intent before DAV. Replays inspect what actually happened and retain the lifecycle identity instead of blindly creating another event.
4. Compare semantic values. Equal values cause no calendar write or repeated notification. Preserve unrelated calendar fields and user-managed attendees/RSVP.
5. Preserve ETags, source snapshots, relation revisions and queue generations. A contender must never release another process's lock or acknowledge a newer event.
6. Treat administrator rules as narrow delegated capabilities. A calendar editor can change only linked dates or the exact trigger; never grant generic access to the other app.
7. Separate removal, pause, archive, done and explicit re-addition. Preserve field ownership, future admission and Deck conflict priority.
8. Bound scans and retries. Keep old pending work from starving behind repeated scans. Long-lived processes must see externally changed flags/rules.
9. Reject ambiguous identity, unsupported recurrence/floating times and malformed calendar data visibly. Do not repair uncertainty by guessing or deleting metadata.
10. Prefer a small policy change over a framework, parallel engine, new flag or dependency. Document consequential tradeoffs and ask for product direction when behavior would change.

## Working on a change

Use a branch in the public repository:

```powershell
git switch main
git pull --ff-only
git switch -c fix/describe-the-problem
```

Read the relevant policy and its callers, reproduce the actual failure, and make a focused change. For meaningful synchronization changes, add a regression scenario that fails for the reported behavior. Avoid tests that merely repeat the implementation. Use disposable examples and loopback mail fixtures, never production cards, accounts or mailboxes.

Useful checks from the repository root:

```powershell
git diff --check
node --check js/admin.js
node tests/email-validation.cjs
python tests/maintenance_tools.py
python tools/check_public_tree.py
```

The maintenance tests skip the POSIX-only shell-export case on Windows. For the PowerShell publication test on Windows:

```powershell
$env:DCB_POWERSHELL = 'powershell'
python tests/publication_powershell.py
$env:DCB_POWERSHELL = 'pwsh'
python tests/publication_powershell.py
```

Use an installed shell for each invocation. CI exercises both Windows PowerShell 5.1 and PowerShell 7. Publishing itself needs neither Python nor these mocks.

Pure PHP checks and the full native fixture are documented in [TESTING.md](TESTING.md). Run the native setup only against a new disposable directory; it refuses an existing Nextcloud configuration. Ordinary Windows publication must never be pointed at a Nextcloud installation.

## Review and releases

Open a PR describing the problem, resulting behavior, actual validation and limitations. Disclose substantial AI assistance. A maintainer must assess the result rather than treating generated code or passing syntax as proof of correctness.

Follow [RELEASE_GATES.md](RELEASE_GATES.md). Choose a higher semantic version for a new release, update the metadata/admin version and changelog, and update current download examples. CI checks version consistency, runs all native and Windows jobs, packages the exact tested commit and publishes the installable ZIP/checksum only on `main` after every gate passes. Published tags/assets are not overwritten. Do not substitute one matrix artifact for a fully successful run.

Keep credentials and signing keys outside Git. Keep private backups/review-term lists outside the checkout. Source files use UTF-8 and LF line endings through `.gitattributes`, including when edited on Windows.

## Compatibility boundaries and App Store distribution

The adapters deliberately use Deck service/mapping internals, direct Deck schema access and an embedded DAV server for the supported version pair. These are coupling points to inspect when Nextcloud or Deck changes; private APIs are not a stable cross-version contract.

The current distribution channel is GitHub. App Store publication is a separate task: review the public-API policy and adapter coupling, validate full metadata, obtain an app-scoped certificate, sign the final files and archive, and produce a compliant `.tar.gz`. A GitHub ZIP/checksum and passing CI do not constitute Store approval or code signing. Keep the Nextcloud/Deck support declaration narrow until additional versions are tested.

## Operations and security ownership

Installation, worker renewal, cron, diagnosis, upgrades and both complete-uninstall choices are documented separately for operators. A change to those procedures must update the matching guide and lifecycle checks. Retain migrations and harmless old queued-job classes while persisted installations can still reference them.

Use [SECURITY.md](../SECURITY.md) for private vulnerability reporting. Do not publish exploit details, credentials or raw personal calendars/cards in issues. AI assistance does not change maintainer responsibility or make this project an independently audited product.
