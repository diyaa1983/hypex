<?php
declare(strict_types=1);

function sys_error_log_ensure_schema(?PDO $pdo = null): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo = $pdo ?? db();
        $pdo->query('SELECT id FROM sys_error_log LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        try {
            $pdo = $pdo ?? db();
            sql_migration_run_file_once($pdo, 'database/migrations/242_sys_error_log.sql');
            $pdo->query('SELECT id FROM sys_error_log LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }

    return $ok;
}

/**
 * تسجيل خطأ ظاهر للمستخدم / استثناء سيرفر — لا يرمي أبداً.
 *
 * @param array{
 *   source?:string,
 *   level?:string,
 *   message:string,
 *   detail?:string|null,
 *   request_uri?:string|null,
 *   http_method?:string|null,
 *   screen_code?:string|null,
 *   user_id?:int|null,
 *   username?:string|null,
 * } $payload
 */
function sys_error_log_write(array $payload): void
{
    static $reentry = false;
    if ($reentry) {
        return;
    }
    $reentry = true;
    try {
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return;
        }
        if (function_exists('mb_substr')) {
            $message = mb_substr($message, 0, 1000);
        } else {
            $message = substr($message, 0, 1000);
        }

        if (!function_exists('db')) {
            return;
        }
        $pdo = db();
        if (!sys_error_log_ensure_schema($pdo)) {
            return;
        }

        $source = strtolower(trim((string) ($payload['source'] ?? 'server')));
        if (!in_array($source, ['server', 'api', 'ui', 'fatal', 'client'], true)) {
            $source = 'server';
        }
        $level = strtolower(trim((string) ($payload['level'] ?? 'error')));
        if (!in_array($level, ['error', 'warning', 'fatal', 'info'], true)) {
            $level = 'error';
        }

        $detail = $payload['detail'] ?? null;
        $detail = is_string($detail) && trim($detail) !== '' ? trim($detail) : null;
        if ($detail !== null && strlen($detail) > 20000) {
            $detail = substr($detail, 0, 20000) . "\n…";
        }

        $uri = $payload['request_uri'] ?? ($_SERVER['REQUEST_URI'] ?? null);
        $uri = is_string($uri) ? substr($uri, 0, 500) : null;
        $method = $payload['http_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? null);
        $method = is_string($method) ? substr(strtoupper($method), 0, 10) : null;
        $screen = $payload['screen_code'] ?? null;
        $screen = is_string($screen) ? substr(trim($screen), 0, 64) : null;

        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        $username = isset($payload['username']) ? trim((string) $payload['username']) : '';
        if ($userId < 1 && function_exists('current_user') && function_exists('is_logged_in') && is_logged_in()) {
            $u = current_user();
            $userId = (int) ($u['id'] ?? 0);
            if ($username === '') {
                $username = (string) ($u['username'] ?? ($u['full_name_ar'] ?? ''));
            }
        }
        if ($username !== '' && function_exists('mb_substr')) {
            $username = mb_substr($username, 0, 80);
        } elseif ($username !== '') {
            $username = substr($username, 0, 80);
        }

        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        // دمج التكرار خلال آخر 10 دقائق
        $dup = $pdo->prepare(
            'SELECT id FROM sys_error_log
             WHERE source = ? AND message = ?
               AND COALESCE(request_uri, \'\') = COALESCE(?, \'\')
               AND last_seen_at >= (NOW() - INTERVAL 10 MINUTE)
             ORDER BY id DESC LIMIT 1'
        );
        $dup->execute([$source, $message, $uri]);
        $dupId = (int) $dup->fetchColumn();
        if ($dupId > 0) {
            $pdo->prepare(
                'UPDATE sys_error_log
                 SET occurrence_count = occurrence_count + 1,
                     last_seen_at = NOW(),
                     detail = COALESCE(?, detail),
                     user_id = COALESCE(?, user_id),
                     username = COALESCE(NULLIF(?, \'\'), username)
                 WHERE id = ?'
            )->execute([
                $detail,
                $userId > 0 ? $userId : null,
                $username,
                $dupId,
            ]);
            return;
        }

        $pdo->prepare(
            'INSERT INTO sys_error_log
             (source, level, message, detail, request_uri, http_method, screen_code,
              user_id, username, ip_address, user_agent, occurrence_count, last_seen_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW())'
        )->execute([
            $source,
            $level,
            $message,
            $detail,
            $uri,
            $method,
            $screen !== '' ? $screen : null,
            $userId > 0 ? $userId : null,
            $username !== '' ? $username : null,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
        ]);
    } catch (Throwable $e) {
        error_log('sys_error_log_write: ' . $e->getMessage());
    } finally {
        $reentry = false;
    }
}

function sys_error_log_from_throwable(Throwable $e, string $source = 'server'): void
{
    sys_error_log_write([
        'source' => $source,
        'level' => 'error',
        'message' => $e->getMessage() !== '' ? $e->getMessage() : get_class($e),
        'detail' => $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(),
    ]);
}

/**
 * @return array{total:int, rows:list<array<string,mixed>>}
 */
function sys_error_log_fetch(
    PDO $pdo,
    string $from,
    string $to,
    ?string $source = null,
    ?string $q = null,
    int $limit = 50,
    int $offset = 0
): array {
    if (!sys_error_log_ensure_schema($pdo)) {
        return ['total' => 0, 'rows' => []];
    }
    $where = 'WHERE DATE(logged_at) >= ? AND DATE(logged_at) <= ?';
    $params = [$from, $to];
    if ($source !== null && $source !== '' && $source !== 'all') {
        $where .= ' AND source = ?';
        $params[] = $source;
    }
    if ($q !== null && trim($q) !== '') {
        $where .= ' AND (message LIKE ? OR detail LIKE ? OR username LIKE ? OR request_uri LIKE ?)';
        $like = '%' . trim($q) . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $stCount = $pdo->prepare('SELECT COUNT(*) FROM sys_error_log ' . $where);
    $stCount->execute($params);
    $total = (int) $stCount->fetchColumn();

    $sql = 'SELECT id, logged_at, last_seen_at, source, level, message, detail,
                   request_uri, http_method, screen_code, user_id, username,
                   ip_address, occurrence_count
            FROM sys_error_log ' . $where . '
            ORDER BY last_seen_at DESC, id DESC
            LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return ['total' => $total, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function sys_error_log_clear(PDO $pdo, ?string $beforeDate = null): int
{
    if (!sys_error_log_ensure_schema($pdo)) {
        return 0;
    }
    if ($beforeDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
        $st = $pdo->prepare('DELETE FROM sys_error_log WHERE DATE(logged_at) < ?');
        $st->execute([$beforeDate]);

        return $st->rowCount();
    }
    return (int) $pdo->exec('DELETE FROM sys_error_log');
}

function sys_error_log_source_label(string $source): string
{
    return match ($source) {
        'ui', 'client' => 'واجهة المستخدم',
        'api' => 'واجهة API',
        'fatal' => 'خطأ قاتل',
        default => 'الخادم',
    };
}
