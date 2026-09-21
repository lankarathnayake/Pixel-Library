<#
  Updates a Pixel Library folder that was installed from a ZIP (no git) to the latest version on GitHub.

    update.bat                          (double-click it; this script does the work)
    powershell -ExecutionPolicy Bypass -File tools\update.ps1 [-Yes]

  What it does: downloads the latest code (a ~1 MB ZIP from github.com/lankarathnayake/Pixel-Library over HTTPS), and replaces
  the app's own files. It NEVER touches your library or settings: the storage folder (database, thumbnails, photos,
  playlists), the php and ffmpeg folders, config.local.php and php.path. Files it replaces are first copied to
  update-backup\<date>\ so you can go back. Your database upgrades itself on the next start when a new version needs it.

  In a git clone it does nothing: use `git pull` there.

  -Yes            do not ask for confirmation
  -Source <zip>   a local ZIP (or another URL) instead of GitHub - for testing / offline use
#>
param(
	[switch]$Yes,
	[string]$Source = 'https://github.com/lankarathnayake/Pixel-Library/archive/refs/heads/main.zip'
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$api = 'https://api.github.com/repos/lankarathnayake/Pixel-Library/commits/main'
$root = Split-Path $PSScriptRoot -Parent

if (Test-Path -LiteralPath (Join-Path $root '.git')) {
	Write-Host 'This folder is a git clone - update it with:  git pull'
	exit 0
}

# Files and folders that belong to YOU and are never replaced.
function Test-Protected([string]$rel) {
	$p = $rel.Replace('\', '/').ToLower()
	return $p -eq 'config.local.php' -or $p -eq 'php.path' -or $p -like 'php/*' -or $p -like 'ffmpeg/*' -or $p -like 'update-backup/*' -or $p -like 'storage/*'
}
function Get-Version([string]$file) {
	if (Test-Path -LiteralPath $file) {
		$t = (Get-Content -LiteralPath $file -Raw).Trim()
		if ($t -match '^[0-9a-f]{40}$') { return $t }
	}
	return $null
}
function Short([string]$v) { if ($v) { return $v.Substring(0, 7) } else { return 'unknown' } }

$local = Get-Version (Join-Path $root 'VERSION')
$isUrl = $Source -match '^https?://'

# Ask GitHub what the latest version is first, so nothing is downloaded when you are already up to date.
$latest = $null
if ($isUrl -and $Source -like 'https://github.com/lankarathnayake/Pixel-Library/*') {
	try {
		$c = Invoke-RestMethod $api -Headers @{ 'User-Agent' = 'Pixel-Library-updater' }
		$latest = $c.sha
		$msg = ($c.commit.message -split "`n")[0]
	} catch { $msg = '' }
	if ($latest -and $local -eq $latest) {
		Write-Host "You are up to date (version $(Short $local))."
		exit 0
	}
	Write-Host "Installed: $(Short $local)    Latest: $(Short $latest)$(if ($msg) { '  -  ' + $msg })"
}

Write-Host "This replaces the app's files in:  $root"
Write-Host 'Your library (storage), php, ffmpeg and config.local.php are NOT touched. Replaced files are backed up first.'
if (-not $Yes) {
	$answer = [string](Read-Host 'Update now? (Y/N)')   # [string]: an empty / missing answer is $null, and $null -notmatch is NOT true
	if (-not ($answer -match '^[yY]')) { Write-Host 'Nothing changed.'; exit 0 }
}

$work = Join-Path ([IO.Path]::GetTempPath()) ('pixel-library-update-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force $work | Out-Null
try {
	$zip = Join-Path $work 'new.zip'
	if ($isUrl) {
		Write-Host 'Downloading ...'
		Invoke-WebRequest $Source -OutFile $zip -Headers @{ 'User-Agent' = 'Pixel-Library-updater' }
	} else {
		Copy-Item -LiteralPath $Source -Destination $zip
	}
	Expand-Archive -LiteralPath $zip -DestinationPath (Join-Path $work 'x') -Force
	$src = Get-ChildItem -LiteralPath (Join-Path $work 'x') -Directory | Select-Object -First 1
	if (-not $src -or -not (Test-Path (Join-Path $src.FullName 'start.bat')) -or -not (Test-Path (Join-Path $src.FullName 'router.php'))) {
		throw 'That download does not look like Pixel Library - nothing was changed.'
	}
	$new = Get-Version (Join-Path $src.FullName 'VERSION')
	if ($new -and $local -eq $new) {
		Write-Host "You are up to date (version $(Short $local))."
		exit 0
	}

	$backup = Join-Path $root ('update-backup\' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
	$changed = 0; $added = 0; $same = 0
	$base = $src.FullName.TrimEnd('\') + '\'
	foreach ($f in Get-ChildItem -LiteralPath $src.FullName -Recurse -File -Force) {
		$rel = $f.FullName.Substring($base.Length)
		if (Test-Protected $rel) {
			# The ONLY thing ever created inside storage is the empty .gitkeep marker of a missing folder - never any other file.
			if ($f.Name -eq '.gitkeep' -and $rel.Replace('\', '/') -like 'storage/*' -and -not (Test-Path -LiteralPath (Join-Path $root $rel))) {
				New-Item -ItemType Directory -Force (Split-Path (Join-Path $root $rel)) | Out-Null
				Copy-Item -LiteralPath $f.FullName -Destination (Join-Path $root $rel)
			}
			continue
		}
		$dest = Join-Path $root $rel
		if (Test-Path -LiteralPath $dest) {
			if ((Get-FileHash -LiteralPath $dest -Algorithm SHA256).Hash -eq (Get-FileHash -LiteralPath $f.FullName -Algorithm SHA256).Hash) { $same++; continue }
			$bk = Join-Path $backup $rel
			New-Item -ItemType Directory -Force (Split-Path $bk) | Out-Null
			Copy-Item -LiteralPath $dest -Destination $bk
			$changed++
		} else {
			New-Item -ItemType Directory -Force (Split-Path $dest) | Out-Null
			$added++
		}
		Copy-Item -LiteralPath $f.FullName -Destination $dest -Force
	}
	Write-Host ''
	Write-Host "Updated to version $(Short $new): $changed file(s) replaced, $added new, $same already identical."
	if ($changed -gt 0) { Write-Host "The replaced files were copied to: $backup" }
	Write-Host 'Start the app as usual (start.bat). If a newer database layout is needed it upgrades itself.'
} finally {
	Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
