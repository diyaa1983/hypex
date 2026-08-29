$ErrorActionPreference = 'Stop'

$appDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$desktop = [Environment]::GetFolderPath('Desktop')
$shortcutPath = Join-Path $desktop 'Hypex.lnk'

$configPath = Join-Path $appDir 'config.json'
$appUrl = 'http://176.29.176.192:3000/'
if (Test-Path $configPath) {
    try {
        $cfg = Get-Content $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($cfg.appUrl) { $appUrl = [string]$cfg.appUrl }
    } catch {}
}

$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edge)) { $edge = "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe" }
$chrome = "$env:ProgramFiles\Google\Chrome\Application\chrome.exe"
if (-not (Test-Path $chrome)) { $chrome = "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe" }
if (-not (Test-Path $chrome)) { $chrome = "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe" }

$target = $null
$arguments = $null
$workDir = $appDir
$icon = "$env:SystemRoot\System32\shell32.dll,14"

if (Test-Path $edge) {
    $target = $edge
    $arguments = "--app=$appUrl --start-maximized"
    $icon = "$edge,0"
} elseif (Test-Path $chrome) {
    $target = $chrome
    $arguments = "--app=$appUrl --start-maximized"
    $icon = "$chrome,0"
} else {
    $target = Join-Path $appDir 'start-app-window.bat'
    $arguments = ''
}

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $target
if ($arguments) { $shortcut.Arguments = $arguments }
$shortcut.WorkingDirectory = $workDir
$shortcut.WindowStyle = 1
$shortcut.Description = 'Hypex app window (no browser toolbar)'
$shortcut.IconLocation = $icon
$shortcut.Save()

Write-Host "OK: Desktop shortcut created:"
Write-Host $shortcutPath
Write-Host "URL: $appUrl"
Write-Host "Open Hypex.lnk from Desktop - do not use normal browser."
