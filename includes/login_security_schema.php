<?php
declare(strict_types=1);

/**
 * تهيئة جداول/أعمدة أمان الدخول (استعادة كلمة المرور + reCAPTCHA).
 */
function login_security_ensure_schema(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sys_user_password_reset LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sys_user_password_reset (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id     INT UNSIGNED NOT NULL,
                    token_hash  CHAR(64) NOT NULL,
                    expires_at  DATETIME NOT NULL,
                    used_at     DATETIME NULL,
                    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_supr_token_hash (token_hash),
                    KEY idx_supr_user (user_id),
                    KEY idx_supr_expires (expires_at),
                    CONSTRAINT fk_supr_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e2) {
            return false;
        }
    }

    $cols = [
        'login_recaptcha_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'login_recaptcha_site_key' => 'VARCHAR(255) NULL',
        'login_recaptcha_secret_key' => 'VARCHAR(255) NULL',
    ];
    foreach ($cols as $name => $def) {
        try {
            $pdo->query("SELECT $name FROM sys_company_settings LIMIT 1");
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Unknown column') === false) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE sys_company_settings ADD COLUMN $name $def");
            } catch (Throwable $e2) {
                // ignored — عمود موجود أو خطأ غير حرج
            }
        }
    }

    try {
        $pdo->query('SELECT id FROM sys_user_password_reset LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}
