# تشغيل التطبيق على التابلت مع مجلد Gradle بديل (يتجاوز .gradle المقفول في الملف الشخصي)
$ErrorActionPreference = 'Stop'
$env:GRADLE_USER_HOME = 'C:\xampp\gradle-home'
$env:ANDROID_SDK_ROOT = 'C:\xampp\android-sdk'
$env:ANDROID_HOME = 'C:\xampp\android-sdk'
New-Item -ItemType Directory -Force -Path $env:GRADLE_USER_HOME | Out-Null

Set-Location (Split-Path $PSScriptRoot -Parent)

Write-Host 'Devices:' -ForegroundColor Cyan
& "C:\xampp\android-sdk\platform-tools\adb.exe" devices
flutter devices
flutter run
