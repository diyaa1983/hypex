import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
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
import '../../widgets/party_picker.dart';
import '../../widgets/ui_kit.dart';

/// استعلام طلبات عملاء حسب العميل (أو الكل) والتاريخ.
class CustomerOrdersQueryScreen extends StatefulWidget {
  const CustomerOrdersQueryScreen({super.key});

  @override
  State<CustomerOrdersQueryScreen> createState() =>
      _CustomerOrdersQueryScreenState();
}

class _CustomerOrdersQueryScreenState extends State<CustomerOrdersQueryScreen> {
  Party? _customer;
  bool _allCustomers = true;
  DateTime _from = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _to = DateTime.now();

  bool _loading = false;
  bool _ran = false;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
  bool _pdfBusy = false;
  bool _shareBusy = false;

  String get _fromIso =>
      '${_from.year.toString().padLeft(4, '0')}-${_from.month.toString().padLeft(2, '0')}-${_from.day.toString().padLeft(2, '0')}';

  String get _toIso =>
      '${_to.year.toString().padLeft(4, '0')}-${_to.month.toString().padLeft(2, '0')}-${_to.day.toString().padLeft(2, '0')}';

  Future<void> _pickCustomer() async {
    final p = await pickParty(context);
    if (p != null) {
      setState(() {
        _customer = p;
        _allCustomers = false;
      });
    }
  }

  Future<void> _pickDate({required bool from}) async {
    final initial = from ? _from : _to;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
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

  double get _grandTotal =>
      _orders.fold<double>(0, (s, o) => s + Fmt.toDouble(o['total']));

  Future<void> _loadLocal() async {
    final store = OfflineStore.instance;
    final cid = (!_allCustomers && _customer != null) ? _customer!.id : null;
    final orders = await store.listOrders(
      customerId: cid,
      from: _fromIso,
      to: _toIso,
      limit: 500,
      offset: 0,
    );
    if (!mounted) return;
    setState(() {
      _orders = orders;
      _loading = false;
    });
  }

  Future<void> _load({bool resetPage = false}) async {
    if (!_allCustomers && _customer == null) {
      showSnack(context, 'اختر العميل أو فعّل جميع العملاء.', error: true);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
      _ran = true;
    });
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        await _loadLocal();
        return;
      }
      final all = <Map<String, dynamic>>[];
      var page = 1;
      var pages = 1;
      while (page <= pages && all.length < 500) {
        final query = <String, dynamic>{
          'from': _fromIso,
          'to': _toIso,
          'page': page,
        };
        if (!_allCustomers && _customer != null) {
          query['customer_id'] = _customer!.id;
        }
        final data = await context.read<ApiClient>().getJson(
              AppConfig.customerOrderListPath,
              query: query,
            );
        final pager = (data['pager'] is Map)
            ? (data['pager'] as Map).cast<String, dynamic>()
            : null;
        pages = (pager?['pages'] as num?)?.toInt() ??
            (pager?['total_pages'] as num?)?.toInt() ??
            1;
        all.addAll(
          (data['orders'] as List? ?? [])
              .whereType<Map>()
              .map((e) => e.cast<String, dynamic>()),
        );
        if ((data['orders'] as List? ?? []).isEmpty) break;
        page++;
      }
      if (!mounted) return;
      setState(() {
        _orders = all;
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
    }
  }

  Future<Uint8List> _buildPdf() {
    return ReportTablePdf.build(
      title: 'تقرير طلبات العملاء',
      subtitle:
          'من ${Fmt.dmy(_fromIso)} إلى ${Fmt.dmy(_toIso)} · ${_orders.length} طلب',
      headers: const ['#', 'اسم العميل', 'تاريخ الطلبية', 'المجموع'],
      rows: [
        for (var i = 0; i < _orders.length; i++)
          [
            '${i + 1}',
            Fmt.str(_orders[i]['customer_name']).isEmpty
                ? '—'
                : Fmt.str(_orders[i]['customer_name']),
            Fmt.dmy(Fmt.str(_orders[i]['order_date'])),
            Fmt.money(Fmt.toDouble(_orders[i]['total'])),
          ],
      ],
      footer: 'الإجمالي: ${Fmt.money(_grandTotal)}',
    );
  }

