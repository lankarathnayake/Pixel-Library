<#
  Downloads a portable PHP for Windows (official build from windows.php.net, checked against the published SHA-256),
  unpacks it into a folder of your choice and tells start.bat where it is (writes php.path).

    powershell -ExecutionPolicy Bypass -File tools\get-php.ps1 -Dir H:\Tools\php

  Keep the folder OUTSIDE this project (the program files do not belong in the repository). Nothing is installed
  system-wide: no registry entries, no PATH change. Requires the "Visual C++ 2015-2022 Redistributable" (x64) that
  most PCs already have.
#>
param(
	[Parameter(Mandatory = $true)][string]$Dir,
	[string]$Series = '8.3'   # any series the site still lists: 8.1, 8.2, 8.3, 8.4 ...
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

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
$root = Split-Path $PSScriptRoot -Parent
Set-Content -Path (Join-Path $root 'php.path') -Value $php -Encoding ascii
Write-Host "start.bat will use $php (written to php.path)."
