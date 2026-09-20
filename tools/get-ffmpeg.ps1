<#
  Downloads ffmpeg + ffprobe (needed for video previews) into the "ffmpeg" folder NEXT TO THE APP. The app finds them there
  by itself - no configuration needed.

    powershell -ExecutionPolicy Bypass -File tools\get-ffmpeg.ps1

  Source: the "release essentials" build from gyan.dev (the Windows builds linked from ffmpeg.org), about 115 MB, checked
  against the SHA-256 the site publishes. Only ffmpeg.exe, ffprobe.exe and the licence files are kept (about 200 MB on disk).
  The ffmpeg folder is git-ignored. Nothing is installed system-wide; delete the folder to uninstall.
  ffmpeg is licensed separately (GPL v3 for this build) - see ffmpeg\LICENSE after the download.

  -Dir <folder>   put ffmpeg somewhere else instead (then set FFMPEG_PATH / FFPROBE_PATH in config.local.php)
#>
param(
	[string]$Dir,
	[switch]$Quiet   # used by start.bat: skip the closing hint
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Windows PowerShell 5.1 downloads many times slower with its progress bar on
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path $PSScriptRoot -Parent
$inApp = -not $Dir
if ($inApp) { $Dir = Join-Path $root 'ffmpeg' }
$Dir = [IO.Path]::GetFullPath($Dir)
$bin = Join-Path $Dir 'bin'

function Get-Text([string]$url) {
	$c = (Invoke-WebRequest $url -UseBasicParsing).Content
	if ($c -is [byte[]]) { $c = [Text.Encoding]::ASCII.GetString($c) }
	return $c.ToString().Trim()
}

if ((Test-Path "$bin\ffmpeg.exe") -and (Test-Path "$bin\ffprobe.exe")) {
	Write-Host "ffmpeg is already in $Dir - nothing to download."
} else {
	$base = 'https://www.gyan.dev/ffmpeg/builds'
	$ver = Get-Text "$base/release-version"
	$name = "ffmpeg-$ver-essentials_build.zip"
	$sha = ((Get-Text "$base/packages/$name.sha256") -split '\s+')[0].ToLower()
	if ($sha -notmatch '^[0-9a-f]{64}$') { throw "Could not read the published checksum for $name" }

	$tmp = Join-Path ([IO.Path]::GetTempPath()) $name
	Write-Host "Downloading $name (about 115 MB - a few minutes on a slow connection) ..."
	Invoke-WebRequest "$base/packages/$name" -OutFile $tmp
	$got = (Get-FileHash $tmp -Algorithm SHA256).Hash.ToLower()
	if ($got -ne $sha) { Remove-Item $tmp; throw "Checksum mismatch (expected $sha, got $got) - download discarded." }
	Write-Host "Checksum OK ($got)"

	New-Item -ItemType Directory -Force $bin | Out-Null
	$zip = [IO.Compression.ZipFile]::OpenRead($tmp)
	try {
		foreach ($e in $zip.Entries) {
			$dest = $null
			if ($e.FullName -match '/bin/(ffmpeg|ffprobe)\.exe$') { $dest = Join-Path $bin $e.Name }
			elseif ($e.FullName -match '^[^/]+/(LICENSE|README\.txt)$') { $dest = Join-Path $Dir $e.Name }
			if ($dest) { [IO.Compression.ZipFileExtensions]::ExtractToFile($e, $dest, $true) }
		}
	} finally { $zip.Dispose() }
	Remove-Item $tmp
}

foreach ($exe in 'ffmpeg.exe', 'ffprobe.exe') {
	if (-not (Test-Path "$bin\$exe")) { throw "$exe is missing after unpacking." }
}
& "$bin\ffmpeg.exe" -version | Select-Object -First 1
if ($LASTEXITCODE -ne 0) { throw "ffmpeg was unpacked but does not start." }
if ($inApp) {
	if (-not $Quiet) { Write-Host "ffmpeg is in $Dir - the app finds it by itself (reload the page)." }
} else {
	Write-Host "Now set these in config.local.php:"
	Write-Host "  define('FFMPEG_PATH', '$($bin.Replace('\', '\\'))\\ffmpeg.exe');"
	Write-Host "  define('FFPROBE_PATH', '$($bin.Replace('\', '\\'))\\ffprobe.exe');"
}
