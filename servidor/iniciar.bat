@echo off
echo ============================================
echo AltChecks Server - Iniciador
echo ============================================
echo.

cd /d "%~dp0"

echo Iniciando servidor...
"C:\xampp\php\php.exe" servidor.php

if errorlevel 1 (
    echo.
    echo Error al iniciar el servidor.
    echo Presiona cualquier tecla para salir...
    pause > nul
)