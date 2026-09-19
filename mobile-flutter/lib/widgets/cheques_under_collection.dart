import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';

/// صف شيك قيد التحصيل كما يأتي من Oracle (`chq_no`, `chq_date`, `amount`, `receipt_date`).
class ChequeUnderCollection {
  const ChequeUnderCollection({
    required this.number,
    required this.dueDate,
    required this.amount,
    required this.receiptDate,
  });

  final String number;
  final String dueDate;
  final double amount;
  final String receiptDate;

  static ChequeUnderCollection fromMap(Map<String, dynamic> m) {
    return ChequeUnderCollection(
      number: Fmt.str(
        m['chq_no'] ?? m['cheque_no'] ?? m['check_no'] ?? m['num'] ?? m['doc_no'],
      ),
      dueDate: Fmt.dmy(
        Fmt.str(m['chq_date'] ?? m['due_date'] ?? m['date'] ?? m['cheque_date']),
      ),
      amount: Fmt.toDouble(m['amount'] ?? m['amt'] ?? m['value']),
      receiptDate: Fmt.dmy(
        Fmt.str(m['receipt_date'] ?? m['recv_date'] ?? m['collection_date']),
      ),
    );
  }

  static List<ChequeUnderCollection> fromResult(Map<String, dynamic>? data) {
    final raw = data?['cheques'];
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((e) => ChequeUnderCollection.fromMap(e.cast<String, dynamic>()))
        .toList();
  }

  static double totalOf(List<ChequeUnderCollection> rows, [Map<String, dynamic>? data]) {
    final fromApi = Fmt.toDouble(data?['cheque_total']);
    if (fromApi > 0) return fromApi;
    return rows.fold<double>(0, (s, r) => s + r.amount);
  }
}

/// جدول الشيكات قيد التحصيل كما في تقرير النظام.
class ChequesUnderCollectionTable extends StatelessWidget {
  const ChequesUnderCollectionTable({
    super.key,
    required this.rows,
    this.total = 0,
  });

  final List<ChequeUnderCollection> rows;
  final double total;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'الشيكات قيد التحصيل',
          style: TextStyle(fontWeight: FontWeight.w900, fontSize: 14),
        ),
        const SizedBox(height: 8),
        Table(
          border: TableBorder.all(color: const Color(0xFFCBD5E1), width: 0.6),
          columnWidths: const {
            0: FlexColumnWidth(1.1),
            1: FlexColumnWidth(1.2),
            2: FlexColumnWidth(1.3),
            3: FlexColumnWidth(1.2),
          },
          defaultVerticalAlignment: TableCellVerticalAlignment.middle,
          children: [
            TableRow(
              decoration: const BoxDecoration(color: Color(0xFFE2E8F0)),
              children: [
                _head('الشيك'),
                _head('التاريخ'),
                _head('قيمة الشيك'),
                _head('تاريخ القبض'),
              ],
            ),
            if (rows.isEmpty)
              TableRow(
                children: [
                  _cell('لا توجد شيكات قيد التحصيل.', spanHint: true),
                  _cell(''),
                  _cell(''),
                  _cell(''),
                ],
              )
            else
              ...rows.map(
                (r) => TableRow(
                  children: [
                    _cell(r.number.isEmpty ? '—' : r.number, ltr: true),
                    _cell(r.dueDate.isEmpty ? '—' : r.dueDate, ltr: true),
                    _cell(Fmt.money(r.amount), ltr: true, bold: true),
                    _cell(r.receiptDate.isEmpty ? '—' : r.receiptDate, ltr: true),
                  ],
                ),
              ),
            TableRow(
              decoration: const BoxDecoration(color: Color(0xFFF8FAFC)),
              children: [
                _cell('مجموع الشيكات قيد التحصيل', bold: true),
                _cell(''),
                _cell(Fmt.money(total), ltr: true, bold: true),
                _cell(''),
              ],
            ),
          ],
        ),
      ],
    );
  }

  static Widget _head(String text) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 7),
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontWeight: FontWeight.w800,
            fontSize: 12,
            color: AppTheme.textSoft,
          ),
        ),
      );

  static Widget _cell(
    String text, {
    bool ltr = false,
    bool bold = false,
    bool spanHint = false,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      child: Text(
        text,
        textAlign: spanHint ? TextAlign.center : (ltr ? TextAlign.left : TextAlign.right),
        textDirection: ltr ? TextDirection.ltr : TextDirection.rtl,
        style: TextStyle(
          fontSize: spanHint ? 12 : 12.5,
          fontWeight: bold ? FontWeight.w800 : FontWeight.w600,
          color: spanHint ? AppTheme.textSoft : AppTheme.textMain,
        ),
      ),
    );
  }
}
