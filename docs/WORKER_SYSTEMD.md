# Worker, events and cron

The worker is a long-running **Nextcloud PHP CLI process**, not a separate bridge server or webhook service. Installing the app registers listeners and native jobs; it does not create or start an operating-system service. Configure that explicitly using [INSTALLATION.md](INSTALLATION.md).

## From an edit to synchronization

1. A registered Deck or calendar event reaches the listener.
2. The listener verifies any original-calendar identity and marks the card's durable queue generation. It does not write the other application from the callback.
3. The native worker picks up `OCA\DeckCalendarBridge\BackgroundJob\ReconcileCardJob`.
4. The reconciler reads current card/rule/original-event values, resolves time changes and writes only actual differences.
5. A generation is acknowledged only if no newer mark superseded it. Busy or failed work remains queued for retry.

| Trigger | Handling |
|---|---|
| Deck `CardCreatedEvent` | Queue the card; create only if rule, tag and date qualify |
| Deck `CardUpdatedEvent` | Queue time/title/tag/done/archive/move changes emitted by Deck |
| Deck `CardDeletedEvent` | Queue cleanup of original appointments |
| Calendar `CalendarObjectUpdatedEvent` | Queue only the exact saved organizer/calendar/object |
| Calendar `CalendarObjectMovedToTrashEvent` | Record the verified deletion and queue its card |
| Calendar `CalendarObjectDeletedEvent` | Record the verified permanent deletion and queue its card |
| Calendar `CalendarObjectRestoredEvent` | Recheck an existing identity; do not guess or restore tags |
| Rule save/global enable | Queue the first backfill page; further pages follow scans |
| Missed events, column/board changes, list rename, label rename/deletion | Recovered through the periodic scan; list-based title prefixes follow current column names |

A same-UID participant copy in another calendar is rejected as a reverse source. New arbitrary calendar appointments do not create Deck cards or relations. The listener does not subscribe to external email or an external calendar provider.

## Timing

| Mechanism | Schedule | Meaning |
|---|---|---|
| Dedicated worker | Polls for eligible native jobs with `--interval=1` | Usually picks up an observed event on its next poll; current work and mail/database latency can delay completion |
| Bridge scan | Eligible every 60 seconds | Paged source/relation/legacy recovery; actual execution depends on worker/cron availability |
| Normal Nextcloud cron | Conventional setup: `cron.php` every five minutes | Runs background work for the whole instance; keep it active alongside the worker |
| Busy card/acknowledgement | Retained and rescheduled; minimum job retry about two seconds | The owner is not unlocked by the contender; no queued generation is discarded |
| Failed/blocked relation | Queue retry normally after 60 seconds | A new actual event promotes the card to immediate work |
| Unobserved permanent absence | At least 30 seconds' confirmation before reverse deletion | Identity moves/errors are not treated as proven manual deletion |
| Worker renewal | `--stop_after=15m`, then systemd restart | Requests a graceful exit after about 15 minutes; a current job may finish later |

Scans process bounded pages, normally 50 source and relation entries per pass; pending processing is also bounded. Large backfills need multiple passes. Old background work keeps its queue age so rescanning lower IDs does not continually jump ahead. Urgent edits have priority. A one-second poll and a 60-second scan are not guaranteed end-to-end deadlines.

Without the dedicated worker, normal cron can still process bridge jobs. AJAX/Webcron rely on different Nextcloud scheduling limits and are not the recommended setup here. Selecting cron mode does not by itself create a system cron entry or timer.

## Start, inspect, stop and restart

After installing the generated unit, run these as root, using the configured service name:

```bash
systemctl daemon-reload
systemctl enable --now deckcalendarbridge-worker.service
systemctl is-enabled deckcalendarbridge-worker.service
systemctl is-active deckcalendarbridge-worker.service
systemctl status deckcalendarbridge-worker.service --no-pager
journalctl -u deckcalendarbridge-worker.service -n 50 --no-pager
```

Expected: `enabled`, `active`, and a running PHP process. No “Running job” messages while idle is normal. Check the bridge's `doctor` and relation status as the web user to confirm actual work, not merely process liveness.

```bash
journalctl -u deckcalendarbridge-worker.service -f
systemctl show deckcalendarbridge-worker.service -p User -p Group -p MainPID -p ExecStart -p NRestarts
systemctl stop deckcalendarbridge-worker.service
systemctl start deckcalendarbridge-worker.service
systemctl restart deckcalendarbridge-worker.service
```

Follow logs in one terminal; use another for edits. Leave the foreground log view with Ctrl+C. A rising restart count every few seconds indicates a startup problem; inspect the journal and run the environment check before changing locks.

`stop` stops the current service. `disable --now` also prevents automatic startup after reboot. Stop other manually launched bridge workers separately. Stop the service before updating or uninstalling, and restart it after an update so it loads the new PHP code.

## Bounded foreground test

Run this in a root/admin SSH shell **on the Linux server**, with the service stopped. The full example paths and `www-data` account must match your installation; see the [command explanation](INSTALLATION.md#read-the-example-commands-first). The `HOME` setting applies only to this process and must be readable by that account:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ background-job:worker --interval=1 --stop_after=30s 'OCA\DeckCalendarBridge\BackgroundJob\ReconcileCardJob' 'OCA\DeckCalendarBridge\BackgroundJob\SyncScanJob'
```

Single backslashes inside shell single quotes are intentional. systemd unit arguments use C-style escaping instead; the unit generator handles this. Always run the worker as the non-root config/web account with a readable HOME. Do not copy a bare root worker command.

Normal cron, multiple workers and manual synchronization use the same queue. Their concurrency is protected, but one dedicated service is enough for an ordinary deployment. Replace legacy services that run only `InspectDeckCardJob`; that compatibility class performs no synchronization.

## Pause and recover

Turning synchronization off pauses new reconciliation. Events while disabled need not be retained individually: the next scan after re-enabling converges the current state. An operation already in progress can finish; stop the worker for a quiescent upgrade or uninstall. Disabling a rule pauses that rule, while removing it requests deletion of its linked originals.

Read the relation error instead of deleting metadata or flushing Redis. A busy lock may belong to a live process or survive a crash until its normal expiry. An exact identity mismatch or unsupported event format needs operator correction. See the [troubleshooting guide](INSTALLATION.md#troubleshooting) and [uninstall choices](UNINSTALLATION.md).
