# Contributing

Read the [development principles](docs/DEVELOPMENT.md), [field ownership rules](docs/CONFIGURATION.md) and [relation architecture](docs/RELATIONS_V1.md) before changing synchronization behavior. Keep one reconciler for events, scans, manual execution and recovery. Preserve original identities, ETag checks, queue generations and the narrow delegated write scope.

Use a separate development branch and a disposable Nextcloud installation. Never run the integration setup against an existing production config. [TESTING.md](docs/TESTING.md) describes the native suite and required PHP modules. Python maintenance-tool tests are offline and do not create or delete GitHub resources.

A useful bug report includes app/Nextcloud/Deck/PHP versions, database/locking type, the exact action, expected behavior and a redacted relation error code. Remove account identifiers, email addresses, hostnames and calendar/card content from logs before posting. Do not attach `config.php`, credentials, raw calendar objects or database dumps.

For fixes, add a meaningful regression scenario that reproduces the problem. Document changes to field ownership, deployment or supported input formats. Update `appinfo/info.xml` and `CHANGELOG.md` for a new release; packaging derives its version from the app metadata.

Use focused branches and PRs. Describe the final behavior, actual checks and limitations. Follow [RELEASE_GATES.md](docs/RELEASE_GATES.md) before publishing; never replace tested release assets in place.

AI-assisted contributions are welcome with the disclosure and verification described in [AI_USAGE.md](AI_USAGE.md). Do not invent test evidence or independent review. Keep private data out of generated examples and commit history. Use UTF-8/LF files; `.gitattributes` keeps Windows and Linux checkouts consistent.
