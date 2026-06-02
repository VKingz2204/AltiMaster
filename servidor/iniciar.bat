@echo off
title AltiChecker Server - Dashboard
cd /d "%~dp0"

REM Enable ANSI escape sequences in Windows 10+
reg query "HKCU\Console" /v VirtualTerminalLevel 2>nul | find "0x1" >nul
if errorlevel 1 (
    reg add "HKCU\Console" /v VirtualTerminalLevel /t REG_DWORD /d 1 /f >nul 2>&1
)

echo ============================================
echo Starting AltiChecker Servers...
echo ============================================

echo [1/2] Starting Search Server (Alpha.php)...
start "AltiChecker-Search" "C:\xampp\php\php.exe" -d output_buffering=0 "%~dp0Alpha.php"

timeout /t 2 /nobreak >nul

echo [2/2] Starting Monitor Server (monitor.php)...
start "AltiChecker-Monitor" "C:\xampp\php\php.exe" -d output_buffering=0 "%~dp0monitor.php"

echo.
echo Both servers started successfully.
echo Close this window or use stop.bat to stop all servers.
echo.
pause
