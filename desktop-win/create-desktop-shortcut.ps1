$ErrorActionPreference = 'Stop'

$appDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$desktop = [Environment]::GetFolderPath('Desktop')
$startBat = Join-Path $appDir 'start.bat'
$electronExe = Join-Path $appDir 'node_modules\electron\dist\electron.exe'
$shortcutPath = Join-Path $desktop 'Local.lnk'

$iconLocation = "$electronExe,0"
$pwaIconDir = Join-Path $env:LOCALAPPDATA 'Microsoft\Edge\User Data\Default\Web Applications'
if (Test-Path $pwaIconDir) {
    $ico = Get-ChildItem -Path $pwaIconDir -Recurse -Filter *.ico -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match 'hkfiladkhlnnabincpmbbefkecjbiiba' } |
        Select-Object -First 1
    if ($ico) {
        $iconLocation = "$($ico.FullName),0"
    }
}

if (-not (Test-Path $startBat)) {
    throw "Missing start.bat in $appDir"
}
if (-not (Test-Path $electronExe)) {
    throw "Missing Electron. Run: npm.cmd install"
}

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $startBat
$shortcut.WorkingDirectory = $appDir
$shortcut.WindowStyle = 7
$shortcut.Description = 'Manager desktop shell with close confirmation'
$shortcut.IconLocation = $iconLocation
$shortcut.Save()

Write-Host "OK: Desktop shortcut Local now opens Electron shell."
Write-Host $shortcutPath
