@echo off
rem Pixel Library - starts the app with PHP's built-in web server. No XAMPP, Apache or MySQL needed.
rem Close this window (or press Ctrl+C) to stop it.
rem   Port:   set PIXEL_LIBRARY_PORT=9000 before running (default 8686)
rem   Browser: it opens by itself; set PIXEL_LIBRARY_NO_BROWSER=1 to stop that
rem   PHP:    the path written into php.path by tools\get-php.ps1, otherwise php.exe on the PATH
setlocal
cd /d "%~dp0"
title Pixel Library

set "PHP="
if exist "php.path" set /p PHP=<"php.path"
if not defined PHP for %%P in (php.exe) do set "PHP=%%~$PATH:P"
if not defined PHP goto nophp
if not exist "%PHP%" goto nophp
for %%D in ("%PHP%") do set "PHPDIR=%%~dpD"

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

:nophp
echo PHP was not found.
echo Run:  powershell -ExecutionPolicy Bypass -File tools\get-php.ps1 -Dir C:\Tools\php
echo (downloads a portable PHP and writes php.path), or put php.exe on your PATH.
pause
exit /b 1
