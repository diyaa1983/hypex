<?php
declare(strict_types=1);

/**
 * وكيل مزامنة ZKT — يُشغَّل على جهاز Windows حيث يوجد att2000.mdb
 * يرسل البصمات إلى السيرفر (Linux) عبر API.
 *
 * الاستخدام:
 *   C:\xampp\php\php.exe tools\zk_sync_agent.php
 *   أو: tools\zk_sync_run.bat
 */

define('HR_ATT_MDB_ONLY', true);

$root = dirname(__DIR__);
if (!is_file($root . '/config/app.php')) {
    fwrite(STDERR, "Manager root not found: {$root}\n");
    exit(1);
}

require $root . '/config/app.php';
require $root . '/includes/hr_attendance.php';

$cfgFile = __DIR__ . '/zk_sync.local.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, "Missing config: tools/zk_sync.local.php\n");
    fwrite(STDERR, "Copy tools/zk_sync.local.example.php to tools/zk_sync.local.php\n");
    exit(1);
}

/** @var array<string,mixed> $cfg */
$cfg = require $cfgFile;
$serverUrl = trim((string) ($cfg['server_url'] ?? ''));
$syncToken = trim((string) ($cfg['sync_token'] ?? ''));
$mdbPath = trim((string) ($cfg['mdb_path'] ?? 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb'));
$useFlag = !array_key_exists('use_flag', $cfg) || (bool) $cfg['use_flag'];
$batchSize = max(50, min(2000, (int) ($cfg['batch_size'] ?? 500)));
$markFlags = !array_key_exists('mark_flags_after_push', $cfg) || (bool) $cfg['mark_flags_after_push'];
$quiet = in_array('--quiet', $argv ?? [], true);

if ($serverUrl === '' || $syncToken === '' || $syncToken === 'PASTE_SYNC_TOKEN_HERE') {
    fwrite(STDERR, "Configure server_url and sync_token in tools/zk_sync.local.php\n");
    exit(1);
}

if (!hr_attendance_pdo_odbc_available() && !hr_attendance_com_available()) {
    fwrite(STDERR, "Enable pdo_odbc or com_dotnet in php.ini on this Windows machine.\n");
    exit(1);
}

function zk_agent_log(string $msg, bool $quiet): void
{
    if (!$quiet) {
        echo date('Y-m-d H:i:s') . ' — ' . $msg . PHP_EOL;
    }
}

function zk_agent_post_batch(string $url, string $token, array $punches): array
{
    $body = json_encode([
        'token' => $token,
        'punches' => $punches,
    ], JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('تعذر ترميز JSON.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('تعذر تهيئة cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'X-HR-Att-Token: ' . $token,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('فشل الاتصال بالسيرفر: ' . $curlErr);
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\nX-HR-Att-Token: {$token}\r\n",
                'content' => $body,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
        if ($response === false) {
            throw new RuntimeException('فشل الاتصال بالسيرفر (file_get_contents).');
        }
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        throw new RuntimeException('استجابة غير صالحة من السيرفر (HTTP ' . $httpCode . ').');
    }
    if ($httpCode >= 400 || empty($data['ok'])) {
        throw new RuntimeException((string) ($data['error'] ?? $data['message'] ?? 'فشل API'));
    }

    return $data;
}

function zk_agent_normalize_row(array $row): array
{
    return [
        'USERID' => (int) ($row['USERID'] ?? 0),
        'CHECKTIME' => $row['CHECKTIME'] ?? '',
        'CHECKTYPE' => $row['CHECKTYPE'] ?? '',
        'VERIFYCODE' => $row['VERIFYCODE'] ?? null,
        'SENSORID' => $row['SENSORID'] ?? '',
        'BADGENUMBER' => $row['BADGENUMBER'] ?? '',
        'NAME' => $row['NAME'] ?? '',
    ];
}

try {
    $readPath = hr_attendance_mdb_prepare_sync_path($mdbPath);
    $hasFlag = $useFlag && hr_attendance_mdb_checkinout_has_flag($readPath);

    if ($hasFlag) {
        $rows = hr_attendance_mdb_fetch_unsynced_punches($readPath);
        zk_agent_log('وضع Flag — سجلات بانتظار الإرسال: ' . count($rows), $quiet);
    } else {
        $rows = hr_attendance_mdb_fetch_all_punches($readPath);
        zk_agent_log('قراءة كل السجلات من Access: ' . count($rows), $quiet);
    }

    if ($rows === []) {
        zk_agent_log('لا توجد بصمات جديدة.', $quiet);
        exit(0);
    }

    $totalInserted = 0;
    $totalSkipped = 0;
    $totalUnlinked = 0;
    $chunks = array_chunk($rows, $batchSize);
    $chunkNo = 0;

    foreach ($chunks as $chunk) {
        $chunkNo++;
        $payload = array_map('zk_agent_normalize_row', $chunk);
        zk_agent_log("إرسال دفعة {$chunkNo}/" . count($chunks) . ' (' . count($payload) . ' سجل)...', $quiet);

        $result = zk_agent_post_batch($serverUrl, $syncToken, $payload);
        $totalInserted += (int) ($result['inserted'] ?? 0);
        $totalSkipped += (int) ($result['skipped'] ?? 0);
        $totalUnlinked += (int) ($result['unlinked'] ?? 0);

        if ($markFlags && $hasFlag) {
            $writeConn = null;
            if (hr_attendance_com_available()) {
                try {
                    $writeConn = hr_attendance_mdb_com_open_path($mdbPath, true);
                } catch (Throwable $e) {
                    $writeConn = null;
                }
            }
            foreach ($chunk as $markRow) {
                $uid = (int) ($markRow['USERID'] ?? 0);
                if ($uid < 1) {
                    continue;
                }
                try {
                    if ($writeConn instanceof COM) {
                        hr_attendance_mdb_mark_checkinout_synced_com_conn(
                            $writeConn,
                            $uid,
                            $markRow['CHECKTIME'] ?? null
                        );
                    } else {
                        hr_attendance_mdb_mark_checkinout_synced($mdbPath, $uid, $markRow['CHECKTIME'] ?? null);
                    }
                } catch (Throwable $e) {
                    // continue marking others
                }
            }
        }
    }

    zk_agent_log(
        "تم — جديد: {$totalInserted}، موجود: {$totalSkipped}، غير مربوط: {$totalUnlinked}",
        $quiet
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'خطأ: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
