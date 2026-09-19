import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

/// خدمة الموقع الجغرافي (GPS) — طلب الإذن وقراءة الموقع.
///
/// ملاحظة مهمة: لا نستخدم `forceLocationManager` لأنه يُعطّل مزوّد الموقع
/// المدمج (Fused) الذي يعتمد على الشبكة/Wi‑Fi، وهو المصدر الوحيد للموقع
/// على الأجهزة اللوحية التي لا تحتوي شريحة GPS.
class LocationService {
  LocationService._();

  /// آخر موقع ناجح داخل الجلسة — يُستخدم كردّ فوري.
  static Position? _cached;
  static DateTime? _cachedAt;

  static const _lastKnownFresh = Duration(minutes: 5);
  static const _cacheFresh = Duration(minutes: 2);

  /// إعدادات مناسبة للتتبّع المستمر (خلفية على iOS/Android).
  static LocationSettings trackingSettings({
    Duration timeLimit = const Duration(seconds: 20),
    LocationAccuracy accuracy = LocationAccuracy.high,
  }) {
    if (!kIsWeb && Platform.isIOS) {
      return AppleSettings(
        accuracy: accuracy,
        activityType: ActivityType.automotiveNavigation,
        allowBackgroundLocationUpdates: true,
        pauseLocationUpdatesAutomatically: false,
        showBackgroundLocationIndicator: true,
        timeLimit: timeLimit,
      );
    }
    if (!kIsWeb && Platform.isAndroid) {
      return AndroidSettings(
        accuracy: accuracy,
        intervalDuration: const Duration(seconds: 2),
        timeLimit: timeLimit,
      );
    }
    return LocationSettings(accuracy: accuracy, timeLimit: timeLimit);
  }

  static bool _isFresh(DateTime? at, Duration maxAge) {
    return at != null && DateTime.now().difference(at) <= maxAge;
  }

  static void _remember(Position pos) {
    _cached = pos;
    _cachedAt = DateTime.now();
  }

  /// رسالة عربية مفهومة بدلاً من نص الاستثناء التقني.
  static String friendlyError(Object error) {
    final s = error.toString();
    if (s.startsWith('خدمة') || s.startsWith('لم') || s.startsWith('تعذّر')) {
      return s;
    }
    if (s.contains('TimeoutException') ||
        s.contains('time limit') ||
        s.contains('Future not completed')) {
      return 'تعذّر تحديد الموقع في الوقت المحدد. '
          'تأكد من تفعيل الموقع والاتصال بالإنترنت، '
          'أو حدّد الموقع يدوياً على الخريطة.';
    }
    if (s.contains('denied')) {
      return 'لم يُمنح إذن الوصول للموقع. فعّله من إعدادات التطبيق.';
    }
    if (s.contains('disabled') || s.contains('LocationService')) {
      return 'خدمة الموقع غير مفعّلة على الجهاز. فعّلها من الإعدادات.';
    }
    return 'تعذّر تحديد الموقع. حاول مجدداً أو حدّد الموقع على الخريطة.';
  }

  static Future<void> _ensurePermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw 'خدمة الموقع غير مفعّلة على الجهاز. فعّلها من الإعدادات.';
    }
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
    }
    if (perm == LocationPermission.denied ||
        perm == LocationPermission.deniedForever) {
      throw 'لم يُمنح إذن الوصول للموقع. فعّله من إعدادات التطبيق.';
    }
  }

  /// قراءة واحدة مع مهلة — تُرجع null بدل رمي الاستثناء.
  static Future<Position?> _read({
    required LocationAccuracy accuracy,
    required Duration timeLimit,
  }) async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: trackingSettings(
          accuracy: accuracy,
          timeLimit: timeLimit,
        ),
      ).timeout(timeLimit + const Duration(seconds: 2));
      _remember(pos);
      return pos;
    } catch (_) {
      return null;
    }
  }

  /// أول قراءة من تدفّق الموقع — أكثر موثوقية على بعض الأجهزة اللوحية.
  static Future<Position?> _firstFromStream({
    required LocationAccuracy accuracy,
    required Duration timeLimit,
  }) async {
    try {
      final pos = await Geolocator.getPositionStream(
        locationSettings: trackingSettings(
          accuracy: accuracy,
          timeLimit: timeLimit,
        ),
      ).first.timeout(timeLimit);
      _remember(pos);
      return pos;
    } catch (_) {
      return null;
    }
  }

  static Future<Position?> _lastKnown() async {
    try {
      return await Geolocator.getLastKnownPosition();
    } catch (_) {
      return null;
    }
  }

  /// موقع فوري إن وُجد (ذاكرة الجلسة أو آخر موقع معروف) — بلا انتظار.
  static Future<Position?> instantPosition() async {
    if (_cached != null && _isFresh(_cachedAt, _cacheFresh)) {
      return _cached;
    }
    final last = await _lastKnown();
    if (last != null && DateTime.now().difference(last.timestamp) <= _lastKnownFresh) {
      return last;
    }
    return last;
  }

  /// محاولة الحصول على الموقع الحالي؛ تُرجع null بصمت عند التعذر.
  /// خفيفة ومناسبة للتتبّع الدوري في الخلفية.
  static Future<Position?> tryGetPosition() async {
    try {
      await _ensurePermission();
    } catch (_) {
      return null;
    }
    if (_cached != null && _isFresh(_cachedAt, _cacheFresh)) {
      return _cached;
    }
    final pos = await _read(
      accuracy: LocationAccuracy.medium,
      timeLimit: const Duration(seconds: 10),
    );
    if (pos != null) return pos;
    return _lastKnown();
  }

  /// طلب الإذن صراحةً وإرجاع الموقع أو رمي رسالة عربية.
  ///
  /// التدرّج: ذاكرة الجلسة ← دقة متوسطة (شبكة/Wi‑Fi، سريعة) ←
  /// دقة عالية (GPS) ← تدفّق الموقع ← آخر موقع معروف.
  static Future<Position> requirePosition() async {
    await _ensurePermission();

    if (_cached != null && _isFresh(_cachedAt, _cacheFresh)) {
      return _cached!;
    }

    // دقة متوسطة أولاً: تعمل عبر الشبكة/Wi‑Fi وتنجح على الأجهزة بلا GPS.
    var pos = await _read(
      accuracy: LocationAccuracy.medium,
      timeLimit: const Duration(seconds: 12),
    );
    if (pos != null) return pos;

    // دقة عالية: GPS للأجهزة التي تدعمه.
    pos = await _read(
      accuracy: LocationAccuracy.high,
      timeLimit: const Duration(seconds: 15),
    );
    if (pos != null) return pos;

    // تدفّق الموقع كبديل أخير قبل الاستسلام.
    pos = await _firstFromStream(
      accuracy: LocationAccuracy.low,
      timeLimit: const Duration(seconds: 12),
    );
    if (pos != null) return pos;

    final last = await _lastKnown();
    if (last != null) return last;

    throw 'تعذّر تحديد الموقع. تأكد من تفعيل الموقع والاتصال بالإنترنت، '
        'أو حدّد الموقع يدوياً على الخريطة.';
  }
}
