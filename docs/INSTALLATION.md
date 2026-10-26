# Install, check and upgrade

This guide targets a conventional Linux installation with systemd. For a container, appliance or managed deployment, run PHP/OCC inside the actual Nextcloud environment and use that deployment's scheduling facilities. Do not point a host-side service at unrelated container paths.

Run the numbered sections in order, one command at a time. Stop if a check fails. These are **Linux server commands**, entered after connecting to the Nextcloud server over SSH. They are not commands to paste into a local Windows PowerShell terminal. Publishing the source from Windows is covered by the separate [VS Code guide](VSCODE_PUBLICATION.md).

## Read the example commands first

The commands use these concrete example values throughout; there are no shell variables to initialize or remember:

| Example value | Meaning | Check it before continuing |
|---|---|---|
| `/var/www/nextcloud` | Directory containing Nextcloud's `occ` and `config/config.php` | Use the directory of your actual Nextcloud installation |
| `/usr/bin/php` | PHP command-line executable | It must use the version and extensions required by this Nextcloud instance |
| `www-data` / `www-data:www-data` | Web-server user / user and group | Match the owner and group of `config/config.php` |
| `/var/www/nextcloud/custom_apps` | Writable app directory | Match the configured writable `apps_paths` entry |

If your installation differs, replace the relevant value in **every command** before running it. For example, use `/usr/bin/php8.4` everywhere instead of `/usr/bin/php` if that is the correct CLI executable. Keep any installation path containing spaces inside shell quotes.

This typical OCC command can be copied as one complete line:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ status
```

`sudo -u www-data` runs PHP as the web account. `env HOME=/var/www/nextcloud` gives **that process** a readable home directory, avoiding failures when an administrator's home directory is inaccessible. It does not move files, change ownership or permanently change an account's home. `/usr/bin/php` runs PHP, `/var/www/nextcloud/occ` selects Nextcloud's administration tool, and `status` is the operation. Keep this account/HOME prefix for the OCC and worker commands below; it prevents the permission problems caused by running them as root.

## 1. Identify the installation — root shell

Connect to the Nextcloud Linux server over SSH. Open a root shell, for example with `sudo -i`, and use that shell for this guide. Root is needed to install files and manage the service; each OCC command switches to the web account explicitly.

```bash
ls -l /var/www/nextcloud/occ /var/www/nextcloud/config/config.php
stat -c '%U:%G %a %n' /var/www/nextcloud/config/config.php
```

Expected: both files exist and `stat` reports `www-data:www-data`, or your installation's actual non-root web account/group. For a different account, adapt the example values above. If the config owner is `root`, resolve ownership using the installation's normal administration method before proceeding. Do not start a root worker as a workaround.

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php -v
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php -m
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ status
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ app:list
```

Check all of the following:

- Nextcloud reports installed, version 35, not in maintenance mode, with no pending core upgrade.
- Deck 1.19.x and DAV are enabled. The Calendar app provides the calendar UI.
- PHP CLI is a supported version; the tested matrix uses PHP 8.3 and 8.4. Match the version/extensions used by this Nextcloud installation. If needed replace `/usr/bin/php` with `/usr/bin/php8.4` or the actual installed binary in all commands, and repeat the checks.
- Nextcloud's required CLI modules and the POSIX extension are present. Common relevant modules include DOM/XML, mbstring and the PDO driver for the selected database. Install missing modules for the exact CLI PHP version through the operating system's package manager.
- The selected organizer and original Nextcloud calendar exist. An organizer email and working Nextcloud mail setup are additionally needed for invitations.

