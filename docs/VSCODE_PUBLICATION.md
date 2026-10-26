# Publish a clean repository with VS Code on Windows

Use this route if Git and VS Code are already installed. **GitHub CLI (`gh`) is not required. Do not create the new repository on GitHub first.** VS Code creates it when you select **Publish to GitHub**. The optional PowerShell publication script is a separate route; do not run its `-Apply` command after using this guide.

These steps publish source code from a Windows computer. They do not install or change Nextcloud. For installation on the Linux server, use [INSTALLATION.md](INSTALLATION.md).

## 1. Download the complete source

1. Sign in to GitHub in your web browser using the account that can access the intended source release. For a private source, you must be signed in before downloading.
2. Open that repository's **Releases** page and open version **1.1.3**.
3. Under **Assets**, download **`deckcalendarbridge-source-1.1.3.zip`** and **`deckcalendarbridge-source-1.1.3.zip.sha256`** to your Downloads folder.
4. Use the explicitly named **source** ZIP. The `deckcalendarbridge-nc35-...` ZIP is the smaller Nextcloud installation package; it does not contain the full development project. GitHub's automatically generated “Source code” links are different assets too.
5. To check the download in Windows, open a PowerShell terminal in the Downloads folder and run:

```powershell
Get-FileHash -Algorithm SHA256 .\deckcalendarbridge-source-1.1.3.zip
Get-Content .\deckcalendarbridge-source-1.1.3.zip.sha256
```

The two SHA-256 values must match, ignoring letter case. Stop and download the matching files again if they differ.

## 2. Extract the project and open the right folder

1. In Windows File Explorer, right-click the source ZIP and choose **Extract All**. Work from the extracted files, not from inside the ZIP viewer.
2. Find the extracted **`deck-calendar-bridge`** folder. Inside it you should see `README.md`, `appinfo`, `lib`, `docs`, `tests`, `tools` and `.github`.
3. Move that entire folder to a new working location, for example **`C:\Projects\deck-calendar-bridge`**. Create `C:\Projects` in File Explorer first if it does not exist. Keep all included files and folders, including `.github` and `.gitignore`.
4. In VS Code select **File → Open Folder…** and choose **`C:\Projects\deck-calendar-bridge`**, or the location you selected.
5. Confirm that `README.md` and `.github` appear directly in VS Code's Explorer. If you see only another `deck-calendar-bridge` folder, open that inner folder instead.
6. Read `README.md`, `AI_USAGE.md` and the documentation. This source ZIP contains no earlier Git history. Do not copy `.git` from an older checkout into it.

The project links use the repository behind the README's **Releases** link. If you publish to a different account or repository name, replace that complete GitHub project base URL throughout the project before the first commit. Keep the Nextcloud app ID and runtime directory name **`deckcalendarbridge`** unchanged.

## 3. Initialize a fresh local repository

Select **Terminal → New Terminal** in VS Code. The terminal should be PowerShell and should already be in the opened project directory. Run these lines one at a time:

```powershell
git --version
git init -b main
git config user.name "Project contributors"
git config user.email "contributors@example.invalid"
```

The first command confirms Git is available. The second starts fresh history on the required **`main`** branch. The last two set a generic author for this project only, so creating the public snapshot does not require putting a personal email address in the first commit. They do not sign you in to GitHub or change other projects' Git settings. Existing attribution and license notices remain in the files.

If Git is not found, restart VS Code after installing Git. If the source folder already has Git history, stop and return to a newly extracted source ZIP.

## 4. Make the first commit

1. Press **Ctrl+Shift+G** to open **Source Control** / **Quellcodeverwaltung**.
2. Review the file list. It should contain the extracted project, including `.github/workflows/ci.yml`, documentation and tests. It should not contain downloaded ZIPs, credentials or unrelated personal files.
3. Click the **+** beside **Changes** to stage all the reviewed files. If the button is hidden, hover over the **Changes** heading, or use its **…** menu and **Stage All Changes**.
4. In the message box type **`Initial public release 1.1.3`**.
5. Click **Commit**. The changes list should become empty. This saves the first commit locally; nothing is public yet.

