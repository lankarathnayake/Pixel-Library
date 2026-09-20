@echo off
rem Pixel Library - starts the app with PHP's built-in web server. No XAMPP, Apache or MySQL needed.
rem First start: if there is no PHP yet, a portable one is downloaded into the "php" folder next to this file.
rem Video previews need ffmpeg: if it is not found, you are offered a download into the "ffmpeg" folder next to this file.
rem Close this window (or press Ctrl+C) to stop the app.
rem   Port:    set PIXEL_LIBRARY_PORT=9000 before running (default 8686)
rem   Browser: it opens by itself; set PIXEL_LIBRARY_NO_BROWSER=1 to stop that
rem   ffmpeg:  set PIXEL_LIBRARY_FFMPEG=yes (download without asking) or no (never offer it); default: ask
rem   PHP:     the "php" folder next to this file, else the path in php.path, else php.exe on the PATH
setlocal
cd /d "%~dp0"
title Pixel Library

set "PHP="
if exist "php\php.exe" set "PHP=%~dp0php\php.exe"
if not defined PHP if exist "php.path" set /p PHP=<"php.path"
if not defined PHP for %%P in (php.exe) do set "PHP=%%~$PATH:P"
if defined PHP if not exist "%PHP%" set "PHP="
if not defined PHP call :getphp
if not defined PHP goto nophp
for %%D in ("%PHP%") do set "PHPDIR=%%~dpD"

rem ffmpeg (optional): ask the app itself whether it can find ffmpeg (config.local.php, the ffmpeg folder, the PATH)
if /i "%PIXEL_LIBRARY_FFMPEG%"=="no" goto ffdone
if exist "ffmpeg\declined.txt" goto ffdone
"%PHP%" -c "%~dp0php.standalone.ini" -d "extension_dir=%PHPDIR%ext" "%~dp0bin\ffmpeg-check.php" >nul 2>&1
if not errorlevel 1 goto ffdone
call :getffmpeg
:ffdone

if not defined PIXEL_LIBRARY_PORT set "PIXEL_LIBRARY_PORT=8686"
set "URL=http://127.0.0.1:%PIXEL_LIBRARY_PORT%/"

if not exist storage mkdir storage
echo Pixel Library: %URL%
echo PHP: %PHP%
echo (leave this window open while you use the app; close it to stop)
echo.
if not defined PIXEL_LIBRARY_NO_BROWSER start "" /b powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; Start-Process '%URL%'"

"%PHP%" -c "%~dp0php.standalone.ini" -d "extension_dir=%PHPDIR%ext" -d "error_log=%~dp0storage\php-errors.log" -S 127.0.0.1:%PIXEL_LIBRARY_PORT% -t "%~dp0." "%~dp0router.php"
echo.
echo The server stopped. If it never started, port %PIXEL_LIBRARY_PORT% may be in use (set PIXEL_LIBRARY_PORT to another number).
pause
exit /b 1

:getphp
echo First start: Pixel Library needs PHP. Downloading a portable copy from windows.php.net
echo (about 30 MB, checked against its published checksum) into the "php" folder next to this file.
echo Nothing is installed on your PC. This happens once and needs internet.
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\get-php.ps1" -Quiet
if exist "php\php.exe" set "PHP=%~dp0php\php.exe"
echo.
exit /b

:getffmpeg
echo Video previews need ffmpeg, a separate program that is not included here.
echo It can be downloaded now: about 115 MB from gyan.dev (checked against its published checksum),
echo into the "ffmpeg" folder next to this file. Nothing is installed on your PC.
echo.
if /i "%PIXEL_LIBRARY_FFMPEG%"=="yes" goto ffdownload
choice /C YN /T 20 /D Y /M "Download it now? Y = yes, N = no and don't ask again (Y in 20 seconds)"
if errorlevel 2 goto ffdecline
:ffdownload
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\get-ffmpeg.ps1" -Quiet
echo.
exit /b
:ffdecline
if not exist ffmpeg mkdir ffmpeg
echo declined> "ffmpeg\declined.txt"
echo OK - no video previews for now. To get ffmpeg later, run tools\get-ffmpeg.ps1 (or delete ffmpeg\declined.txt to be asked again).
echo.
exit /b

:nophp
echo PHP could not be set up automatically.
echo Manual way: download "PHP 8.3 - VS16 x64 - Non Thread Safe (zip)" from https://windows.php.net/download/
echo and unzip it into a folder called "php" next to this file. Then start this again.
pause
exit /b 1
