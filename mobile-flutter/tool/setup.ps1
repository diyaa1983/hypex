# Setup Flutter project on Windows (for Android builds).
# Requires Flutter SDK on PATH: https://docs.flutter.dev/get-started/install/windows
# Run:  powershell -ExecutionPolicy Bypass -File tool\setup.ps1

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
    Write-Error "Flutter not found on PATH. Install Flutter SDK first: https://docs.flutter.dev/get-started/install/windows"
}

Write-Host "==> Creating platform folders (existing AndroidManifest.xml will be kept)"
flutter create . --org com.gppjo.biodev --project-name namma_mobile --platforms=android,ios

Write-Host "==> Fetching packages"
flutter pub get

Write-Host ""
Write-Host "Setup done. Useful commands:"
Write-Host "  flutter run"
Write-Host "  flutter build apk --release"
Write-Host ""
Write-Host "Android permissions are already in android/app/src/main/AndroidManifest.xml"
Write-Host "To change applicationId, edit android/app/build.gradle"
