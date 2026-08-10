@echo off
REM استيراد مواد الجملة من Excel على السيرفر (يجب تشغيله على جهاز السيرفر)
setlocal
cd /d "%~dp0..\hypex-node"

set "XLSX=%~1"
if "%XLSX%"=="" set "XLSX=%~dp0..\uploads\Retail_Whol_Price_2.xlsx"
if not exist "%XLSX%" set "XLSX=C:\xampp\htdocs\Hypex\uploads\Retail_Whol_Price_2.xlsx"
if not exist "%XLSX%" set "XLSX=C:\xampp\htdocs\hypex\uploads\Retail_Whol_Price_2.xlsx"
if not exist "%XLSX%" set "XLSX=C:\xampp\htdocs\Hypex\uploads\Retail & Whol Price (2).xlsx"
if not exist "%XLSX%" set "XLSX=C:\xampp\htdocs\hypex\uploads\Retail & Whol Price (2).xlsx"

if not exist "%XLSX%" (
  echo [ERROR] Excel not found. Place it under uploads or pass full path.
  echo Example: deploy\import-wholesale-items.cmd "C:\xampp\htdocs\hypex\uploads\Retail_Whol_Price_2.xlsx"
  exit /b 1
)

where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Node.js not in PATH
  exit /b 1
)

echo Importing from: %XLSX%
echo Using DB from hypex-node\.env
node cli\import_wholesale_items.js "%XLSX%"
set ERR=%ERRORLEVEL%
if not "%ERR%"=="0" (
  echo [ERROR] Import failed code %ERR%
  exit /b %ERR%
)
echo.
echo Done. Check inventory items list on this server.
endlocal
