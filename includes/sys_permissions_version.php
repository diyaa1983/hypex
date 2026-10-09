<?php
declare(strict_types=1);

/**
 * رقم إصدار صلاحيات المجموعات — يُزاد عند كل حفظ لصلاحيات/مجموعات المستخدم
 * حتى تعيد كل الجلسات تحميل الصلاحيات فوراً دون انتظار TTL.
 */
const SYS_PERMISSIONS_VERSION_KEY = 'sys_permissions_version';

function sys_permissions_version_get(): string
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $pdo = db();
        acc_coa_meta_ensure_table($pdo);
        $v = acc_coa_meta_get($pdo, SYS_PERMISSIONS_VERSION_KEY);

        return $v !== null && $v !== '' ? (string) $v : '0';
    } catch (Throwable $e) {
        return '0';
    }
}

function sys_permissions_version_bump(): string
{
    $v = (string) (int) (microtime(true) * 1000);
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $pdo = db();
        acc_coa_meta_set($pdo, SYS_PERMISSIONS_VERSION_KEY, $v);
    } catch (Throwable $e) {
        // ignore — الجلسة الحالية ما زالت تُحدَّث محلياً
    }

    return $v;
}
