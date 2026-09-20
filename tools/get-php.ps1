<#
  Downloads a portable PHP for Windows (official build from windows.php.net, checked against the published SHA-256)
  into the "php" folder NEXT TO THE APP, so the whole app lives in one folder. start.bat finds it there by itself.

    powershell -ExecutionPolicy Bypass -File tools\get-php.ps1

  The php folder is git-ignored (the program files do not belong in the repository). Nothing is installed
  system-wide: no registry entries, no PATH change. Delete the folder to uninstall. Requires the
  "Visual C++ 2015-2022 Redistributable" (x64) that most PCs already have.

  -Dir <folder>   put PHP somewhere else instead (then this script also writes php.path so start.bat can find it)
  -Series 8.4     another PHP series (default 8.3)
#>
param(
	[string]$Dir,
	[string]$Series = '8.3',   # any series the site still lists: 8.1, 8.2, 8.3, 8.4 ...
	[switch]$Quiet          # used by start.bat: skip the closing hint
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Windows PowerShell 5.1 downloads many times slower with its progress bar on
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root = Split-Path $PSScriptRoot -Parent
$inApp = -not $Dir
if ($inApp) { $Dir = Join-Path $root 'php' }

$base = 'https://windows.php.net/downloads/releases'
$rel = (Invoke-RestMethod "$base/releases.json").$Series
if (-not $rel) { throw "PHP $Series is not listed on windows.php.net" }
$build = $rel.PSObject.Properties | Where-Object { $_.Name -match '^nts-.*-x64$' } | Select-Object -First 1
if (-not $build) { throw "No non-thread-safe x64 build of PHP $Series found" }
$zipName = $build.Value.zip.path
$sha = $build.Value.zip.sha256

$Dir = [IO.Path]::GetFullPath($Dir)
if (Test-Path "$Dir\php.exe") {
	Write-Host "PHP is already in $Dir - nothing to download."
} else {
	$tmp = Join-Path ([IO.Path]::GetTempPath()) $zipName
	Write-Host "Downloading $zipName ..."
	Invoke-WebRequest "$base/$zipName" -OutFile $tmp
	$got = (Get-FileHash $tmp -Algorithm SHA256).Hash.ToLower()
	if ($got -ne $sha.ToLower()) { Remove-Item $tmp; throw "Checksum mismatch (expected $sha, got $got) - download discarded." }
	Write-Host "Checksum OK ($got)"
	New-Item -ItemType Directory -Force $Dir | Out-Null
	Expand-Archive $tmp -DestinationPath $Dir -Force
	Remove-Item $tmp
}

$php = Join-Path $Dir 'php.exe'
& $php -v
if ($LASTEXITCODE -ne 0) {
	throw "PHP was unpacked but does not start. Most likely the Microsoft Visual C++ Redistributable (x64) is missing: install it from https://aka.ms/vs/17/release/vc_redist.x64.exe and start again."
}
if ($inApp) {
	if (-not $Quiet) { Write-Host "PHP is in $Dir - just double-click start.bat." }
} else {
	Set-Content -Path (Join-Path $root 'php.path') -Value $php -Encoding ascii
	Write-Host "start.bat will use $php (written to php.path)."
}
