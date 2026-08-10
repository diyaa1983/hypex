@echo off
REM استيراد مواد الجملة من Excel — يشغَّل على السيرفر (أو المحلي)
REM الاستخدام:
REM   deploy\import-wholesale-items.cmd
REM   deploy\import-wholesale-items.cmd "C:\path\to\file.xlsx"
setlocal EnableExtensions
cd /d "%~dp0.."

set "ROOT=%CD%"
set "NODE_DIR=%ROOT%\hypex-node"
set "CLI_JS=%NODE_DIR%\cli\import_wholesale_items.js"
set "XLSX=%~1"

if not exist "%NODE_DIR%" (
  echo [ERROR] Folder not found: %NODE_DIR%
  exit /b 1
)
if not exist "%CLI_JS%" (
  echo [ERROR] Missing import script:
  echo   %CLI_JS%
  echo.
  echo Copy from the development PC to the server:
  echo   hypex-node\cli\import_wholesale_items.js
  echo   hypex-node\cli\inv_items_xlsx_to_json.php
  echo   includes\xlsx_simple_reader.php
  exit /b 1
)

REM Prefer a path WITHOUT spaces or ^& so cmd never splits on ^&
if not defined XLSX if exist "%ROOT%\uploads\Retail_Whol_Price_2.xlsx" set "XLSX=%ROOT%\uploads\Retail_Whol_Price_2.xlsx"

REM If Excel has spaces/ampersand, copy to a safe name first
if not defined XLSX (
  if exist "%ROOT%\uploads\Retail & Whol Price (2).xlsx" (
    echo Copying Excel to safe filename...
    copy /Y "%ROOT%\uploads\Retail & Whol Price (2).xlsx" "%ROOT%\uploads\Retail_Whol_Price_2.xlsx" >nul
    set "XLSX=%ROOT%\uploads\Retail_Whol_Price_2.xlsx"
  )
)

if not defined XLSX if exist "%ROOT%\uploads\Retail & Whol Price.xlsx" (
  copy /Y "%ROOT%\uploads\Retail & Whol Price.xlsx" "%ROOT%\uploads\Retail_Whol_Price_2.xlsx" >nul
  set "XLSX=%ROOT%\uploads\Retail_Whol_Price_2.xlsx"
)

if not defined XLSX (
  echo [ERROR] Excel file not found under uploads.
  echo Put one of these files in:
  echo   %ROOT%\uploads\
  echo     Retail_Whol_Price_2.xlsx   ^(recommended, no spaces^)
  echo     Retail ^& Whol Price ^(2^).xlsx
  echo.
  echo Or pass the full path between quotes:
  echo   deploy\import-wholesale-items.cmd "C:\path\file.xlsx"
  exit /b 1
)

if not exist "%XLSX%" (
  echo [ERROR] File not readable:
  echo   %XLSX%
  exit /b 1
)

where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Node.js not in PATH. Install Node 18+ and reopen CMD.
  exit /b 1
)

echo.
echo Root:    %ROOT%
echo Script:  %CLI_JS%
echo Excel:   %XLSX%
echo DB:      hypex-node\.env
echo.

cd /d "%NODE_DIR%"
node "cli\import_wholesale_items.js" "%XLSX%"
set "ERR=%ERRORLEVEL%"
if not "%ERR%"=="0" (
  echo.
  echo [ERROR] Import failed code %ERR%
  exit /b %ERR%
)
echo.
echo Done. Open inventory items list on this server.
endlocal
exit /b 0
