#Requires -Version 5.1
<#
  وكيل مزامنة ZKT — يعمل بدون PHP
  يقرأ att2000.mdb عبر ADODB ويرسل للسيرفر
#>
param(
    [switch]$Quiet,
    [switch]$Diagnose
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

function Format-CheckTimeValue($raw) {
    if ($null -eq $raw) {
        return ''
    }
    if ($raw -is [datetime]) {
        return $raw.ToString('yyyy-MM-dd HH:mm:ss')
    }
    $text = [string]$raw
    if ($text -match '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$') {
        return $text
    }
    return $text.Trim()
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
        $checkRaw = $rs.Fields.Item('CHECKTIME').Value
        $checkStr = Format-CheckTimeValue $checkRaw
        if ($checkStr -match '#Error') {
            $rs.MoveNext()
            continue
        }
        $rows.Add([pscustomobject]@{
            USERID      = [int]$rs.Fields.Item('USERID').Value
            CHECKTIME   = $checkStr
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

function Get-PendingFlagCount([object]$Conn) {
    try {
        $rs = $Conn.Execute('SELECT COUNT(*) AS c FROM CHECKINOUT WHERE ([Flag] = 0 OR [Flag] IS NULL)')
        if (-not $rs.EOF) {
            return [int]$rs.Fields.Item(0).Value
        }
    } catch {
        return -1
    }
    return 0
}

function Get-TotalCheckinCount([object]$Conn) {
    try {
        $rs = $Conn.Execute('SELECT COUNT(*) FROM CHECKINOUT')
        if (-not $rs.EOF) {
            return [int]$rs.Fields.Item(0).Value
        }
    } catch {
        return -1
    }
    return 0
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

function Mark-RowSynced([object]$Conn, [int]$UserId, [string]$CheckTimeIso) {
    $dt = [datetime]$CheckTimeIso
    $lit = '#' + $dt.ToString('yyyy/MM/dd HH:mm:ss') + '#'
    $sql = "UPDATE CHECKINOUT SET [Flag] = 1 WHERE USERID = $UserId AND CHECKTIME = $lit"
    $Conn.Execute($sql) | Out-Null
}

function Mark-ProcessedKeys([object]$Conn, [string[]]$Keys) {
    foreach ($key in $Keys) {
        if ([string]::IsNullOrWhiteSpace($key)) { continue }
        $parts = $key -split '\|', 2
        if ($parts.Count -lt 2) { continue }
        $userId = 0
        if (-not [int]::TryParse($parts[0], [ref]$userId) -or $userId -lt 1) { continue }
        try {
            Mark-RowSynced -Conn $Conn -UserId $userId -CheckTimeIso $parts[1]
        } catch {
            Write-Log ("Could not mark Flag=1 for $key : $($_.Exception.Message)")
        }
    }
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
    $rowArray = @($rows)
    $rowCount = $rowArray.Count

    if ($Diagnose) {
        $pending = if ($hasFlag) { Get-PendingFlagCount -Conn $conn } else { -1 }
        $total = Get-TotalCheckinCount -Conn $conn
        Write-Host "mdb=$($cfg.MdbPath) use_flag=$($cfg.UseFlag) hasFlag=$hasFlag"
        Write-Host "pending=$pending total=$total rows_to_send=$rowCount"
        foreach ($r in $rowArray) {
            Write-Host "  USERID=$($r.USERID) CHECKTIME=$($r.CHECKTIME)"
        }
        exit 0
    }

    if ($rowCount -lt 1) {
        if ($cfg.UseFlag -and $hasFlag) {
            $pending = Get-PendingFlagCount -Conn $conn
            $total = Get-TotalCheckinCount -Conn $conn
            if ($pending -eq 0 -and $total -gt 0) {
                Write-Log ('No new punches to send. All {0} record(s) have Flag=1. Set Flag=0 on new rows or use_flag=false in config.' -f $total)
            } else {
                Write-Log ('No new punches to send. Pending Flag=0: {0}, total CHECKINOUT: {1}' -f $pending, $total)
            }
        } else {
            Write-Log 'No new punches to send.'
        }
        exit 0
    }

    Write-Log ('Found {0} punch(es) to send.' -f $rowCount)
    $batchSize = [Math]::Max(50, [Math]::Min(2000, $cfg.BatchSize))
    $totalInserted = 0
    $totalSkipped = 0
    $chunkIndex = 0
    $chunkCount = [Math]::Ceiling($rowCount / $batchSize)

    for ($i = 0; $i -lt $rowCount; $i += $batchSize) {
        $chunkIndex++
        $end = [Math]::Min($i + $batchSize - 1, $rowCount - 1)
        $chunk = $rowArray[$i..$end]
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
        if ($result.message) {
            Write-Log ('Server: {0}' -f $result.message)
        }
        $parseFailed = 0
        if ($null -ne $result.parse_failed) {
            $parseFailed = [int]$result.parse_failed
        }
        if ($parseFailed -gt 0) {
            Write-Log ('Warning: {0} punch(es) rejected (bad date format). They stay Flag=0 — upload includes/hr_attendance.php fix to server.' -f $parseFailed)
        }
        $totalInserted += [int]$result.inserted
        $totalSkipped += [int]$result.skipped

        if ($cfg.MarkFlagsAfterPush -and $hasFlag) {
            $processedKeys = @()
            if ($null -ne $result.source_keys_processed) {
                $processedKeys = @($result.source_keys_processed)
            }
            if ($processedKeys.Count -gt 0) {
                Mark-ProcessedKeys -Conn $conn -Keys $processedKeys
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
