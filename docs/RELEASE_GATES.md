# Release checklist

Use one reviewed commit and its exact build artifact throughout validation. Do not treat an artifact from one successful matrix job as evidence that the whole run passed.

1. PHP and JavaScript syntax, rule/serializer tests and deterministic reconciliation pass.
2. Real Nextcloud 35.0.1 / Deck 1.19.0 / DAV / SMTP scenarios pass on PHP 8.3 and 8.4 with SQLite and on PHP 8.4 with MariaDB 10.11 / Redis.
3. The Redis job runs two long-lived workers, concurrency and disable/uninstall/reinstall scenarios as the actual `www-data` OS user. Permission diagnosis includes a deliberately unwritable core sequence directory.
4. Description restoration, optional category-update delivery, current attendee/RSVP preservation, exact invitation contents and no resend on equality pass through native scheduling.
5. The packaged systemd unit passes `systemd-analyze verify`; installation explicitly activates it and keeps normal cron enabled.
6. Package version, admin version and artifact name agree. `BUILD_COMMIT` matches the tested source. The app ZIP and published SHA-256 sidecar agree. The ZIP contains one top-level `deckcalendarbridge/` directory, runtime files, English documentation and change history; it excludes fixtures, secrets and development dependencies.
7. Current docs cover fresh install, upgrade, managed-description migration, permission/lock diagnosis, pause, both uninstall choices, and client behavior limits. Obsolete candidate instructions are removed rather than presented as current alternatives.
8. Before deploying, back up and verify the target environment with the preflight and doctor. Validate real mailbox delivery/client rendering there; CI does not claim to exercise a production mailbox.

The complete gate also includes both native Windows PowerShell publication jobs, matching version/license/issue metadata, AI disclosure, full developer/operator guides and UTF-8/LF source policy. Publication tests use real temporary Git histories and mocked external commands; they never create or delete a live GitHub repository. A successful public handover additionally verifies the real target's CI/tag/checksum/BUILD_COMMIT through the PowerShell tool.

The release also includes a complete source ZIP/checksum. Packaging checks its file list against every tracked file and excludes Git history. This source export contains development/publication tools and tests; the installable app ZIP remains a separate artifact.

See [TESTING.md](TESTING.md) for reproducible scenarios, [INSTALLATION.md](INSTALLATION.md) for deployment and [UNINSTALLATION.md](UNINSTALLATION.md) for removal. Legacy migration schemas and harmless old job-class stubs remain intentionally so upgrades and persisted jobs continue to work.

The 1.0 gate additionally covers queue acknowledgement/fairness, stale deletion intent, parent-column deletion/movement, doctor HOME/rule failures, unambiguous calendar formats and offline maintenance tools. Public publication uses the reviewed tagged tree with new history; cleaning old branches alone does not sanitize archived PRs or commits.
