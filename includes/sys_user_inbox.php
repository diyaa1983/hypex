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

/**
 * مستخدمو النظام الذين لديهم إحدى شاشات الاعتماد (سطح مكتب أو موبايل).
 *
 * @param list<string> $screenCodes
 * @return list<int>
 */
function sys_user_inbox_users_with_screens(PDO $pdo, array $screenCodes): array
{
    $codes = [];
    foreach ($screenCodes as $c) {
        $c = trim((string) $c);
        if ($c !== '') {
            $codes[$c] = true;
        }
    }
    $codes = array_keys($codes);
    if ($codes === []) {
        return [];
    }
    $ids = [];
    try {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare(
            "SELECT DISTINCT ug.user_id
             FROM sys_user_group ug
             INNER JOIN sys_group_permission gp ON gp.group_id = ug.group_id AND gp.allowed = 1
             INNER JOIN sys_screen s ON s.id = gp.screen_id
             INNER JOIN sys_user u ON u.id = ug.user_id AND u.is_active = 1
             WHERE s.code IN ({$ph})"
        );
        $st->execute($codes);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
            $u = (int) $uid;
            if ($u > 0) {
                $ids[$u] = true;
            }
        }
        $admins = $pdo->query(
            "SELECT DISTINCT ug.user_id
             FROM sys_user_group ug
             INNER JOIN sys_group g ON g.id = ug.group_id AND g.code = 'ADMINS'
             INNER JOIN sys_user u ON u.id = ug.user_id AND u.is_active = 1"
        );
        foreach (($admins ? $admins->fetchAll(PDO::FETCH_COLUMN) : []) ?: [] as $uid) {
            $u = (int) $uid;
            if ($u > 0) {
                $ids[$u] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('sys_user_inbox_users_with_screens: ' . $e->getMessage());
    }

    return array_map('intval', array_keys($ids));
}

/** إشعار مديري المبيعات بطلب خروج يدوي بانتظار الموافقة. */
function sys_user_inbox_push_checkout_pending(PDO $pdo, array $reqRow): void
{
    $customerId = (int) ($reqRow['customer_id'] ?? 0);
    $name = (string) ($reqRow['customer_name'] ?? '');
    $code = (string) ($reqRow['customer_code'] ?? '');
    $repName = (string) ($reqRow['sales_rep_name'] ?? '');
    if ($name === '' && $customerId > 0) {
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
    if ($repName === '') {
        $repId = (int) ($reqRow['sales_rep_id'] ?? 0);
        if ($repId > 0) {
            try {
                $st = $pdo->prepare('SELECT name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
                $st->execute([$repId]);
                $repName = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable $e) {
            }
        }
    }
    $who = $name !== '' ? '«' . $name . '»' : 'عميل';
    if ($code !== '') {
        $who .= ' (' . $code . ')';
    }
    $title = 'طلب خروج يدوي بانتظار الموافقة';
    $body = ($repName !== '' ? $repName . ' — ' : '') . 'خروج يدوي من زيارة ' . $who;
    $payload = [
        'customer_name' => $name,
        'customer_code' => $code,
        'sales_rep_name' => $repName,
        'route' => '/approvals/visit-checkout',
    ];
    $exclude = (int) ($reqRow['requested_by'] ?? 0);
    foreach (
        sys_user_inbox_users_with_screens($pdo, [
            'sales_rep_visit_checkout_approve',
            'm_visit_checkout_approve',
        ]) as $uid
    ) {
        if ($uid === $exclude) {
            continue;
        }
        try {
            sys_user_inbox_push($pdo, $uid, 'visit_checkout_pending', $title, $body, [
                'ref_type' => 'sal_rep_visit_checkout_request',
                'ref_id' => (int) ($reqRow['id'] ?? 0),
                'customer_id' => $customerId,
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
        }
    }
}

/** إشعار مديري المبيعات بطلب تعديل موقع عميل بانتظار الموافقة. */
function sys_user_inbox_push_gps_pending(PDO $pdo, array $changeRow): void
{
    $customerId = (int) ($changeRow['customer_id'] ?? 0);
    $name = (string) ($changeRow['customer_name'] ?? '');
    $code = (string) ($changeRow['customer_code'] ?? '');
    $repName = (string) ($changeRow['sales_rep_name'] ?? '');
    if ($name === '' && $customerId > 0) {
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
    if ($repName === '') {
        $repId = (int) ($changeRow['sales_rep_id'] ?? 0);
        if ($repId > 0) {
            try {
                $st = $pdo->prepare('SELECT name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
                $st->execute([$repId]);
                $repName = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable $e) {
            }
        }
    }
    $who = $name !== '' ? '«' . $name . '»' : 'عميل';
    if ($code !== '') {
        $who .= ' (' . $code . ')';
    }
    $clear = !empty($changeRow['clear_gps']);
    $title = 'طلب تعديل موقع عميل بانتظار الموافقة';
    $body = ($repName !== '' ? $repName . ' — ' : '')
        . ($clear ? 'طلب مسح موقع ' : 'طلب تعديل موقع ')
        . $who;
    $payload = [
        'customer_name' => $name,
        'customer_code' => $code,
        'sales_rep_name' => $repName,
        'clear_gps' => $clear,
        'route' => '/approvals/customer-gps',
    ];
    $exclude = (int) ($changeRow['requested_by'] ?? 0);
    foreach (
        sys_user_inbox_users_with_screens($pdo, [
            'crm_customer_gps_approve',
            'm_customer_gps_approve',
        ]) as $uid
    ) {
        if ($uid === $exclude) {
            continue;
        }
        try {
            sys_user_inbox_push($pdo, $uid, 'gps_change_pending', $title, $body, [
                'ref_type' => 'crm_customer_gps_change',
                'ref_id' => (int) ($changeRow['id'] ?? 0),
                'customer_id' => $customerId,
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
        }
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

function sys_user_inbox_push_checkout_decision(PDO $pdo, array $reqRow, bool $approve): void
{
    $customerId = (int) ($reqRow['customer_id'] ?? 0);
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
    $who = $name !== '' ? '«' . $name . '»' : 'العميل';
    if ($code !== '') {
        $who .= ' (' . $code . ')';
    }
    $kind = $approve ? 'visit_checkout_approved' : 'visit_checkout_rejected';
    $title = $approve ? 'تمت الموافقة على الخروج اليدوي' : 'رُفض طلب الخروج اليدوي';
    $body = $approve
        ? 'تم اعتماد خروجك اليدوي من زيارة ' . $who . '.'
        : 'تم رفض طلب الخروج اليدوي من زيارة ' . $who . '. الزيارة ما زالت مفتوحة.';
    $payload = [
        'customer_name' => $name,
        'customer_code' => $code,
    ];
    foreach (sys_user_inbox_recipient_ids($pdo, $reqRow) as $uid) {
        try {
            sys_user_inbox_push($pdo, $uid, $kind, $title, $body, [
                'ref_type' => 'sal_rep_visit_checkout_request',
                'ref_id' => (int) ($reqRow['id'] ?? 0),
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
