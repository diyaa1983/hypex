<?php
declare(strict_types=1);

require_permission('settings');

require_once app_path('includes/mobile_gps_settings.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
$settings = mobile_gps_settings($pdo);
$googleMapsKey = mobile_gps_settings_google_maps_key($pdo);
$mapProvider = mobile_gps_settings_map_provider($pdo);
if ($googleMapsKey === '' && defined('APP_GOOGLE_MAPS_API_KEY')) {
    $googleMapsKey = trim((string) APP_GOOGLE_MAPS_API_KEY);
}
$exitUrl = nav_exit_url($activeRoute ?? 'gps_tracking_settings');
$msg = '';
$msgType = '';

$intervalOptions = [10, 15, 30, 60, 120, 300];
$distanceOptions = [0, 15, 30, 50, 100];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        try {
            mobile_gps_settings_save($pdo, [
                'auto_enable' => $_POST['gps_mobile_auto_enable'] ?? null,
                'interval_sec' => $_POST['gps_mobile_interval_sec'] ?? 10,
                'min_distance_m' => $_POST['gps_mobile_min_distance_m'] ?? 0,
                'user_can_disable' => $_POST['gps_mobile_user_can_disable'] ?? null,
                'google_maps_api_key' => $_POST['gps_google_maps_api_key'] ?? '',
                'map_provider' => $_POST['gps_map_provider'] ?? 'esri',
            ]);
            $settings = mobile_gps_settings($pdo);
            $googleMapsKey = mobile_gps_settings_google_maps_key($pdo);
            $mapProvider = mobile_gps_settings_map_provider($pdo);
            $msg = 'تم حفظ إعدادات تتبّع موقع تطبيق الهاتف.';
            $msgType = 'success';
        } catch (Throwable $e) {
            error_log('gps_tracking_settings save: ' . $e->getMessage());
            $msg = 'تعذر حفظ الإعدادات.';
            $msgType = 'error';
        }
    }
}
?>
<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h1 class="h5 mb-1">إعدادات تتبّع موقع الهاتف</h1>
            <p class="text-muted small mb-0">
                تُطبَّق تلقائياً على جميع مستخدمي تطبيق الهاتف. المستخدم العادي لا يغيّرها من التطبيق.
            </p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= esc($exitUrl) ?>">خروج</a>
    </div>
    <div class="card-body">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'danger' ?>"><?= esc($msg) ?></div>
        <?php endif; ?>

        <?php if (!$settings['enabled']): ?>
            <div class="alert alert-warning">
                تتبّع GPS معطّل عالمياً في إعدادات السيرفر (<code>APP_GPS_ENABLED</code>).
                لن يعمل التطبيق حتى يُفعَّل من ملف الإعدادات.
            </div>
        <?php endif; ?>

        <form method="post" class="row g-3" style="max-width: 720px;">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="gps_mobile_auto_enable" value="1" id="gps_auto"
                        <?= $settings['auto_enable'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="gps_auto">
                        <strong>تفعيل التتبّع تلقائياً عند تسجيل الدخول</strong>
                        <div class="text-muted small">
                            يبدأ تتبّع الموقع في الخلفية فور دخول المستخدم لتطبيق الهاتف دون الحاجة لتشغيله يدوياً.
                        </div>
                    </label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="gps_interval">مدة إرسال الموقع</label>
                <select class="form-select" name="gps_mobile_interval_sec" id="gps_interval">
                    <?php foreach ($intervalOptions as $sec): ?>
                        <option value="<?= $sec ?>" <?= $settings['interval_sec'] === $sec ? 'selected' : '' ?>>
                            <?= esc(mobile_gps_settings_interval_label($sec)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="gps_distance">أقل مسافة للإرسال</label>
                <select class="form-select" name="gps_mobile_min_distance_m" id="gps_distance">
                    <?php foreach ($distanceOptions as $m): ?>
                        <option value="<?= $m ?>" <?= $settings['min_distance_m'] === $m ? 'selected' : '' ?>>
                            <?= esc(mobile_gps_settings_distance_label($m)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">يُرسل نبضة دورية حتى بدون حركة وفق المدة أعلاه.</div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="gps_mobile_user_can_disable" value="1" id="gps_can_disable"
                        <?= $settings['user_can_disable'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="gps_can_disable">
                        السماح للمستخدم بإيقاف التتبّع من التطبيق
                        <div class="text-muted small">
                            افتراضياً: المستخدم العادي لا يرى زر الإيقاف — التتبّع إلزامي من النظام.
                        </div>
                    </label>
                </div>
            </div>

            <div class="col-12">
                <hr class="my-2">
                <h2 class="h6 mb-2">الخريطة</h2>
                <label class="form-label" for="gps_map_provider">نوع الخريطة</label>
                <select class="form-select" id="gps_map_provider" name="gps_map_provider" style="max-width: 420px;">
                    <option value="esri" <?= $mapProvider === 'esri' ? 'selected' : '' ?>>
                        Esri + Carto — مجاني (Esri للتصغير، Carto عند التكبير)
                    </option>
                    <option value="carto" <?= $mapProvider === 'carto' ? 'selected' : '' ?>>
                        Carto Voyager — مجاني
                    </option>
                    <option value="google" <?= $mapProvider === 'google' ? 'selected' : '' ?>>
                        Google Maps — يحتاج مفتاح وفوترة
                    </option>
                </select>
                <div class="form-text">
                    الافتراضي <strong>Esri</strong>: مجاني 100% بدون بطاقة وبدون علامة «For development purposes only».
                </div>
            </div>

            <div class="col-12">
                <h2 class="h6 mb-2">خرائط Google (اختياري)</h2>
                <label class="form-label" for="gps_google_key">مفتاح Google Maps API</label>
                <input type="password" class="form-control" id="gps_google_key" name="gps_google_maps_api_key"
                    value="<?= esc($googleMapsKey) ?>" autocomplete="off"
                    placeholder="AIzaSy...">
                <div class="form-text">
                    مطلوب فقط إذا اخترت <strong>Google Maps</strong> أعلاه.
                    بدون فوترة مكتملة تظهر خريطة باهتة بعلامة مائية.
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
            </div>
        </form>
    </div>
</div>
