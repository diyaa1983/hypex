<?php
declare(strict_types=1);

require_once app_path('includes/db.php');

function hr_employee_ensure_schema(PDO $pdo): void
{
    $tableExists = false;
    try {
        $pdo->query('SELECT id FROM hr_employee LIMIT 1');
        $tableExists = true;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }
    if ($tableExists) {
        hr_employee_ensure_link_columns($pdo);
        return;
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/061_hr_employees.sql');
    } catch (Throwable $e) {
        // محاولة احتياطية: إنشاء الجداول مباشرة
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_employee (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    emp_code VARCHAR(40) NULL,
                    name_ar VARCHAR(160) NOT NULL,
                    national_id VARCHAR(40) NULL,
                    phone VARCHAR(40) NULL,
                    email VARCHAR(160) NULL,
                    job_title VARCHAR(120) NULL,
                    department VARCHAR(120) NULL,
                    hire_date DATE NULL,
                    base_salary DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    allowances DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    social_security_no VARCHAR(60) NULL,
                    bank_name VARCHAR(120) NULL,
                    bank_account VARCHAR(60) NULL,
                    notes TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_employee_code (emp_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
    hr_employee_ensure_link_columns($pdo);
}

/**
 * إضافة آمنة لأعمدة الربط (department_id, job_title_id, gender)
 * — تُتجاهَل الأعمدة الموجودة سلفاً.
 */
function hr_employee_ensure_link_columns(PDO $pdo): void
{
    $cols = [];
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM hr_employee')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $cols[strtolower((string) ($r['Field'] ?? ''))] = true;
        }
    } catch (Throwable $e) {
        return;
    }

    if (!isset($cols['department_id'])) {
        try {
            $pdo->exec('ALTER TABLE hr_employee ADD COLUMN department_id INT UNSIGNED NULL');
            $pdo->exec('ALTER TABLE hr_employee ADD KEY idx_hr_employee_dept_id (department_id)');
        } catch (Throwable $e) {
            // ignored
        }
    }
    if (!isset($cols['job_title_id'])) {
        try {
            $pdo->exec('ALTER TABLE hr_employee ADD COLUMN job_title_id INT UNSIGNED NULL');
            $pdo->exec('ALTER TABLE hr_employee ADD KEY idx_hr_employee_jt_id (job_title_id)');
        } catch (Throwable $e) {
            // ignored
        }
    }
    if (!isset($cols['gender'])) {
        try {
            $pdo->exec("ALTER TABLE hr_employee ADD COLUMN gender VARCHAR(10) NULL");
        } catch (Throwable $e) {
            // ignored
        }
    }
    if (!isset($cols['subject_to_social_security'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/097_hr_employee_ss_subject.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec(
                    'ALTER TABLE hr_employee ADD COLUMN subject_to_social_security TINYINT(1) NOT NULL DEFAULT 0'
                );
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['nationality_id'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/101_hr_employee_nationality_id.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ADD COLUMN nationality_id INT UNSIGNED NULL AFTER gender');
                $pdo->exec('ALTER TABLE hr_employee ADD KEY idx_hr_employee_nat_id (nationality_id)');
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['salary_bank_id'])) {
        hr_salary_bank_ensure_schema($pdo);
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/093_hr_employee_salary_bank.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ADD COLUMN salary_bank_id INT UNSIGNED NULL');
                $pdo->exec('ALTER TABLE hr_employee ADD KEY idx_hr_employee_salary_bank (salary_bank_id)');
            } catch (Throwable $e2) {
                // ignored
            }
            try {
                $pdo->exec(
                    'ALTER TABLE hr_employee ADD CONSTRAINT fk_hr_employee_salary_bank
                     FOREIGN KEY (salary_bank_id) REFERENCES hr_salary_bank(id) ON DELETE SET NULL'
                );
            } catch (Throwable $e3) {
                // ignored
            }
        }
    }
    if (!isset($cols['resignation_date'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/103_hr_employee_resignation.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ADD COLUMN resignation_date DATE NULL AFTER hire_date');
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['is_resigned_posted'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/103_hr_employee_resignation.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ADD COLUMN is_resigned_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['is_married'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/106_hr_employee_marital_status.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ADD COLUMN is_married TINYINT(1) NOT NULL DEFAULT 0 AFTER gender');
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['subject_to_income_tax'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/107_hr_income_tax.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec(
                    'ALTER TABLE hr_employee ADD COLUMN subject_to_income_tax TINYINT(1) NOT NULL DEFAULT 0
                     AFTER subject_to_social_security'
                );
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['address_ar'])) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/115_hr_employee_address.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ADD COLUMN address_ar VARCHAR(500) NULL AFTER email');
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
    if (!isset($cols['address_city'])) {
        try {
            $pdo->exec('ALTER TABLE hr_employee ADD COLUMN address_city VARCHAR(120) NULL AFTER address_ar');
        } catch (Throwable $e) {
            // ignored
        }
    }
    if (!isset($cols['address_district'])) {
        try {
            $pdo->exec('ALTER TABLE hr_employee ADD COLUMN address_district VARCHAR(120) NULL AFTER address_city');
        } catch (Throwable $e) {
            // ignored
        }
    }
    hr_employee_ensure_name_part_columns($pdo);
}

/**
 * @return array{first:string, father:string, grandfather:string, family:string}
 */
function hr_employee_name_parts_split(string $fullName): array
{
    $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $count = count($parts);

    return [
        'first' => (string) ($parts[0] ?? ''),
        'father' => (string) ($parts[1] ?? ''),
        'grandfather' => (string) ($parts[2] ?? ''),
        'family' => $count > 3 ? implode(' ', array_slice($parts, 3)) : (string) ($parts[3] ?? ''),
    ];
}

function hr_employee_build_full_name(string $first, string $father, string $grandfather, string $family): string
{
    $chunks = [];
    foreach ([$first, $father, $grandfather, $family] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $chunks[] = $part;
        }
    }

    return implode(' ', $chunks);
}

/**
 * @return array{first:string, father:string, grandfather:string, family:string}
 */
function hr_employee_name_parts_from_row(array $row): array
{
    $first = trim((string) ($row['name_first'] ?? ''));
    $father = trim((string) ($row['name_father'] ?? ''));
    $grandfather = trim((string) ($row['name_grandfather'] ?? ''));
    $family = trim((string) ($row['name_family'] ?? ''));

    if ($first !== '' || $father !== '' || $grandfather !== '' || $family !== '') {
        return [
            'first' => $first,
            'father' => $father,
            'grandfather' => $grandfather,
            'family' => $family,
        ];
    }

    return hr_employee_name_parts_split((string) ($row['name_ar'] ?? ''));
}

function hr_employee_ensure_name_part_columns(PDO $pdo): void
{
    $cols = [];
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM hr_employee')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $cols[strtolower((string) ($r['Field'] ?? ''))] = true;
        }
    } catch (Throwable $e) {
        return;
    }

    $added = false;
    foreach ([
        'name_first' => "ADD COLUMN name_first VARCHAR(80) NOT NULL DEFAULT '' AFTER name_ar",
        'name_father' => "ADD COLUMN name_father VARCHAR(80) NOT NULL DEFAULT '' AFTER name_first",
        'name_grandfather' => "ADD COLUMN name_grandfather VARCHAR(80) NOT NULL DEFAULT '' AFTER name_father",
        'name_family' => "ADD COLUMN name_family VARCHAR(80) NOT NULL DEFAULT '' AFTER name_grandfather",
    ] as $col => $ddl) {
        if (isset($cols[$col])) {
            continue;
        }
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/140_hr_employee_name_parts.sql');
            $added = true;
            break;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE hr_employee ' . $ddl);
                $added = true;
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }

    if ($added) {
        hr_employee_backfill_name_parts($pdo);
    }
}

function hr_employee_backfill_name_parts(PDO $pdo): void
{
    try {
        $rows = $pdo->query(
            "SELECT id, name_ar FROM hr_employee
             WHERE TRIM(name_ar) <> ''
               AND TRIM(COALESCE(name_first, '')) = ''
               AND TRIM(COALESCE(name_father, '')) = ''
               AND TRIM(COALESCE(name_grandfather, '')) = ''
               AND TRIM(COALESCE(name_family, '')) = ''"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }

    if ($rows === []) {
        return;
    }

    $st = $pdo->prepare(
        'UPDATE hr_employee SET name_first = ?, name_father = ?, name_grandfather = ?, name_family = ? WHERE id = ?'
    );
    foreach ($rows as $row) {
        $parts = hr_employee_name_parts_split((string) ($row['name_ar'] ?? ''));
        $st->execute([
            $parts['first'],
            $parts['father'],
            $parts['grandfather'],
            $parts['family'],
            (int) ($row['id'] ?? 0),
        ]);
    }
}

function hr_employee_is_resignation_posted(array $row): bool
{
    return (int) ($row['is_resigned_posted'] ?? 0) === 1;
}

function hr_employee_marital_status_label(array $row): string
{
    return (int) ($row['is_married'] ?? 0) === 1 ? 'متزوج' : 'أعزب';
}

function hr_employee_assert_editable(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        throw new RuntimeException('احفظ الموظف أولاً ثم نفّذ الترحيل.');
    }
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);
    $st = $pdo->prepare('SELECT is_resigned_posted FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    if ((int) ($st->fetchColumn() ?: 0) === 1) {
        throw new RuntimeException('بطاقة موظف مستقيل مرحّلة — لا يمكن التعديل إلا بعد فك الترحيل.');
    }
}

function hr_employee_usage_count(PDO $pdo, string $table, int $employeeId): int
{
    static $allowed = [
        'hr_salary',
        'hr_social_security',
        'hr_employee_advance',
        'hr_employee_salary_line',
        'hr_employee_monthly_payroll_line',
    ];
    if ($employeeId < 1 || !in_array($table, $allowed, true)) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE employee_id = ?');
        $st->execute([$employeeId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{can_delete:bool, message:string}
 */
function hr_employee_delete_check(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1) {
        return ['can_delete' => false, 'message' => 'معرّف الموظف غير صالح.'];
    }

    hr_employee_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT id FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    if (!$st->fetchColumn()) {
        return ['can_delete' => false, 'message' => 'الموظف غير موجود.'];
    }

    $blocks = [];
    $salaryCount = hr_employee_usage_count($pdo, 'hr_salary', $employeeId);
    if ($salaryCount > 0) {
        $blocks[] = 'رواتب (' . $salaryCount . ')';
    }
    $ssCount = hr_employee_usage_count($pdo, 'hr_social_security', $employeeId);
    if ($ssCount > 0) {
        $blocks[] = 'ضمان اجتماعي (' . $ssCount . ')';
    }
    $advanceCount = hr_employee_usage_count($pdo, 'hr_employee_advance', $employeeId);
    if ($advanceCount > 0) {
        $blocks[] = 'سلف (' . $advanceCount . ')';
    }
    $salaryLineCount = hr_employee_usage_count($pdo, 'hr_employee_salary_line', $employeeId);
    if ($salaryLineCount > 0) {
        $blocks[] = 'إعدادات راتب وعلاوات (' . $salaryLineCount . ')';
    }
    $monthlyLineCount = hr_employee_usage_count($pdo, 'hr_employee_monthly_payroll_line', $employeeId);
    if ($monthlyLineCount > 0) {
        $blocks[] = 'علاوات/اقتطاعات شهرية (' . $monthlyLineCount . ')';
    }

    if ($blocks !== []) {
        return [
            'can_delete' => false,
            'message' => 'لا يمكن حذف الموظف: يوجد عليه حركات في النظام — ' . implode('، ', $blocks) . '.',
        ];
    }

    return ['can_delete' => true, 'message' => ''];
}

function hr_employee_assert_deletable(PDO $pdo, int $employeeId): void
{
    $chk = hr_employee_delete_check($pdo, $employeeId);
    if (!$chk['can_delete']) {
        throw new RuntimeException((string) ($chk['message'] ?: 'لا يمكن حذف الموظف.'));
    }
}

/** @return array<int, array<string, mixed>> */
function hr_employee_active_list(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT id, emp_code, name_ar, job_title, department, base_salary, allowances, phone
             FROM hr_employee WHERE is_active = 1 ORDER BY name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function hr_employee_list_order_sql(): string
{
    return "ORDER BY CASE WHEN emp_code REGEXP '^[0-9]+$' THEN CAST(emp_code AS UNSIGNED) ELSE 999999999 END ASC,
            emp_code ASC, id ASC";
}

/** @return array<int, array{id:int, emp_code:string, name_ar:string, is_active:int}> */
function hr_employee_picker_list(PDO $pdo): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);

    $extraCols = '';
    try {
        $colRows = $pdo->query('SHOW COLUMNS FROM hr_employee')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colSet = [];
        foreach ($colRows as $colRow) {
            $colSet[strtolower((string) ($colRow['Field'] ?? ''))] = true;
        }
        if (isset($colSet['resignation_date'])) {
            $extraCols .= ', resignation_date';
        }
        if (isset($colSet['is_resigned_posted'])) {
            $extraCols .= ', is_resigned_posted';
        }
    } catch (Throwable $e) {
        $extraCols = '';
    }

    $sql = 'SELECT id, emp_code, name_ar, is_active' . $extraCols . ' FROM hr_employee ' . hr_employee_list_order_sql();
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query(
                'SELECT id, emp_code, name_ar, is_active FROM hr_employee ORDER BY id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            return $rows ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/**
 * تنقّل سابق/تالي حسب ترتيب الرقم الوظيفي (يشمل الموظف id=1).
 *
 * @return array{prev:int, next:int, position:int, total:int}
 */
function hr_employee_browse_nav(PDO $pdo, int $currentId): array
{
    hr_employee_ensure_schema($pdo);
    $ids = [];
    try {
        $ids = array_map(
            'intval',
            $pdo->query('SELECT id FROM hr_employee ' . hr_employee_list_order_sql())->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    } catch (Throwable $e) {
        return ['prev' => 0, 'next' => 0, 'first' => 0, 'last' => 0, 'position' => 0, 'total' => 0];
    }

    $total = count($ids);
    if ($total === 0) {
        return ['prev' => 0, 'next' => 0, 'first' => 0, 'last' => 0, 'position' => 0, 'total' => 0];
    }

    if ($currentId < 1) {
        return [
            'prev' => 0,
            'next' => $ids[0],
            'first' => $ids[0],
            'last' => $ids[$total - 1],
            'position' => 0,
            'total' => $total,
        ];
    }

    $pos = array_search($currentId, $ids, true);
    if ($pos === false) {
        return [
            'prev' => 0,
            'next' => $ids[0],
            'first' => $ids[0],
            'last' => $ids[$total - 1],
            'position' => 0,
            'total' => $total,
        ];
    }

    return [
        'prev' => $pos > 0 ? $ids[$pos - 1] : 0,
        'next' => $pos < $total - 1 ? $ids[$pos + 1] : 0,
        'first' => $ids[0],
        'last' => $ids[$total - 1],
        'position' => $pos + 1,
        'total' => $total,
    ];
}

/** بحث بالرقم الوظيفي أو بمعرّف السجل (id). */
function hr_employee_resolve_lookup(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    hr_employee_ensure_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM hr_employee WHERE emp_code = ? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        if (ctype_digit($token)) {
            $st = $pdo->prepare('SELECT * FROM hr_employee WHERE id = ? LIMIT 1');
            $st->execute([(int) $token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function hr_nationality_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);
    try {
        $pdo->query('SELECT id FROM hr_nationality LIMIT 1');
        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/102_hr_nationalities.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_nationality (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    nat_code VARCHAR(40) NULL,
                    name_ar VARCHAR(160) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_nat_code (nat_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
    try {
        $pdo->exec(
            'ALTER TABLE hr_employee
             ADD CONSTRAINT fk_hr_employee_nat FOREIGN KEY (nationality_id)
             REFERENCES hr_nationality(id) ON DELETE SET NULL'
        );
    } catch (Throwable $eFk) {
        // ignored
    }
}

/** الرقم التسلسلي التالي لجنسية جديدة (أرقام فقط). */
function hr_nationality_next_code(PDO $pdo): string
{
    hr_nationality_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $maxNum = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(nat_code AS UNSIGNED)), 0) FROM hr_nationality
             WHERE nat_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_nationality')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

/** @return array<int, array<string, mixed>> */
function hr_nationality_active_list(PDO $pdo): array
{
    hr_nationality_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, nat_code, name_ar FROM hr_nationality
             WHERE is_active = 1 ORDER BY name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function hr_department_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);
    try {
        $pdo->query('SELECT id FROM hr_department LIMIT 1');
        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/062_hr_departments.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_department (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    dept_code VARCHAR(40) NULL,
                    name_ar VARCHAR(160) NOT NULL,
                    manager_id INT UNSIGNED NULL,
                    description TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_dept_code (dept_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

/** الرقم التسلسلي التالي لقسم جديد (أرقام فقط). */
function hr_department_next_code(PDO $pdo): string
{
    hr_department_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $maxNum = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(dept_code AS UNSIGNED)), 0) FROM hr_department
             WHERE dept_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_department')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

/** @return array<int, array<string, mixed>> */
function hr_department_active_list(PDO $pdo): array
{
    hr_department_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, dept_code, name_ar FROM hr_department
             WHERE is_active = 1 ORDER BY name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function hr_job_title_ensure_schema(PDO $pdo): void
{
    hr_department_ensure_schema($pdo);
    try {
        $pdo->query('SELECT id FROM hr_job_title LIMIT 1');
        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/063_hr_job_titles.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_job_title (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    title_code VARCHAR(40) NULL,
                    name_ar VARCHAR(160) NOT NULL,
                    department_id INT UNSIGNED NULL,
                    description TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_job_title_code (title_code),
                    KEY idx_hr_job_title_dept (department_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

/** الرقم التسلسلي التالي لمسمى وظيفي جديد (أرقام فقط). */
function hr_job_title_next_code(PDO $pdo): string
{
    hr_job_title_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $maxNum = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(title_code AS UNSIGNED)), 0) FROM hr_job_title
             WHERE title_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_job_title')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

function hr_salary_bank_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT id FROM hr_salary_bank LIMIT 1');
        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/092_hr_salary_banks.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_salary_bank (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    bank_code VARCHAR(40) NULL,
                    name_ar VARCHAR(160) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_salary_bank_code (bank_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

/** الرقم التسلسلي التالي لبنك جديد (أرقام فقط). */
function hr_salary_bank_next_code(PDO $pdo): string
{
    hr_salary_bank_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $maxNum = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(bank_code AS UNSIGNED)), 0) FROM hr_salary_bank
             WHERE bank_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_salary_bank')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

/** @return array<int, array<string, mixed>> */
function hr_salary_bank_active_list(PDO $pdo): array
{
    hr_salary_bank_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, bank_code, name_ar FROM hr_salary_bank
             WHERE is_active = 1 ORDER BY name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function hr_payroll_component_ensure_schema(PDO $pdo): void
{
    $exists = true;
    try {
        $pdo->query('SELECT id FROM hr_payroll_component LIMIT 1');
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
        $exists = false;
    }

    if (!$exists) {
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/065_hr_payroll_components.sql');
        } catch (Throwable $e) {
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS hr_payroll_component (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        comp_code VARCHAR(40) NULL,
                        name_ar VARCHAR(160) NOT NULL,
                        comp_type ENUM('allowance', 'deduction') NOT NULL DEFAULT 'allowance',
                        is_percent TINYINT(1) NOT NULL DEFAULT 0,
                        default_amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                        description TEXT NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uk_hr_payroll_comp_type_code (comp_type, comp_code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }

    hr_payroll_component_migrate_indexes($pdo);
}

/** ترقية فهرس الرقم ليكون تسلسلياً منفصلاً لكل نوع (علاوة / اقتطاع). */
function hr_payroll_component_migrate_indexes(PDO $pdo): void
{
    try {
        $pdo->query('SELECT id FROM hr_payroll_component LIMIT 1');
    } catch (Throwable $e) {
        return;
    }

    try {
        $st = $pdo->query(
            "SHOW INDEX FROM hr_payroll_component WHERE Key_name = 'uk_hr_payroll_comp_code'"
        );
        if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE hr_payroll_component DROP INDEX uk_hr_payroll_comp_code');
        }
    } catch (Throwable $e) {
        // ignored
    }

    try {
        $st = $pdo->query(
            "SHOW INDEX FROM hr_payroll_component WHERE Key_name = 'uk_hr_payroll_comp_type_code'"
        );
        if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec(
                'ALTER TABLE hr_payroll_component
                 ADD UNIQUE KEY uk_hr_payroll_comp_type_code (comp_type, comp_code)'
            );
        }
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_payroll_component_next_code(PDO $pdo, string $compType): string
{
    if (!in_array($compType, ['allowance', 'deduction'], true)) {
        $compType = 'allowance';
    }

    hr_payroll_component_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(comp_code AS UNSIGNED)), 0) FROM hr_payroll_component
             WHERE comp_type = ? AND comp_code REGEXP '^[0-9]+$'"
        );
        $st->execute([$compType]);
        $maxNum = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_payroll_component')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

/** @return array<int, array<string, mixed>> */
function hr_job_title_active_list(PDO $pdo): array
{
    hr_job_title_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT jt.id, jt.title_code, jt.name_ar, jt.department_id, d.name_ar AS department_name
             FROM hr_job_title jt
             LEFT JOIN hr_department d ON d.id = jt.department_id
             WHERE jt.is_active = 1
             ORDER BY jt.name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