  Future<void> _openPdf() async {
    if (_orders.isEmpty || _pdfBusy) return;
    setState(() => _pdfBusy = true);
    try {
      final bytes = await _buildPdf();
      if (!mounted) return;
      await DocumentPrintHelper.openPdfBytes(
        context,
        bytes: bytes,
        title: 'تقرير طلبات العملاء',
        fileName: 'طلبات-عملاء-$_fromIso-$_toIso',
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر إنشاء PDF: $e', error: true);
    } finally {
      if (mounted) setState(() => _pdfBusy = false);
    }
  }

  Future<void> _sharePdf() async {
    if (_orders.isEmpty || _shareBusy) return;
    setState(() => _shareBusy = true);
    try {
      final bytes = await _buildPdf();
      await DocumentPrintHelper.sharePdfBytes(
        bytes,
        fileName: 'طلبات-عملاء-$_fromIso-$_toIso',
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
      title: const Text('طلبات عملاء'),
      actions: [
        IconButton(
          onPressed: _loading ? null : () => _load(resetPage: true),
          icon: const Icon(Icons.search_rounded),
          tooltip: 'بحث',
        ),
      ],
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Column(
              children: [
                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  title: const Text(
                    'جميع العملاء',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                  value: _allCustomers,
                  onChanged: (v) => setState(() {
                    _allCustomers = v;
                    if (v) _customer = null;
                  }),
                ),
                if (!_allCustomers)
                  InkWell(
                    onTap: _pickCustomer,
                    borderRadius: BorderRadius.circular(14),
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'العميل',
                        suffixIcon: Icon(Icons.person_search_rounded),
                      ),
                      child: Text(
                        _customer == null
                            ? 'اختر العميل…'
                            : [
                                if (_customer!.code.isNotEmpty) _customer!.code,
                                _customer!.name,
                              ].where((s) => s.isNotEmpty).join(' — '),
                        style: TextStyle(
                          color: _customer == null
                              ? AppTheme.textSoft
                              : AppTheme.textMain,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: InkWell(
                        onTap: () => _pickDate(from: true),
                        borderRadius: BorderRadius.circular(12),
                        child: InputDecorator(
                          decoration: const InputDecoration(
                            labelText: 'من تاريخ',
                            suffixIcon: Icon(Icons.calendar_month_rounded),
                            isDense: true,
                          ),
                          child: Text(Fmt.dmy(_fromIso)),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: InkWell(
                        onTap: () => _pickDate(from: false),
                        borderRadius: BorderRadius.circular(12),
                        child: InputDecorator(
                          decoration: const InputDecoration(
                            labelText: 'إلى تاريخ',
                            suffixIcon: Icon(Icons.calendar_month_rounded),
                            isDense: true,
                          ),
                          child: Text(Fmt.dmy(_toIso)),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _loading ? null : () => _load(resetPage: true),
                    icon: const Icon(Icons.filter_alt_rounded),
                    label: const Text('عرض الطلبات'),
                  ),
                ),
                if (_ran && _orders.isNotEmpty) ...[
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
          Expanded(
            child: !_ran
                ? const Center(
                    child: Text(
                      'حدد الفترة ثم اضغط عرض.',
                      style: TextStyle(color: AppTheme.textSoft),
                    ),
                  )
                : AsyncView(
                    loading: _loading,
                    error: _error,
                    onRetry: () => _load(resetPage: true),
                    child: _orders.isEmpty
                        ? ListView(
                            children: const [
                              SizedBox(height: 70),
                              EmptyState(
                                message: 'لا توجد طلبات ضمن الفترة.',
                                icon: Icons.date_range_rounded,
                              ),
                            ],
                          )
                        : RefreshIndicator(
                            onRefresh: () => _load(),
                            child: ListView(
                              padding:
                                  const EdgeInsets.fromLTRB(10, 4, 10, 16),
                              children: [
                                SingleChildScrollView(
                                  scrollDirection: Axis.horizontal,
                                  child: DataTable(
                                    headingRowHeight: 40,
                                    dataRowMinHeight: 40,
                                    dataRowMaxHeight: 48,
                                    headingTextStyle: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                      fontSize: 12.5,
                                      color: AppTheme.textMain,
                                    ),
                                    columns: const [
                                      DataColumn(label: Text('#')),
                                      DataColumn(label: Text('اسم العميل')),
                                      DataColumn(label: Text('تاريخ الطلبية')),
                                      DataColumn(
                                        label: Text('المجموع'),
                                        numeric: true,
                                      ),
                                    ],
                                    rows: [
                                      for (var i = 0; i < _orders.length; i++)
                                        DataRow(
                                          onSelectChanged: (_) => context.push(
                                            '/customer-orders/${Fmt.toInt(_orders[i]['id'])}',
                                          ),
                                          cells: [
                                            DataCell(Text('${i + 1}')),
                                            DataCell(Text(
                                              Fmt.str(_orders[i]
                                                          ['customer_name'])
                                                      .isEmpty
                                                  ? '—'
                                                  : Fmt.str(_orders[i]
                                                      ['customer_name']),
                                            )),
                                            DataCell(Text(Fmt.dmy(Fmt.str(
                                                _orders[i]['order_date'])))),
                                            DataCell(Text(
                                              Fmt.money(Fmt.toDouble(
                                                  _orders[i]['total'])),
                                              textDirection: TextDirection.ltr,
                                            )),
                                          ],
                                        ),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: 8),
                                Align(
                                  alignment: Alignment.centerLeft,
                                  child: Text(
                                    'الإجمالي: ${Fmt.money(_grandTotal)}',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w900,
                                      fontSize: 15,
                                    ),
                                  ),
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
