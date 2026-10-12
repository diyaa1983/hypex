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

  /// عند طلب موقع «الآن» نرفض قراءة أقدم من هذا العمر (Fused قد يعيد كاشاً).
  static const _freshFixMaxAge = Duration(seconds: 30);

  /// إعدادات مناسبة للتتبّع المستمر (خلفية على iOS/Android).
  static LocationSettings trackingSettings({
    Duration timeLimit = const Duration(seconds: 20),
    LocationAccuracy accuracy = LocationAccuracy.high,
    bool forFreshFix = false,
  }) {
    if (!kIsWeb && Platform.isIOS) {
      return AppleSettings(
        accuracy: accuracy,
        activityType: ActivityType.automotiveNavigation,
        allowBackgroundLocationUpdates: true,
        pauseLocationUpdatesAutomatically: false,
        showBackgroundLocationIndicator: true,
        timeLimit: timeLimit,
        distanceFilter: forFreshFix ? 0 : 0,
      );
    }
    if (!kIsWeb && Platform.isAndroid) {
      return AndroidSettings(
        accuracy: accuracy,
        distanceFilter: 0,
        intervalDuration: Duration(seconds: forFreshFix ? 1 : 2),
        timeLimit: timeLimit,
      );
    }
    return LocationSettings(
      accuracy: accuracy,
      timeLimit: timeLimit,
      distanceFilter: 0,
    );
  }

  static bool _isFresh(DateTime? at, Duration maxAge) {
    return at != null && DateTime.now().difference(at) <= maxAge;
  }

  static bool _positionAgeOk(Position pos, {required bool requireFresh}) {
    if (!requireFresh) return true;
    try {
      return DateTime.now().difference(pos.timestamp) <= _freshFixMaxAge;
    } catch (_) {
      return true;
    }
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
    bool requireFresh = false,
  }) async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: trackingSettings(
          accuracy: accuracy,
          timeLimit: timeLimit,
          forFreshFix: requireFresh,
        ),
      ).timeout(timeLimit + const Duration(seconds: 2));
      if (!_positionAgeOk(pos, requireFresh: requireFresh)) {
        return null;
      }
      _remember(pos);
      return pos;
    } catch (_) {
      return null;
    }
  }

  /// أول قراءة حديثة من تدفّق الموقع — أكثر موثوقية على بعض الأجهزة اللوحية.
  static Future<Position?> _firstFromStream({
    required LocationAccuracy accuracy,
    required Duration timeLimit,
    bool requireFresh = false,
  }) async {
    StreamSubscription<Position>? sub;
    try {
      final completer = Completer<Position?>();
      final stream = Geolocator.getPositionStream(
        locationSettings: trackingSettings(
          accuracy: accuracy,
          timeLimit: timeLimit,
          forFreshFix: requireFresh,
        ),
      );
      sub = stream.listen(
        (pos) {
          if (!_positionAgeOk(pos, requireFresh: requireFresh)) return;
          if (!completer.isCompleted) completer.complete(pos);
        },
        onError: (_) {
          if (!completer.isCompleted) completer.complete(null);
        },
        cancelOnError: true,
      );
      final pos = await completer.future.timeout(timeLimit, onTimeout: () => null);
      if (pos != null) _remember(pos);
      return pos;
    } catch (_) {
      return null;
    } finally {
      await sub?.cancel();
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
  /// لا يُرجع موقعاً قديماً خارج مدة الصلاحية.
  static Future<Position?> instantPosition() async {
    if (_cached != null && _isFresh(_cachedAt, _cacheFresh)) {
      return _cached;
    }
    final last = await _lastKnown();
    if (last != null &&
        DateTime.now().difference(last.timestamp) <= _lastKnownFresh) {
      return last;
    }
    return null;
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
    final last = await _lastKnown();
    if (last != null &&
        DateTime.now().difference(last.timestamp) <= _lastKnownFresh) {
      return last;
    }
    return null;
  }

  /// طلب الإذن صراحةً وإرجاع الموقع أو رمي رسالة عربية.
  ///
  /// [fresh]: عند الضغط على «موقعي الحالي» — يتجاهل الذاكرة وآخر موقع معروف
  /// ويرفض قراءات Fused ذات timestamp أقدم من ~30 ثانية.
  ///
  /// التدرّج الاعتيادي: ذاكرة الجلسة ← دقة متوسطة ← دقة عالية ← تدفّق ← آخر معروف.
  static Future<Position> requirePosition({bool fresh = false}) async {
    await _ensurePermission();

    if (fresh) {
      _cached = null;
      _cachedAt = null;
    } else if (_cached != null && _isFresh(_cachedAt, _cacheFresh)) {
      return _cached!;
    }

    // دقة متوسطة أولاً: تعمل عبر الشبكة/Wi‑Fi وتنجح على الأجهزة بلا GPS.
    var pos = await _read(
      accuracy: LocationAccuracy.medium,
      timeLimit: Duration(seconds: fresh ? 15 : 12),
      requireFresh: fresh,
    );
    if (pos != null) return pos;

    // دقة عالية: GPS للأجهزة التي تدعمه.
    pos = await _read(
      accuracy: LocationAccuracy.high,
      timeLimit: Duration(seconds: fresh ? 20 : 15),
      requireFresh: fresh,
    );
    if (pos != null) return pos;

    // تدفّق الموقع — انتظار أول fix بعمر مقبول عند fresh.
    pos = await _firstFromStream(
      accuracy: fresh ? LocationAccuracy.high : LocationAccuracy.low,
      timeLimit: Duration(seconds: fresh ? 20 : 12),
      requireFresh: fresh,
    );
    if (pos != null) return pos;

    // عند طلب موقع «الآن» لا نُرجع موقعاً قديماً — أفضل رسالة خطأ من موقع خاطئ.
    if (!fresh) {
      final last = await _lastKnown();
      if (last != null) return last;
    }

    throw 'تعذّر تحديد الموقع الحالي الآن. تأكد من تفعيل الموقع والاتصال بالإنترنت، '
        'ثم أعد المحاولة أو حدّد الموقع يدوياً على الخريطة.';
  }
}