If Git asks for a name or email, repeat the last two commands in step 3 from this project's terminal. The status bar should show **`main`**.

## 5. Create the new public repository from VS Code

1. Press **Ctrl+Shift+P** to open the Command Palette.
2. Type **`Publish to GitHub`** and select that command. If it is unavailable, confirm that the correct local folder is open, Git is detected and the first commit succeeded.
3. If prompted, allow VS Code to sign in and complete the GitHub sign-in in the browser. Use the GitHub account that should own the new project, then return to VS Code.
4. Enter a new, available repository name, for example **`deck-calendar-bridge`**. Keep it consistent with the project URLs reviewed in step 2.
5. Choose the option labeled **Public** / **Publish to GitHub public repository**. This makes the uploaded files accessible to everyone.
6. Wait for publication to finish, then select **Open on GitHub**.

VS Code creates the hosted repository, connects it to the local folder and uploads your new commit. Do not create it separately with an initial README, license or `.gitignore`; those files are already included in the source. If the chosen target already exists, inspect it before proceeding instead of force-pushing or deleting it.

## 6. Check the new repository's own tests and release

1. On GitHub, verify the owner/name, **Public** visibility and **`main`** branch. Check that the initial history contains only the new commit.
2. Check that `README.md`, `AI_USAGE.md`, `docs`, `tests` and `.github/workflows/ci.yml` are present.
3. Open **Actions** and the run for your initial commit, named **Relations engine and native NC35 integration**. Wait for completion; a yellow/running check is not a pass.
4. All metadata, both Windows shell tests and all three native Nextcloud integration configurations must pass. The final **release** job must pass too. The full run takes several minutes.
5. Open **Releases** in this **new** repository. Version **`v1.1.3`** must include all four assets:

```text
deckcalendarbridge-nc35-1.1.3.zip
deckcalendarbridge-nc35-1.1.3.zip.sha256
deckcalendarbridge-source-1.1.3.zip
deckcalendarbridge-source-1.1.3.zip.sha256
```

6. The release tag must point to your initial commit. Download the installable app ZIP and its checksum into a separate folder, compare SHA-256 values as in step 1, then extract that ZIP and open `deckcalendarbridge/BUILD_COMMIT`. It must contain the full commit SHA shown by the successful Actions run and release tag.

The first push to `main` triggers the included workflow and, after all checks pass, creates this repository's own release. You do not need to create a release manually. The manual VS Code route does not create the PowerShell script's publication receipt; perform the checks above yourself.

If no run appears, check the new repository's **Settings → Actions → General** and whether Actions are enabled for the included workflow. If a job fails, open its log and resolve the reported problem. Do not archive the earlier repository or treat the preparation repository's release as proof that this new publication succeeded.

## 7. Keep developing here; archive the earlier repository afterwards

The folder opened in VS Code is now connected to the new repository. For later work, create a branch from `main`, edit, review, commit and push through Source Control, then use a pull request. Follow [DEVELOPMENT.md](DEVELOPMENT.md) for architecture, required checks and release/version rules. Do not import an earlier private repository's Git history.

Only after the new repository's own source, full CI run and downloadable release have been checked:

1. Open the **earlier private** repository on GitHub. Double-check its owner/name.
2. Keep its visibility private.
3. Select **Settings → General**, scroll to **Danger Zone**, then choose **Archive this repository**.
4. Read and complete GitHub's confirmation for that earlier repository.

Archiving keeps the history and makes the repository read-only; it can be reversed. Deletion is unnecessary for this move. Archiving GitHub does not disable an app already installed on Nextcloud; server removal is described in [UNINSTALLATION.md](UNINSTALLATION.md).

References: [VS Code first commit](https://code.visualstudio.com/docs/sourcecontrol/quickstart), [Publish to GitHub](https://code.visualstudio.com/docs/sourcecontrol/repos-remotes), [GitHub archiving](https://docs.github.com/en/repositories/archiving-a-github-repository/archiving-repositories).
