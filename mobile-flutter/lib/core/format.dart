import 'package:intl/intl.dart';

/// أدوات تنسيق الأرقام والتواريخ — متوافقة مع إعدادات النظام (ويندوز).
class Fmt {
  Fmt._();

  /// خانات المبالغ العامة (رصيد، مجموع، ضريبة…) — من إعدادات النظام.
  static int decimalPlaces = 3;

  /// خانات أسعار الوحدة / المادة — من إعدادات النظام.
  static int unitPriceDecimalPlaces = 3;

  static const int _maxDp = 10;

  static int _clampDp(int? v, [int fallback = 3]) {
    final d = v ?? fallback;
    if (d < 0) return 0;
    if (d > _maxDp) return _maxDp;
    return d;
  }

  /// تطبيق إعدادات الخانات من ميتا المزامنة / شاشة الطلب.
  static void applySettings({
    dynamic decimalPlaces,
    dynamic unitPriceDecimalPlaces,
  }) {
    final amount = _clampDp(
      int.tryParse('$decimalPlaces') ??
          (decimalPlaces is num ? decimalPlaces.toInt() : null),
      3,
    );
    final unit = _clampDp(
      int.tryParse('$unitPriceDecimalPlaces') ??
          (unitPriceDecimalPlaces is num
              ? unitPriceDecimalPlaces.toInt()
              : null),
      amount,
    );
    Fmt.decimalPlaces = amount;
    Fmt.unitPriceDecimalPlaces = unit;
  }

  static NumberFormat _fixed(int dp, {bool grouping = true}) {
    final d = _clampDp(dp);
    final pattern = grouping
        ? (d <= 0 ? '#,##0' : '#,##0.${'0' * d}')
        : (d <= 0 ? '0' : '0.${'0' * d}');
    return NumberFormat(pattern, 'en');
  }

  static double roundTo(num? value, int dp) {
    final d = _clampDp(dp);
    final x = (value ?? 0).toDouble();
    if (!x.isFinite) return 0;
    final p = _pow10(d);
    return (x * p).round() / p;
  }

  static double _pow10(int d) {
    var p = 1.0;
    for (var i = 0; i < d; i++) {
      p *= 10;
    }
    return p;
  }

  static double roundAmount(num? value) => roundTo(value, decimalPlaces);

  static double roundUnitPrice(num? value) =>
      roundTo(value, unitPriceDecimalPlaces);

  /// مبالغ عامة بخانات النظام الثابتة (مثل ويندوز).
  static String money(num? value) =>
      _fixed(decimalPlaces).format(roundAmount(value));

  /// سعر الوحدة / المادة بخانات إعداد السعر في النظام.
  static String unitPrice(num? value) => _fixed(
        unitPriceDecimalPlaces,
        grouping: false,
      ).format(roundUnitPrice(value));

  static String intNum(num? value) => NumberFormat('#,##0', 'en').format(value ?? 0);

  /// تحويل قيمة إلى double بأمان.
  static double toDouble(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toDouble();
    return double.tryParse(v.toString().replaceAll(',', '')) ?? 0;
  }

  static int toInt(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toInt();
    return int.tryParse(v.toString()) ?? 0;
  }

  static String str(dynamic v) => v == null ? '' : v.toString();

  /// بيان حركة الكشف بدون «من المستودع رقم 4».
  static String statementDesc(dynamic v) {
    var s = str(v).trim();
    if (s.isEmpty) return '';
    s = s.replaceAll(
      RegExp(r'\s*من\s+المستودع\s*(?:رقم\s*)?4\s*', unicode: true),
      ' ',
    );
    s = s.replaceAll(
      RegExp(r'\s*المستودع\s*(?:رقم\s*)?4\s*', unicode: true),
      ' ',
    );
    s = s.replaceAll(
      RegExp(r'\s*مستودع\s*(?:رقم\s*)?4\s*', unicode: true),
      ' ',
    );
    s = s.replaceAll(RegExp(r'\s{2,}'), ' ').trim();
    return s.replaceAll(RegExp(r'^[\s\-/·|,]+|[\s\-/·|,]+$'), '');
  }

  /// رقم بدون أصفار زائدة — مناسب للكميات وحقول الإدخال العامة.
  static String trimNum(double v) {
    if (v == v.roundToDouble()) return v.toInt().toString();
    final s = v.toStringAsFixed(6);
    return s.replaceFirst(RegExp(r'\.?0+$'), '');
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
