import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../offline/offline_store.dart';
import '../services/location_presence_service.dart';
import '../services/location_tracking_service.dart';
import 'api_client.dart';
import 'config.dart';
import 'device_identity.dart';
import 'gps_tracking_config.dart';

/// حالة الجلسة: عنوان السيرفر، الدخول، الصلاحيات، CSRF.
class SessionController extends ChangeNotifier {
  SessionController(this.api);

  final ApiClient api;
  static const _kServer = 'server_base';
  static const _kServerConfigured = 'server_configured_v1';
  static const _kRemember = 'remember_login';
  static const _kOfflineProfile = 'offline_profile_v1';
  static const _kOfflineResume = 'offline_resume_ok';
  static const _secure = FlutterSecureStorage();

  bool booting = true;
  /// أول تثبيت / لم يُحفظ عنوان سيرفر بعد — اعرض شاشة إعداد السيرفر.
  bool needsServerSetup = false;
  bool authenticated = false;
  /// جلسة محلية دون كوكي سيرفر (بعد دخول أونلاين سابق + كتالوج).
  bool offlineSession = false;
  bool busy = false;
  bool isSystemAdmin = false;
  String? userName;
  String? userUsername;
  int userId = 0;
  String csrf = '';
  Set<String> permissions = <String>{};
  String? lastError;
  /// تنبيه غير حرج (مثل العمل دون اتصال) — لا يُعرض كخطأ أحمر.
  String? lastInfo;
  GpsTrackingConfig gpsConfig = GpsTrackingConfig.defaults;

  /// عدد أسطر الصفحة من إعدادات النظام (10 / 15 / 20).
  int rowsPerPage = 10;

  /// فتح إعدادات التتبّع بعد التحقق من كلمة مرور مدير النظام (جلسة التطبيق فقط).
  bool settingsUnlocked = false;

  bool can(String code) => permissions.contains(code);

  void lockSettings() {
    if (!settingsUnlocked) return;
    settingsUnlocked = false;
    notifyListeners();
  }

  void unlockSettings() {
    if (settingsUnlocked) return;
    settingsUnlocked = true;
    notifyListeners();
  }

  /// التحقق من بيانات أي مستخدم في مجموعة ADMINS دون تغيير جلسة المندوب.
  Future<String?> verifyAdminPassword(String username, String password) async {
    try {
      final res = await api.postForm(
        AppConfig.verifyAdminPath,
        fields: {
          'username': username.trim(),
          'password': password,
        },
      );
      if (res['ok'] == true) {
        unlockSettings();
        return null;
      }
      return (res['message'] as String?) ?? 'تعذّر التحقق من المدير.';
    } on ApiException catch (e) {
      return e.message;
    }
  }

  Future<Map<String, String>> _deviceFields() async {
    final id = await DeviceIdentity.id();
    String label = 'هاتف';
    if (!kIsWeb) {
      try {
        label = Platform.isAndroid
            ? 'أندرويد'
            : (Platform.isIOS ? 'آيفون' : Platform.operatingSystem);
      } catch (_) {}
    }
    return {'device_id': id, 'device_label': label};
  }

