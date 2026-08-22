import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../services/document_print_helper.dart';
import '../../services/party_statement_bluetooth_receipt.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/cheques_under_collection.dart';
import '../../widgets/thermal_preview_screen.dart';
import '../../widgets/ui_kit.dart';

class PartyStatementScreen extends StatefulWidget {
  const PartyStatementScreen({
    super.key,
    this.initialCustomerId,
    this.initialCustomerName,
    this.initialCustomerCode,
    this.autoRun = false,
    this.embedded = false,
    this.hidePartyPicker = false,
  });

  final int? initialCustomerId;
  final String? initialCustomerName;
  final String? initialCustomerCode;
  final bool autoRun;
  /// داخل تبويب (بدون MobileScaffold وعنوان كامل).
  final bool embedded;
  final bool hidePartyPicker;

  @override
  State<PartyStatementScreen> createState() => _PartyStatementScreenState();
}

class _PartyStatementScreenState extends State<PartyStatementScreen> {
  String _type = 'customer';
  Party? _party;
  DateTime _from = DateTime(DateTime.now().year, 1, 1);
  DateTime _to = DateTime.now();

  bool _loading = false;
  bool _printBusy = false;
  bool _previewBusy = false;
  bool _pdfBusy = false;
  bool _shareBusy = false;
  String? _error;
  Map<String, dynamic>? _result;
  bool _didAutoRun = false;

