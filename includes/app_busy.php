<?php
declare(strict_types=1);

function app_busy_css_url(): string
{
    $path = app_path('assets/css/app-busy.css');

    return app_url('assets/css/app-busy.css')
        . (is_file($path) ? '?v=' . (string) filemtime($path) : '');
}

function app_busy_js_url(): string
{
    $path = app_path('assets/js/app-busy.js');

    return app_url('assets/js/app-busy.js')
        . (is_file($path) ? '?v=' . (string) filemtime($path) : '');
}

function app_busy_render_overlay(): void
{
    ?>
    <div id="app-busy" class="app-busy no-print" hidden aria-live="polite" aria-busy="true">
        <div class="app-busy-panel" role="status">
            <div class="app-busy-spinner" aria-hidden="true"></div>
            <p class="app-busy-msg" id="app-busy-msg">جاري التنفيذ...</p>
            <p class="app-busy-hint">يرجى الانتظار — لا تغلق المتصفح حتى انتهاء العملية</p>
        </div>
    </div>
    <?php
}
