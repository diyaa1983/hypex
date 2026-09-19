<?php
declare(strict_types=1);

/** @return list<array{code: string, name_ar: string, hint_ar: string, post_auto: int, post_manual: int, is_active: int, affects_gl: int, sort_order: int}> */
function inv_movement_type_default_rows(): array
{
    return [
        [
            'code' => 'adjust_in',
            'name_ar' => 'تعديل مخزون (زيادة)',
            'hint_ar' => 'إدخال كميات إضافية إلى المستودع',
            'post_auto' => 0,
            'post_manual' => 1,
            'is_active' => 1,
            'affects_gl' => 1,
            'sort_order' => 10,
        ],
        [
            'code' => 'adjust_out',
            'name_ar' => 'تعديل مخزون (نقصان)',
            'hint_ar' => 'خصم كميات من المستودع',
            'post_auto' => 0,
            'post_manual' => 1,
            'is_active' => 1,
            'affects_gl' => 1,
            'sort_order' => 15,
        ],
        [
            'code' => 'transfer',
            'name_ar' => 'نقل بين المستودعات',
            'hint_ar' => 'صرف من مستودع وإدخال إلى مستودع آخر',
            'post_auto' => 0,
            'post_manual' => 1,
            'is_active' => 1,
            'affects_gl' => 0,
            'sort_order' => 20,
        ],
        [
            'code' => 'disposal',
            'name_ar' => 'إتلاف',
            'hint_ar' => 'إخراج مواد تالفة أو منتهية من المخزون',
            'post_auto' => 0,
            'post_manual' => 1,
            'is_active' => 1,
            'affects_gl' => 1,
            'sort_order' => 30,
        ],
    ];
}

function inv_movement_type_ensure_affects_gl_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT affects_gl FROM inv_movement_type LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'ALTER TABLE inv_movement_type
                 ADD COLUMN affects_gl TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active'
            );
            $pdo->exec("UPDATE inv_movement_type SET affects_gl = 0 WHERE code = 'transfer'");
        } catch (Throwable $e2) {
            //
        }
    }
}

function inv_movement_type_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inv_movement_type (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          code VARCHAR(40) NOT NULL,
          name_ar VARCHAR(120) NOT NULL,
          hint_ar VARCHAR(255) NULL,
          post_auto TINYINT(1) NOT NULL DEFAULT 0,
          post_manual TINYINT(1) NOT NULL DEFAULT 1,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_inv_movement_type_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    inv_movement_type_seed_defaults($pdo);
    inv_movement_type_migrate_legacy_adjustment($pdo);
    inv_movement_type_normalize_post_modes($pdo);
    inv_movement_type_ensure_affects_gl_column($pdo);
}

/** لا يجوز تفعيل الترحيل التلقائي واليدوي معاً. */
function inv_movement_type_normalize_post_modes(PDO $pdo): void
{
    $pdo->exec(
        'UPDATE inv_movement_type SET post_manual = 0 WHERE post_auto = 1 AND post_manual = 1'
    );
}

/** فصل النوع القديم adjustment إلى زيادة / نقصان (مرة واحدة). */
function inv_movement_type_migrate_legacy_adjustment(PDO $pdo): void
{
    $st = $pdo->prepare('SELECT id, post_auto, post_manual, is_active FROM inv_movement_type WHERE code = ? LIMIT 1');
    $st->execute(['adjustment']);
    $legacy = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($legacy)) {
        return;
    }
    $postAuto = (int) ($legacy['post_auto'] ?? 0);
    $postManual = (int) ($legacy['post_manual'] ?? 1);
    $active = (int) ($legacy['is_active'] ?? 1);

    $ins = $pdo->prepare(
        'INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
         SELECT ?, ?, ?, ?, ?, ?, ?
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = ?)'
    );
    foreach (
        [
            ['adjust_in', 'تعديل مخزون (زيادة)', 'إدخال كميات إضافية إلى المستودع', 10],
            ['adjust_out', 'تعديل مخزون (نقصان)', 'خصم كميات من المستودع', 15],
        ] as $row
    ) {
        $ins->execute([
            $row[0],
            $row[1],
            $row[2],
            $postAuto,
            $postManual,
            $active,
            $row[3],
            $row[0],
        ]);
    }

    $pdo->prepare('DELETE FROM inv_movement_type WHERE code = ?')->execute(['adjustment']);
}

function inv_movement_type_seed_defaults(PDO $pdo): void
{
    $st = $pdo->prepare(
        'INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
         SELECT ?, ?, ?, ?, ?, ?, ?
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = ?)'
    );
    foreach (inv_movement_type_default_rows() as $row) {
        $st->execute([
            $row['code'],
            $row['name_ar'],
            $row['hint_ar'],
            $row['post_auto'],
            $row['post_manual'],
            $row['is_active'],
            $row['sort_order'],
            $row['code'],
        ]);
    }
}

/** @return list<string> */
function inv_movement_type_picker_codes(): array
{
    return ['adjust_in', 'adjust_out', 'transfer', 'disposal'];
}

/** تفعيل الأنواع الافتراضية إذا أُوقفت كلها من الإعدادات. */
function inv_movement_type_ensure_picker_defaults(PDO $pdo): void
{
    inv_movement_type_ensure_schema($pdo);
    $st = $pdo->prepare('UPDATE inv_movement_type SET is_active = 1 WHERE code = ?');
    foreach (inv_movement_type_picker_codes() as $code) {
        $st->execute([$code]);
    }
}

/** أنواع الحركة لقائمة شاشة حركات المستودع (مع ضمان وجود نوع واحد على الأقل). */
function inv_movement_types_for_picker(PDO $pdo): array
{
    inv_movement_type_ensure_schema($pdo);
    $types = array_values(array_filter(
        inv_movement_types_all($pdo, true),
        static fn (array $t): bool => (string) ($t['code'] ?? '') !== 'adjustment'
    ));
    if ($types !== []) {
        return $types;
    }
    inv_movement_type_ensure_picker_defaults($pdo);

    return array_values(array_filter(
        inv_movement_types_all($pdo, true),
        static fn (array $t): bool => (string) ($t['code'] ?? '') !== 'adjustment'
    ));
}

/** @return list<array<string, mixed>> */
function inv_movement_types_all(PDO $pdo, bool $activeOnly = false): array
{
    inv_movement_type_ensure_schema($pdo);
    $where = $activeOnly ? ' WHERE is_active = 1' : '';
    $order = ' ORDER BY sort_order ASC, id ASC';

    try {
        $sql = 'SELECT id, code, name_ar, hint_ar, post_auto, post_manual, is_active, affects_gl, sort_order
                FROM inv_movement_type' . $where . $order;

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $sql = 'SELECT id, code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order
                FROM inv_movement_type' . $where . $order;
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            if (!array_key_exists('affects_gl', $row)) {
                $row['affects_gl'] = (string) ($row['code'] ?? '') === 'transfer' ? 0 : 1;
            }
        }
        unset($row);

        return $rows;
    }
}

/** @return array<string, mixed>|null */
function inv_movement_type_by_code(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    inv_movement_type_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT id, code, name_ar, hint_ar, post_auto, post_manual, is_active, affects_gl, sort_order
         FROM inv_movement_type WHERE code = ? LIMIT 1'
    );
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function inv_movement_type_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    inv_movement_type_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT id, code, name_ar, hint_ar, post_auto, post_manual, is_active, affects_gl, sort_order
         FROM inv_movement_type WHERE id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}
