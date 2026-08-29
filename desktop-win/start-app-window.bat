@echo off
REM فتح النظام كنافذة تطبيق بدون شريط عنوان المتصفح (Not secure / الرابط)
setlocal
cd /d "%~dp0"

set "APP_URL=http://176.29.176.192:3000/"
if exist "config.json" (
  for /f "usebackq tokens=2 delims=:," %%A in (`findstr /i "appUrl" "config.json"`) do (
    set "RAW=%%~A"
  )
)
if defined RAW (
  set "APP_URL=%RAW:"=%"
  set "APP_URL=%APP_URL: =%"
)

REM تفضيل Edge ثم Chrome — وضع --app يخفي شريط العنوان بالكامل
set "EDGE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" set "EDGE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
set "CHROME2=%LocalAppData%\Google\Chrome\Application\chrome.exe"

if exist "%EDGE%" (
  start "" "%EDGE%" --app="%APP_URL%" --start-maximized --disable-features=TranslateUI
  exit /b 0
)
if exist "%CHROME%" (
  start "" "%CHROME%" --app="%APP_URL%" --start-maximized --disable-features=TranslateUI
  exit /b 0
)
if exist "%CHROME2%" (
  start "" "%CHROME2%" --app="%APP_URL%" --start-maximized --disable-features=TranslateUI
  exit /b 0
)

echo لم يُعثر على Edge أو Chrome.
echo افتح يدوياً: %APP_URL%
pause
endlocal
