# Repository cleanup and public distribution

For a complete clean-source ZIP on Windows, start with the [VS Code guide](VSCODE_PUBLICATION.md): Git and VS Code are sufficient, and VS Code creates the new repository. GitHub CLI is not required for that route. The optional [PowerShell guide](WINDOWS_PUBLICATION.md) uses Git and GitHub CLI to automate publication and release verification. Both guides explain when to archive an earlier private repository. The POSIX/Python tools below are optional alternatives for maintainers; old repository cleanup is not required to create a clean public copy.

These are repository-maintenance tasks, not Nextcloud installation or uninstall steps. Run them from a **source checkout on an administrator's workstation** with Git, Python 3.10+, a POSIX shell and GitHub CLI (`gh`). They do not connect to a Nextcloud server. The app ZIP does not include the repository's `tools/` directory; obtain the tagged source checkout to use these scripts.

Authenticate GitHub CLI with the account authorized for the source and target repositories:

```bash
gh auth login --hostname github.com --git-protocol https --scopes repo,workflow
gh auth status
```

The example login requests repository and workflow access because the exported source includes its CI workflow. Existing fine-grained credentials need the corresponding repository-creation, content and workflow permissions; use credentials appropriate to the target owner. The account needs repository access; deleting Actions runs additionally requires the appropriate Actions permission. Git operations use GitHub CLI's credential helper without placing tokens in command arguments. Keep any backups and private review terms outside the public checkout.

## Why a fresh public copy

Making today's files generic does not erase historical commits, closed PR diffs, comments, release assets or old Actions logs. Deleting an obsolete branch does not remove every GitHub reference to its commits. Old development history can therefore remain private even after a source cleanup.

When old history contains internal examples, the provided public-copy script exports **only the reviewed tagged tree** into a new repository with one new initial commit. It copies no old `.git`, branches, tags, PRs, issues or logs. It leaves the original repository unchanged. Retain that original as a private archive, rather than changing its visibility.

The script refuses an existing target and does not force-push or change another repository's visibility. Its initial commit uses generic project attribution while retaining source/license notices. Project URLs are rewritten from the source repository to the new target. The new repository's own CI then builds and publishes a fresh package with its own correct `BUILD_COMMIT`.

## Review the public export

Substitute actual GitHub repository names. Choose a **new** target name, not the source name:

```bash
sh tools/create_public_copy.sh OWNER/PRIVATE-SOURCE OWNER/NEW-PUBLIC-REPOSITORY v1.1.3
```

This is a dry-run. It reads/clones the exact tag, checks application identity/version, exports the current files, scans for credential patterns and prints the private temporary export directory to inspect. No repository is created and nothing is published. The temporary directory is retained for review.

For additional project-specific terms, create a UTF-8 file outside the checkout, one term per line. Do not commit that list. Pass its absolute path:

```bash
export DCB_DENY_FILE=/absolute/private/path/publication-deny-terms.txt
sh tools/create_public_copy.sh OWNER/PRIVATE-SOURCE OWNER/NEW-PUBLIC-REPOSITORY v1.1.3
```

Findings show file/line/category, not the matching private value. A pattern scan complements manual review; it cannot determine the meaning or sensitivity of every possible string. Inspect the README, examples, tests, comments and exported file list. Only the tag's current files are eligible for publication.

After reviewing the dry-run and export, the publishing command is:

```bash
sh tools/create_public_copy.sh OWNER/PRIVATE-SOURCE OWNER/NEW-PUBLIC-REPOSITORY v1.1.3 --apply
```

**`--apply` creates a public GitHub repository and pushes the clean source.** It is the publishing step. The original private repository remains unchanged. Wait for all checks in the new repository's Actions run before using its generated release asset. Configure repository description/topics and private vulnerability reporting through GitHub as desired.

## Clean the existing private repository

Run cleanup only after an official stable release exists and its full CI run has passed. The default command prints a plan and performs no deletion:

```bash
python3 tools/github_cleanup.py --repo OWNER/REPOSITORY --keep-release v1.1.3
```

The plan protects the default branch, protected branches, branches with open PRs, and all stable tags/releases at major version 1 or later. It selects obsolete `feature/`, `release/` and `rewrite/` branches and pre-1.0/nonstable tags/releases. Other branch names are left alone. Review every planned branch; work without an open PR is still work and should be kept or backed up intentionally.

To apply, provide a **new private backup directory outside the checkout**:

```bash
python3 tools/github_cleanup.py --repo OWNER/REPOSITORY --keep-release v1.1.3 --backup-dir /absolute/private/backups/bridge-before-cleanup --apply
```

Before deletion, the script saves repository/PR/comment/ref metadata, a Git mirror and portable bundle, and assets of selected old releases. A failure during backup prevents deletion. Ref deletion uses the reviewed expected SHA, so a changed branch/tag is rejected rather than force-deleted blindly. Newly opened PR branches are rechecked. Changed release metadata stops cleanup for review.

Old completed Actions runs can be included explicitly:

```bash
python3 tools/github_cleanup.py --repo OWNER/REPOSITORY --keep-release v1.1.3 --delete-runs
python3 tools/github_cleanup.py --repo OWNER/REPOSITORY --keep-release v1.1.3 --delete-runs --backup-dir /absolute/private/backups/bridge-before-run-cleanup --apply
```

The second command also backs up available run logs before deletion. It preserves runs for the current default-branch head and retained stable-tag commits, and rechecks that a selected run has not become active. Large histories can take time and require backup space. Run-log backups may contain private information; never include them in the public export.

Cleanup does not delete closed PRs, erase main's history or sanitize every historical GitHub reference. It is housekeeping, not a reason to make the historical repository public. Use the clean-copy procedure above for a history-free public start.

## Restore and failures

The backup mirror/bundle can recover deleted refs; metadata JSON and downloaded assets retain the old release information. Keep backups private. If a lease or permission check fails, inspect the reported resource and rerun a fresh dry-run; do not bypass branch protection or force-push over changed work.

The scripts are tested offline for protected/open/stable selection, credential/deny-term detection and a dry-run export that excludes deleted historical files and `.git`. Actual repository deletion/publication requires the operator's authenticated GitHub CLI and explicit `--apply`.
