# Pause and complete removal

Connect to the **Nextcloud Linux server over SSH** and open a root/admin shell, for example with `sudo -i`. These are server commands, not local Windows PowerShell commands. Run the selected removal procedure one command at a time and stop at an error.

Every OCC command below switches to the owner of `config/config.php`. The concrete examples use `/var/www/nextcloud`, `/usr/bin/php` and `www-data`; substitute your actual installation values in every command if they differ. The [installation guide's example table and command explanation](INSTALLATION.md#read-the-example-commands-first) explain those values and the process-local `HOME` setting. No shell variables need to be initialized. Service management and service-file removal run as root. Back up the matching database and files before complete removal.

## Pause without removing data

```bash
systemctl stop deckcalendarbridge-worker.service
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ config:app:set deckcalendarbridge relations_v1_enabled --value=no
```

Stopping the service alone does not pause synchronization: normal cron can still run bridge jobs. The flag change above pauses it. Rules, relations, cards, tags and events remain. Ordinary `occ app:disable deckcalendarbridge` is also nondestructive and preserves bridge tables. Stop the service if the app is to remain disabled. To resume, enable the app if needed, set the synchronization flag to `yes` and start the worker; run the doctor first if the environment changed.

## Remove the bridge and its linked events

Stop and disable the actual bridge worker service. Also stop manually launched bridge workers; inspect the process list before continuing. Keep other Nextcloud apps and their normal scheduler available.

```bash
systemctl disable --now deckcalendarbridge-worker.service
ps -eo pid,etime,args | grep -E '[p]hp.*occ.*(background-job:worker|deckcalendarbridge:sync)'
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:uninstall
```

The final command is a **read-only preview**. It changes no rules, events or tables. Execute the reviewed plan explicitly:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:uninstall --execute
```

The command pauses new work and deletes only verified linked original events through the normal reconciler. It retains **all Deck cards and tags**. After cleanup succeeds, it removes the app files, old/current bridge jobs, its five tables, its migration markers and its app/user settings. Other apps and unrelated events remain.

Native DAV deletion may send normal attendee cancellations. Nextcloud trash-bin retention still applies; the bridge does not empty a calendar or its entire trash bin.

If a relation is busy or an original cannot be safely identified/deleted, cleanup stops **before removing relation metadata**. Some earlier verified originals may already have been removed; rerunning continues cleanup, and switching to keep-events does not restore those earlier deletions. Synchronization remains paused. Resolve the reported cause and rerun the command. Previous rules remain available under the administrative `uninstall_rules_backup` key while cleanup is incomplete. Do not delete tables or flush Redis to force progress.

Removal also requires the normal Nextcloud database permissions to drop this app’s own tables. Calendar changes, file removal and schema changes are not one atomic transaction; retain the matching backup. File removal requires a real writable app directory in a writable app path. Development symlinks are rejected. If file removal fails, metadata is retained; correct permissions, re-enable the app if necessary, and retry explicit removal.

## Remove the bridge but retain its events

After stopping/disabling the bridge service, use this alternative:

```bash
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:uninstall --keep-events
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ deckcalendarbridge:uninstall --keep-events --execute
```

Events remain as independent calendar objects, including their current descriptions and attendees. Relations, rules and jobs are removed. Retaining events sends no cancellation by itself. Deck cards and tags remain.

A later clean installation receives a new identity and starts with synchronization disabled. Linking the same cards again can create new events. Retained events are not automatically adopted by matching titles.

## Remove the service file and verify

After successful app removal, run as root, substituting a different service name/path if one was used:

```bash
systemctl reset-failed deckcalendarbridge-worker.service
rm /etc/systemd/system/deckcalendarbridge-worker.service
systemctl daemon-reload
sudo -u www-data env HOME=/var/www/nextcloud /usr/bin/php /var/www/nextcloud/occ app:list
test ! -e /var/www/nextcloud/custom_apps/deckcalendarbridge
```

Remove only the unit installed for this bridge. A second `occ app:remove` is unnecessary: the explicit bridge command already removes app files.

Nextcloud/journald logs and historical job statistics remain operational history. Old short-lived cache keys can expire naturally and do not block a new installation identity. Keep normal cron, Redis, Deck and Calendar/DAV running.

## Already removed through Nextcloud's app manager?

Ordinary `occ app:remove` does not perform the bridge's full external-event and metadata cleanup. Run the explicit bridge uninstall command **before** removing its files. If already removed, reinstall the matching version without resetting existing metadata, then use explicit uninstall. Removing relation tables first loses the evidence needed to identify original events safely.
