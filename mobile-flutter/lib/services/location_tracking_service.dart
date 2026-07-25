import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';

import '../core/config.dart';

/// مفاتيح التخزين المشتركة بين الواجهة و isolate الخدمة.
class TrackKeys {
  TrackKeys._();

  static const enabled = 'trk_enabled';
  static const intervalSec = 'trk_interval_sec';
  static const base = 'trk_base';
  static const user = 'trk_user';
  static const pass = 'trk_pass';
  static const minDistance = 'trk_min_distance';
  static const lastPingMs = 'trk_last_ping_ms';
  static const lastLat = 'trk_last_lat';
  static const lastLng = 'trk_last_lng';
  static const sentCount = 'trk_sent_count';
  static const lastStatus = 'trk_last_status';
}

/// حالة مختصرة تُعرض في شاشة الإعدادات.
class TrackingStatus {
  const TrackingStatus({
    required this.running,
    required this.intervalSec,
    required this.minDistance,
    required this.sentCount,
    required this.lastPing,
    required this.lastStatus,
    required this.lastLat,
    required this.lastLng,
  });

  final bool running;
  final int intervalSec;
  final int minDistance;
  final int sentCount;
  final DateTime? lastPing;
  final String lastStatus;
  final double? lastLat;
  final double? lastLng;
}

/// خدمة تتبّع الموقع التي تعمل في الخلفية (Foreground Service على أندرويد).
///
/// تُخزَّن بيانات الاتصال عبر [FlutterForegroundTask.saveData] لأن الخدمة
/// تعمل في isolate منفصل لا يشارك الذاكرة مع واجهة التطبيق.
class LocationTrackingService {
  LocationTrackingService._();

  static const int serviceId = 8801;
  static const int defaultIntervalSec = 300;
  static const int defaultMinDistance = 30;

  static bool _initialized = false;

