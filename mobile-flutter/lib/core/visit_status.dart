/// حالة زيارة العميل للعرض — اللون الأحمر بعد الخروج لنهاية اليوم فقط.
class VisitStatus {
  VisitStatus._();

  static String? _isoDay(String? raw) {
    final s = (raw ?? '').trim();
    if (s.length >= 10) return s.substring(0, 10);
    return null;
  }

  /// `checked_out` يُعاد `idle` إذا تاريخ الخروج/الدخول ≠ [referenceDate] (yyyy-MM-dd).
  static String effective({
    required String status,
    String? checkinAt,
    String? checkoutAt,
    required String referenceDate,
  }) {
    if (status != 'checked_out') return status;
    final day = _isoDay(checkoutAt) ?? _isoDay(checkinAt);
    if (day == null || day != referenceDate) return 'idle';
    return 'checked_out';
  }
}
