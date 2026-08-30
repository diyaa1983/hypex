import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../services/document_print_helper.dart';
import '../../services/report_table_pdf.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/item_picker.dart';
import '../../widgets/ui_kit.dart';

class SalesMovementScreen extends StatefulWidget {
  const SalesMovementScreen({super.key});

  @override
  State<SalesMovementScreen> createState() => _SalesMovementScreenState();
}

class _SalesMovementScreenState extends State<SalesMovementScreen> {
  bool _loading = true;
  String? _error;
  String _from = '';
  String _to = '';
  int _customerId = 0;
  String _customerName = '';
  int _itemId = 0;
  String _itemLabel = '';
  List<Map<String, dynamic>> _rows = [];
  double _totalQty = 0;
  double _totalAmount = 0;
  bool _pdfBusy = false;
  bool _shareBusy = false;

  @override
  void initState() {
    super.initState();
    _to = Fmt.todayIso();
    final now = DateTime.now();
    _from =
        '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-01';
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.salesMovementPath,
            query: {
              'from': _from,
              'to': _to,
              if (_customerId > 0) 'customer_id': '$_customerId',
              if (_itemId > 0) 'item_id': '$_itemId',
            },
          );
      if (!mounted) return;
      final totals = (res['totals'] as Map?)?.cast<String, dynamic>() ?? {};
      setState(() {
        _from = Fmt.str(res['from']).isEmpty ? _from : Fmt.str(res['from']);
        _to = Fmt.str(res['to']).isEmpty ? _to : Fmt.str(res['to']);
        _rows = (res['rows'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _totalQty = Fmt.toDouble(totals['qty']);
        _totalAmount = Fmt.toDouble(totals['amount']);
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _pickDate(bool isFrom) async {
    final current = DateTime.tryParse(isFrom ? _from : _to) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: current,
      firstDate: DateTime(current.year - 3),
      lastDate: DateTime(current.year + 1),
    );
    if (picked == null) return;
    final iso =
        '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
    setState(() {
      if (isFrom) {
        _from = iso;
      } else {
        _to = iso;
      }
    });
    await _load();
  }

  Future<void> _pickCustomer() async {
    final picked = await pickParty(context, type: 'customer');
    if (picked == null) return;
    setState(() {
      _customerId = picked.id;
      _customerName = picked.name;
    });
    await _load();
  }

  Future<void> _pickItem() async {
    final picked = await pickItem(context, warehouseId: 0);
    if (picked == null) return;
    setState(() {
      _itemId = picked.id;
      _itemLabel = picked.name;
    });
    await _load();
  }

  void _clearCustomer() {
    setState(() {
      _customerId = 0;
      _customerName = '';
    });
    _load();
  }

  void _clearItem() {
    setState(() {
      _itemId = 0;
      _itemLabel = '';
    });
    _load();
  }

  List<String> _headers() => const [
        '#',
        'التاريخ',
        'النوع / الرقم',
        'اسم العميل',
        'المادة',
        'الكمية',
        'المجموع',
      ];

  List<String> _cells(Map<String, dynamic> r, int i) {
    final invNo = Fmt.str(r['invoice_no']);
    final source = Fmt.str(r['source_label']);
    final item = Fmt.str(r['item_name']);
    final code = Fmt.str(r['item_code']);
    return [
      '${i + 1}',
      Fmt.dmy(Fmt.str(r['invoice_date'])),
      source.isNotEmpty ? '$source $invNo' : invNo,
      Fmt.str(r['customer_name']).isEmpty ? '—' : Fmt.str(r['customer_name']),
      code.isNotEmpty ? '$item ($code)' : item,
      Fmt.trimNum(Fmt.toDouble(r['qty'])),
      Fmt.money(Fmt.toDouble(r['line_total'])),
    ];
  }

  Future<Uint8List> _buildPdf() {
    return ReportTablePdf.build(
      title: 'كشف حركات المبيعات',
      subtitle:
          'من ${Fmt.dmy(_from)} إلى ${Fmt.dmy(_to)} · ${_rows.length} سطر · كمية ${Fmt.trimNum(_totalQty)} · الإجمالي ${Fmt.money(_totalAmount)}',
      headers: _headers(),
      rows: [
        for (var i = 0; i < _rows.length; i++) _cells(_rows[i], i),
      ],
      footer:
          'الإجمالي: ${Fmt.money(_totalAmount)}  ·  الكمية: ${Fmt.trimNum(_totalQty)}',
      landscape: true,
    );
  }

  Future<void> _openPdf() async {
    if (_rows.isEmpty || _pdfBusy) return;
    setState(() => _pdfBusy = true);
    try {
      final bytes = await _buildPdf();
      if (!mounted) return;
      await DocumentPrintHelper.openPdfBytes(
        context,
        bytes: bytes,
        title: 'كشف حركات المبيعات',
        fileName: 'حركات-مبيعات-$_from-$_to',
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر إنشاء PDF: $e', error: true);
    } finally {
      if (mounted) setState(() => _pdfBusy = false);
    }
  }

  Future<void> _sharePdf() async {
    if (_rows.isEmpty || _shareBusy) return;
    setState(() => _shareBusy = true);
    try {
      final bytes = await _buildPdf();
      if (!mounted) return;
      await DocumentPrintHelper.sharePdfBytes(
        bytes,
        fileName: 'sales-movement-$_from-$_to',
        context: context,
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر مشاركة PDF: $e', error: true);
    } finally {
      if (mounted) setState(() => _shareBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('كشف حركات المبيعات'),
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.all(14),
            children: [
              AppCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'فواتير مبيعات مؤكدة + طلبات شراء معتمدة — مواد نشطة فقط.',
                      style: TextStyle(
                        fontSize: 13,
                        color: AppTheme.textSoft,
                        height: 1.4,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: _loading ? null : () => _pickDate(true),
                            child: Text('من: ${Fmt.dmy(_from)}'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: OutlinedButton(
                            onPressed: _loading ? null : () => _pickDate(false),
                            child: Text('إلى: ${Fmt.dmy(_to)}'),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: _loading ? null : _pickCustomer,
                      icon: const Icon(Icons.person_outline_rounded),
                      label: Text(
                        _customerId > 0
                            ? 'العميل: $_customerName'
                            : 'كل العملاء — اختر عميلاً',
                      ),
                    ),
                    if (_customerId > 0)
                      TextButton(onPressed: _clearCustomer, child: const Text('مسح العميل')),
                    const SizedBox(height: 6),
                    OutlinedButton.icon(
                      onPressed: _loading ? null : _pickItem,
                      icon: const Icon(Icons.inventory_2_outlined),
                      label: Text(
                        _itemId > 0 ? 'المادة: $_itemLabel' : 'كل المواد — اختر مادة',
                      ),
                    ),
                    if (_itemId > 0)
                      TextButton(onPressed: _clearItem, child: const Text('مسح المادة')),
                    const SizedBox(height: 10),
                    FilledButton.icon(
                      onPressed: _loading ? null : _load,
                      icon: const Icon(Icons.search_rounded),
                      label: const Text('عرض'),
                    ),
                    if (_rows.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Expanded(
                            child: ActionChipButton(
                              icon: Icons.picture_as_pdf_outlined,
                              label: 'تحويل',
                              color: AppTheme.primary,
                              busy: _pdfBusy,
                              onTap: _openPdf,
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: ActionChipButton(
                              icon: Icons.share_outlined,
                              label: 'مشاركة PDF',
                              color: AppTheme.teal,
                              busy: _shareBusy,
                              onTap: _sharePdf,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 12),
              if (_rows.isEmpty && !_loading)
                const AppCard(
                  child: Text(
                    'لا حركات في الفترة المحددة.',
                    style: TextStyle(color: AppTheme.textSoft),
                  ),
                )
              else ...[
                Text(
                  '${_rows.length} سطر · كمية ${Fmt.trimNum(_totalQty)}',
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 8),
                LinedReportTable(
                  headers: _headers(),
                  rows: [
                    for (var i = 0; i < _rows.length; i++) _cells(_rows[i], i),
                  ],
                  numericCols: const {0, 5, 6},
                ),
                const SizedBox(height: 8),
                ReportTotalBar(label: 'الإجمالي: ${Fmt.money(_totalAmount)}'),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
