#Requires -Version 5.1
<#
  وكيل مزامنة ZKT — يعمل بدون PHP
  يقرأ att2000.mdb عبر ADODB ويرسل للسيرفر
#>
param(
    [switch]$Quiet
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $ScriptDir 'zk_sync.local.php'

function Write-Log([string]$Msg) {
    $line = ('{0} - {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Msg)
    if (-not $Quiet) {
        Write-Host $line
    }
    try {
        $logFile = Join-Path $ScriptDir 'zk_sync.log'
        Add-Content -LiteralPath $logFile -Value $line -Encoding UTF8
    } catch {
        # ignore log write errors
    }
}

function Read-PhpConfig([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        throw 'Missing config: tools\zk_sync.local.php'
    }
    $text = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
    function Get-Val([string]$key) {
        if ($text -match ("'{0}'\s*=>\s*'([^']*)'" -f [regex]::Escape($key))) {
            return ($Matches[1] -replace '\\\\', '\')
        }
        if ($text -match ("'{0}'\s*=>\s*(true|false)" -f [regex]::Escape($key))) {
            return ($Matches[1] -eq 'true')
        }
        if ($text -match ("'{0}'\s*=>\s*(\d+)" -f [regex]::Escape($key))) {
            return [int]$Matches[1]
        }
        return $null
    }
    return [pscustomobject]@{
        ServerUrl           = Get-Val 'server_url'
        SyncToken           = Get-Val 'sync_token'
        MdbPath             = Get-Val 'mdb_path'
        UseFlag             = if ($null -ne (Get-Val 'use_flag')) { [bool](Get-Val 'use_flag') } else { $true }
        BatchSize           = if ($null -ne (Get-Val 'batch_size')) { [int](Get-Val 'batch_size') } else { 500 }
        MarkFlagsAfterPush  = if ($null -ne (Get-Val 'mark_flags_after_push')) { [bool](Get-Val 'mark_flags_after_push') } else { $true }
    }
}

function Open-MdbConnection([string]$MdbPath) {
    if (-not (Test-Path -LiteralPath $MdbPath)) {
        throw "Fingerprint file not found: $MdbPath"
    }
    $providers = @(
        ("Provider=Microsoft.ACE.OLEDB.12.0;Data Source=" + $MdbPath + ";Persist Security Info=False;")
        ("Provider=Microsoft.Jet.OLEDB.4.0;Data Source=" + $MdbPath + ";")
    )
    $conn = New-Object -ComObject ADODB.Connection
    $lastErr = $null
    foreach ($connStr in $providers) {
        try {
            $conn.Open($connStr)
            return $conn
        } catch {
            $lastErr = $_.Exception.Message
        }
    }
    throw "Cannot open Access DB. Install Microsoft Access Database Engine. $lastErr"
}

function Test-HasFlagColumn([object]$Conn) {
    try {
        $null = $Conn.Execute('SELECT TOP 1 [Flag] FROM CHECKINOUT')
        return $true
    } catch {
        return $false
    }
}

function Get-PunchRows([object]$Conn, [bool]$UseFlag, [bool]$HasFlag) {
    $sql = @'
SELECT c.USERID, c.CHECKTIME, c.CHECKTYPE, c.VERIFYCODE, c.SENSORID,
       u.BADGENUMBER, u.NAME
FROM CHECKINOUT AS c
LEFT JOIN USERINFO AS u ON u.USERID = c.USERID
'@
    if ($UseFlag -and $HasFlag) {
        $sql += ' WHERE (c.[Flag] = 0 OR c.[Flag] IS NULL)'
    }
    $sql += ' ORDER BY c.CHECKTIME ASC'
    $rs = $Conn.Execute($sql)
    $rows = New-Object System.Collections.Generic.List[object]
    while (-not $rs.EOF) {
        $rows.Add([pscustomobject]@{
            USERID      = [int]$rs.Fields.Item('USERID').Value
            CHECKTIME   = [string]$rs.Fields.Item('CHECKTIME').Value
            CHECKTYPE   = [string]$rs.Fields.Item('CHECKTYPE').Value
            VERIFYCODE  = [string]$rs.Fields.Item('VERIFYCODE').Value
            SENSORID    = [string]$rs.Fields.Item('SENSORID').Value
            BADGENUMBER = [string]$rs.Fields.Item('BADGENUMBER').Value
            NAME        = [string]$rs.Fields.Item('NAME').Value
        }) | Out-Null
        $rs.MoveNext()
    }
    return $rows
}

function Send-Batch([string]$Url, [string]$Token, [object[]]$Punches) {
    $bodyObj = @{ token = $Token; punches = $Punches }
    $json = $bodyObj | ConvertTo-Json -Depth 6 -Compress
    $headers = @{
        'Content-Type'   = 'application/json; charset=utf-8'
        'X-HR-Att-Token' = $Token
    }
    try {
        return Invoke-RestMethod -Uri $Url -Method Post -Body $json -Headers $headers -TimeoutSec 120
    } catch {
        $detail = $_.Exception.Message
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) {
            $detail = $_.ErrorDetails.Message
        }
        throw "Server error: $detail"
    }
}

function Mark-RowSynced([object]$Conn, [int]$UserId, [string]$CheckTime) {
    $lit = '#' + ([datetime]$CheckTime).ToString('yyyy/MM/dd HH:mm:ss') + '#'
    $sql = "UPDATE CHECKINOUT SET [Flag] = 1 WHERE USERID = $UserId AND CHECKTIME = $lit"
    $Conn.Execute($sql) | Out-Null
}

try {
    $cfg = Read-PhpConfig -Path $ConfigPath
    if ([string]::IsNullOrWhiteSpace($cfg.ServerUrl) -or [string]::IsNullOrWhiteSpace($cfg.SyncToken)) {
        throw 'Configure server_url and sync_token in zk_sync.local.php'
    }
    if ([string]::IsNullOrWhiteSpace($cfg.MdbPath)) {
        $cfg.MdbPath = Join-Path (Split-Path $ScriptDir -Parent) 'att2000.mdb'
    }

    $conn = Open-MdbConnection -MdbPath $cfg.MdbPath
    $hasFlag = Test-HasFlagColumn -Conn $conn
    $rows = Get-PunchRows -Conn $conn -UseFlag $cfg.UseFlag -HasFlag $hasFlag

    if ($rows.Count -eq 0) {
        Write-Log 'No new punches to send.'
        exit 0
    }

    Write-Log ("Found {0} punch(es) to send." -f $rows.Count)
    $batchSize = [Math]::Max(50, [Math]::Min(2000, $cfg.BatchSize))
    $totalInserted = 0
    $totalSkipped = 0
    $chunkIndex = 0
    $chunkCount = [Math]::Ceiling($rows.Count / $batchSize)

    for ($i = 0; $i -lt $rows.Count; $i += $batchSize) {
        $chunkIndex++
        $chunk = @($rows[$i..([Math]::Min($i + $batchSize - 1, $rows.Count - 1))])
        Write-Log ("Sending batch {0}/{1} ({2} records)..." -f $chunkIndex, $chunkCount, $chunk.Count)

        $payload = foreach ($r in $chunk) {
            @{
                USERID      = $r.USERID
                CHECKTIME   = $r.CHECKTIME
                CHECKTYPE   = $r.CHECKTYPE
                VERIFYCODE  = $r.VERIFYCODE
                SENSORID    = $r.SENSORID
                BADGENUMBER = $r.BADGENUMBER
                NAME        = $r.NAME
            }
        }

        $result = Send-Batch -Url $cfg.ServerUrl -Token $cfg.SyncToken -Punches $payload
        $totalInserted += [int]$result.inserted
        $totalSkipped += [int]$result.skipped

        if ($cfg.MarkFlagsAfterPush -and $hasFlag) {
            foreach ($r in $chunk) {
                try { Mark-RowSynced -Conn $conn -UserId $r.USERID -CheckTime $r.CHECKTIME } catch { }
            }
        }
    }

    Write-Log ('Done - new: {0}, already exists: {1}' -f $totalInserted, $totalSkipped)
    exit 0
} catch {
    $errLine = ('{0} - Error: {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $_.Exception.Message)
    if (-not $Quiet) {
        Write-Host $errLine -ForegroundColor Red
    }
    try {
        Add-Content -LiteralPath (Join-Path $ScriptDir 'zk_sync.log') -Value $errLine -Encoding UTF8
    } catch { }
    exit 1
}
