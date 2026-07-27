import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../services/location_presence_service.dart';
import '../services/location_tracking_service.dart';
import 'api_client.dart';
import 'config.dart';
import 'device_identity.dart';

/// حالة الجلسة: عنوان السيرفر، الدخول، الصلاحيات، CSRF.
class SessionController extends ChangeNotifier {
  SessionController(this.api);

  final ApiClient api;
  static const _kServer = 'server_base';
  static const _kRemember = 'remember_login';
  static const _secure = FlutterSecureStorage();

  bool booting = true;
  bool authenticated = false;
  bool busy = false;
  String? userName;
  int userId = 0;
  String csrf = '';
  Set<String> permissions = <String>{};
  String? lastError;

  bool can(String code) => permissions.contains(code);

  Future<Map<String, String>> _deviceFields() async {
    final id = await DeviceIdentity.id();
    String label = 'هاتف';
    if (!kIsWeb) {
      try {
        label = Platform.isAndroid ? 'أندرويد' : (Platform.isIOS ? 'آيفون' : Platform.operatingSystem);
      } catch (_) {}
    }
    return {'device_id': id, 'device_label': label};
  }

  /// تحميل العنوان المحفوظ ومحاولة استرجاع الجلسة.
  Future<void> boot() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_kServer) ?? '';
    api.setBase(saved.isEmpty ? AppConfig.defaultServerBase : saved);
    final device = await _deviceFields();
    api.setDevice(device['device_id']!, label: device['device_label']!);
    await LocationTrackingService.saveDeviceId(
      device['device_id']!,
      label: device['device_label']!,
    );
    await _syncTrackingCredentials();
    if (saved.isNotEmpty) {
      try {
        await refreshMe();
      } catch (_) {
        authenticated = false;
      }
    }
    LocationPresenceService.bind(api, csrf: csrf);
    if (authenticated) {
      await LocationPresenceService.resumeIfNeeded(
        api: api,
        csrf: csrf,
        authenticated: true,
      );
    }
    booting = false;
    notifyListeners();
  }

  /// نسخ بيانات الدخول المحفوظة إلى خدمة الخلفية (isolate منفصل).
  Future<void> _syncTrackingCredentials() async {
    await LocationTrackingService.saveCredentials(base: api.base);
    final u = await _secure.read(key: 'u');
    final p = await _secure.read(key: 'p');
    if (u != null && p != null && u.isNotEmpty && p.isNotEmpty) {
      await LocationTrackingService.saveCredentials(
        base: api.base,
        username: u,
        password: p,
      );
    }
  }

  bool get hasServer => api.base.isNotEmpty;

  Future<void> saveServer(String raw) async {
    api.setBase(raw);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kServer, api.base);
    await LocationTrackingService.saveCredentials(base: api.base);
    notifyListeners();
  }

  /// فحص الاتصال بالسيرفر.
  Future<bool> ping() async {
    try {
      final res = await api.getJson(AppConfig.pingPath);
      return res['ok'] == true;
    } catch (_) {
      return false;
    }
  }

  Future<void> refreshMe() async {
    final wasAuth = authenticated;
    final device = await _deviceFields();
    final res = await api.getJson(
      AppConfig.sessionPath,
      query: {
        'action': 'me',
        ...device,
      },
    );
    _apply(res);
    if (wasAuth && !authenticated) {
      final reason = res['session_end_reason'] as String?;
      if (reason == 'device_in_use' || reason == 'device_id_required') {
        lastError = (res['message'] as String?) ??
            'تم إنهاء الجلسة — الحساب مستخدم على جهاز آخر.';
        await _clearLocalSession(stopServices: true);
      }
    }
    LocationPresenceService.setCsrf(csrf);
  }

  Future<bool> login(
    String username,
    String password, {
    bool remember = true,
  }) async {
    busy = true;
    lastError = null;
    notifyListeners();
    try {
      final device = await _deviceFields();
      api.setDevice(device['device_id']!, label: device['device_label']!);
      final res = await api.postForm(
        AppConfig.sessionPath,
        fields: {
          'action': 'login',
          'username': username,
          'password': password,
          ...device,
        },
      );
      _apply(res);
      if (authenticated) {
        await LocationTrackingService.saveDeviceId(
          device['device_id']!,
          label: device['device_label']!,
        );
        // دائماً نمرّر بيانات الدخول لخدمة التتبّع — وإلا تعمل الخدمة شكلياً دون إرسال.
        await LocationTrackingService.saveCredentials(
          base: api.base,
          username: username,
          password: password,
        );
        if (remember) {
          await _secure.write(key: 'u', value: username);
          await _secure.write(key: 'p', value: password);
          final prefs = await SharedPreferences.getInstance();
          await prefs.setBool(_kRemember, true);
        }
        await LocationPresenceService.resumeIfNeeded(
          api: api,
          csrf: csrf,
          authenticated: true,
        );
      }
      return authenticated;
    } on ApiException catch (e) {
      lastError = e.message;
      authenticated = false;
      return false;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<({String? u, String? p})> savedCredentials() async {
    return (u: await _secure.read(key: 'u'), p: await _secure.read(key: 'p'));
  }

  Future<void> logout() async {
    await _clearLocalSession(stopServices: true, callServer: true);
  }

  /// إنهاء الجلسة محلياً بعد رفض السيرفر (جهاز آخر نشط).
  Future<void> handleDeviceConflict(String message) async {
    lastError = message;
    await _clearLocalSession(stopServices: true, callServer: false);
  }

  Future<void> _clearLocalSession({
    bool stopServices = false,
    bool callServer = false,
  }) async {
    if (stopServices) {
      try {
        await LocationPresenceService.stop();
        await LocationTrackingService.stop();
        await LocationTrackingService.clearCredentials();
      } catch (_) {}
    }
    if (callServer) {
      try {
        await api.postForm(AppConfig.sessionPath, fields: {'action': 'logout'});
      } catch (_) {}
    }
    await api.clearCookies();
    await _secure.delete(key: 'u');
    await _secure.delete(key: 'p');
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kRemember, false);
    authenticated = false;
    permissions = <String>{};
    userName = null;
    userId = 0;
    notifyListeners();
  }

  void _apply(Map<String, dynamic> res) {
    authenticated = res['authenticated'] == true;
    csrf = (res['csrf'] as String?) ?? csrf;
    final user = res['user'];
    if (user is Map) {
      userId = (user['id'] as num?)?.toInt() ?? 0;
      userName = user['name'] as String?;
    }
    final perms = res['permissions'];
    if (perms is List) {
      permissions = perms.map((e) => e.toString()).toSet();
    }
    LocationPresenceService.setCsrf(csrf);
  }
}