  /// تهيئة قناة الإشعار وخيارات المهمة (تُستدعى مرة واحدة عند الإقلاع).
  static void init({int? intervalSec}) {
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: 'namma_location_tracking',
        channelName: 'تتبّع الموقع',
        channelDescription: 'يظهر هذا الإشعار أثناء عمل خدمة تتبّع الموقع.',
        channelImportance: NotificationChannelImportance.LOW,
        priority: NotificationPriority.LOW,
        onlyAlertOnce: true,
        showWhen: false,
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: false,
        playSound: false,
      ),
      foregroundTaskOptions: ForegroundTaskOptions(
        eventAction: ForegroundTaskEventAction.repeat(
          (intervalSec ?? defaultIntervalSec) * 1000,
        ),
        autoRunOnBoot: true,
        autoRunOnMyPackageReplaced: true,
        allowWakeLock: true,
        allowWifiLock: true,
        allowAutoRestart: true,
        stopWithTask: false,
      ),
    );
    _initialized = true;
  }

  static Future<bool> get isRunning => FlutterForegroundTask.isRunningService;

  /// حفظ بيانات الاتصال ليستخدمها isolate الخدمة في تسجيل الدخول والإرسال.
  static Future<void> saveCredentials({
    required String base,
    String? username,
    String? password,
  }) async {
    await FlutterForegroundTask.saveData(key: TrackKeys.base, value: base);
    if (username != null && password != null) {
      await FlutterForegroundTask.saveData(
        key: TrackKeys.user,
        value: base64Encode(utf8.encode(username)),
      );
      await FlutterForegroundTask.saveData(
        key: TrackKeys.pass,
        value: base64Encode(utf8.encode(password)),
      );
    }
  }

  static Future<void> clearCredentials() async {
    await FlutterForegroundTask.removeData(key: TrackKeys.user);
    await FlutterForegroundTask.removeData(key: TrackKeys.pass);
  }

  static Future<bool> get isEnabled async =>
      (await FlutterForegroundTask.getData<bool>(key: TrackKeys.enabled)) ??
      false;

  static Future<int> get intervalSec async =>
      (await FlutterForegroundTask.getData<int>(key: TrackKeys.intervalSec)) ??
      defaultIntervalSec;

  static Future<int> get minDistance async =>
      (await FlutterForegroundTask.getData<int>(key: TrackKeys.minDistance)) ??
      defaultMinDistance;

  static Future<void> setInterval(int seconds) async {
    await FlutterForegroundTask.saveData(
      key: TrackKeys.intervalSec,
      value: seconds,
    );
    if (await isRunning) {
      await FlutterForegroundTask.updateService(
        foregroundTaskOptions: ForegroundTaskOptions(
          eventAction: ForegroundTaskEventAction.repeat(seconds * 1000),
        ),
      );
    }
  }

  static Future<void> setMinDistance(int meters) => FlutterForegroundTask
      .saveData(key: TrackKeys.minDistance, value: meters)
      .then((_) {});

  /// طلب كل الأذونات اللازمة؛ يُرجع رسالة خطأ عربية أو null عند النجاح.
  static Future<String?> requestPermissions() async {
    final notif = await FlutterForegroundTask.checkNotificationPermission();
    if (notif != NotificationPermission.granted) {
      await FlutterForegroundTask.requestNotificationPermission();
    }

    if (!await Geolocator.isLocationServiceEnabled()) {
      return 'خدمة الموقع (GPS) غير مفعّلة على الجهاز. فعّلها ثم أعد المحاولة.';
    }

    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
    }
    if (perm == LocationPermission.denied) {
      return 'لم يُمنح إذن الوصول للموقع.';
    }
    if (perm == LocationPermission.deniedForever) {
      return 'إذن الموقع مرفوض نهائياً. افتح إعدادات التطبيق وامنح إذن الموقع.';
    }

    if (Platform.isAndroid) {
      // إذن "السماح طوال الوقت" ضروري لاستمرار التتبّع والتطبيق مغلق.
      if (perm == LocationPermission.whileInUse) {
        await Geolocator.requestPermission();
      }
      if (!await FlutterForegroundTask.isIgnoringBatteryOptimizations) {
        await FlutterForegroundTask.requestIgnoreBatteryOptimization();
      }
    }
    return null;
  }

  /// تشغيل الخدمة؛ يُرجع رسالة خطأ عربية أو null عند النجاح.
  static Future<String?> start() async {
    if (!_initialized) init();
    final permError = await requestPermissions();
    if (permError != null) return permError;

    final seconds = await intervalSec;
    await FlutterForegroundTask.saveData(key: TrackKeys.enabled, value: true);

    final result = await (await isRunning
        ? FlutterForegroundTask.restartService()
        : FlutterForegroundTask.startService(
            serviceId: serviceId,
            serviceTypes: const [ForegroundServiceTypes.location],
            notificationTitle: 'تتبّع الموقع نشِط',
            notificationText: 'يتم إرسال موقعك كل ${_humanInterval(seconds)}.',
            notificationInitialRoute: '/home',
            callback: startLocationTrackingTask,
          ));

    if (result is ServiceRequestFailure) {
      await FlutterForegroundTask.saveData(
        key: TrackKeys.enabled,
        value: false,
      );
      return 'تعذّر تشغيل خدمة التتبّع: ${result.error}';
    }
    return null;
  }

  static Future<void> stop() async {
    await FlutterForegroundTask.saveData(key: TrackKeys.enabled, value: false);
    if (await isRunning) {
      await FlutterForegroundTask.stopService();
    }
  }

  /// إعادة التشغيل التلقائي بعد فتح التطبيق إذا كان المستخدم قد فعّل التتبّع.
  static Future<void> resumeIfEnabled() async {
    if (!await isEnabled) return;
    if (await isRunning) return;
    await start();
  }

  /// طلب إرسال فوري للموقع من الخدمة العاملة.
  static void requestImmediatePing() {
    FlutterForegroundTask.sendDataToTask('ping_now');
  }

  static Future<TrackingStatus> status() async {
    final lastMs =
        await FlutterForegroundTask.getData<int>(key: TrackKeys.lastPingMs);
    return TrackingStatus(
      running: await isRunning,
      intervalSec: await intervalSec,
      minDistance: await minDistance,
      sentCount:
          await FlutterForegroundTask.getData<int>(key: TrackKeys.sentCount) ??
              0,
      lastPing: lastMs == null
          ? null
          : DateTime.fromMillisecondsSinceEpoch(lastMs),
      lastStatus: await FlutterForegroundTask.getData<String>(
            key: TrackKeys.lastStatus,
          ) ??
          '',
      lastLat:
          await FlutterForegroundTask.getData<double>(key: TrackKeys.lastLat),
      lastLng:
          await FlutterForegroundTask.getData<double>(key: TrackKeys.lastLng),
    );
  }

  static String _humanInterval(int seconds) {
    if (seconds < 60) return '$seconds ثانية';
    final m = seconds ~/ 60;
    if (m == 1) return 'دقيقة';
    if (m == 2) return 'دقيقتين';
    if (m < 11) return '$m دقائق';
    return '$m دقيقة';
  }

  static String humanInterval(int seconds) => _humanInterval(seconds);
}

