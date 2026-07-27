import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/device_identity.dart';
import 'location_service.dart';
import 'location_tracking_service.dart';

/// إرسال موقع موثوق من واجهة التطبيق عبر جلسة الدخول الحالية.
///
/// خدمة الخلفية وحدها غير كافية: قد تعمل الإشعارات بينما يفشل تسجيل الدخول
/// أو قراءة GPS داخل isolate منفصل دون أن يظهر ذلك للمستخدم.
class LocationPresenceService {
  LocationPresenceService._();

  static Timer? _timer;
  static ApiClient? _api;
  static String _csrf = '';
  static bool _busy = false;
  static bool _enabled = false;
  static DateTime? lastOkAt;
  static String lastMessage = '';
  static void Function(String message)? onSessionConflict;

  static void bind(ApiClient api, {String csrf = ''}) {
    _api = api;
    if (csrf.isNotEmpty) _csrf = csrf;
  }

  static void setCsrf(String csrf) {
    if (csrf.isNotEmpty) _csrf = csrf;
  }

  /// تشغيل النبضات أثناء فتح التطبيق (كل 10 ثوانٍ).
  static Future<void> start({
    required ApiClient api,
    required String csrf,
  }) async {
    bind(api, csrf: csrf);
    _enabled = true;
    await LocationTrackingService.setEnabledFlag(true);
    _armTimer();
    await pingNow();
  }

  static Future<void> stop() async {
    _enabled = false;
    _timer?.cancel();
    _timer = null;
  }

  /// يستأنف النبض إن كان التتبّع مفعّلاً والمستخدم مسجّل الدخول.
  static Future<void> resumeIfNeeded({
    required ApiClient api,
    required String csrf,
    required bool authenticated,
  }) async {
    bind(api, csrf: csrf);
    final on = await LocationTrackingService.isEnabled;
    _enabled = on;
    if (!authenticated || !on) {
      _timer?.cancel();
      _timer = null;
      return;
    }
    _armTimer();
    await pingNow();
  }

  static void _armTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 10), (_) {
      unawaited(pingNow());
    });
  }

  static Future<bool> pingNow({bool force = false}) async {
    if (_busy) return false;
    final api = _api;
    if (api == null || api.base.isEmpty) {
      lastMessage = 'لا يوجد عنوان سيرفر';
      return false;
    }
    if (!_enabled && !force) return false;

    _busy = true;
    try {
      final pos = await LocationService.tryGetPosition();
      if (pos == null) {
        lastMessage = 'تعذّرت قراءة الموقع على الجهاز';
        if (kDebugMode) debugPrint('[presence] $lastMessage');
        await LocationTrackingService.saveLastStatus(lastMessage);
        return false;
      }

      final deviceId = await DeviceIdentity.id();
      String deviceLabel = 'هاتف';
      if (!kIsWeb) {
        try {
          deviceLabel = Platform.isAndroid
              ? 'أندرويد'
              : (Platform.isIOS ? 'آيفون' : Platform.operatingSystem);
        } catch (_) {}
      }
      final res = await api.postForm(
        AppConfig.userLocationPingPath,
        csrf: _csrf.isNotEmpty ? _csrf : null,
        fields: {
          'latitude': pos.latitude.toString(),
          'longitude': pos.longitude.toString(),
          'gps_accuracy': pos.accuracy.toString(),
          'gps_source': 'mobile',
          'gps_channel': 'native_app',
          'device_id': deviceId,
          'device_label': deviceLabel,
        },
      );

      if (res['ok'] == true) {
        lastOkAt = DateTime.now();
        lastMessage = res['skipped'] == true
            ? 'تم تأكيد الحضور'
            : 'تم إرسال الموقع';
        await LocationTrackingService.saveLastStatus(
          lastMessage,
          lat: pos.latitude,
          lng: pos.longitude,
        );
        if (kDebugMode) {
          debugPrint(
            '[presence] ok lat=${pos.latitude} lng=${pos.longitude} '
            'skipped=${res['skipped']}',
          );
        }
        return true;
      }

      lastMessage = 'رفض السيرفر: ${res['error'] ?? 'غير معروف'}';
      await LocationTrackingService.saveLastStatus(lastMessage);
      return false;
    } on ApiException catch (e) {
      lastMessage = e.message;
      await LocationTrackingService.saveLastStatus(lastMessage);
      if (e.code == 'device_in_use') {
        onSessionConflict?.call(e.message);
      }
      if (kDebugMode) debugPrint('[presence] api error: ${e.message}');
      return false;
    } catch (e) {
      lastMessage = 'فشل الإرسال';
      await LocationTrackingService.saveLastStatus(lastMessage);
      if (kDebugMode) debugPrint('[presence] error: $e');
      return false;
    } finally {
      _busy = false;
    }
  }
}
