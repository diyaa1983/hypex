# Run Hypex Flutter on a tablet with Hot Reload (USB or Wireless ADB).
#
# USB:
#   powershell -ExecutionPolicy Bypass -File tool\run-dev.ps1
#
# Wireless:
#   powershell -ExecutionPolicy Bypass -File tool\run-dev.ps1 -Pair "192.168.1.139:37123" -PairCode 123456 -Connect "192.168.1.139:41234"
#   Use the IP and ports shown on the tablet (not these sample numbers).

param(
    [string]$Pair = "",
    [string]$PairCode = "",
    [string]$Connect = ""
)

$ErrorActionPreference = "Stop"

function Test-HostPort([string]$Value, [string]$Name) {
    if ($Value -notmatch '^\d{1,3}(\.\d{1,3}){3}:(\d{2,5})$') {
        Write-Error "$Name must look like 192.168.1.139:37123 (real numbers from the tablet, not xxxxx)."
    }
    $port = [int]$Matches[2]
    if ($port -lt 1 -or $port -gt 65535) {
        Write-Error "$Name port $port is invalid. TCP ports are 1-65535."
    }
}

$sdk = $env:ANDROID_HOME
if (-not $sdk) { $sdk = $env:ANDROID_SDK_ROOT }
if (-not $sdk) { $sdk = Join-Path $env:LOCALAPPDATA "Android\sdk" }

$adb = Join-Path $sdk "platform-tools\adb.exe"
if (-not (Test-Path $adb)) {
    Write-Error "adb not found at: $adb"
}

$env:ANDROID_HOME = $sdk
$env:ANDROID_SDK_ROOT = $sdk
$env:Path = "$(Join-Path $sdk 'platform-tools');$env:Path"

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Get-AndroidSerials {
    $lines = & $adb devices | Select-Object -Skip 1
    $serials = @()
    foreach ($line in $lines) {
        if ($line -match '^\s*$') { continue }
        if ($line -match '^(\S+)\s+device\s*$') { $serials += $Matches[1] }
    }
    return $serials
}

Write-Host "==> adb: $adb"
& $adb start-server | Out-Null

if ($Pair) { Test-HostPort $Pair "-Pair" }
if ($Connect) { Test-HostPort $Connect "-Connect" }
if ($PairCode -and $PairCode -notmatch '^\d{6}$') {
    Write-Error "-PairCode must be the 6-digit code shown on the tablet (not 123456 unless that is the real code)."
}

# Do not treat adb stderr as a terminating PowerShell error.
$ErrorActionPreference = "Continue"

if ($Pair) {
    Write-Host "==> pairing: $Pair"
    if ($PairCode) {
        & $adb pair $Pair $PairCode
    } else {
        Write-Host "Enter the pairing code from the tablet, then press Enter:"
        & $adb pair $Pair
    }
}

if ($Connect) {
    Write-Host "==> connect: $Connect"
    & $adb connect $Connect
}

$ErrorActionPreference = "Stop"

$serials = Get-AndroidSerials
if (-not $serials -or $serials.Count -eq 0) {
    Write-Host ""
    Write-Host "No tablet connected."
    Write-Host ""
    Write-Host "  USB: enable USB debugging, plug the cable, allow the prompt."
    Write-Host "  Wireless: same Wi-Fi, enable Wireless debugging, then rerun with -Pair -PairCode -Connect."
    Write-Host ""
    Write-Host "Waiting 60 seconds..."
    $deadline = (Get-Date).AddSeconds(60)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 3
        $serials = Get-AndroidSerials
        if ($serials -and $serials.Count -gt 0) { break }
        Write-Host "  ... still no device"
    }
}

$serials = Get-AndroidSerials
if (-not $serials -or $serials.Count -eq 0) {
    Write-Host ""
    & $adb devices -l
    Write-Error "Tablet not found. Check USB debugging or Wireless pair/connect values."
}

Write-Host "==> Android devices:"
& $adb devices -l
Write-Host ""
Write-Host "Hot Reload: save a file and the tablet updates."
Write-Host "  r = reload   R = restart   q = quit"
Write-Host ""

$device = $serials[0]
if ($serials.Count -gt 1) {
    Write-Host "Using first device: $device"
}

flutter run -d $device