/// نقطة الدخول لـ isolate الخدمة — يجب أن تكون دالة عليا.
@pragma('vm:entry-point')
void startLocationTrackingTask() {
  FlutterForegroundTask.setTaskHandler(_TrackingTaskHandler());
}

class _TrackingTaskHandler extends TaskHandler {
  Dio? _dio;
  String _base = '';
  String _csrf = '';
  bool _authenticated = false;
  bool _busy = false;
  int _sent = 0;
  double? _lastLat;
  double? _lastLng;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {
    _base = await FlutterForegroundTask.getData<String>(key: TrackKeys.base) ??
        AppConfig.defaultServerBase;
    _sent =
        await FlutterForegroundTask.getData<int>(key: TrackKeys.sentCount) ?? 0;
    _lastLat =
        await FlutterForegroundTask.getData<double>(key: TrackKeys.lastLat);
    _lastLng =
        await FlutterForegroundTask.getData<double>(key: TrackKeys.lastLng);

    _dio = Dio(
      BaseOptions(
        connectTimeout: const Duration(seconds: 20),
        receiveTimeout: const Duration(seconds: 25),
        headers: {'Accept': 'application/json'},
        validateStatus: (c) => c != null && c < 600,
      ),
    );
    _dio!.interceptors.add(_MemoryCookieInterceptor());

    await _login();
    await _tick(force: true);
  }

  @override
  void onRepeatEvent(DateTime timestamp) {
    _tick();
  }

  @override
  void onReceiveData(Object data) {
    if (data == 'ping_now') _tick(force: true);
  }

  @override
  void onNotificationPressed() {
    FlutterForegroundTask.launchApp('/home');
  }

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {
    _dio?.close(force: true);
  }

  Future<bool> _login() async {
    final encUser =
        await FlutterForegroundTask.getData<String>(key: TrackKeys.user);
    final encPass =
        await FlutterForegroundTask.getData<String>(key: TrackKeys.pass);
    if (encUser == null || encPass == null) {
      _authenticated = false;
      await _setStatus('لا توجد بيانات دخول محفوظة — سجّل الدخول مع "تذكّرني".');
      return false;
    }
    try {
      final res = await _dio!.post(
        '$_base/${AppConfig.sessionPath}',
        data: {
          'action': 'login',
          'username': utf8.decode(base64Decode(encUser)),
          'password': utf8.decode(base64Decode(encPass)),
        },
        options: Options(contentType: Headers.formUrlEncodedContentType),
      );
      final map = _asMap(res.data);
      _authenticated = map['authenticated'] == true;
      _csrf = (map['csrf'] ?? '').toString();
      if (!_authenticated) {
        await _setStatus('فشل تسجيل الدخول للخدمة.');
      }
      return _authenticated;
    } catch (e) {
      _authenticated = false;
      await _setStatus('تعذّر الاتصال بالسيرفر.');
      return false;
    }
  }

  Future<void> _tick({bool force = false}) async {
    if (_busy) return;
    _busy = true;
    try {
      final pos = await _readPosition();
      if (pos == null) {
        await _setStatus('تعذّرت قراءة الموقع.');
        return;
      }

      final minDist = await FlutterForegroundTask.getData<int>(
            key: TrackKeys.minDistance,
          ) ??
          LocationTrackingService.defaultMinDistance;
      if (!force && _lastLat != null && _lastLng != null) {
        final moved = _distanceMeters(
          _lastLat!,
          _lastLng!,
          pos.latitude,
          pos.longitude,
        );
        if (moved < minDist) {
          await _setStatus('لم يتغيّر الموقع (أقل من $minDist م).');
          return;
        }
      }

      if (!_authenticated && !await _login()) return;

      var ok = await _send(pos);
      if (!ok && await _login()) {
        ok = await _send(pos);
      }
      if (!ok) return;

      _sent++;
      _lastLat = pos.latitude;
      _lastLng = pos.longitude;
      final now = DateTime.now();
      await FlutterForegroundTask.saveData(
        key: TrackKeys.sentCount,
        value: _sent,
      );
      await FlutterForegroundTask.saveData(
        key: TrackKeys.lastLat,
        value: pos.latitude,
      );
      await FlutterForegroundTask.saveData(
        key: TrackKeys.lastLng,
        value: pos.longitude,
      );
      await FlutterForegroundTask.saveData(
        key: TrackKeys.lastPingMs,
        value: now.millisecondsSinceEpoch,
      );
      await _setStatus('تم إرسال الموقع');

      final hh = now.hour.toString().padLeft(2, '0');
      final mm = now.minute.toString().padLeft(2, '0');
      await FlutterForegroundTask.updateService(
        notificationTitle: 'تتبّع الموقع نشِط',
        notificationText: 'آخر إرسال $hh:$mm • $_sent نقطة',
      );
      FlutterForegroundTask.sendDataToMain({
        'lat': pos.latitude,
        'lng': pos.longitude,
        'at': now.millisecondsSinceEpoch,
        'sent': _sent,
      });
    } catch (e) {
      await _setStatus('خطأ في الخدمة: $e');
    } finally {
      _busy = false;
    }
  }

