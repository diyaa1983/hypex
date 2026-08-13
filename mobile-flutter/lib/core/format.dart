import 'package:intl/intl.dart';

/// أدوات تنسيق الأرقام والتواريخ.
class Fmt {
  Fmt._();

  static final NumberFormat _money = NumberFormat('#,##0.###', 'en');
  static final NumberFormat _int = NumberFormat('#,##0', 'en');

  static String money(num? value) => _money.format(value ?? 0);

  static String intNum(num? value) => _int.format(value ?? 0);

  /// تحويل قيمة إلى double بأمان.
  static double toDouble(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toDouble();
    return double.tryParse(v.toString()) ?? 0;
  }

  static int toInt(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toInt();
    return int.tryParse(v.toString()) ?? 0;
  }

  static String str(dynamic v) => v == null ? '' : v.toString();

  /// رقم بدون أصفار زائدة — مناسب لحقول الإدخال.
  static String trimNum(double v) {
    if (v == v.roundToDouble()) return v.toInt().toString();
    return v.toString();
  }

  /// تاريخ ISO (Y-m-d) → d/m/Y للعرض.
  static String dmy(String? iso) {
    final v = (iso ?? '').trim();
    if (v.isEmpty) return '—';
    try {
      final d = DateTime.parse(v);
      return DateFormat('dd/MM/yyyy').format(d);
    } catch (_) {
      return v;
    }
  }

  static String dmyHm(String? iso) {
    final v = (iso ?? '').trim();
    if (v.isEmpty) return '—';
    try {
      final d = DateTime.parse(v.contains('T') ? v : v.replaceFirst(' ', 'T'));
      return DateFormat('dd/MM/yyyy HH:mm').format(d);
    } catch (_) {
      return v;
    }
  }

  /// اليوم بصيغة ISO.
  static String todayIso() => DateFormat('yyyy-MM-dd').format(DateTime.now());

  /// أول يوم في السنة الحالية بصيغة ISO.
  static String yearStartIso() =>
      DateFormat('yyyy-01-01').format(DateTime.now());
}
