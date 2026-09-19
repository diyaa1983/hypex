<?php
declare(strict_types=1);

/**
 * @param callable(string,string,int):string $buildUrl
 */
function hr_attendance_handle_post(PDO $pdo, array $config, callable $buildUrl, string $listUrl, string $screen): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($buildUrl(
            trim((string) ($_POST['date_from'] ?? date('Y-m-01'))),
            trim((string) ($_POST['date_to'] ?? date('Y-m-d'))),
            (int) ($_POST['filter_employee_id'] ?? 0)
        ));
    }

    $act = (string) ($_POST['_action'] ?? '');
    $dateFrom = trim((string) ($_POST['date_from'] ?? date('Y-m-01')));
    $dateTo = trim((string) ($_POST['date_to'] ?? date('Y-m-d')));
    $filterEmpId = (int) ($_POST['filter_employee_id'] ?? 0);
    $returnUrl = $buildUrl($dateFrom, $dateTo, $filterEmpId);

    try {
        if ($act === 'save_config') {
            if ($screen === 'server') {
                hr_attendance_save_config($pdo, hr_attendance_remote_agent_marker());
                flash_set('success', 'تم حفظ إعدادات مزامنة السيرفر.');
            } else {
                hr_attendance_save_config($pdo, (string) ($_POST['mdb_path'] ?? ''), true);
                flash_set('success', 'تم حفظ مسار قاعدة البصمة.');
            }
            redirect($listUrl);
        }

        if ($act === 'regenerate_sync_token' && $screen === 'server') {
            hr_attendance_sync_token_regenerate($pdo);
            hr_attendance_save_config($pdo, hr_attendance_remote_agent_marker());
            flash_set('success', 'تم إنشاء رمز مزامنة جديد — حدّث zk_sync.local.php على جهاز ZKT.');
            redirect($listUrl);
        }

        if ($act === 'upload_mdb' && $screen === 'local') {
            if (!isset($_FILES['mdb_file']) || !is_array($_FILES['mdb_file'])) {
                throw new RuntimeException('لم يُرفَع أي ملف.');
            }
            $upload = $_FILES['mdb_file'];
            $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('تعذر رفع الملف (رمز ' . $err . ').');
            }
            $tmp = (string) ($upload['tmp_name'] ?? '');
            $orig = strtolower((string) ($upload['name'] ?? ''));
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('ملف الرفع غير صالح.');
            }
            if (!str_ends_with($orig, '.mdb')) {
                throw new RuntimeException('يجب أن يكون الملف att2000.mdb (Access).');
            }
            $dest = hr_attendance_recommended_mdb_path();
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new RuntimeException('تعذر إنشاء مجلد: ' . $destDir);
            }
            if (!@move_uploaded_file($tmp, $dest)) {
                if (!@copy($tmp, $dest)) {
                    throw new RuntimeException('تعذر حفظ الملف على الخادم.');
                }
            }
            @chmod($dest, 0644);
            hr_attendance_save_config($pdo, $dest, true);
            flash_set('success', 'تم رفع att2000.mdb وحفظ المسار. يمكنك «اختبار الاتصال» ثم «مزامنة الآن».');
            redirect($listUrl);
        }

        if ($act === 'test_mdb' && $screen === 'local') {
            $test = hr_attendance_test_mdb(trim((string) ($_POST['mdb_path'] ?? $config['mdb_path'])));
            flash_set($test['ok'] ? 'success' : 'error', $test['message']);
            redirect($listUrl);
        }

        if ($act === 'sync' && $screen === 'local') {
            $result = hr_attendance_sync($pdo, true);
            flash_set('success', $result['message']);
            redirect($returnUrl);
        }

        if ($act === 'mark_all_flags' && $screen === 'local') {
            $mark = hr_attendance_mdb_mark_all_pending_flags($config['mdb_path']);
            flash_set($mark['ok'] ? 'success' : 'error', $mark['message']);
            redirect($returnUrl);
        }

        if ($act === 'auto_map' && $screen === 'main') {
            $n = hr_attendance_auto_map_existing($pdo);
            flash_set('success', $n > 0
                ? 'تم ربط ' . $n . ' سجل — رقم الموظف في النظام = رقم البصمة في Access.'
                : 'لا يوجد ربط تلقائي جديد — تأكد من تطابق رقم الموظف مع رقم البصمة.');
            redirect($returnUrl);
        }

        if ($act === 'map_employee' && $screen === 'main') {
            $empCode = trim((string) ($_POST['emp_code'] ?? ''));
            if ($empCode !== '') {
                hr_attendance_save_manual_map_by_emp_code(
                    $pdo,
                    (int) ($_POST['zk_user_id'] ?? 0),
                    $empCode
                );
            } else {
                hr_attendance_save_manual_map(
                    $pdo,
                    (int) ($_POST['zk_user_id'] ?? 0),
                    (int) ($_POST['employee_id'] ?? 0)
                );
            }
            flash_set('success', 'تم ربط رقم الموظف برقم البصمة.');
            redirect($returnUrl);
        }

        if ($act === 'map_batch' && $screen === 'main') {
            $maps = $_POST['maps'] ?? [];
            if (!is_array($maps)) {
                $maps = [];
            }
            if ($maps === []) {
                $empCodes = $_POST['emp_codes'] ?? [];
                if (is_array($empCodes) && $empCodes !== []) {
                    $result = hr_attendance_save_manual_maps_by_emp_code_batch($pdo, $empCodes);
                } else {
                    throw new RuntimeException('اختر موظفاً ورقم بصمة واحداً على الأقل للربط.');
                }
            } else {
                $result = hr_attendance_save_manual_maps_batch($pdo, $maps);
            }
            $message = $result['saved'] > 0
                ? 'تم حفظ ' . $result['saved'] . ' ربط.'
                : 'لم يُحفظ أي ربط.';
            if ($result['errors'] !== []) {
                $message .= ' ' . implode(' — ', array_slice($result['errors'], 0, 3));
            }
            flash_set($result['saved'] > 0 ? 'success' : 'error', $message);
            redirect($returnUrl);
        }

        if ($act === 'unmap_employee' && $screen === 'main') {
            hr_attendance_delete_map($pdo, (int) ($_POST['zk_user_id'] ?? 0));
            flash_set('success', 'تم إلغاء ربط مستخدم البصمة بالموظف.');
            redirect($returnUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

function hr_attendance_build_screen_url(
    string $route,
    string $dateFrom = '',
    string $dateTo = '',
    int $empId = 0
): string {
    if ($dateFrom === '') {
        $dateFrom = date('Y-m-01');
    }
    if ($dateTo === '') {
        $dateTo = date('Y-m-d');
    }
    $q = 'date_from=' . rawurlencode($dateFrom) . '&date_to=' . rawurlencode($dateTo);
    if ($empId > 0) {
        $q .= '&employee_id=' . $empId;
    }

    return app_url('index.php?r=' . rawurlencode($route) . '&' . $q);
}

/**
 * @param 'main'|'server'|'local' $active
 */
function hr_attendance_render_nav_tabs(string $active): void
{
    $tabs = [
        'main' => ['route' => 'hr_employee_attendance', 'label' => 'بصمات الموظفين'],
        'server' => ['route' => 'hr_attendance_sync_server', 'label' => 'مزامنة السيرفر (ZKT)'],
        'local' => ['route' => 'hr_attendance_sync_local', 'label' => 'مزامنة Windows (محلي)'],
    ];
    echo '<nav class="hr-att-screen-tabs no-print" aria-label="شاشات البصمة">';
    foreach ($tabs as $key => $tab) {
        $class = 'hr-att-screen-tab' . ($key === $active ? ' is-active' : '');
        $href = app_url('index.php?r=' . rawurlencode($tab['route']));
        echo '<a class="' . esc($class) . '" href="' . esc($href) . '">' . esc($tab['label']) . '</a>';
    }
    echo '</nav>';
}
