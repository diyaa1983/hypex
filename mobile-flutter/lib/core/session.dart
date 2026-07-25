import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_client.dart';
import 'config.dart';

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

  /// تحميل العنوان المحفوظ ومحاولة استرجاع الجلسة.
  Future<void> boot() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_kServer) ?? '';
    api.setBase(saved.isEmpty ? AppConfig.defaultServerBase : saved);
    if (saved.isNotEmpty) {
      try {
        await refreshMe();
      } catch (_) {
        authenticated = false;
      }
    }
    booting = false;
    notifyListeners();
  }

  bool get hasServer => api.base.isNotEmpty;

  Future<void> saveServer(String raw) async {
    api.setBase(raw);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kServer, api.base);
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
    final res = await api.getJson(
      AppConfig.sessionPath,
      query: {'action': 'me'},
    );
    _apply(res);
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
      final res = await api.postForm(
        AppConfig.sessionPath,
        fields: {
          'action': 'login',
          'username': username,
          'password': password,
        },
      );
      _apply(res);
      if (authenticated && remember) {
        await _secure.write(key: 'u', value: username);
        await _secure.write(key: 'p', value: password);
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
    try {
      await api.postForm(AppConfig.sessionPath, fields: {'action': 'logout'});
    } catch (_) {}
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
  }
}
