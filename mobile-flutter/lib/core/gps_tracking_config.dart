/// إعدادات تتبّع الموقع القادمة من السيرفر (مدير النظام).
class GpsTrackingConfig {
  const GpsTrackingConfig({
    this.enabled = true,
    this.autoEnable = true,
    this.intervalSec = 10,
    this.minDistanceM = 0,
    this.userCanDisable = false,
    this.repVisitGeofence = false,
  });

  final bool enabled;
  final bool autoEnable;
  final int intervalSec;
  final int minDistanceM;
  final bool userCanDisable;

  /// إلزام التواجد ضمن نطاق موقع العميل عند الفاتورة/طلب الشراء.
  final bool repVisitGeofence;

  static const defaults = GpsTrackingConfig();

  factory GpsTrackingConfig.fromJson(Map<String, dynamic>? raw) {
    if (raw == null) return defaults;
    return GpsTrackingConfig(
      enabled: raw['enabled'] == true,
      autoEnable: raw['auto_enable'] == true,
      intervalSec: _clampInt(raw['interval_sec'], 10, 300, 10),
      minDistanceM: _clampInt(raw['min_distance_m'], 0, 500, 0),
      userCanDisable: raw['user_can_disable'] == true,
      repVisitGeofence: raw['rep_visit_geofence'] == true,
    );
  }

  static int _clampInt(dynamic v, int min, int max, int fallback) {
    final n = (v is num) ? v.toInt() : int.tryParse('$v');
    if (n == null) return fallback;
    return n.clamp(min, max);
  }

  String get intervalLabel {
    switch (intervalSec) {
      case 10:
        return 'كل 10 ثوانٍ';
      case 15:
        return 'كل 15 ثانية';
      case 30:
        return 'كل 30 ثانية';
      case 60:
        return 'كل دقيقة';
      case 120:
        return 'كل دقيقتين';
      case 300:
        return 'كل 5 دقائق';
      default:
        return 'كل $intervalSec ثانية';
    }
  }

  String get distanceLabel {
    switch (minDistanceM) {
      case 0:
        return 'دائماً (بدون شرط)';
      case 15:
        return 'بعد 15 متر';
      case 30:
        return 'بعد 30 متر';
      case 50:
        return 'بعد 50 متر';
      case 100:
        return 'بعد 100 متر';
      default:
        return 'بعد $minDistanceM متر';
    }
  }
}
