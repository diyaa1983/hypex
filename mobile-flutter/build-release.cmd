@echo off
REM السبب الشائع للفشل: مجلد C:\Users\...\ .gradle محظور الكتابة.
REM نوجّه Gradle إلى مجلد محلي داخل المشروع.
set "GRADLE_USER_HOME=%~dp0.gradle-home"
if not exist "%GRADLE_USER_HOME%" mkdir "%GRADLE_USER_HOME%"
cd /d "%~dp0"
echo GRADLE_USER_HOME=%GRADLE_USER_HOME%
echo.
flutter build apk --release %*
if errorlevel 1 (
  echo.
  echo فشل البناء. إن ظهر خطأ ملفات مقفلة: أغلق Android Studio / أي بناء سابق ثم أعد المحاولة.
  exit /b 1
)
echo.
echo تم البناء: build\app\outputs\flutter-apk\app-release.apk
exit /b 0
