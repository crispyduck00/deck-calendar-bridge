# Publish the clean source from Windows PowerShell

Already have Git and VS Code, and prefer clicking through publication? Use [VSCODE_PUBLICATION.md](VSCODE_PUBLICATION.md) instead. That route does not require GitHub CLI or this script. Choose one route; do not create the same target twice.

This creates a **new public repository** with one initial commit and its own tested release. It does not change the visibility, history or files of any earlier repository. It needs Windows PowerShell 5.1 or PowerShell 7, Git and GitHub CLI. No WSL, Bash, Python, PHP or Nextcloud server is needed for publication.

The input is the complete clean-source ZIP supplied for this step, not the smaller installable app ZIP and not a clone containing `.git`. The script refuses Git metadata, links/junctions, common build/private files, invalid UTF-8 and recognized credential patterns. An optional private deny-term file complements manual source review.

## 1. Install tools and reopen the terminal

On Windows with WinGet:

```powershell
winget install --id Git.Git --exact --source winget
winget install --id GitHub.cli --exact --source winget
```

PowerShell 7 is optional:

```powershell
winget install --id Microsoft.PowerShell --exact --source winget
```

Close and reopen the terminal after installation so its PATH includes the tools. Check:

```powershell
git --version
gh --version
$PSVersionTable.PSVersion
```

Run subsequent commands in the same ordinary user terminal. No web-user account or OCC command is involved.

## 2. Extract the complete source and sign in

Download `deckcalendarbridge-source-1.1.3.zip` and its `.sha256` sidecar from the intended release. These are distinct from the smaller installable app ZIP. For a private source repository, sign in with GitHub CLI first, then download both files using `gh release download v1.1.3 --repo OWNER/SOURCE-REPOSITORY --pattern 'deckcalendarbridge-source-1.1.3.zip*' --dir .\source-download`. Use an existing release and a new download directory.

Extract the clean-source ZIP to a new directory, open its `deck-calendar-bridge` directory in PowerShell, and verify that it contains `README.md`, `appinfo`, `lib`, `docs`, `tests`, `.github` and `tools`. Do not copy old Git metadata into it. Read the README, AI disclosure and publication script.

After reviewing the script, allow local/reviewed scripts for this terminal process and unblock that file only. The setting expires when this terminal closes; an organization-enforced policy still takes precedence:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy RemoteSigned -Force
Unblock-File -LiteralPath .\tools\Publish-CleanRepository.ps1
```

Authenticate the GitHub account that will own the new repository:

```powershell
gh auth login --hostname github.com --git-protocol https --scopes repo,workflow
gh auth setup-git --hostname github.com
gh auth status
```

Use GitHub CLI's browser/device flow. Never paste tokens or passwords into source files or chat. The workflow scope permits publishing the included CI definition. An organization or fine-grained credential can require corresponding content/workflow/repository-creation permissions.

## 3. Preview a new target

Choose a repository name that does not exist yet. Do not create it manually first. Substitute the owner/name in every command:

```powershell
.\tools\Publish-CleanRepository.ps1 -Repository OWNER/deck-calendar-bridge
```

The default is a local preview with no network calls. It creates a new staging directory and prints `Review directory`. Inspect that directory and the adjacent `publication-plan.json`. Project GitHub links are rewritten to the selected target. The source directory remains unchanged. Every invocation uses a new work directory and refuses to overwrite existing output.

Optional private review terms must be stored outside the source, one per line:

```powershell
.\tools\Publish-CleanRepository.ps1 -Repository OWNER/deck-calendar-bridge -DenyTermsFile C:\Private\publication-terms.txt
```

The scanner reports a finding without printing the matching value. A scan cannot determine every possible private meaning, so review the current source and documentation too. Use the same deny file with `-Apply` if one was used for preview.

## 4. Publish and verify the release

After reviewing the source and preview:

```powershell
.\tools\Publish-CleanRepository.ps1 -Repository OWNER/deck-calendar-bridge -Apply -WaitForRelease
```

**`-Apply` creates a public repository and pushes the clean source.** It refuses an existing target and never force-pushes. The new initial commit has generic project attribution and exactly one commit of history. Source/license notices are retained.

`-WaitForRelease` watches the actual CI run for that commit. After all jobs pass, it verifies the stable tag, both release assets, downloaded SHA-256 checksum and the ZIP's `BUILD_COMMIT`. It leaves a `publication-receipt.json` beside the staging directory and prints the release URL. Keep that receipt until the transition is complete. The app ZIP and full source export are different deliverables.

The workflow includes native Nextcloud/Deck/DAV/SMTP tests, concurrent web-user worker/lifecycle checks and both Windows PowerShell versions. A failed job stops release publication. The script does not label a failed build successful or remove the new repository to hide a failure.

If interrupted after repository creation, keep the printed staging directory and inspect GitHub Actions/Releases. Do not repeat the initial-publication command against the now-existing target or delete it to bypass a failure. Use the staged Git checkout for focused fixes, PRs and a higher version if an already published release needs changing.

If no workflow starts, inspect the repository's Actions setting and permissions. If the initial push failed, inspect the error and resume the initial push from the staged checkout after correcting authentication. No deletion or visibility change of an earlier repository is part of this process.

## 5. Continue development in the new repository

Clone the public repository into a fresh working directory:

```powershell
gh repo clone OWNER/deck-calendar-bridge
cd deck-calendar-bridge
git switch -c docs/first-follow-up
```

Use this checkout for subsequent work. [DEVELOPMENT.md](DEVELOPMENT.md) explains the source map, invariants, branches, test environment and release gates. Do not push an earlier private repository's Git history into the public repository.

## 6. Archive an earlier private repository separately

First confirm that the new repository contains the full source, developer/operator guides, tests, workflow and a successful downloadable release. Keep the publication receipt and any private backup. An earlier repository may still contain useful private history, PR discussions and old diagnostics that were intentionally not exported.

Archiving retains that history and is reversible. Keep the earlier repository private. Run this only after replacing the placeholder with the actual earlier repository and completing the checks above:

```powershell
gh repo archive OWNER/PREVIOUS-PRIVATE-REPOSITORY --yes
```

Deleting a repository is a separate decision that can lose historical references and assets; this guide and script do not delete one. Old branch cleanup is optional housekeeping, not a prerequisite for the clean public copy.

## Scope of this Windows guide

These commands publish/manage the GitHub project. The Nextcloud app itself is installed on its Linux host using [INSTALLATION.md](INSTALLATION.md); that includes environment checks and explicit worker activation. [UNINSTALLATION.md](UNINSTALLATION.md) removes the app from Nextcloud and is unrelated to archiving a GitHub repository. App Store signing and submission are separate from this GitHub release.