  /// تحميل العنوان المحفوظ ومحاولة استرجاع الجلسة.
  Future<void> boot() async {
    final prefs = await SharedPreferences.getInstance();
    var saved = (prefs.getString(_kServer) ?? '').trim();
    var configured = prefs.getBool(_kServerConfigured) ?? false;

    // أجهزة قديمة لديها عنوان محفوظ → اعتبر الإعداد مكتملاً
    if (!configured &&
        saved.isNotEmpty &&
        !AppConfig.isLegacyDefaultServer(saved)) {
      configured = true;
      await prefs.setBool(_kServerConfigured, true);
    }

    if (!configured) {
      // أول تثبيت: لا تفرض اتصالاً — اعرض شاشة إدخال عنوان السيرفر/IP
      needsServerSetup = true;
      final hint = (saved.isEmpty || AppConfig.isLegacyDefaultServer(saved))
          ? AppConfig.defaultServerBase
          : saved;
      api.setBase(hint);
      final device = await _deviceFields();
      api.setDevice(device['device_id']!, label: device['device_label']!);
      await LocationTrackingService.saveDeviceId(
        device['device_id']!,
        label: device['device_label']!,
      );
      LocationPresenceService.bind(api, csrf: csrf);
      booting = false;
      notifyListeners();
      return;
    }

    if (saved.isEmpty || AppConfig.isLegacyDefaultServer(saved)) {
      saved = AppConfig.defaultServerBase;
      await prefs.setString(_kServer, saved);
    }
    needsServerSetup = false;
    api.setBase(saved);
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
      } on ApiException catch (e) {
        authenticated = false;
        if (e.isNetwork) {
          await _tryResumeOfflineSession();
        }
      } catch (_) {
        authenticated = false;
      }
    }
    LocationPresenceService.bind(api, csrf: csrf);
    if (authenticated && !offlineSession) {
      await _syncGpsTracking();
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
    await prefs.setBool(_kServerConfigured, true);
    needsServerSetup = false;
    await LocationTrackingService.saveCredentials(base: api.base);
    notifyListeners();
  }

  /// يُعيد CSRF الحالي، ويحدّث الجلسة إن كان فارغاً.
  Future<String> ensureCsrf() async {
    if (csrf.isNotEmpty) return csrf;
    if (!authenticated && api.base.isEmpty) return '';
    try {
      await refreshMe();
    } catch (_) {}
    return csrf;
  }

  /// فحص الاتصال بالسيرفر.
  Future<bool> ping() async {
    try {
      return await api.ping();
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
    final stillAuth = res['authenticated'] == true;
    if (wasAuth && !stillAuth) {
      final reason = res['session_end_reason'] as String?;
      if (reason == 'device_in_use' ||
          reason == 'device_id_required' ||
          reason == 'admin_killed') {
        _apply(res);
        lastError = (res['message'] as String?) ??
            'تم إنهاء الجلسة — الحساب مستخدم على جهاز آخر.';
        await _clearLocalSession(stopServices: true);
        return;
      }
      if (await _silentRelogin()) return;
      return;
    }
    _apply(res);
    LocationPresenceService.setCsrf(csrf);
    if (authenticated) {
      offlineSession = false;
      await _persistOfflineProfile();
      await _setOfflineResume(true);
      await _syncGpsTracking();
    }
  }

  /// استعادة الجلسة من بيانات الدخول المحفوظة دون إخراج المستخدم من الشاشة.
  Future<bool> _silentRelogin() async {
    final saved = await savedCredentials();
    final u = saved.u;
    final p = saved.p;
    if (u == null || p == null || u.isEmpty || p.isEmpty) return false;
    try {
      final device = await _deviceFields();
      api.setDevice(device['device_id']!, label: device['device_label']!);
      final res = await api.postForm(
        AppConfig.sessionPath,
        fields: {
          'action': 'login',
          'username': u,
          'password': p,
          ...device,
        },
      );
      if (res['authenticated'] != true) return false;
      offlineSession = false;
      _apply(res);
      LocationPresenceService.setCsrf(csrf);
      await _persistOfflineProfile();
      await _setOfflineResume(true);
      return true;
    } catch (_) {
      return false;
    }
  }

  /// إعادة مصادقة عند عودة الشبكة قبل ترحيل الطابور المحلي.
  Future<bool> reauthIfNeeded() async {
    if (authenticated && !offlineSession && csrf.isNotEmpty) {
      try {
        await refreshMe();
        if (authenticated && !offlineSession) return true;
      } catch (_) {}
    }
    final ok = await _silentRelogin();
    if (ok) {
      offlineSession = false;
      lastInfo = null;
      await _syncGpsTracking();
      notifyListeners();
    }
    return ok;
  }

  Future<bool> login(
    String username,
    String password, {
    bool remember = true,
  }) async {
    busy = true;
    lastError = null;
    lastInfo = null;
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
      offlineSession = false;
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
        } else {
          await _secure.delete(key: 'u');
          await _secure.delete(key: 'p');
          final prefs = await SharedPreferences.getInstance();
          await prefs.setBool(_kRemember, false);
        }
        await _persistOfflineProfile();
        await _setOfflineResume(true);
        await _syncGpsTracking();
      }
      return authenticated;
    } on ApiException catch (e) {
      if (e.isNetwork) {
        final ok = await _tryOfflineLogin(username, password, remember: remember);
        if (ok) return true;
        return false;
      }
      lastError = e.message;
      authenticated = false;
      offlineSession = false;
      return false;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> _setOfflineResume(bool ok) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kOfflineResume, ok);
  }

  Future<void> _persistOfflineProfile() async {
    if (!authenticated || userId <= 0) return;
    final prefs = await SharedPreferences.getInstance();
    final payload = <String, dynamic>{
      'authenticated': true,
      'is_system_admin': isSystemAdmin,
      'rows_per_page': rowsPerPage,
      'permissions': permissions.toList(),
      'gps_tracking': gpsConfig.toJson(),
      'user': {
        'id': userId,
        'name': userName,
        'username': userUsername,
      },
    };
    await prefs.setString(_kOfflineProfile, jsonEncode(payload));
  }

  Future<Map<String, dynamic>?> _loadOfflineProfile() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kOfflineProfile);
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        return decoded.map((k, v) => MapEntry(k.toString(), v));
      }
    } catch (_) {}
    return null;
  }

  Future<bool> _tryResumeOfflineSession() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_kOfflineResume) != true) return false;
    if (!await OfflineStore.instance.hasCatalog) return false;
    final profile = await _loadOfflineProfile();
    if (profile == null) return false;
    offlineSession = true;
    csrf = '';
    _apply(profile);
    if (!authenticated) return false;
    lastInfo = 'تعمل دون اتصال — ستُرحَّل البيانات عند عودة الشبكة';
    return true;
  }

  Future<bool> _tryOfflineLogin(
    String username,
    String password, {
    required bool remember,
  }) async {
    if (!await OfflineStore.instance.hasCatalog) {
      lastError =
          'لا يوجد اتصال ولا بيانات محلية. اتصل بالإنترنت وافتح «تحديث البيانات» مرة أولاً.';
      authenticated = false;
      offlineSession = false;
      return false;
    }

    final saved = await savedCredentials();
    final savedU = (saved.u ?? '').trim();
    final savedP = saved.p ?? '';
    final profile = await _loadOfflineProfile();

    if (savedU.isEmpty || savedP.isEmpty || profile == null) {
      lastError =
          'للعمل دون اتصال: ادخل أونلاين مرة مع تفعيل «تذكّرني» وحدّث البيانات.';
      authenticated = false;
      offlineSession = false;
      return false;
    }

    if (username.trim().toLowerCase() != savedU.toLowerCase() ||
        password != savedP) {
      lastError = 'اسم المستخدم أو كلمة السر غير صحيحة (وضع دون اتصال).';
      authenticated = false;
      offlineSession = false;
      return false;
    }

    final profileUser = profile['user'];
    final profileUsername = profileUser is Map
        ? (profileUser['username'] as String? ?? '')
        : '';
    if (profileUsername.isNotEmpty &&
        profileUsername.toLowerCase() != username.trim().toLowerCase()) {
      lastError =
          'البيانات المحلية لمستخدم آخر. ادخل أونلاين بهذا الحساب وحدّث البيانات.';
      authenticated = false;
      offlineSession = false;
      return false;
    }

    offlineSession = true;
    csrf = '';
    _apply(profile);
    if (!authenticated) {
      lastError = 'تعذر فتح جلسة محلية. حدّث البيانات عند توفر الإنترنت.';
      offlineSession = false;
      return false;
    }

    if (remember) {
      await _secure.write(key: 'u', value: username.trim());
      await _secure.write(key: 'p', value: password);
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(_kRemember, true);
    }
    await _setOfflineResume(true);
    lastError = null;
    lastInfo = 'تعمل دون اتصال — ستُرحَّل البيانات عند عودة الشبكة';
    return true;
  }

  Future<({String? u, String? p})> savedCredentials() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_kRemember) != true) {
      return (u: null, p: null);
    }
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
        // أعد ضبط معرّف الجهاز صراحةً قبل الخروج حتى يُحرَّر القفل على السيرفر.
        final device = await _deviceFields();
        api.setDevice(device['device_id']!, label: device['device_label']!);
        await api.postForm(
          AppConfig.sessionPath,
          fields: {
            'action': 'logout',
            ...device,
          },
        );
      } catch (_) {}
    }
    await api.clearCookies();
    final prefs = await SharedPreferences.getInstance();
    // بيانات "تذكّرني" تبقى بعد تسجيل الخروج لتعبئة شاشة الدخول التالية.
    // إيقاف تذكّرها يتم عند دخول لاحق مع إلغاء الخيار.
    if (prefs.getBool(_kRemember) != true) {
      await _secure.delete(key: 'u');
      await _secure.delete(key: 'p');
    }
    await prefs.setBool(_kOfflineResume, false);
    authenticated = false;
    offlineSession = false;
    lastInfo = null;
    isSystemAdmin = false;
    permissions = <String>{};
    userName = null;
    userUsername = null;
    userId = 0;
    csrf = '';
    gpsConfig = GpsTrackingConfig.defaults;
    rowsPerPage = 10;
    settingsUnlocked = false;
    notifyListeners();
  }

  Future<void> _syncGpsTracking() async {
    if (!authenticated || !gpsConfig.enabled) {
      await LocationPresenceService.stop();
      await LocationTrackingService.stop();
      return;
    }

    await LocationTrackingService.applyServerConfig(
      intervalSec: gpsConfig.intervalSec,
      minDistanceM: gpsConfig.minDistanceM,
    );

    // إذا مُنع إيقاف التتبّع من النظام — فرض التشغيل دائماً عند auto_enable.
    final explicit = await LocationTrackingService.enabledFlagOrNull;
    final forceOn = gpsConfig.autoEnable && !gpsConfig.userCanDisable;
    final shouldAutoStart = forceOn
        ? true
        : (gpsConfig.autoEnable
            ? explicit != false
            : (explicit == true || explicit == null));

    if (shouldAutoStart) {
      if (forceOn) {
        await LocationTrackingService.setEnabledFlag(true);
      }
      if (!await LocationTrackingService.isRunning) {
        final err = await LocationTrackingService.start();
        if (err != null) return;
      }
      await LocationPresenceService.resumeIfNeeded(
        api: api,
        csrf: csrf,
        authenticated: true,
        intervalSec: gpsConfig.intervalSec,
      );
      return;
    }

    await LocationPresenceService.resumeIfNeeded(
      api: api,
      csrf: csrf,
      authenticated: true,
      intervalSec: gpsConfig.intervalSec,
    );
  }

  void _apply(Map<String, dynamic> res) {
    authenticated = res['authenticated'] == true;
    csrf = (res['csrf'] as String?) ?? csrf;
    isSystemAdmin = res['is_system_admin'] == true;
    final rawGps = res['gps_tracking'];
    if (rawGps is Map) {
      gpsConfig = GpsTrackingConfig.fromJson(
        rawGps.map((k, v) => MapEntry(k.toString(), v)),
      );
    }
    final rpp = (res['rows_per_page'] as num?)?.toInt() ?? rowsPerPage;
    rowsPerPage = (rpp == 10 || rpp == 15 || rpp == 20) ? rpp : 10;
    final user = res['user'];
    if (user is Map) {
      userId = (user['id'] as num?)?.toInt() ?? 0;
      userName = user['name'] as String?;
      userUsername = user['username'] as String?;
    } else {
      userUsername = null;
    }
    final perms = res['permissions'];
    if (perms is List) {
      permissions = perms.map((e) => e.toString()).toSet();
    }
    if (!authenticated) {
      settingsUnlocked = false;
    }
    LocationPresenceService.setCsrf(csrf);
  }
}
