<?php
declare(strict_types=1);

function sys_user_inbox_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->query('SELECT id FROM sys_user_inbox LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/287_sys_user_inbox.sql');
    }
}

/** @return list<int> */
function sys_user_inbox_recipient_ids(PDO $pdo, array $changeRow): array
{
    $ids = [];
    $reqBy = (int) ($changeRow['requested_by'] ?? 0);
    if ($reqBy > 0) {
        $ids[$reqBy] = true;
    }
    $repId = (int) ($changeRow['sales_rep_id'] ?? 0);
    $customerId = (int) ($changeRow['customer_id'] ?? 0);
    if ($repId < 1 && $customerId > 0) {
        try {
            $st = $pdo->prepare('SELECT sales_rep_id FROM crm_customer WHERE id = ? LIMIT 1');
            $st->execute([$customerId]);
            $repId = (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
        }
    }
    if ($repId > 0) {
        try {
            $st = $pdo->prepare('SELECT id FROM sys_user WHERE sales_rep_id = ?');
            $st->execute([$repId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
                $u = (int) $uid;
                if ($u > 0) {
                    $ids[$u] = true;
                }
            }
        } catch (Throwable $e) {
        }
    }

    return array_map('intval', array_keys($ids));
}

function sys_user_inbox_push(
    PDO $pdo,
    int $userId,
    string $kind,
    string $title,
    string $body,
    array $meta = []
): void {
    if ($userId < 1) {
        return;
    }
    sys_user_inbox_ensure($pdo);
    $refType = (string) ($meta['ref_type'] ?? '');
    $refId = (int) ($meta['ref_id'] ?? 0);
    if ($refType !== '' && $refId > 0) {
        $dup = $pdo->prepare(
            'SELECT id FROM sys_user_inbox
             WHERE user_id = ? AND kind = ? AND ref_type = ? AND ref_id = ?
             LIMIT 1'
        );
        $dup->execute([$userId, $kind, $refType, $refId]);
        if ((int) ($dup->fetchColumn() ?: 0) > 0) {
            return;
        }
    }
    $payload = $meta['payload'] ?? null;
    $pdo->prepare(
        'INSERT INTO sys_user_inbox
         (user_id, kind, title, body, ref_type, ref_id, customer_id, payload_json)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $userId,
        $kind,
        $title,
        $body !== '' ? $body : null,
        $refType !== '' ? $refType : null,
        $refId > 0 ? $refId : null,
        (int) ($meta['customer_id'] ?? 0) ?: null,
        $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function sys_user_inbox_push_gps_decision(PDO $pdo, array $changeRow, bool $approve): void
{
    $customerId = (int) ($changeRow['customer_id'] ?? 0);
    $name = '';
    $code = '';
    if ($customerId > 0) {
        try {
            $st = $pdo->prepare('SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1');
            $st->execute([$customerId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($c)) {
                $name = (string) ($c['name_ar'] ?? '');
                $code = (string) ($c['code'] ?? '');
            }
        } catch (Throwable $e) {
        }
    }
    $who = $name !== '' ? '«' . $name . '»' : 'عميل';
    if ($code !== '') {
        $who .= ' (' . $code . ')';
    }
    $kind = $approve ? 'gps_change_approved' : 'gps_change_rejected';
    $title = $approve ? 'تمت الموافقة على موقع العميل' : 'رُفض تعديل موقع العميل';
    $body = $approve
        ? 'تم اعتماد تحديد موقع العميل ' . $who . '.'
        : 'تم رفض طلب تعديل موقع العميل ' . $who . '.';
    $payload = [
        'customer_name' => $name,
        'customer_code' => $code,
        'latitude' => $changeRow['new_latitude'] ?? null,
        'longitude' => $changeRow['new_longitude'] ?? null,
        'clear_gps' => !empty($changeRow['clear_gps']),
    ];
    foreach (sys_user_inbox_recipient_ids($pdo, $changeRow) as $uid) {
        try {
            sys_user_inbox_push($pdo, $uid, $kind, $title, $body, [
                'ref_type' => 'crm_customer_gps_change',
                'ref_id' => (int) ($changeRow['id'] ?? 0),
                'customer_id' => $customerId,
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
        }
    }
}

/** @return list<array<string,mixed>> */
function sys_user_inbox_list(PDO $pdo, int $userId, int $limit = 50): array
{
    sys_user_inbox_ensure($pdo);
    if ($userId < 1) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $st = $pdo->prepare(
        "SELECT id, kind, title, body, ref_type, ref_id, customer_id, payload_json,
                is_read, created_at
         FROM sys_user_inbox
         WHERE user_id = ?
         ORDER BY is_read ASC, created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $payload = [];
        $raw = (string) ($row['payload_json'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'kind' => (string) ($row['kind'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'ref_type' => (string) ($row['ref_type'] ?? ''),
            'ref_id' => (int) ($row['ref_id'] ?? 0),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'is_read' => ((int) ($row['is_read'] ?? 0)) === 1,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'customer_name' => (string) ($payload['customer_name'] ?? ''),
            'customer_code' => (string) ($payload['customer_code'] ?? ''),
            'latitude' => isset($payload['latitude']) ? (float) $payload['latitude'] : null,
            'longitude' => isset($payload['longitude']) ? (float) $payload['longitude'] : null,
            'clear_gps' => !empty($payload['clear_gps']),
        ];
    }

    return $out;
}

function sys_user_inbox_unread_count(PDO $pdo, int $userId): int
{
    sys_user_inbox_ensure($pdo);
    if ($userId < 1) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM sys_user_inbox WHERE user_id = ? AND is_read = 0'
    );
    $st->execute([$userId]);

    return (int) ($st->fetchColumn() ?: 0);
}

function sys_user_inbox_mark_read(PDO $pdo, int $userId, array $ids): int
{
    sys_user_inbox_ensure($pdo);
    $clean = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $clean[$n] = true;
        }
    }
    $clean = array_keys($clean);
    if ($userId < 1 || $clean === []) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($clean), '?'));
    $st = $pdo->prepare(
        "UPDATE sys_user_inbox SET is_read = 1, read_at = NOW()
         WHERE user_id = ? AND is_read = 0 AND id IN ({$ph})"
    );
    $st->execute([$userId, ...$clean]);

    return $st->rowCount();
}

function sys_user_inbox_mark_all_read(PDO $pdo, int $userId): int
{
    sys_user_inbox_ensure($pdo);
    if ($userId < 1) {
        return 0;
    }
    $st = $pdo->prepare(
        'UPDATE sys_user_inbox SET is_read = 1, read_at = NOW()
         WHERE user_id = ? AND is_read = 0'
    );
    $st->execute([$userId]);

    return $st->rowCount();
}

/** @return array{unread_count:int,items:list<array<string,mixed>>} */
function sys_user_inbox_api_payload(PDO $pdo, int $userId, int $limit = 50): array
{
    return [
        'unread_count' => sys_user_inbox_unread_count($pdo, $userId),
        'items' => sys_user_inbox_list($pdo, $userId, $limit),
    ];
}
