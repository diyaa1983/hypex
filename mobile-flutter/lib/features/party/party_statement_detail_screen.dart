import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../services/document_print_helper.dart';
import '../../services/party_statement_bluetooth_receipt.dart';
import '../../widgets/async_view.dart';
import '../../widgets/cheques_under_collection.dart';
import '../../widgets/thermal_preview_screen.dart';

/// عرض كشف الحساب التفصيلي على الشاشة قبل الطباعة.
class PartyStatementDetailScreen extends StatefulWidget {
  const PartyStatementDetailScreen({
    super.key,
    required this.payload,
    this.pdfPath,
    this.pdfQuery,
    this.fileName = 'كشف حساب',
  });

  final Map<String, dynamic> payload;
  final String? pdfPath;
  final Map<String, dynamic>? pdfQuery;
  final String fileName;

  @override
  State<PartyStatementDetailScreen> createState() =>
      _PartyStatementDetailScreenState();
}

class _PartyStatementDetailScreenState
    extends State<PartyStatementDetailScreen> {
  bool _printBusy = false;
  bool _pdfBusy = false;
  bool _previewBusy = false;

  List<Map<String, dynamic>> get _rows {
    final raw = widget.payload['rows'] ?? widget.payload['lines'];
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
  }

  Future<void> _printBluetooth() async {
    if (_printBusy) return;
    setState(() => _printBusy = true);
    showSnack(context, 'جاري الطباعة...');
    try {
      final err = await PartyStatementBluetoothReceipt.printStatement(
        widget.payload,
      );
      if (!mounted) return;
      if (err != null) {
        showSnack(context, err, error: true);
      } else {
        showSnack(context, 'تم إرسال الكشف للطابعة.');
      }
    } finally {
      if (mounted) setState(() => _printBusy = false);
    }
  }

  Future<void> _openPdf() async {
    final path = widget.pdfPath;
    if (_pdfBusy || path == null || path.isEmpty) return;
    setState(() => _pdfBusy = true);
    try {
      await DocumentPrintHelper.openPdfFromApi(
        context,
        apiPath: path,
        query: widget.pdfQuery,
        title: 'كشف حساب تفصيلي',
        fileName: widget.fileName,
      );
    } finally {
      if (mounted) setState(() => _pdfBusy = false);
    }
  }

  Future<void> _openThermal() async {
    if (_previewBusy) return;
    setState(() => _previewBusy = true);
    try {
      final payload = widget.payload;
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => ThermalPreviewScreen(
            title: 'عرض حراري',
            buildPdf: (paperMm) =>
                PartyStatementBluetoothReceipt.buildThermalPdf(
              payload,
              paperMm: paperMm,
            ),
            onPrint: (ctx) async {
              showSnack(ctx, 'جاري الطباعة...');
              final err =
                  await PartyStatementBluetoothReceipt.printStatement(payload);
              if (!ctx.mounted) return;
              if (err != null) {
                showSnack(ctx, err, error: true);
              } else {
                showSnack(ctx, 'تم إرسال الكشف للطابعة.');
              }
            },
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _previewBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = widget.payload;
    final name = Fmt.str(data['party_name']).isEmpty
        ? 'العميل'
        : Fmt.str(data['party_name']);
    final code = Fmt.str(data['party_code']);
    final from = Fmt.dmy(Fmt.str(data['from_dmy'] ?? data['from']));
    final to = Fmt.dmy(Fmt.str(data['to_dmy'] ?? data['to']));
    final rep = Fmt.str(data['sales_rep_name'] ?? data['sales_rep_names']);
    final rows = _rows;
    final cheques = ChequeUnderCollection.fromResult(data);
    final chequeTotal = ChequeUnderCollection.totalOf(cheques, data);

    return Scaffold(
      backgroundColor: const Color(0xFFF0F4F8),
      appBar: AppBar(
        title: const Text('كشف حساب تفصيلي'),
      ),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            color: Colors.white,
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 17,
                  ),
                ),
                const SizedBox(height: 4),
                Wrap(
                  spacing: 14,
                  runSpacing: 4,
                  children: [
                    if (code.isNotEmpty)
                      Text(
                        'الرمز: $code',
                        style: const TextStyle(
                          color: AppTheme.textSoft,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    Text(
                      'الفترة: $from  —  $to',
                      style: const TextStyle(
                        color: AppTheme.textSoft,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (rep.isNotEmpty)
                      Text(
                        'المندوب: $rep',
                        style: const TextStyle(
                          color: AppTheme.textSoft,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    _totalChip(
                      'مدين',
                      Fmt.money(Fmt.toDouble(data['total_debit'])),
                    ),
                    const SizedBox(width: 8),
                    _totalChip(
                      'دائن',
                      Fmt.money(Fmt.toDouble(data['total_credit'])),
                    ),
                    const SizedBox(width: 8),
                    _totalChip(
                      'الرصيد',
                      Fmt.money(
                        Fmt.toDouble(
                          data['balance'] ?? data['closing_balance'],
                        ),
                      ),
                      emphasize: true,
                    ),
                  ],
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(10, 8, 10, 16),
              children: [
                _headerRow(),
                if (rows.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 40),
                    child: EmptyState(message: 'لا توجد حركات.'),
                  )
                else
                  ...rows.map(_detailRow),
                if (cheques.isNotEmpty || chequeTotal > 0) ...[
                  const SizedBox(height: 16),
                  ChequesUnderCollectionTable(
                    rows: cheques,
                    total: chequeTotal,
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
          child: Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: _printBusy ? null : _printBluetooth,
                  icon: _printBusy
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.print_outlined, size: 20),
                  label: const Text('طباعة'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _pdfBusy || widget.pdfPath == null
                      ? null
                      : _openPdf,
                  icon: _pdfBusy
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.picture_as_pdf_outlined, size: 20),
                  label: const Text('PDF'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _previewBusy ? null : _openThermal,
                  icon: const Icon(Icons.receipt_long_outlined, size: 20),
                  label: const Text('حراري'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _totalChip(String label, String value, {bool emphasize = false}) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: emphasize
              ? AppTheme.primary.withValues(alpha: 0.08)
              : const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          children: [
            Text(
              label,
              style: const TextStyle(
                fontSize: 11.5,
                color: AppTheme.textSoft,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              value,
              textDirection: TextDirection.ltr,
              style: TextStyle(
                fontWeight: FontWeight.w900,
                fontSize: 13.5,
                color: emphasize ? AppTheme.primary : AppTheme.textMain,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _headerRow() {
    const style = TextStyle(
      fontWeight: FontWeight.w800,
      fontSize: 12,
      color: Color(0xFF475569),
    );
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFFE2E8F0),
        borderRadius: BorderRadius.circular(8),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 9),
      child: const Row(
        children: [
          SizedBox(
            width: 78,
            child: Text('التاريخ', style: style, textAlign: TextAlign.center),
          ),
          SizedBox(
            width: 64,
            child: Text('السند', style: style, textAlign: TextAlign.center),
          ),
          SizedBox(
            width: 112,
            child: Text('النوع', style: style),
          ),
          Expanded(child: Text('البيان', style: style)),
          SizedBox(
            width: 78,
            child: Text('مدين', style: style, textAlign: TextAlign.center),
          ),
          SizedBox(
            width: 78,
            child: Text('دائن', style: style, textAlign: TextAlign.center),
          ),
          SizedBox(
            width: 86,
            child: Text('رصيد', style: style, textAlign: TextAlign.center),
          ),
        ],
      ),
    );
  }

  Widget _detailRow(Map<String, dynamic> row) {
    final opening = row['is_opening'] == true || row['is_opening'] == 1;
    final date = Fmt.dmy(
      Fmt.str(row['trn_date'] ?? row['date'] ?? row['doc_date']),
    );
    final docNo = Fmt.str(row['doc_no']);
    final type = Fmt.str(row['doc_type'] ?? row['type']);
    final desc = Fmt.str(row['description'] ?? row['remark']);
    final debit = Fmt.toDouble(row['debit']);
    final credit = Fmt.toDouble(row['credit']);
    final balance = Fmt.toDouble(row['balance'] ?? row['running_balance']);
    const numStyle = TextStyle(fontSize: 12, fontWeight: FontWeight.w700);

    return Container(
      decoration: BoxDecoration(
        color: opening ? const Color(0xFFF8FAFC) : Colors.white,
        border: const Border(
          bottom: BorderSide(color: Color(0xFFE2E8F0)),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 78,
            child: Text(
              date.isEmpty ? '—' : date,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12),
            ),
          ),
          SizedBox(
            width: 64,
            child: Text(
              docNo.isEmpty ? '—' : docNo,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
            ),
          ),
          SizedBox(
            width: 112,
            child: Text(
              type.isEmpty ? (opening ? 'رصيد' : '—') : type,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
            ),
          ),
          Expanded(
            child: Text(
              desc.isEmpty ? '—' : desc,
              style: const TextStyle(fontSize: 12.5, height: 1.35),
            ),
          ),
          SizedBox(
            width: 78,
            child: Text(
              debit > 0 ? Fmt.money(debit) : '',
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: numStyle,
            ),
          ),
          SizedBox(
            width: 78,
            child: Text(
              credit > 0 ? Fmt.money(credit) : '',
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: numStyle,
            ),
          ),
          SizedBox(
            width: 86,
            child: Text(
              Fmt.money(balance),
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w900),
            ),
          ),
        ],
      ),
    );
  }
}