  Future<Position?> _readPosition() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      final perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied ||
          perm == LocationPermission.deniedForever) {
        return null;
      }
      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 25),
        ),
      );
    } catch (_) {
      try {
        return await Geolocator.getLastKnownPosition();
      } catch (_) {
        return null;
      }
    }
  }

  Future<bool> _send(Position pos) async {
    try {
      final res = await _dio!.post(
        '$_base/${AppConfig.userLocationPingPath}',
        data: {
          '_csrf': _csrf,
          'latitude': pos.latitude,
          'longitude': pos.longitude,
          'gps_accuracy': pos.accuracy,
          'gps_source': 'mobile',
        },
        options: Options(
          contentType: Headers.formUrlEncodedContentType,
          headers: {'X-CSRF-Token': _csrf},
        ),
      );
      if (res.statusCode == 401 || res.statusCode == 403) {
        _authenticated = false;
        return false;
      }
      final map = _asMap(res.data);
      if (map['ok'] == true) return true;
      if (map['error'] == 'csrf' || map['error'] == 'unauthorized') {
        _authenticated = false;
        return false;
      }
      await _setStatus('رفض السيرفر الإرسال: ${map['error'] ?? 'غير معروف'}');
      return false;
    } catch (e) {
      await _setStatus('تعذّر الإرسال — لا يوجد اتصال.');
      return false;
    }
  }

  Future<void> _setStatus(String text) async {
    if (kDebugMode) debugPrint('[tracking] $text');
    await FlutterForegroundTask.saveData(
      key: TrackKeys.lastStatus,
      value: text,
    );
  }

  Map<String, dynamic> _asMap(dynamic data) {
    if (data is Map) return data.map((k, v) => MapEntry(k.toString(), v));
    if (data is String) {
      final t = data.trim();
      if (t.startsWith('{')) {
        try {
          final d = jsonDecode(t);
          if (d is Map) return d.map((k, v) => MapEntry(k.toString(), v));
        } catch (_) {}
      }
    }
    return <String, dynamic>{};
  }

  static double _distanceMeters(
    double lat1,
    double lon1,
    double lat2,
    double lon2,
  ) {
    const r = 6371000.0;
    final dLat = (lat2 - lat1) * math.pi / 180;
    final dLon = (lon2 - lon1) * math.pi / 180;
    final a = math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(lat1 * math.pi / 180) *
            math.cos(lat2 * math.pi / 180) *
            math.sin(dLon / 2) *
            math.sin(dLon / 2);
    return r * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a));
  }
}

/// حافظ كوكيز بسيط في الذاكرة (الجلسة داخل isolate الخدمة فقط).
class _MemoryCookieInterceptor extends Interceptor {
  final Map<String, String> _cookies = {};

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    if (_cookies.isNotEmpty) {
      options.headers['Cookie'] =
          _cookies.entries.map((e) => '${e.key}=${e.value}').join('; ');
    }
    handler.next(options);
  }

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    final raw = response.headers.map['set-cookie'];
    if (raw != null) {
      for (final line in raw) {
        final first = line.split(';').first;
        final eq = first.indexOf('=');
        if (eq > 0) {
          _cookies[first.substring(0, eq).trim()] =
              first.substring(eq + 1).trim();
        }
      }
    }
    handler.next(response);
  }
}
