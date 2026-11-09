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
import '../../widgets/ui_kit.dart';

/// تقرير طلبات الشراء لكل المندوبين أو مندوب واحد — لمدير المبيعات.
class ManagerOrdersReportScreen extends StatefulWidget {
  const ManagerOrdersReportScreen({super.key});

  @override
  State<ManagerOrdersReportScreen> createState() =>
      _ManagerOrdersReportScreenState();
}

class _ManagerOrdersReportScreenState extends State<ManagerOrdersReportScreen> {
  bool _loadingReps = true;
  bool _loading = false;
  bool _pdfBusy = false;
  String? _error;
  List<Map<String, dynamic>> _reps = [];
  List<Map<String, dynamic>> _orders = [];
  int _repId = 0;
  DateTime _from = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _to = DateTime.now();

  String get _fromIso =>
      '${_from.year.toString().padLeft(4, '0')}-${_from.month.toString().padLeft(2, '0')}-${_from.day.toString().padLeft(2, '0')}';

  String get _toIso =>
      '${_to.year.toString().padLeft(4, '0')}-${_to.month.toString().padLeft(2, '0')}-${_to.day.toString().padLeft(2, '0')}';

  double get _total =>
      _orders.fold<double>(0, (s, o) => s + Fmt.toDouble(o['total']));

  @override
  void initState() {
    super.initState();
    _loadReps();
  }

