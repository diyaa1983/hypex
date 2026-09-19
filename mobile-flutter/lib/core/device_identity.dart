import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// معرّف ثابت لهذا الجهاز — يُرسل مع الدخول ونبضات GPS.
class DeviceIdentity {
  DeviceIdentity._();

  static const _key = 'device_id';
  static const _secure = FlutterSecureStorage();
  static String? _cached;

  static Future<String> id() async {
    if (_cached != null && _cached!.isNotEmpty) return _cached!;
    var stored = await _secure.read(key: _key);
    if (stored == null || stored.trim().isEmpty) {
      stored = _generate();
      await _secure.write(key: _key, value: stored);
    }
    _cached = stored.trim();
    return _cached!;
  }

  static String _generate() {
    final r = Random.secure();
    final bytes = List<int>.generate(16, (_) => r.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}
