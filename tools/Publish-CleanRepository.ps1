#Requires -Version 5.1
<#
.SYNOPSIS
Publish a reviewed, history-free source export to a NEW public GitHub repository.
.DESCRIPTION
Preview is the default and makes no network calls. The source must not contain
.git, links, build outputs or credentials. -Apply creates a public repository;
-WaitForRelease additionally checks CI, the release checksum and BUILD_COMMIT.
The source and any earlier repository are never modified or removed.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Repository,
    [string]$SourcePath = (Split-Path -Parent $PSScriptRoot),
    [string]$SourceRepository,
    [string]$WorkDirectory,
    [string]$DenyTermsFile,
    [switch]$Apply,
    [switch]$WaitForRelease
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-Native {
    param([string]$Executable, [string[]]$Arguments)
    $result = & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Executable failed (exit $LASTEXITCODE). No later step was executed." }
    return $result
}
function Invoke-GhJson {
    param([string[]]$Arguments)
    $text = @(Invoke-Native 'gh' $Arguments)
    $decoded = ConvertFrom-Json -InputObject ($text -join "`n")
    foreach ($item in $decoded) { if ($null -ne $item) { $item } }
}
function Get-Sha256 {
    param([string]$Path)
    $stream = [IO.File]::OpenRead($Path)
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { return [BitConverter]::ToString($algorithm.ComputeHash($stream)).Replace('-','').ToLowerInvariant() }
    finally { $algorithm.Dispose(); $stream.Dispose() }
}
function Get-SourceFiles {
    param([string]$Root)
    $pending = New-Object 'System.Collections.Generic.Queue[string]'
    $pending.Enqueue($Root)
    while ($pending.Count -gt 0) {
        foreach ($item in Get-ChildItem -LiteralPath ($pending.Dequeue()) -Force) {
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Source links/junctions are not allowed.' }
            if ($item.Name -in @('.git','node_modules','vendor','build','dist','.venv','__pycache__','.env','config.php') -or
                $item.Name -match '(?i)(\.key|\.pem|\.pfx|\.p12|\.zip|\.tar\.gz|\.log)$|^\.env\.') {
                throw "Refused source entry: $($item.Name). Use the clean source ZIP, not an installed app or working checkout."
            }
            if ($item.PSIsContainer) { $pending.Enqueue($item.FullName) } else { $item }
        }
    }
}

