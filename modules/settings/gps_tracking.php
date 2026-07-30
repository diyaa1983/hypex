<?php
declare(strict_types=1);

require_permission('settings');

require_once app_path('includes/mobile_gps_settings.php');

$pdo = db();
$settings = mobile_gps_settings($pdo);
$googleMapsKey = mobile_gps_settings_google_maps_key($pdo);
$mapProvider = mobile_gps_settings_map_provider($pdo);
$mapEngine = mobile_gps_settings_map_engine($pdo);
if ($googleMapsKey === '' && defined('APP_GOOGLE_MAPS_API_KEY')) {
    $googleMapsKey = trim((string) APP_GOOGLE_MAPS_API_KEY);
}
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
                'map_engine' => $_POST['gps_map_engine'] ?? 'leaflet',
            ]);
            $settings = mobile_gps_settings($pdo);
            $googleMapsKey = mobile_gps_settings_google_maps_key($pdo);
            $mapProvider = mobile_gps_settings_map_provider($pdo);
            $mapEngine = mobile_gps_settings_map_engine($pdo);
            $msg = 'تم حفظ إعدادات تتبّع موقع تطبيق الهاتف.';
            $msgType = 'success';
        } catch (Throwable $e) {
            error_log('gps_tracking_settings save: ' . $e->getMessage());
            $msg = 'تعذر حفظ الإعدادات.';
            $msgType = 'error';
        }
    }
}

