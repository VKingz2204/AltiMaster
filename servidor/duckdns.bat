@echo off
echo ============================================
echo AltChecks - Actualizar DuckDNS
echo ============================================
echo.

cd /d "%~dp0"

echo Actualizando DuckDNS...
powershell -NoProfile -Command "try { Invoke-WebRequest -Uri 'https://www.duckdns.org/update?domains=altimaster.duckdns.org&token=c3763a1c-bc5e-422e-9556-6b75352c6220&ip=' -UseBasicParsing -ErrorAction Stop | Out-Null; Write-Host ' DuckDNS actualizado correctamente' } catch { Write-Host ' Error:' $_ }"

echo.
pause
