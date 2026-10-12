@echo off
REM السبب الشائع للفشل: مجلد C:\Users\...\ .gradle محظور الكتابة،
REM أو توزيعة Gradle تالفة/مقفلة داخل .gradle-home.
set "GRADLE_USER_HOME=%~dp0.gradle-home"
if not exist "%GRADLE_USER_HOME%" mkdir "%GRADLE_USER_HOME%"
cd /d "%~dp0"

REM أزل أقفال التوزيعة إن بقيت من بناء سابق.
del /q /s "%GRADLE_USER_HOME%\wrapper\dists\*.lck" >nul 2>&1

echo GRADLE_USER_HOME=%GRADLE_USER_HOME%
echo.
flutter build apk --release %*
if errorlevel 1 (
  echo.
  echo فشل البناء. جرّب بالترتيب:
  echo   1^) أغلق Android Studio وأي نافذة بناء أخرى
  echo   2^) powershell -ExecutionPolicy Bypass -File .\build-release.ps1
  echo   3^) إن استمر الخطأ: احذف المجلد .gradle-home ثم أعد البناء
  exit /b 1
)
echo.
echo تم البناء: build\app\outputs\flutter-apk\app-release.apk
exit /b 0