  @override
  void initState() {
    super.initState();
    final id = widget.initialCustomerId ?? 0;
    if (id > 0) {
      _type = 'customer';
      _party = Party(
        id,
        (widget.initialCustomerName ?? '').trim().isEmpty
            ? 'عميل #$id'
            : widget.initialCustomerName!.trim(),
        widget.initialCustomerCode ?? '',
      );
    }
    if (widget.autoRun && id > 0) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!_didAutoRun && mounted) {
          _didAutoRun = true;
          _run();
        }
      });
    }
  }

  Future<void> _pick() async {
    final p = await pickParty(context, type: _type);
    if (p != null) setState(() => _party = p);
  }

  Future<void> _pickDate(bool from) async {
    final init = from ? _from : _to;
    final d = await showDatePicker(
      context: context,
      initialDate: init,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (d != null) {
      setState(() {
        if (from) {
          _from = d;
        } else {
          _to = d;
        }
      });
    }
  }

  Future<void> _run() async {
    if (_party == null) {
      showSnack(context, 'اختر الطرف أولاً', error: true);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final api = context.read<ApiClient>();
      final Map<String, dynamic> res;
      if (_type == 'customer') {
        res = await api.getJson(
          AppConfig.oracleCustomerStatementPath,
          query: {
            'customer_id': _party!.id,
            'from': _iso(_from),
            'to': _iso(_to),
          },
        );
      } else {
        res = await api.getJson(
          AppConfig.partyStatementPath,
          query: {
            'party_type': _type,
            'party_id': _party!.id,
            'from': _iso(_from),
            'to': _iso(_to),
          },
        );
      }
      if (!mounted) return;
      setState(() {
        _result = res;
        _loading = false;
        if (res['ok'] == false) {
          _error = Fmt.str(res['message']).isEmpty
              ? 'تعذر جلب الكشف'
              : Fmt.str(res['message']);
        }
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Map<String, dynamic> get _printPayload {
    final data = Map<String, dynamic>.from(_result ?? const {});
    data['party_type'] = _type;
    if (_party != null) {
      data['party_name'] = data['party_name'] ?? _party!.name;
      data['party_code'] = data['party_code'] ?? _party!.code;
      data['party_id'] = _party!.id;
    }
    return data;
  }

  Future<void> _printBluetooth() async {
    if (_printBusy || _result == null) return;
    setState(() => _printBusy = true);
    showSnack(context, 'جاري الطباعة...');
    try {
      final err = await PartyStatementBluetoothReceipt.printStatement(
        _printPayload,
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

  Future<void> _openPreview() async {
    if (_previewBusy || _result == null) return;
    setState(() => _previewBusy = true);
    try {
      final payload = _printPayload;
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => ThermalPreviewScreen(
            title: 'عرض كشف الحساب',
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

  Future<void> _openPdfA4() async {
    if (_pdfBusy || _result == null || _party == null) return;
    setState(() => _pdfBusy = true);
    try {
      final query = _type == 'customer'
          ? {
              'customer_id': _party!.id,
              'from': _iso(_from),
              'to': _iso(_to),
            }
          : {
              'party_type': _type,
              'party_id': _party!.id,
              'from': _iso(_from),
              'to': _iso(_to),
            };
      final path = _type == 'customer'
          ? AppConfig.oracleCustomerStatementPdfPath
          : AppConfig.partyStatementPdfPath;
      final name = 'كشف حساب - ${_party!.name}';
      if (!mounted) return;
      await DocumentPrintHelper.openPdfFromApi(
        context,
        apiPath: path,
        query: query,
        title: 'كشف الحساب',
        fileName: name,
      );
    } finally {
      if (mounted) setState(() => _pdfBusy = false);
    }
  }

  Future<void> _sharePdf() async {
    if (_shareBusy || _result == null || _party == null) return;
    setState(() => _shareBusy = true);
    try {
      final query = _type == 'customer'
          ? {
              'customer_id': _party!.id,
              'from': _iso(_from),
              'to': _iso(_to),
            }
          : {
              'party_type': _type,
              'party_id': _party!.id,
              'from': _iso(_from),
              'to': _iso(_to),
            };
      final path = _type == 'customer'
          ? AppConfig.oracleCustomerStatementPdfPath
          : AppConfig.partyStatementPdfPath;
      if (!mounted) return;
      await DocumentPrintHelper.sharePdfFromApi(
        context,
        apiPath: path,
        query: query,
        fileName: 'كشف حساب - ${_party!.name}',
      );
    } finally {
      if (mounted) setState(() => _shareBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = (_result?['rows'] as List? ??
            _result?['lines'] as List? ??
            const [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    final hasResult = _result != null &&
        _party != null &&
        (_result!['ok'] != false);

    final body = Column(
      children: [
        Card(
          margin: EdgeInsets.all(widget.embedded ? 8 : 10),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              children: [
                if (!widget.hidePartyPicker) ...[
                  Row(
                    children: [
                      ChoiceChip(
                        label: const Text('عميل Oracle'),
                        selected: _type == 'customer',
                        onSelected: (_) => setState(() {
                          _type = 'customer';
                          _party = null;
                          _result = null;
                          _error = null;
                        }),
                      ),
                      const SizedBox(width: 8),
                      ChoiceChip(
                        label: const Text('مورد'),
                        selected: _type == 'supplier',
                        onSelected: (_) => setState(() {
                          _type = 'supplier';
                          _party = null;
                          _result = null;
                          _error = null;
                        }),
                      ),
                    ],
                  ),
                  if (_type == 'customer')
                    const Padding(
                      padding: EdgeInsets.only(bottom: 6),
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Text(
                          'نفس بيانات كشف الحساب في تقرير Oracle على النظام',
                          style: TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSoft,
                            height: 1.35,
                          ),
                        ),
                      ),
                    ),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.badge_outlined),
                    title: Text(
                      _party?.name ??
                          (_type == 'customer' ? 'اختر العميل' : 'اختر المورد'),
                    ),
                    trailing: const Icon(Icons.chevron_left),
                    onTap: _pick,
                  ),
                ] else if (_party != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Align(
                      alignment: Alignment.centerRight,
                      child: Text(
                        _party!.name,
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 14,
                        ),
                      ),
                    ),
                  ),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        icon: const Icon(Icons.calendar_today, size: 16),
                        label: Text('من: ${Fmt.dmy(_iso(_from))}'),
                        onPressed: () => _pickDate(true),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton.icon(
                        icon: const Icon(Icons.calendar_today, size: 16),
                        label: Text('إلى: ${Fmt.dmy(_iso(_to))}'),
                        onPressed: () => _pickDate(false),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                FilledButton.icon(
                  onPressed: _loading ? null : _run,
                  icon: const Icon(Icons.search),
                  label: const Text('عرض الكشف'),
                ),
                if (hasResult) ...[
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: ActionChipButton(
                          icon: Icons.print_outlined,
                          label: 'طباعة',
                          busy: _printBusy,
                          onTap: _printBluetooth,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: ActionChipButton(
                          icon: Icons.picture_as_pdf_outlined,
                          label: 'PDF',
                          color: AppTheme.primary,
                          busy: _pdfBusy,
                          onTap: _openPdfA4,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: ActionChipButton(
                          icon: Icons.share_outlined,
                          label: 'مشاركة PDF',
                          color: AppTheme.teal,
                          busy: _shareBusy,
                          onTap: _sharePdf,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: ActionChipButton(
                          icon: Icons.receipt_long_outlined,
                          label: 'عرض حراري',
                          busy: _previewBusy,
                          onTap: _openPreview,
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
        Expanded(
          child: AsyncView(
            loading: _loading,
            error: _error,
            onRetry: _run,
            child: _result == null
                ? const EmptyState(
                    message: 'اختر الفترة ثم اعرض الكشف.',
                    icon: Icons.menu_book_outlined,
                  )
                : Column(
                    children: [
                      _summary(),
                      _chequesBlock(),
                      const Divider(height: 1),
                      Expanded(
                        child: rows.isEmpty
                            ? const EmptyState(message: 'لا توجد حركات.')
                            : Column(
                                children: [
                                  _stmtHeader(),
                                  Expanded(
                                    child: ListView.separated(
                                      itemCount: rows.length,
                                      separatorBuilder: (_, __) =>
                                          const Divider(height: 1),
                                      itemBuilder: (_, i) =>
                                          _rowTile(rows[i]),
                                    ),
                                  ),
                                ],
                              ),
                      ),
                    ],
                  ),
          ),
        ),
      ],
    );

    if (widget.embedded) {
      return Material(color: const Color(0xFFF0F4F8), child: body);
    }

    return MobileScaffold(
      title: Text(_type == 'customer' ? 'كشف حساب عميل (Oracle)' : 'كشف حساب'),
      body: body,
    );
  }

  Widget _summary() {
    final r = _result!;
    final rep = Fmt.str(r['sales_rep_name'] ?? r['sales_rep_names']);
    return Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        children: [
          if (rep.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text('المندوب: $rep',
                  style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _stat('مدين', Fmt.money(Fmt.toDouble(r['total_debit']))),
              _stat('دائن', Fmt.money(Fmt.toDouble(r['total_credit']))),
              _stat(
                'الرصيد',
                Fmt.money(
                  Fmt.toDouble(
                    r['balance'] ?? r['closing_balance'],
                  ),
                ),
                bold: true,
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _chequesBlock() {
    if (_type != 'customer' || _result == null) {
      return const SizedBox.shrink();
    }
    final rows = ChequeUnderCollection.fromResult(_result);
    final total = ChequeUnderCollection.totalOf(rows, _result);
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 10),
      child: ChequesUnderCollectionTable(rows: rows, total: total),
    );
  }

  Widget _stat(String label, String value, {bool bold = false}) {
    return Column(
      children: [
        Text(label,
            style: const TextStyle(fontSize: 12, color: Colors.black54)),
        const SizedBox(height: 2),
        Text(
          value,
          textDirection: TextDirection.ltr,
          style: TextStyle(
            fontWeight: bold ? FontWeight.bold : FontWeight.w600,
          ),
        ),
      ],
    );
  }

  Widget _stmtHeader() {
    const style = TextStyle(
      fontWeight: FontWeight.w800,
      fontSize: 11,
      color: Color(0xFF475569),
    );
    return Container(
      color: const Color(0xFFE2E8F0),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      child: const Row(
        children: [
          SizedBox(
            width: 68,
            child: Text('التاريخ', style: style, textAlign: TextAlign.center),
          ),
          Expanded(child: Text('البيان', style: style)),
          SizedBox(
            width: 64,
            child: Text('مدين', style: style, textAlign: TextAlign.center),
          ),
          SizedBox(
            width: 64,
            child: Text('دائن', style: style, textAlign: TextAlign.center),
          ),
          SizedBox(
            width: 72,
            child: Text('رصيد', style: style, textAlign: TextAlign.center),
          ),
        ],
      ),
    );
  }

  Widget _rowTile(Map<String, dynamic> row) {
    final desc = Fmt.str(
      row['description'] ??
          row['remark'] ??
          row['doc_type'] ??
          row['doc_no'] ??
          row['type'],
    );
    final date = Fmt.dmy(
      Fmt.str(row['trn_date'] ?? row['date'] ?? row['doc_date']),
    );
    final debit = Fmt.toDouble(row['debit']);
    final credit = Fmt.toDouble(row['credit']);
    final balance = Fmt.toDouble(row['balance'] ?? row['running_balance']);
    const numStyle = TextStyle(fontSize: 11, fontWeight: FontWeight.w600);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 68,
            child: Text(
              date.isEmpty ? '—' : date,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 11),
            ),
          ),
          Expanded(
            child: Text(
              desc.isEmpty ? '—' : desc,
              style: const TextStyle(fontSize: 12),
            ),
          ),
          SizedBox(
            width: 64,
            child: Text(
              debit > 0 ? Fmt.money(debit) : '',
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: numStyle,
            ),
          ),
          SizedBox(
            width: 64,
            child: Text(
              credit > 0 ? Fmt.money(credit) : '',
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: numStyle,
            ),
          ),
          SizedBox(
            width: 72,
            child: Text(
              Fmt.money(balance),
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800),
            ),
          ),
        ],
      ),
    );
  }
}