Back up database, configuration and app files. A restored database and its Nextcloud files must belong to the same snapshot. If replacing an existing bridge, follow [Upgrade](#upgrade) instead of extracting over its files.

## 2. Download and verify the installable package

From the intended [official release](https://github.com/crispyduck00/deck-calendar-bridge/releases), download:

- `deckcalendarbridge-nc35-1.1.3.zip`
- `deckcalendarbridge-nc35-1.1.3.zip.sha256`

Put both files in `/tmp` on the Nextcloud server, using the browser/download/SSH transfer method appropriate for the repository's visibility. Do not choose GitHub's automatic “Source code” ZIP. For example, transfer already downloaded files from a workstation with `scp` to the server.

```bash
cd /tmp
sha256sum -c deckcalendarbridge-nc35-1.1.3.zip.sha256
unzip -t /tmp/deckcalendarbridge-nc35-1.1.3.zip
unzip -l /tmp/deckcalendarbridge-nc35-1.1.3.zip | head -15
```

Expected: checksum `OK`, no ZIP errors, and paths starting with `deckcalendarbridge/`, including `deckcalendarbridge/appinfo/info.xml`. A checksum detects a damaged or mismatched download; obtain both files from the intended release. The package's `BUILD_COMMIT` records the exact source tested by CI.

Development Actions downloads are different: an outer artifact ZIP contains the app ZIP and checksum. Extract the outer archive into a separate download directory first; use the inner app ZIP in this guide.

## 3. Install the files — root shell

Use one writable app path. This guide uses `custom_apps`; do not leave duplicate bridge copies under both `apps` and `custom_apps`.

```bash
install -d -o www-data -g www-data -m 0755 /var/www/nextcloud/custom_apps
```

Keep an existing correct `apps_paths` setting. If a writable app path is not configured, add it to `config/config.php` while preserving the rest of the file. This is a PHP configuration fragment, not a shell command; substitute the real paths:

```php
'apps_paths' => [
    ['path' => '/var/www/nextcloud/apps', 'url' => '/apps', 'writable' => false],
    ['path' => '/var/www/nextcloud/custom_apps', 'url' => '/custom_apps', 'writable' => true],
],
```

For a fresh install, `/var/www/nextcloud/custom_apps/deckcalendarbridge` must not exist. This command extracts the archive only when that directory is absent; a `STOP` message means use the upgrade procedure instead.

```bash
if [ -e /var/www/nextcloud/custom_apps/deckcalendarbridge ] || [ -L /var/www/nextcloud/custom_apps/deckcalendarbridge ]; then
    printf 'STOP: bridge directory already exists. Use the upgrade procedure.\n'
else
    unzip /tmp/deckcalendarbridge-nc35-1.1.3.zip -d /var/www/nextcloud/custom_apps
fi
```

After successful extraction:

```bash
chown -R www-data:www-data /var/www/nextcloud/custom_apps/deckcalendarbridge
find /var/www/nextcloud/custom_apps/deckcalendarbridge -type d -exec chmod 0755 {} +
find /var/www/nextcloud/custom_apps/deckcalendarbridge -type f -exec chmod 0644 {} +
```

Only this app directory is changed recursively. Do not recursively alter the whole Nextcloud tree to fix one bridge permission.

## 4. Run the preflight, enable the app and run the doctor

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/custom_apps/deckcalendarbridge/bin/check-environment.php /var/www/nextcloud
```

Expected: `ok: true` and every entry in `checks` is true. The preflight boots the installed Nextcloud without requiring bridge activation. It checks versions, enabled dependencies, config/process ownership, effective HTTPS link URL, HOME, data/log access, actual core job-ID generation and an independent native lock probe. It does not create cards, appointments or background jobs. One unused core ID is allocated by the ID-generation test.

If preflight succeeds:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ app:enable deckcalendarbridge
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:doctor
```

Expected after a fresh install: version `1.1.3`, `ok: true`, `enabled: false`, and valid empty rules. Synchronization being disabled is intentional. Enabling the app initializes tables, jobs and installation identity; it does not activate calendar writes. The doctor additionally validates stored rule invariants and reports pending-card/open-relation counts.

Use the troubleshooting table below if a check fails. Do not continue merely because the app appears in the app list.

## 5. Configure normal Nextcloud cron

Keep an existing working cron entry or deployment-managed systemd timer. Inspect it first:

```bash
crontab -u www-data -l
systemctl list-timers --all --no-pager | grep -i nextcloud
```

If using cron, select cron mode and verify it:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ background:cron
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ config:app:get core backgroundjobs_mode
```

Expected: `cron`. This selects the Nextcloud mode; it does **not** create an operating-system schedule. If neither a working cron entry nor a Nextcloud timer exists, open the web user's crontab:

```bash
crontab -u www-data -e
```

Add the following single line to that editor, then save and close it. This is a crontab entry, not a shell command:

```cron
*/5 * * * * /usr/bin/php -f /var/www/nextcloud/cron.php
```

That example runs every five minutes. Keep only one ordinary scheduler for the instance. On a cron-based distribution, its cron/crond service must also be active; a systemd-timer deployment uses its timer instead.

A manual invocation of `cron.php` can verify execution, but runs eligible background work for **all apps**, not just this bridge. The Nextcloud administration overview should show a recent background-job execution. Keep normal cron active even after adding the bridge worker.

## 6. Generate, install and explicitly activate the worker — root shell

The generator reads paths and config ownership and prints a systemd unit; it does not bootstrap Nextcloud or start jobs as root. It targets the `custom_apps` location used in this guide. The packaged unit in `packaging/` is an example; generating it avoids manually editing user/path fields. First save a review copy in root's private directory, inspect it and verify it:

```bash
/usr/bin/php /var/www/nextcloud/custom_apps/deckcalendarbridge/bin/render-worker.php /var/www/nextcloud /usr/bin/php > /root/deckcalendarbridge-worker.service
cat /root/deckcalendarbridge-worker.service
systemd-analyze verify /root/deckcalendarbridge-worker.service
```

Expected: the actual non-root config owner in `User`, a matching group, correct PHP/Nextcloud paths, `HOME` set to the Nextcloud directory, and **both** `ReconcileCardJob` and `SyncScanJob` in `ExecStart`. Keep the generated backslash escaping. Stop here if generation or verification fails.

```bash
install -m 0644 /root/deckcalendarbridge-worker.service /etc/systemd/system/deckcalendarbridge-worker.service
systemctl daemon-reload
systemctl enable --now deckcalendarbridge-worker.service
systemctl is-enabled deckcalendarbridge-worker.service
systemctl is-active deckcalendarbridge-worker.service
systemctl status deckcalendarbridge-worker.service --no-pager
journalctl -u deckcalendarbridge-worker.service -n 50 --no-pager
```

Expected: `enabled`, `active` and a running PHP process. **This explicit activation is required.** Installing/enabling the Nextcloud app does not start the service. An idle worker may have no job messages yet. A repeating permission/class/startup error is not normal; check it before enabling synchronization.

The worker handles native event jobs and periodic scans. It requests renewal after about 15 minutes, then systemd restarts it. [WORKER_SYSTEMD.md](WORKER_SYSTEMD.md) explains each trigger, timing, foreground tests, stop/start/restart commands and what cron does.

## 7. Save rules and enable synchronization

Open **Administration → Deck Calendar Bridge**. Add one rule per board/trigger tag, select the original target calendar, enable that rule and save. Optional settings include prefix, categories, initial attendees and category-update emails. See [CONFIGURATION.md](CONFIGURATION.md).

Start without invitation recipients if no test mailbox is available. The organizer must have a valid email address when initial invitations are enabled. Existing appointments keep their current attendees if the rule's recipient list changes later.

Enable global synchronization in the admin page, or run:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ config:app:set deckcalendarbridge relations_v1_enabled --value=yes
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:sync
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:doctor
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:relations:status --details --verify-local
```

`Config value were not updated` can mean `yes` was already saved. Confirm the saved value with this complete command:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ config:app:get deckcalendarbridge relations_v1_enabled
```

New rules process existing matching cards in bounded pages. A source start or due date must be now or in the future for first creation. Historical cards qualify later if their dates move forward. A nonempty relation error denotes unresolved work; leave the metadata intact and inspect the reason.

## 8. Verify with a disposable card

1. Create a future card and add a configured trigger tag. Expect exactly one original appointment for that rule.
2. Change card times. Expect the appointment to follow automatically.
3. Move the original Nextcloud appointment. Expect card times and any other linked originals to follow.
4. Remove one trigger tag. Expect only that original appointment to be deleted.
5. Add the trigger again. Expect a new lifecycle; then delete its original appointment and check that only its trigger tag is removed.
6. Archive a test card: existing appointments remain. Test done with the rule's keep/delete choice.
7. Add a new rule for an existing future card and allow the paged backfill to run.
8. Change a managed description: expect the exact card link and notice to return. An unchanged repeat sends no additional update.
9. With a controlled test recipient, verify invitation, time/title update, category emails off/on and cancellation. If using an external automatic-acceptance rule, verify that external rule separately.

The bridge does not control an external client's acceptance, rendering or category retention. Only an edit to the original Nextcloud event can change Deck times; moving an external attendee copy is not reverse synchronization.

## Upgrade

1. Back up database, app files, config and any descriptions you need. Keep the backup outside all Nextcloud app paths.
2. Stop `deckcalendarbridge-worker.service` and inspect other manually launched workers. Finish/stop bridge processes before replacing code. Do not stop unrelated Nextcloud workers indiscriminately.
3. Download and verify the intended new app ZIP/checksum. Extract it into a separate staging directory. Move the old app directory outside every configured app path; install the complete new directory with the ownership/modes in step 3. Do not overlay files or reset tables.
4. Run the preflight. Run the complete upgrade/doctor commands below as the config owner with the correct HOME/PHP. Confirm version `1.1.3` and inspect the package's `BUILD_COMMIT`.
5. Regenerate/reinstall the unit if paths, PHP, account or the old worker command changed. Start the service again and check status/journal.
6. Preserve existing enable flags, rules and relation identities. Only a first migration from 0.3.x needs explicit engine activation. Old 0.3.x write flags do not control this engine.
7. Repeat the disposable-card checks and allow recovery/backfill to settle.

After installing the replacement files and passing the preflight:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ upgrade
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:doctor
```

When migrating from 0.4.2 or earlier, descriptions are normalized once to the card link and ownership notice. Preserve needed calendar notes in Deck first. This genuine description change may send a normal participant update even with category emails disabled. Upgrading from 0.4.3 keeps already normalized descriptions equal.

## Troubleshooting

| Symptom/check | Next step |
|---|---|
| PHP version/module failure | Select the matching CLI binary; install its required modules; rerun preflight |
| Unsupported Nextcloud/Deck version | Use the supported release combination; do not edit app version constraints to bypass the check |
| Config owner/process mismatch | Recheck `stat`, the selected web account and explicit `sudo -u` invocation |
| HOME check fails | Keep `env HOME=/var/www/nextcloud` in the command; adapt the path and verify that directory is readable by the config owner |
| HTTPS base-URL check fails | Set the actual canonical HTTPS `overwrite.cli.url`, including any Nextcloud subpath, then rerun the check |
| Core ID-generation check fails | Inspect the exact Snowflake path printed by the doctor; see below |
| Lock probe fails | Verify Nextcloud's configured locking backend and its connectivity/permissions |
| Rules invalid | Review the saved configuration; active rules need one board/tag and a unique binding |
| Worker active, no appointments | Check global enable, rule enable, matching tag, source dates, relation errors and both worker job filters |
| Work delayed with worker stopped | Cron processes eligible jobs on its actual schedule; install/start the dedicated worker for prompt handling |
| `card_locked` / `scan_locked` | Another operation owns that resource; queued work remains; inspect actual processes and allow normal retry/expiry |
| Calendar identity/format error | Restore the original supported object/target or use the documented keep-events uninstall choice; do not guess a new relation |

For an incorrect CLI URL, replace `https://YOUR-NEXTCLOUD-HOST` with your actual canonical HTTPS URL, including a Nextcloud subpath if applicable, then run:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ config:system:set overwrite.cli.url --value='https://YOUR-NEXTCLOUD-HOST'
```

Useful checks from the server's root shell; every OCC line includes its complete account and paths:

```bash
systemctl show deckcalendarbridge-worker.service -p User -p Group -p MainPID -p ExecStart -p NRestarts
ps -eo user,pid,etime,args | grep -E '[p]hp.*occ.*(background-job:worker|deckcalendarbridge:sync)'
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:doctor
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:relations:status --details --verify-local
journalctl -u deckcalendarbridge-worker.service -n 100 --no-pager
```

Add `--card-id=ACTUAL-ID` to relation status or manual sync to inspect/process one actual card. A slow calendar save/delete HTTP request is different from delayed background propagation: callbacks do bounded metadata/queue work, while Nextcloud's native scheduling/mail delivery and database contention can also affect request time.

### Targeted core ID-permission repair

Nextcloud can allocate a core job-history ID before bridge code runs. Its Snowflake sequence directory is normally named `sfi_file_sequence_<instance-id>` below the temp directory. If created by a different operating-system user, its private permissions can prevent startup.

The doctor reports the **exact current-instance path** at `id_generation.snowflake_directory`. Only if that check identifies an ownership failure: stop incorrectly owned processes, inspect that exact directory and correct its ownership as root. Replace the placeholder below with the reported path:

```bash
stat -c '%U:%G %a %n' /exact/current-instance/path/from/doctor
chown -R www-data:www-data /exact/current-instance/path/from/doctor
```

Rerun preflight/doctor afterwards. Do not change all of `/tmp`, delete sequence files, alter another instance's directory, flush Redis globally or delete all Nextcloud locks. Sequence contents support core ID uniqueness. A new bridge installation namespace isolates bridge mutexes; it does not repair unrelated core filesystem ownership.

To pause or completely remove the app, follow [UNINSTALLATION.md](UNINSTALLATION.md).
