@echo off
title AltChecks Server - Dashboard
cd /d "%~dp0"

REM Enable ANSI escape sequences in Windows 10+
reg query "HKCU\Console" /v VirtualTerminalLevel 2>nul | find "0x1" >nul
if errorlevel 1 (
    reg add "HKCU\Console" /v VirtualTerminalLevel /t REG_DWORD /d 1 /f >nul 2>&1
)

"C:\xampp\php\php.exe" -d output_buffering=0 servidor.php

if errorlevel 1 (
    echo.
    echo Error al iniciar el servidor.
    echo Presiona cualquier tecla para salir...
    pause > nul
)
