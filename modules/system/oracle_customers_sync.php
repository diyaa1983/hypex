<?php
declare(strict_types=1);

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_customer_sync.php');

$pdo = db();
oracle_customer_schema_ensure($pdo);

$msg = '';
$err = '';
$connInfo = null;
$candidateTables = [];
$describeCols = [];
$previewRows = [];
$syncResult = null;

$owner = strtoupper(trim((string) ($_POST['owner'] ?? $_GET['owner'] ?? '')));
$table = strtoupper(trim((string) ($_POST['table'] ?? $_GET['table'] ?? '')));
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

$map = [
    'oracle_key' => strtoupper(trim((string) ($_POST['map_oracle_key'] ?? ''))),
    'code' => strtoupper(trim((string) ($_POST['map_code'] ?? ''))),
    'name_ar' => strtoupper(trim((string) ($_POST['map_name'] ?? ''))),
    'phone' => strtoupper(trim((string) ($_POST['map_phone'] ?? ''))),
    'email' => strtoupper(trim((string) ($_POST['map_email'] ?? ''))),
    'tax_number' => strtoupper(trim((string) ($_POST['map_tax'] ?? ''))),
    'address_ar' => strtoupper(trim((string) ($_POST['map_address'] ?? ''))),
    'is_active' => strtoupper(trim((string) ($_POST['map_active'] ?? ''))),
];