$cssPath = app_path('assets/css/settings-oracle12.css');
$cssUrl = app_url('assets/css/settings-oracle12.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<?php if ($msg !== ''): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> settings-ora-flash"><?= esc($msg) ?></div>
<?php endif; ?>

<?php if (!$settings['enabled']): ?>
    <div class="settings-ora-info">
        تتبّع GPS معطّل عالمياً في إعدادات السيرفر (<code>APP_GPS_ENABLED</code>).
        لن يعمل التطبيق حتى يُفعَّل من ملف الإعدادات.
    </div>
<?php endif; ?>

<form method="post" class="settings-ora-form master-page-form" id="gps-tracking-settings-form">
    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">سلوك التتبّع</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">
                تُطبَّق تلقائياً على جميع مستخدمي تطبيق الهاتف. المستخدم العادي لا يغيّرها من التطبيق.
            </p>

            <label class="field field-check field--full">
                <input type="checkbox" name="gps_mobile_auto_enable" value="1" id="gps_auto"
                    <?= $settings['auto_enable'] ? 'checked' : '' ?>>
                <span class="field-label">تفعيل التتبّع تلقائياً عند تسجيل الدخول</span>
            </label>
            <span class="field-hint field--full" style="display:block;margin:-0.15rem 0 0.55rem 1.6rem;">
                يبدأ تتبّع الموقع في الخلفية فور دخول المستخدم لتطبيق الهاتف دون الحاجة لتشغيله يدوياً.
            </span>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">مدة إرسال الموقع</span>
                    <select class="input" name="gps_mobile_interval_sec" id="gps_interval">
                        <?php foreach ($intervalOptions as $sec): ?>
                            <option value="<?= $sec ?>" <?= $settings['interval_sec'] === $sec ? 'selected' : '' ?>>
                                <?= esc(mobile_gps_settings_interval_label($sec)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">أقل مسافة للإرسال</span>
                    <select class="input" name="gps_mobile_min_distance_m" id="gps_distance">
                        <?php foreach ($distanceOptions as $m): ?>
                            <option value="<?= $m ?>" <?= $settings['min_distance_m'] === $m ? 'selected' : '' ?>>
                                <?= esc(mobile_gps_settings_distance_label($m)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint">يُرسل نبضة دورية حتى بدون حركة وفق المدة أعلاه.</span>
                </label>
            </div>

            <label class="field field-check field--full">
                <input type="checkbox" name="gps_mobile_user_can_disable" value="1" id="gps_can_disable"
                    <?= $settings['user_can_disable'] ? 'checked' : '' ?>>
                <span class="field-label">السماح للمستخدم بإيقاف التتبّع من التطبيق</span>
            </label>
            <span class="field-hint field--full" style="display:block;margin:-0.15rem 0 0 1.6rem;">
                افتراضياً: المستخدم العادي لا يرى زر الإيقاف — التتبّع إلزامي من النظام.
            </span>
        </div>
    </div>

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">الخريطة</h2>
        <div class="settings-ora-panel-body">
            <div class="form-row">
                <label class="field">
                    <span class="field-label">محرك الخريطة (تتبّع المواقع)</span>
                    <select class="input" id="gps_map_engine" name="gps_map_engine">
                        <option value="leaflet" <?= $mapEngine === 'leaflet' ? 'selected' : '' ?>>
                            Leaflet — الافتراضي (خفيف وسريع)
                        </option>
                        <option value="arcgis" <?= $mapEngine === 'arcgis' ? 'selected' : '' ?>>
                            ArcGIS JavaScript SDK — تجريبي
                        </option>
                    </select>
                    <span class="field-hint">
                        عند اختيار ArcGIS SDK تُستخدم طبقة Esri المختارة أدناه. للتراجع: اختر Leaflet واحفظ.
                    </span>
                </label>
                <label class="field">
                    <span class="field-label">نوع بلاط الخريطة</span>
                    <select class="input" id="gps_map_provider" name="gps_map_provider">
                        <option value="esri" <?= $mapProvider === 'esri' ? 'selected' : '' ?>>
                            Esri World Street Map — مجاني (موصى به للتتبّع)
                        </option>
                        <option value="natgeo" <?= $mapProvider === 'natgeo' ? 'selected' : '' ?>>
                            National Geographic World Map — مجاني
                        </option>
                        <option value="carto" <?= $mapProvider === 'carto' ? 'selected' : '' ?>>
                            Carto Voyager — مجاني (Leaflet فقط)
                        </option>
                        <option value="google" <?= $mapProvider === 'google' ? 'selected' : '' ?>>
                            Google Maps — يحتاج مفتاح وفوترة
                        </option>
                    </select>
                    <span class="field-hint">
                        <strong>World Street</strong>: شوارع تفصيلية.
                        <strong>NatGeo</strong>: خريطة مرجعية أجمل لكن بلا تفصيل شوارع عميق.
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">خرائط Google (اختياري)</h2>
        <div class="settings-ora-panel-body">
            <label class="field field--full">
                <span class="field-label">مفتاح Google Maps API</span>
                <input type="password" class="input" id="gps_google_key" name="gps_google_maps_api_key"
                    value="<?= esc($googleMapsKey) ?>" autocomplete="off"
                    placeholder="AIzaSy...">
                <span class="field-hint">
                    مطلوب فقط إذا اخترت <strong>Google Maps</strong> أعلاه.
                    بدون فوترة مكتملة تظهر خريطة باهتة بعلامة مائية.
                </span>
            </label>
        </div>
    </div>

    <div class="settings-ora-actions no-print sr-only" aria-hidden="true">
        <button class="btn btn-primary" type="submit" id="gps-tracking-settings-submit">حفظ الإعدادات</button>
    </div>
</form>

<p class="muted no-print settings-ora-toolbar-hint" style="margin:0.75rem 0 0;font-size:0.9rem;">
    عدّل الإعدادات ثم اضغط <strong>حفظ</strong> في الشريط العلوي.
</p>

<script>
document.addEventListener('master-toolbar', function (e) {
  if (e.detail && e.detail.action === 'save') {
    e.preventDefault();
    e.stopImmediatePropagation();
    var f = document.getElementById('gps-tracking-settings-form');
    if (!f) return;
    if (typeof f.requestSubmit === 'function') {
      f.requestSubmit();
    } else {
      var btn = document.getElementById('gps-tracking-settings-submit');
      if (btn) btn.click();
      else f.submit();
    }
  }
});
</script>
