@echo off
chcp 65001 >nul
setlocal EnableExtensions
title Hypex external access check

echo.
echo ============================================
echo   Hypex - external access diagnosis
echo ============================================
echo.

echo [1] Local services
curl -s -o nul -w "  Apache /hypex     HTTP %%{http_code}\n" -L --max-time 8 http://127.0.0.1/hypex/
curl -s -o nul -w "  Node /health      HTTP %%{http_code}\n" --max-time 5 http://127.0.0.1:3000/health
echo   Node health body:
curl -s --max-time 5 http://127.0.0.1:3000/health
echo.
echo.

echo [2] LAN addresses - point Port Forward to the main PC IP
powershell -NoProfile -Command ^
  "Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { Write-Host ('  ' + $_.InterfaceAlias + ' = ' + $_.IPAddress) }"
echo.

echo [3] Listening ports
netstat -ano | findstr "LISTENING" | findstr ":80 "
netstat -ano | findstr "LISTENING" | findstr ":3000 "
echo.

echo [4] Current public IP
set "PUB="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "try { (Invoke-RestMethod 'https://api.ipify.org?format=json' -TimeoutSec 12).ip } catch { Write-Output '' }"`) do set "PUB=%%i"

if "%PUB%"=="" (
  echo   Could not fetch public IP.
) else (
  echo   PUBLIC_IP=%PUB%
  echo.
  echo   Test from mobile DATA only [not home WiFi]:
  echo     http://%PUB%/hypex
  echo     http://%PUB%/hypex/login
)
echo.

echo [5] What to configure on the router
echo   Port Mapping TCP external 80 -^> internal PC IP port 80
echo   Recommended PC IP on LAN: Ethernet if available e.g. 192.168.1.159
echo   Do NOT forward 3000 or 3306 to the internet.
echo   Old IP 176.29.176.192 may be outdated - always use PUBLIC_IP above.
echo.
echo [6] Firewall helper [Run as Administrator]:
echo     deploy\open-external-firewall.cmd
echo.
echo Full guide: deploy\EXTERNAL-ACCESS.txt
echo ============================================
pause
endlocal
