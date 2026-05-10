@echo off
echo ============================================
echo AltChecks Server - Detener Servidor
echo ============================================
echo.

cd /d "%~dp0"

echo Apagando servidor...
"C:\xampp\php\php.exe" stop.php

echo.
echo ✓ Servidor detenido
echo.
pause