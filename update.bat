@echo off
rem Pixel Library - updates a folder installed from a ZIP to the latest version on GitHub. Your library, php, ffmpeg and
rem config.local.php are never touched. (In a git clone use "git pull" instead.) The work is done by tools\update.ps1 - plain text, open it to see.
rem Everything after the powershell call is on ONE line on purpose: this file can be replaced by the update while it runs.
setlocal
cd /d "%~dp0"
title Pixel Library - update
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\update.ps1" %* & echo. & pause & exit /b
