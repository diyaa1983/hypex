/// أكواد وفترات السداد المستخدمة في الموبايل (نفس قيم السيرفر).
class PaymentPeriod {
  PaymentPeriod._();

  static const Map<String, String> options = {
    'cash_with_vehicle': 'كاش مع السيارة',
    'cash_with_rep': 'نقدي مع المندوب',
    'credit': 'ذمم',
  };

  static const String defaultCode = 'cash_with_rep';

  static String codeOf(dynamic v) {
    final s = (v ?? '').toString().trim();
    if (options.containsKey(s)) return s;
    return '';
  }

  static String labelOf(dynamic v) {
    final code = codeOf(v);
    if (code.isEmpty) return '';
    return options[code] ?? '';
  }

  static String displayOf(dynamic v, {String empty = '—'}) {
    final label = labelOf(v);
    return label.isEmpty ? empty : label;
  }
}
