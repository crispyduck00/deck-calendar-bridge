# Deck Calendar Bridge

Turn tagged Nextcloud Deck cards into real calendar appointments. Move an appointment in its original Nextcloud calendar and its time moves back to the card. Remove the trigger tag or delete the original appointment and the corresponding link is removed.

**Version 1.1.3** targets Nextcloud 35 and Deck 1.19. The app uses Nextcloud's existing background jobs, CalDAV scheduling and email configuration; it needs no separate synchronization server.

**AI transparency:** most initial code, tests and documentation were created with substantial AI assistance. Maintainers are responsible for accepting changes and releases. Automated test results are recorded; no independent security audit is claimed. Read [AI_USAGE.md](AI_USAGE.md).

[Install or upgrade](docs/INSTALLATION.md) · [Configure rules](docs/CONFIGURATION.md) · [Worker and cron](docs/WORKER_SYSTEMD.md) · [Uninstall](docs/UNINSTALLATION.md) · [Develop](docs/DEVELOPMENT.md)

## Example: block time in an external calendar

A card has a due date and a `Calendar block` tag. A rule creates a 20-minute appointment in a selected Nextcloud calendar and optionally invites `schedule@example.com`. The external calendar can accept those invitations automatically using a rule configured in that calendar system. Its attendee copy then blocks the time occupied by the Deck card.

The bridge manages the **original Nextcloud appointment**. Nextcloud sends invitations, updates and cancellations. The bridge does not need the external account's password or a provider-specific API. Automatic acceptance and the external calendar's busy/private settings are configured outside this app.

Time synchronization back to Deck applies to the original Nextcloud appointment, not to an external participant copy. External acceptance, display, category handling and cancellation processing depend on the receiving calendar system.

Invitations are optional. The same rule mechanism can create appointments in a shared calendar without inviting anyone.

## What it does

| Action | Result |
|---|---|
| Add a configured trigger tag to a future card | Create its linked original appointment |
| Change the card's start/due time | Update its linked appointments |
| Move or resize an original appointment | Update the card's times, then its other linked appointments |
| Change both sides before synchronization | Deck wins conflicting time changes |
| Remove a trigger tag | Delete only that tag's linked original appointment |
| Delete an original appointment | Remove only its trigger tag; keep the card and other triggers |
| Remove a rule | Delete its linked originals; retain Deck tags |
| Disable a rule | Pause that rule and retain its appointments |
| Archive a card | Retain its appointments |
| Mark a card done | Retain by default; optional deletion per rule |
| Add/change a rule | Backfill existing matching cards with a current/future source date |

Multiple different trigger tags on one card can target different calendars. A card has one shared start/due pair, so moving one projection updates the others. Independent appointment dates require separate cards.

Titles come from Deck. Each rule can use no prefix, custom text, or the card's current list/column name, followed by a configurable separator such as ` - ` or `: `. Moving the card or renaming its list keeps a list-based title current. Existing literal prefixes retain their exact formatting on upgrade. Fixed rule categories and optional exports of all current card tags stay updated; unrelated calendar categories are preserved. Descriptions contain the exact card link and a short ownership notice. Card descriptions and checklists stay in Deck.

Initial attendees are optional and set once. Later attendee edits, RSVP, location and alarms remain calendar-managed. Category-only attendee emails are optional and off by default. When enabled, actual category changes request a native update to the appointment's current attendees, respecting explicit delivery opt-outs. Equal values cause no calendar write or repeated notification.

## Requirements and installation

| Component | Verified configuration |
|---|---|
| Nextcloud | 35, integration-tested with 35.0.1 |
| Deck | 1.19.x, integration-tested with 1.19.0 |
| PHP CLI | 8.3 and 8.4, with Nextcloud's required extensions and POSIX |
| Database/locking | SQLite with native database locks; MariaDB 10.11 with Redis |
| Scheduler | Working Nextcloud cron; dedicated worker recommended for prompt processing |
| Invitations | Organizer email and working Nextcloud mail delivery |

Other Nextcloud/Deck major versions are not enabled by this release. Other database backends have not been verified by the included native matrix.

