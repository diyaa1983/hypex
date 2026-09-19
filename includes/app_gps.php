<?php
declare(strict_types=1);

/** هل ميزة GPS/حفظ الموقع مفعّلة في النظام؟ */
function app_gps_enabled(): bool
{
    return defined('APP_GPS_ENABLED') && APP_GPS_ENABLED;
}