foreach ($name in @($Repository, $SourceRepository)) {
    if ($name -and $name -cnotmatch '^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$') { throw 'Use OWNER/REPOSITORY names.' }
}
if ($WaitForRelease -and -not $Apply) { throw '-WaitForRelease requires -Apply.' }
if ($env:GH_HOST -and $env:GH_HOST -ne 'github.com') { throw 'This tool supports github.com only.' }
$source = (Get-Item -LiteralPath $SourcePath).FullName.TrimEnd('\','/')
if (-not (Test-Path -LiteralPath $source -PathType Container)) { throw 'Source directory missing.' }
if (((Get-Item -LiteralPath $source).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Source root must not be a link.' }
$files = @(Get-SourceFiles $source | Sort-Object FullName)
$required = @('README.md','LICENSE.md','CHANGELOG.md','CONTRIBUTING.md','SECURITY.md','AI_USAGE.md',
    'docs/INSTALLATION.md','docs/UNINSTALLATION.md','docs/DEVELOPMENT.md','.github/workflows/ci.yml','tests/sync-runtime.php','appinfo/info.xml')
foreach ($path in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $source $path) -PathType Leaf)) { throw "Full source export required; missing $path" }
}
$utf8 = New-Object System.Text.UTF8Encoding($false, $true)
if (-not $SourceRepository) {
    $projectLink = [regex]::Match([IO.File]::ReadAllText((Join-Path $source 'README.md'), $utf8),
        'https://github\.com/([A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*)/releases')
    if (-not $projectLink.Success) { throw 'Cannot determine project links; specify -SourceRepository OWNER/REPOSITORY.' }
    $SourceRepository = $projectLink.Groups[1].Value
}
$xmlSettings = New-Object System.Xml.XmlReaderSettings
$xmlSettings.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
$xmlSettings.XmlResolver = $null
$reader = [System.Xml.XmlReader]::Create((Join-Path $source 'appinfo/info.xml'), $xmlSettings)
try { $info = New-Object System.Xml.XmlDocument; $info.XmlResolver = $null; $info.Load($reader) } finally { $reader.Dispose() }
$version = [string]$info.info.version
if ($info.info.id -ne 'deckcalendarbridge' -or $version -cnotmatch '^[1-9][0-9]*\.[0-9]+\.[0-9]+$') { throw 'Wrong application or nonstable version.' }
$denied = @()
if ($DenyTermsFile) {
    $denyPath = (Get-Item -LiteralPath $DenyTermsFile).FullName
    if ($denyPath.StartsWith($source + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Keep private review terms outside the source.' }
    $denied = @([IO.File]::ReadAllLines($denyPath, $utf8) | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}
if (-not $WorkDirectory) { $WorkDirectory = Join-Path ([IO.Path]::GetTempPath()) ('dcb-public-' + [guid]::NewGuid().ToString('N')) }
$work = [IO.Path]::GetFullPath($WorkDirectory).TrimEnd('\','/')
if ($work -eq $source -or $work.StartsWith($source + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Work directory must be outside the source.' }
if (Test-Path -LiteralPath $work) { throw 'Choose a new work directory; existing output is never overwritten.' }
$stage = Join-Path $work 'repository'
[IO.Directory]::CreateDirectory($stage) | Out-Null
$patterns = @('-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----','\bgh[pousr]_[A-Za-z0-9_]{30,}\b',
    '\bgithub_pat_[A-Za-z0-9_]{40,}\b','\bAKIA[0-9A-Z]{16}\b')
$manifest = @()
foreach ($file in $files) {
    $relative = $file.FullName.Substring($source.Length + 1)
    $content = [IO.File]::ReadAllText($file.FullName, $utf8)
    if ($content.IndexOf([char]0) -ge 0) { throw "Binary source needs review: $relative" }
    foreach ($pattern in $patterns) { if ($content -match $pattern) { throw "Credential pattern needs review: $relative" } }
    foreach ($term in $denied) {
        if ($content.IndexOf($term, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or $relative.IndexOf($term, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
            throw "Private review term found in a source file. Matching values are not printed."
        }
    }
    $content = $content.Replace('https://github.com/' + $SourceRepository, 'https://github.com/' + $Repository)
    $target = Join-Path $stage $relative
    [IO.Directory]::CreateDirectory((Split-Path -Parent $target)) | Out-Null
    [IO.File]::WriteAllText($target, $content, $utf8)
    $manifest += [ordered]@{ path = $relative.Replace('\','/'); sha256 = (Get-Sha256 $target) }
}
$plan = [ordered]@{ repository = $Repository; version = $version; public = $true; files = $manifest }
[IO.File]::WriteAllText((Join-Path $work 'publication-plan.json'), ($plan | ConvertTo-Json -Depth 6), $utf8)
Write-Host "Version: $version; files: $($files.Count); new PUBLIC target: $Repository"
Write-Host "Review directory: $stage"
if (-not $Apply) { Write-Host 'Preview complete. No network calls, repository creation or publication occurred.'; return }

Get-Command git, gh -CommandType Application -ErrorAction Stop | Out-Null
Invoke-Native 'gh' @('auth','status','--hostname','github.com') | Out-Host
Push-Location $stage
try {
    Invoke-Native 'git' @('init','--quiet','-b','main') | Out-Host
    Invoke-Native 'git' @('-c','core.autocrlf=false','add','-A') | Out-Host
    Invoke-Native 'git' @('-c','user.name=Project contributors','-c','user.email=contributors@example.invalid','-c','commit.gpgsign=false','commit','--quiet','-m','Initial official public source release') | Out-Host
    $commit = ([string](Invoke-Native 'git' @('rev-parse','HEAD'))).Trim()
    if (([string](Invoke-Native 'git' @('rev-list','--count','HEAD'))).Trim() -ne '1') { throw 'Public source must have exactly one initial commit.' }
    Invoke-Native 'gh' @('repo','create',$Repository,'--public','--description','Link Deck cards to calendar events with bidirectional times and optional invitations') | Out-Host
    Invoke-Native 'git' @('remote','add','origin',('https://github.com/' + $Repository + '.git')) | Out-Host
    Invoke-Native 'git' @('-c','credential.helper=','-c','credential.helper=!gh auth git-credential','push','--set-upstream','origin','main') | Out-Host
    Invoke-Native 'gh' @('repo','edit',$Repository,'--default-branch','main') | Out-Host
    Write-Host "Published https://github.com/$Repository at $commit"
    if (-not $WaitForRelease) { Write-Host 'Check Actions and the generated release before archiving any earlier repository.'; return }
    $runs = @()
    for ($attempt = 0; $attempt -lt 30 -and $runs.Count -eq 0; $attempt++) {
        $runs = @(Invoke-GhJson @('run','list','--repo',$Repository,'--commit',$commit,'--branch','main','--event','push','--limit','5','--json','databaseId,headSha,workflowName') | Where-Object { $null -ne $_ -and $_.headSha -eq $commit -and $_.workflowName -eq 'Relations engine and native NC35 integration' })
        if ($runs.Count -eq 0) { Start-Sleep -Seconds 2 }
    }
    if ($runs.Count -eq 0) { throw 'No CI run found yet. Check Actions; the new repository has been retained.' }
    Invoke-Native 'gh' @('run','watch',[string]$runs[0].databaseId,'--repo',$Repository,'--exit-status') | Out-Host
    $tag = Invoke-GhJson @('api',('repos/' + $Repository + '/git/ref/tags/v' + $version))
    if ($tag.object.type -ne 'commit' -or $tag.object.sha -ne $commit) { throw 'Release tag does not point to the published source.' }
    $release = Invoke-GhJson @('api',('repos/' + $Repository + '/releases/tags/v' + $version))
    if ($release.draft -or $release.prerelease) { throw 'Expected a published stable release.' }
    $archive = 'deckcalendarbridge-nc35-' + $version + '.zip'
    foreach ($name in @($archive, ($archive + '.sha256'))) {
        if (@($release.assets | Where-Object { $_.name -eq $name }).Count -ne 1) { throw "Missing/ambiguous release asset: $name" }
    }
    $downloads = Join-Path $work 'release'
    [IO.Directory]::CreateDirectory($downloads) | Out-Null
    Invoke-Native 'gh' @('release','download',('v' + $version),'--repo',$Repository,'--dir',$downloads,'--pattern',$archive,'--pattern',($archive + '.sha256')) | Out-Host
    $checksum = [IO.File]::ReadAllText((Join-Path $downloads ($archive + '.sha256')), $utf8).Trim()
    if ($checksum -cnotmatch '^([0-9a-f]{64})  (.+)$' -or $Matches[2] -ne $archive) { throw 'Invalid checksum sidecar.' }
    $expectedHash = $Matches[1]
    if ((Get-Sha256 (Join-Path $downloads $archive)) -ne $expectedHash) { throw 'Release checksum mismatch.' }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead((Join-Path $downloads $archive))
    try {
        $entry = $zip.GetEntry('deckcalendarbridge/BUILD_COMMIT')
        if ($null -eq $entry) { throw 'Release BUILD_COMMIT missing.' }
        $stream = [IO.StreamReader]::new($entry.Open())
        try { $builtCommit = $stream.ReadToEnd().Trim() } finally { $stream.Dispose() }
        if ($builtCommit -ne $commit) { throw 'Release was built from a different commit.' }
    } finally { $zip.Dispose() }
    $receipt = [ordered]@{ repository = $Repository; version = $version; commit = $commit; ci_run = $runs[0].databaseId;
        release_url = $release.html_url; archive_sha256 = $expectedHash; verified_at_utc = [DateTime]::UtcNow.ToString('o') }
    [IO.File]::WriteAllText((Join-Path $work 'publication-receipt.json'), ($receipt | ConvertTo-Json), $utf8)
    Write-Host "Verified CI, tag, checksum and BUILD_COMMIT: $($release.html_url)"
    Write-Host "Receipt: $(Join-Path $work 'publication-receipt.json')"
    Write-Host 'An earlier private repository may now be archived separately. This script never archives or deletes one.'
} finally { Pop-Location }