Download the **installable app ZIP** from [Releases](https://github.com/crispyduck00/deck-calendar-bridge/releases), together with its `.sha256` checksum. Do not install GitHub's automatically generated “Source code” archive as the app. The app ZIP has one top-level `deckcalendarbridge/` directory. CI records the tested source in `BUILD_COMMIT` and publishes only after every check passes.

The [installation guide](docs/INSTALLATION.md) uses complete example commands, explains which account and paths to check, and walks through environment checks, installing files, running the doctor, configuring cron, explicitly enabling a worker service, and testing the result. It explains the `HOME` setting without requiring shell variables. Fresh installs start with synchronization disabled. A normal upgrade retains existing rules, relation identities and enable flags.

This is a manual GitHub release installation. App Store distribution/signing is a separate publishing step.

## How synchronization runs

Deck card create/update/delete events and verified original-calendar update/delete/restore events mark a durable per-card queue. Callbacks do not perform the full cross-app synchronization. A worker polls native Nextcloud jobs and runs the same reconciler used by cron and manual execution.

A periodic scan is eligible every 60 seconds and recovers missed events, parent/label changes and paged backfill. Standard Nextcloud cron is normally scheduled every five minutes and remains necessary for the rest of Nextcloud. With only cron, processing follows cron's actual execution and workload. With the dedicated worker, observed card/calendar changes are usually picked up on its next one-second poll; that is not a completion-time guarantee.

See [worker operation](docs/WORKER_SYSTEMD.md) for the exact event list, timing, service commands, log checks, retries, pause/resume and recovery behavior.

## Permissions, identity and recovery

A saved administrator rule is a narrow cross-app delegation. A Deck editor need not have access to the target calendar. The calendar owner need not have general Deck rights to update the linked card's times or remove its exact trigger tag. Normal permissions outside those saved relations remain unchanged; only administrators can configure rules.

The bridge stores the original organizer, calendar, UID, object filename, source board/tag and lifecycle generation before changing the calendar. ETags prevent stale calendar writes. Queue generations and card locks protect concurrent workers. Failed or interrupted operations inspect the exact original before retrying instead of guessing from titles or recreating appointments blindly.

Source card, board and column deletion clean their originals. Moves to unmatched boards clean old projections. Rule changes are rechecked before deletion. Ambiguous identities or unsupported calendar data produce visible pending errors rather than a guessed reverse update.

## Boundaries

- One active rule per board/trigger tag. Multiple different tags per card are supported.
- Normal timed events synchronize both ways. Forced `all_day` and `day_edges` modes discard clock information and are forward-only; their ownership notice explains this.
- Recurring events, floating calendar times without an explicit time zone, duplicate managed properties and automatically restoring closed historical relations are not supported.
- Restoring an old deleted appointment does not re-add a Deck tag. Re-adding the trigger deliberately starts a new lifecycle.
- Initial recipients are a fixed optional email list. Dynamic assignee recipients and additional availability modes are not implemented.
- Writable-calendar users can edit fields. The bridge restores managed fields afterwards; a change may already have generated a native participant update before restoration.
- A completely historical card does not receive its first appointment. Moving its start/due into the future makes it eligible later. Existing links remain as time passes.

See [configuration](docs/CONFIGURATION.md) for date modes, category limits and field ownership, and [uninstall](docs/UNINSTALLATION.md) for both event-retention choices. Ordinary app disable is nondestructive. Complete removal is an explicit previewable command.

## Development and public distribution

[Development principles](docs/DEVELOPMENT.md) · [Architecture](docs/RELATIONS_V1.md) · [Tests](docs/TESTING.md) · [Release gates](docs/RELEASE_GATES.md) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [Change history](CHANGELOG.md)

CI installs real Nextcloud/Deck and tests DAV permissions, invitations through a local SMTP receiver, bidirectional times, conflict handling, native locks, interrupted writes and lifecycle cleanup. It also runs concurrent long-lived workers and uninstall/reinstall tests as the actual `www-data` OS user. No test forwards mail to external recipients.

The complete source includes runtime code, tests, Linux installation/upgrade/worker/uninstall guides, developer principles, AI disclosure, issue/PR templates and CI/release automation. The installable app ZIP contains runtime files and operator documentation; source/publishing tools are obtained from the source checkout.

Each release also provides `deckcalendarbridge-source-VERSION.zip` and its checksum. That complete source ZIP includes the PowerShell publication tool and every tracked project file, with no Git history. Use the `deckcalendarbridge-nc35-VERSION.zip` package for installation on Nextcloud.

The public Git history starts from a clean source snapshot. Earlier changelog entries describe inherited application behavior, not historical GitHub content imported into this repository. The [step-by-step VS Code guide for Windows](docs/VSCODE_PUBLICATION.md) publishes a clean source ZIP using Git and VS Code, with no GitHub CLI required. VS Code creates the new public repository; the guide explains how to check its own CI and release before archiving an earlier private repository. An optional [PowerShell publication guide](docs/WINDOWS_PUBLICATION.md) and preview-first script automate publication and release verification using GitHub CLI. Both routes avoid copying earlier commits, PRs, issues or logs. Windows is the publication workstation; the Nextcloud runtime remains Linux.

Optional repository-maintenance tools are described in [PUBLICATION.md](docs/PUBLICATION.md). Keep historical private repositories/backups private. Archive an earlier repository only after checking the new source and release. App Store API compatibility, signing and submission remain a separate task.

Licensed under [AGPL-3.0-or-later](LICENSE.md).