  Future<void> _loadReps() async {
    setState(() {
      _loadingReps = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.managerCustomerOrdersPath,
          );
      if (!mounted) return;
      setState(() {
        _reps = (res['reps'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _loadingReps = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loadingReps = false;
      });
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final query = <String, dynamic>{
        'report': '1',
        'from': _fromIso,
        'to': _toIso,
      };
      if (_repId > 0) query['sales_rep_id'] = '$_repId';
      final res = await context.read<ApiClient>().getJson(
            AppConfig.managerCustomerOrdersPath,
            query: query,
          );
      if (!mounted) return;
      setState(() {
        _orders = (res['orders'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _pickDate({required bool from}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: from ? _from : _to,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
  }

  String _repLabel(Map<String, dynamic> r) {
    final name = Fmt.str(r['name_ar']);
    final code = Fmt.str(r['code']);
    if (code.isEmpty) return name;
    return '$name ($code)';
  }

  String _status(Map<String, dynamic> o) {
    final sent = o['is_sent'] == true || o['is_sent'] == 1 || '${o['is_sent']}' == '1';
    if (Fmt.str(o['status']) == 'approved') return 'معتمد';
    if (sent) return 'مرحّل';
    return 'مسودة';
  }

  String _selectedRepName() {
    if (_repId < 1) return 'كل المندوبين';
    for (final r in _reps) {
      if (Fmt.toInt(r['id']) == _repId) return _repLabel(r);
    }
    return 'مندوب';
  }

  Future<void> _pdf() async {
    if (_orders.isEmpty || _pdfBusy) return;
    setState(() => _pdfBusy = true);
    try {
      final bytes = await ReportTablePdf.build(
        title: 'تقرير طلبات الشراء',
        subtitle:
            '${_selectedRepName()}  •  ${Fmt.dmy(_fromIso)} — ${Fmt.dmy(_toIso)}',
        landscape: true,
        headers: const [
          '#',
          'رقم الطلب',
          'التاريخ',
          'المندوب',
          'العميل',
          'الحالة',
          'المجموع',
        ],
        rows: [
          for (var i = 0; i < _orders.length; i++)
            [
              '${i + 1}',
              Fmt.str(_orders[i]['order_no']).isEmpty
                  ? '#${Fmt.toInt(_orders[i]['id'])}'
                  : Fmt.str(_orders[i]['order_no']),
              Fmt.dmy(Fmt.str(_orders[i]['order_date'])),
              Fmt.str(_orders[i]['sales_rep_name']).isEmpty
                  ? '—'
                  : Fmt.str(_orders[i]['sales_rep_name']),
              Fmt.str(_orders[i]['customer_name']),
              _status(_orders[i]),
              Fmt.money(Fmt.toDouble(_orders[i]['total'])),
            ],
        ],
        footer: 'عدد الطلبات: ${_orders.length}    المجموع: ${Fmt.money(_total)}',
      );
      if (!mounted) return;
      await DocumentPrintHelper.openPdfBytes(
        context,
        bytes: bytes,
        title: 'تقرير طلبات الشراء',
        fileName: 'طلبات-شراء-$_fromIso-$_toIso',
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر إنشاء PDF: $e', error: true);
    } finally {
      if (mounted) setState(() => _pdfBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('تقرير طلبات الشراء'),
      actions: [
        IconButton(
          tooltip: 'PDF',
          onPressed: _orders.isEmpty || _pdfBusy ? null : _pdf,
          icon: _pdfBusy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.picture_as_pdf_outlined),
        ),
      ],
      body: _loadingReps
          ? const Center(child: CircularProgressIndicator())
          : AsyncView(
              loading: false,
              error: _error,
              onRetry: _loadReps,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
                    child: Column(
                      children: [
                        DropdownButtonFormField<int>(
                          value: _repId,
                          isExpanded: true,
                          decoration: const InputDecoration(
                            labelText: 'المندوب',
                            border: OutlineInputBorder(),
                            filled: true,
                            fillColor: Colors.white,
                            isDense: true,
                          ),
                          items: [
                            const DropdownMenuItem(
                              value: 0,
                              child: Text('كل المندوبين'),
                            ),
                            for (final r in _reps)
                              DropdownMenuItem(
                                value: Fmt.toInt(r['id']),
                                child: Text(
                                  _repLabel(r),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                          onChanged: (v) => setState(() => _repId = v ?? 0),
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            Expanded(
                              child: _dateBox('من', Fmt.dmy(_fromIso), () => _pickDate(from: true)),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: _dateBox('إلى', Fmt.dmy(_toIso), () => _pickDate(from: false)),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        SizedBox(
                          width: double.infinity,
                          child: FilledButton.icon(
                            onPressed: _loading ? null : _load,
                            icon: const Icon(Icons.search_rounded),
                            label: Text(_loading ? 'جاري العرض…' : 'عرض التقرير'),
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (_orders.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
                      child: Text(
                        '${_orders.length} طلب  •  المجموع ${Fmt.money(_total)}',
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                    ),
                  Expanded(
                    child: _orders.isEmpty
                        ? const Center(
                            child: Text(
                              'اختر الفترة ثم اضغط عرض التقرير.',
                              style: TextStyle(
                                color: AppTheme.textSoft,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          )
                        : ListView.builder(
                            padding: const EdgeInsets.fromLTRB(12, 0, 12, 16),
                            itemCount: _orders.length,
                            itemBuilder: (_, i) {
                              final o = _orders[i];
                              final no = Fmt.str(o['order_no']).isEmpty
                                  ? '#${Fmt.toInt(o['id'])}'
                                  : Fmt.str(o['order_no']);
                              return Padding(
                                padding: const EdgeInsets.only(bottom: 8),
                                child: AppCard(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        no,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        '${Fmt.str(o['sales_rep_name']).isEmpty ? '—' : Fmt.str(o['sales_rep_name'])}  •  ${Fmt.dmy(Fmt.str(o['order_date']))}',
                                        style: const TextStyle(
                                          color: AppTheme.textSoft,
                                          fontSize: 12.5,
                                        ),
                                      ),
                                      Text(
                                        Fmt.str(o['customer_name']),
                                        style: const TextStyle(fontSize: 13),
                                      ),
                                      Text(
                                        '${_status(o)}  •  ${Fmt.money(Fmt.toDouble(o['total']))}',
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              );
                            },
                          ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _dateBox(String label, String value, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          isDense: true,
          filled: true,
          fillColor: Colors.white,
          suffixIcon: const Icon(Icons.calendar_month_rounded, size: 18),
        ),
        child: Text(value, style: const TextStyle(fontWeight: FontWeight.w800)),
      ),
    );
  }
}
