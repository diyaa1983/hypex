import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../services/document_print_helper.dart';
import '../../services/report_table_pdf.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class RepVisitReportScreen extends StatefulWidget {
  const RepVisitReportScreen({super.key});

  @override
  State<RepVisitReportScreen> createState() => _RepVisitReportScreenState();
}

class _RepVisitReportScreenState extends State<RepVisitReportScreen> {
  bool _loading = true;
  String? _error;
  String _from = '';
  String _to = '';
  List<Map<String, dynamic>> _rows = [];
  List<Map<String, dynamic>> _customers = [];
  int _customerId = 0;
  int _orderCount = 0;
  double _orderTotal = 0;
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
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        await _loadLocal();
        return;
      }
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitReportPath,
            query: {
              'from': _from,
              'to': _to,
              if (_customerId > 0) 'customer_id': '$_customerId',
            },
          );
      if (!mounted) return;
      setState(() {
        _from = Fmt.str(res['from']).isEmpty ? _from : Fmt.str(res['from']);
        _to = Fmt.str(res['to']).isEmpty ? _to : Fmt.str(res['to']);
        _rows = (res['visits'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _customers = (res['customers'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _orderCount = Fmt.toInt(res['order_count']);
        _orderTotal = Fmt.toDouble(res['order_total']);
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        await _loadLocal();
        return;
      }
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

  Future<void> _loadLocal() async {
    final store = OfflineStore.instance;
    final rows = await store.visitReportRows(
      from: _from,
      to: _to,
      customerId: _customerId,
    );
    final custMap = <int, Map<String, dynamic>>{};
    var orderCount = 0;
    var orderTotal = 0.0;
    for (final r in rows) {
      final cid = Fmt.toInt(r['customer_id']);
      if (cid != 0 && !custMap.containsKey(cid)) {
        custMap[cid] = {
          'id': cid,
          'name': Fmt.str(r['customer_name']),
          'code': Fmt.str(r['customer_code']),
        };
      }
      orderCount += Fmt.toInt(r['order_count']);
      orderTotal += Fmt.toDouble(r['order_total']);
    }
    if (!mounted) return;
    setState(() {
      _rows = rows;
      _customers = custMap.values.toList();
      _orderCount = orderCount;
      _orderTotal = orderTotal;
      _loading = false;
    });
  }

  Future<void> _pick(bool isFrom) async {
    final current = DateTime.tryParse(isFrom ? _from : _to) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: current,
      firstDate: DateTime(current.year - 2),
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

  List<String> _visitHeaders() => const [
        '#',
        'العميل',
        'الرمز',
        'التاريخ',
        'الحالة',
        'الجولة',
        'رقم الطلب',
        'مبلغ الطلب',
        'سبب عدم الطلب',
        'دخول',
        'طريقة الدخول',
        'خروج',
        'طريقة الخروج',
        'المدة',
      ];

  List<String> _visitCells(Map<String, dynamic> row, int i) {
    final inMethod = Fmt.str(row['checkin_method_label']).isEmpty
        ? Fmt.str(row['checkin_method'])
        : Fmt.str(row['checkin_method_label']);
    final outMethod = Fmt.str(row['checkout_method_label']).isEmpty
        ? Fmt.str(row['checkout_method'])
        : Fmt.str(row['checkout_method_label']);
    final inPlan = row['in_plan'] == true || Fmt.toInt(row['in_plan']) == 1;
    return [
      '${i + 1}',
      Fmt.str(row['customer_name']),
      Fmt.str(row['customer_code']),
      Fmt.dmy(Fmt.str(row['route_date'])),
      Fmt.str(row['status_label']),
      inPlan ? 'داخل الجولة' : 'خارج الجولة',
      Fmt.str(row['order_numbers']).isEmpty ? '—' : Fmt.str(row['order_numbers']),
      Fmt.toInt(row['order_count']) > 0
          ? Fmt.money(Fmt.toDouble(row['order_total']))
          : '—',
      Fmt.str(row['no_order_reasons']).isEmpty
          ? '—'
          : Fmt.str(row['no_order_reasons']),
      Fmt.dmyHm(Fmt.str(row['visit_checkin_at'])),
      inMethod.isEmpty ? '—' : inMethod,
      Fmt.dmyHm(Fmt.str(row['visit_checkout_at'])),
      outMethod.isEmpty ? '—' : outMethod,
      Fmt.str(row['duration_label']).isEmpty
          ? '—'
          : Fmt.str(row['duration_label']),
    ];
  }

  Future<Uint8List> _buildPdf() {
    return ReportTablePdf.build(
      title: 'تقرير الزيارات',
      subtitle:
          'من ${Fmt.dmy(_from)} إلى ${Fmt.dmy(_to)} · ${_rows.length} زيارة · طلبات: $_orderCount · الإجمالي: ${Fmt.money(_orderTotal)}',
      headers: _visitHeaders(),
      rows: [
        for (var i = 0; i < _rows.length; i++) _visitCells(_rows[i], i),
      ],
      footer:
          'عدد الزيارات: ${_rows.length}  ·  عدد الطلبيات: $_orderCount  ·  إجمالي الطلبيات: ${Fmt.money(_orderTotal)}',
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
        title: 'تقرير الزيارات',
        fileName: 'تقرير-زيارات-$_from-$_to',
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
        fileName: 'visits-$_from-$_to',
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
      title: const Text('تقرير الزيارات'),
      actions: [
        IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh_rounded)),
      ],
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 14, 6),
            child: AppCard(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(child: _DateChip(label: 'من', value: Fmt.dmy(_from), onTap: () => _pick(true))),
                      const SizedBox(width: 8),
                      Expanded(child: _DateChip(label: 'إلى', value: Fmt.dmy(_to), onTap: () => _pick(false))),
                    ],
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int>(
                    key: ValueKey(_customerId),
                    initialValue: _customerId,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'العميل',
                      prefixIcon: Icon(Icons.storefront_rounded),
                    ),
                    items: [
                      const DropdownMenuItem<int>(
                        value: 0,
                        child: Text('جميع العملاء'),
                      ),
                      ..._customers.map(
                        (c) => DropdownMenuItem<int>(
                          value: Fmt.toInt(c['id']),
                          child: Text(
                            '${Fmt.str(c['name'])} · ${Fmt.str(c['code'])}',
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ),
                    ],
                    onChanged: _loading
                        ? null
                        : (v) {
                            setState(() => _customerId = v ?? 0);
                            _load();
                          },
                  ),
                  if (_rows.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: ActionChipButton(
                            icon: Icons.picture_as_pdf_outlined,
                            label: 'PDF',
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
          ),
          if (!_loading && _error == null)
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 2, 14, 6),
              child: AppCard(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                child: Row(
                  children: [
                    Expanded(
                      child: _ReportStat(
                        label: 'عدد الزيارات',
                        value: '${_rows.length}',
                        icon: Icons.location_on_rounded,
                        color: AppTheme.primary,
                      ),
                    ),
                    Expanded(
                      child: _ReportStat(
                        label: 'عدد الطلبيات',
                        value: '$_orderCount',
                        icon: Icons.shopping_cart_checkout_rounded,
                        color: AppTheme.success,
                      ),
                    ),
                    Expanded(
                      child: _ReportStat(
                        label: 'إجمالي الطلبيات',
                        value: Fmt.money(_orderTotal),
                        icon: Icons.payments_rounded,
                        color: AppTheme.violet,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _rows.isEmpty
                  ? const EmptyState(
                      message: 'لا تسجيلات دخول/خروج في هذه الفترة.',
                      icon: Icons.assignment_outlined,
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(8, 4, 8, 20),
                        children: [
                          LinedReportTable(
                            headers: _visitHeaders(),
                            numericCols: const {0, 7},
                            rows: [
                              for (var i = 0; i < _rows.length; i++)
                                _visitCells(_rows[i], i),
                            ],
                          ),
                        ],
                      ),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DateChip extends StatelessWidget {
  const _DateChip({required this.label, required this.value, required this.onTap});

  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: AppTheme.surfaceAlt,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontSize: 11.5, color: AppTheme.textSoft)),
            const SizedBox(height: 2),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
          ],
        ),
      ),
    );
  }
}

class _ReportStat extends StatelessWidget {
  const _ReportStat({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(height: 3),
        Text(
          value,
          textAlign: TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: color,
            fontWeight: FontWeight.w900,
            fontSize: 13,
          ),
        ),
        Text(
          label,
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppTheme.textSoft, fontSize: 10.5),
        ),
      ],
    );
  }
}
