# بناء APK — يتجنب مجلد Gradle المحظور ويصلح التوزيعة التالفة.
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
Set-Location $root

$gradleHome = Join-Path $root ".gradle-home"
if (-not (Test-Path $gradleHome)) {
    New-Item -ItemType Directory -Path $gradleHome | Out-Null
}
$env:GRADLE_USER_HOME = $gradleHome

# أزل أقفال التوزيعة إن بقيت من بناء سابق.
Get-ChildItem -Path (Join-Path $gradleHome "wrapper\dists") -Recurse -Filter "*.lck" -ErrorAction SilentlyContinue |
    Remove-Item -Force -ErrorAction SilentlyContinue

# إن وُجد zip لكن bin\gradle.bat ناقص — أعد الاستخراج.
$distRoot = Join-Path $gradleHome "wrapper\dists"
if (Test-Path $distRoot) {
    Get-ChildItem $distRoot -Directory -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^gradle-\d' -and (Test-Path (Join-Path $_.Parent.FullName ($_.Name + "-all.zip"))) } |
        ForEach-Object {
            $bat = Join-Path $_.FullName "bin\gradle.bat"
            if (-not (Test-Path $bat)) {
                $zip = Join-Path $_.Parent.FullName ($_.Name + "-all.zip")
                if (-not (Test-Path $zip)) {
                    $zip = Get-ChildItem $_.Parent.FullName -Filter "gradle-*-all.zip" | Select-Object -First 1 -ExpandProperty FullName
                }
                if ($zip -and (Test-Path $zip)) {
                    Write-Host "إصلاح توزيعة Gradle التالفة: $($_.Name)"
                    $parent = $_.Parent.FullName
                    Remove-Item $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
                    Add-Type -AssemblyName System.IO.Compression.FileSystem
                    [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $parent)
                    $ok = Join-Path $parent ((Split-Path $zip -Leaf) + ".ok")
                    if (-not (Test-Path $ok)) { New-Item -ItemType File -Path $ok -Force | Out-Null }
                }
            }
        }
}

Write-Host "GRADLE_USER_HOME=$env:GRADLE_USER_HOME"
Write-Host ""

& flutter build apk --release @args
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "فشل البناء. أغلق Android Studio ثم أعد:"
    Write-Host "  powershell -ExecutionPolicy Bypass -File .\build-release.ps1"
    exit $LASTEXITCODE
}

$apk = Join-Path $root "build\app\outputs\flutter-apk\app-release.apk"
Write-Host ""
Write-Host "تم البناء: $apk"
exit 0
