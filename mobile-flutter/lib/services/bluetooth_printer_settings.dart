import 'package:shared_preferences/shared_preferences.dart';

/// إعدادات طابعة Bluetooth المحفوظة (مشتركة لكل شاشات التطبيق).
class BluetoothPrinterSettings {
  BluetoothPrinterSettings._();

  static const _kMac = 'bt_printer_mac';
  static const _kName = 'bt_printer_name';
  static const _kPaper = 'bt_printer_paper'; // 58 | 80

  static Future<BluetoothPrinterConfig> load() async {
    final p = await SharedPreferences.getInstance();
    return BluetoothPrinterConfig(
      mac: (p.getString(_kMac) ?? '').trim(),
      name: (p.getString(_kName) ?? '').trim(),
      paperMm: p.getInt(_kPaper) == 80 ? 80 : 58,
    );
  }

  static Future<void> save(BluetoothPrinterConfig cfg) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kMac, cfg.mac.trim());
    await p.setString(_kName, cfg.name.trim());
    await p.setInt(_kPaper, cfg.paperMm == 80 ? 80 : 58);
  }

  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kMac);
    await p.remove(_kName);
  }
}

class BluetoothPrinterConfig {
  const BluetoothPrinterConfig({
    required this.mac,
    required this.name,
    this.paperMm = 58,
  });

  final String mac;
  final String name;
  final int paperMm;

  bool get isConfigured => mac.isNotEmpty;

  String get displayLabel {
    if (!isConfigured) return 'لم تُحدَّد طابعة';
    if (name.isNotEmpty) return name;
    return mac;
  }

  BluetoothPrinterConfig copyWith({
    String? mac,
    String? name,
    int? paperMm,
  }) {
    return BluetoothPrinterConfig(
      mac: mac ?? this.mac,
      name: name ?? this.name,
      paperMm: paperMm ?? this.paperMm,
    );
  }
}