if ($action === 'save_config') {
    try {
        $path = oracle_write_local_config([
            'enabled' => isset($_POST['cfg_enabled']),
            'host' => (string) ($_POST['cfg_host'] ?? ''),
            'port' => (int) ($_POST['cfg_port'] ?? 1521),
            'sid' => (string) ($_POST['cfg_sid'] ?? ''),
            'service_name' => (string) ($_POST['cfg_service'] ?? ''),
            'user' => (string) ($_POST['cfg_user'] ?? ''),
            'pass' => (string) ($_POST['cfg_pass'] ?? ''),
            'charset' => (string) ($_POST['cfg_charset'] ?? 'AL32UTF8'),
            'odbc_dsn' => (string) ($_POST['cfg_odbc'] ?? ''),
        ]);
        $msg = "تم حفظ الإعداد بنجاح:\n" . $path
            . "\nالحالة الآن: " . (oracle_is_enabled() ? 'مفعّل' : 'غير مفعّل — راجع الحقول');
        if (!oracle_php_has_oracle_driver()) {
            $msg .= "\nتنبيه: ما زال PHP بلا pdo_oci/oci8 — الاتصال لن ينجح حتى تثبت Instant Client + الامتداد.";
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
} elseif ($action !== '') {
    $connInfo = oracle_connect();
    if (!$connInfo['ok']) {
        $err = (string) $connInfo['message'];
    } else {
        try {
            if ($action === 'test') {
                $msg = 'نجح الاتصال — المشغّل: ' . $connInfo['driver'] . ' — ' . $connInfo['message'];
                $one = oracle_query_all($connInfo, 'SELECT USER AS U, BANNER FROM V$VERSION WHERE ROWNUM = 1');
                if ($one) {
                    $msg .= ' | USER=' . ($one[0]['U'] ?? $one[0]['u'] ?? '')
                        . ' | ' . ($one[0]['BANNER'] ?? $one[0]['banner'] ?? '');
                }
            } elseif ($action === 'discover') {
                $candidateTables = oracle_discover_customer_tables($connInfo);
                $msg = 'عُثر على ' . count($candidateTables) . ' جدول مرشّح للعملاء.';
            } elseif ($action === 'describe' && $owner !== '' && $table !== '') {
                $describeCols = oracle_describe_table($connInfo, $owner, $table);
                $previewRows = oracle_preview_table($connInfo, $owner, $table, 15);
                $msg = 'هيكل ' . $owner . '.' . $table . ' — ' . count($describeCols) . ' عمود.';
            } elseif ($action === 'sync' && $owner !== '' && $table !== '') {
                if ($map['name_ar'] === '' || ($map['oracle_key'] === '' && $map['code'] === '')) {
                    $err = 'اربط على الأقل: الاسم + (oracle_key أو code).';
                } else {
                    if ($map['oracle_key'] === '') {
                        $map['oracle_key'] = $map['code'];
                    }
                    $syncResult = oracle_sync_customers_to_mysql($pdo, $connInfo, $owner, $table, $map);
                    $msg = 'انتهت المزامنة: جديد ' . $syncResult['inserted']
                        . ' | محدّث ' . $syncResult['updated']
                        . ' | متجاوز ' . $syncResult['skipped'];
                    if ($syncResult['errors'] !== []) {
                        $err = implode("\n", array_slice($syncResult['errors'], 0, 15));
                    }
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

// اقتراح تلقائي لأسماء الأعمدة الشائعة
$suggest = static function (array $cols, array $needles): string {
    foreach ($cols as $c) {
        $n = strtoupper((string) ($c['column_name'] ?? ''));
        foreach ($needles as $needle) {
            if (str_contains($n, $needle)) {
                return $n;
            }
        }
    }
    return '';
};
if ($describeCols !== []) {
    if ($map['oracle_key'] === '') {
        $map['oracle_key'] = $suggest($describeCols, ['CUST_ID', 'CUSTOMER_ID', 'CLIENT_ID', 'ID_NO', 'CODE', 'NO']) ?: ($describeCols[0]['column_name'] ?? '');
    }
    if ($map['code'] === '') {
        $map['code'] = $suggest($describeCols, ['CODE', 'NO', 'NUM', 'رقم']);
    }
    if ($map['name_ar'] === '') {
        $map['name_ar'] = $suggest($describeCols, ['NAME', 'NAME_A', 'اسم', 'CNAME']);
    }
    if ($map['phone'] === '') {
        $map['phone'] = $suggest($describeCols, ['PHONE', 'TEL', 'MOBILE', 'هاتف']);
    }
    if ($map['tax_number'] === '') {
        $map['tax_number'] = $suggest($describeCols, ['TAX', 'VAT', 'ضريب']);
    }
    if ($map['address_ar'] === '') {
        $map['address_ar'] = $suggest($describeCols, ['ADDR', 'ADDRESS', 'عنوان']);
    }
}

$cfg = oracle_config();
$drivers = oracle_php_drivers_status();
$cfgEnabled = oracle_is_enabled();
$cfgPath = (string) ($cfg['_path'] ?? app_path('config/oracle.local.php'));
$passPlaceholder = is_file($cfgPath) && trim((string) ($cfg['pass'] ?? '')) !== '';
?>
<div class="card" style="max-width:1100px;">
    <h2 style="margin-top:0;">تكامل Oracle — تجربة العملاء</h2>
    <p class="muted" style="margin-top:0;">
        اتصال تجريبي بـ Oracle واستكشاف جداول العملاء ثم مزامنتها إلى Hypex.
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-success" style="white-space:pre-wrap;"><?= esc($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="alert alert-error" style="white-space:pre-wrap;"><?= esc($err) ?></div>
    <?php endif; ?>

    <div class="alert <?= $cfgEnabled ? 'alert-success' : 'alert-error' ?>" style="white-space:pre-wrap;">
        <strong>1) ملف الإعداد</strong><br>
        المسار: <code><?= esc($cfgPath) ?></code><br>
        الحالة: <?= $cfgEnabled ? 'مفعّل' : 'غير مفعّل' ?><br>
        <?php if (!$cfgEnabled): ?>
            <?= esc(oracle_config_status_message()) ?><br>
            <strong>مهم:</strong> احفظ الإعداد من النموذج أدناه (لا تعتمد على example فقط).
            على السيرفر يكون المسار غالباً: <code>C:\xampp\htdocs\system\config\oracle.local.php</code>
        <?php endif; ?>
    </div>

    <div class="alert <?= oracle_php_has_oracle_driver() ? 'alert-success' : 'alert-error' ?>" style="white-space:pre-wrap;">
        <strong>2) مشغّلات PHP على هذا الجهاز</strong><br>
        pdo_oci: <?= !empty($drivers['pdo_oci']) ? 'موجود' : 'غير محمّل' ?><br>
        oci8: <?= !empty($drivers['oci8']) ? 'موجود' : 'غير محمّل' ?><br>
        pdo_odbc: <?= !empty($drivers['pdo_odbc']) ? 'موجود' : 'غير محمّل' ?>
        <?php if (!oracle_php_has_oracle_driver()): ?>
            <br><br>
            <strong>هذا سبب فشل «اختبار الاتصال» بعد تفعيل الملف.</strong><br>
            ثبّت Oracle Instant Client + فعّل <code>pdo_oci</code> أو <code>oci8</code> في php.ini لـ XAMPP ثم أعد تشغيل Apache.
        <?php endif; ?>
    </div>

    <h3>إعداد الاتصال (يحفظ oracle.local.php هنا)</h3>
    <form method="post" class="report-sales-filters" style="margin-bottom:1.25rem;">
        <input type="hidden" name="action" value="save_config">
        <div class="form-row" style="flex-wrap:wrap;gap:0.75rem;">
            <label class="field" style="flex:0 0 auto;">
                <span class="field-label">
                    <input type="checkbox" name="cfg_enabled" value="1" <?= !empty($cfg['enabled']) || !is_file($cfgPath) ? 'checked' : '' ?>>
                    مفعّل
                </span>
            </label>
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">Host</span>
                <input class="input" name="cfg_host" value="<?= esc((string) ($cfg['host'] ?? '192.168.100.2')) ?>" required>
            </label>
            <label class="field" style="flex:0 1 6rem;">
                <span class="field-label">Port</span>
                <input class="input" name="cfg_port" type="number" value="<?= esc((string) ($cfg['port'] ?? 1521)) ?>">
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">SID</span>
                <input class="input" name="cfg_sid" value="<?= esc((string) ($cfg['sid'] ?? 'taqwa')) ?>">
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">Service Name (اختياري)</span>
                <input class="input" name="cfg_service" value="<?= esc((string) ($cfg['service_name'] ?? '')) ?>">
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">User</span>
                <input class="input" name="cfg_user" value="<?= esc((string) ($cfg['user'] ?? '')) ?>" required>
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">Password<?= $passPlaceholder ? ' (اتركه فارغاً للإبقاء)' : '' ?></span>
                <input class="input" type="password" name="cfg_pass" value="" autocomplete="new-password" <?= $passPlaceholder ? '' : 'required' ?>>
            </label>
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">ODBC DSN (اختياري)</span>
                <input class="input" name="cfg_odbc" value="<?= esc((string) ($cfg['odbc_dsn'] ?? '')) ?>" placeholder="إن وُجد Oracle ODBC">
            </label>
            <input type="hidden" name="cfg_charset" value="<?= esc((string) ($cfg['charset'] ?? 'AL32UTF8')) ?>">
        </div>
        <div style="margin-top:0.75rem;">
            <button class="btn btn-primary" type="submit">حفظ الإعداد على هذا السيرفر</button>
        </div>
    </form>

    <div class="form-row" style="gap:0.5rem;flex-wrap:wrap;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="test">
            <button class="btn btn-primary" type="submit">1) اختبار الاتصال</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="discover">
            <button class="btn btn-ghost" type="submit">2) اكتشاف جداول العملاء</button>
        </form>
    </div>

    <p class="muted" style="font-size:0.9rem;">
        Host حالياً: <?= esc((string) ($cfg['host'] ?? '')) ?>
        :<?= esc((string) ($cfg['port'] ?? '')) ?>
        | SID: <?= esc((string) ($cfg['sid'] ?? '')) ?>
        | User: <?= esc((string) ($cfg['user'] ?? '')) ?>
    </p>

    <?php if ($candidateTables !== []): ?>
        <h3>جداول مرشّحة</h3>
        <div class="report-sales-table-wrap">
            <table class="report-sales-table">
                <thead>
                <tr><th>Schema</th><th>الجدول</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($candidateTables as $t): ?>
                    <tr>
                        <td><code><?= esc($t['owner']) ?></code></td>
                        <td><code><?= esc($t['table_name']) ?></code></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="describe">
                                <input type="hidden" name="owner" value="<?= esc($t['owner']) ?>">
                                <input type="hidden" name="table" value="<?= esc($t['table_name']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">عرض الأعمدة + عينة</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($owner !== '' && $table !== ''): ?>
        <h3>تعيين الأعمدة — <?= esc($owner . '.' . $table) ?></h3>
        <form method="post" class="report-sales-filters">
            <input type="hidden" name="action" value="sync">
            <input type="hidden" name="owner" value="<?= esc($owner) ?>">
            <input type="hidden" name="table" value="<?= esc($table) ?>">
            <div class="form-row">
                <?php
                $fields = [
                    'map_oracle_key' => ['مفتاح Oracle *', $map['oracle_key']],
                    'map_code' => ['رمز العميل', $map['code']],
                    'map_name' => ['الاسم *', $map['name_ar']],
                    'map_phone' => ['الهاتف', $map['phone']],
                    'map_email' => ['البريد', $map['email']],
                    'map_tax' => ['الرقم الضريبي', $map['tax_number']],
                    'map_address' => ['العنوان', $map['address_ar']],
                    'map_active' => ['الحالة', $map['is_active']],
                ];
                foreach ($fields as $fname => [$label, $val]):
                    ?>
                    <label class="field" style="flex:1 1 12rem;">
                        <span class="field-label"><?= esc($label) ?></span>
                        <select class="input" name="<?= esc($fname) ?>">
                            <option value="">—</option>
                            <?php foreach ($describeCols as $c): ?>
                                <?php $cn = (string) $c['column_name']; ?>
                                <option value="<?= esc($cn) ?>" <?= strtoupper($val) === strtoupper($cn) ? 'selected' : '' ?>>
                                    <?= esc($cn . ' (' . ($c['data_type'] ?? '') . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:0.75rem;">
                <button class="btn btn-primary" type="submit">3) مزامنة العملاء إلى Hypex</button>
                <button class="btn btn-ghost" type="submit" name="action" value="describe" formnovalidate>تحديث العينة</button>
            </div>
        </form>

        <?php if ($describeCols !== []): ?>
            <h4>الأعمدة</h4>
            <p class="muted"><?php foreach ($describeCols as $c) {
                echo esc($c['column_name'] . ':' . $c['data_type']) . ' &nbsp; ';
            } ?></p>
        <?php endif; ?>

        <?php if ($previewRows !== []): ?>
            <h4>عينة (أول <?= count($previewRows) ?> صف)</h4>
            <div class="report-sales-table-wrap" style="overflow:auto;max-height:320px;">
                <table class="report-sales-table" style="font-size:0.85rem;">
                    <thead>
                    <tr>
                        <?php foreach (array_keys($previewRows[0]) as $col): ?>
                            <th><?= esc((string) $col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewRows as $pr): ?>
                        <tr>
                            <?php foreach ($pr as $cell): ?>
                                <td><?= esc(is_scalar($cell) || $cell === null ? (string) $cell : json_encode($cell, JSON_UNESCAPED_UNICODE)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (is_array($syncResult)): ?>
        <p>
            النتيجة:
            مدرج <?= (int) $syncResult['inserted'] ?> —
            محدّث <?= (int) $syncResult['updated'] ?> —
            متجاوز <?= (int) $syncResult['skipped'] ?>
        </p>
        <p><a class="btn btn-ghost" href="<?= esc(app_url('index.php?r=customers')) ?>">فتح شاشة العملاء</a></p>
    <?php endif; ?>
</div>
