import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../services/customer_order_bluetooth_receipt.dart';
import '../../services/document_print_helper.dart';
import '../../widgets/app_confirm_dialog.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/thermal_preview_screen.dart';
import '../../widgets/ui_kit.dart';

class CustomerOrderViewScreen extends StatefulWidget {
  const CustomerOrderViewScreen({super.key, required this.orderId});
  final int orderId;
  @override
  State<CustomerOrderViewScreen> createState() =>
      _CustomerOrderViewScreenState();
}

class _CustomerOrderViewScreenState extends State<CustomerOrderViewScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _order = {};

  /// دمج بنود نفس المادة (كرتونة/قطعة + مجاني) لعرض مطابق لشاشة ويندوز.
  List<Map<String, dynamic>> _mergedDisplayLines(List rawLines) {
    final out = <Map<String, dynamic>>[];
    for (final raw in rawLines) {
      if (raw is! Map) continue;
      final m = raw.cast<String, dynamic>();
      final itemId = Fmt.toInt(m['item_id']);
      final factor = Fmt.toDouble(m['unit_factor'] ?? 1);
      final name = Fmt.str(m['unit_name'] ?? m['unit']);
      final isCarton = factor > 1.001 || name.contains('كرتون');
      final qty = Fmt.toDouble(m['qty']);
      final extra = Fmt.toDouble(m['qty_extra']);
      final price = Fmt.toDouble(m['unit_price']);
      final disc = Fmt.toDouble(m['discount_pct']);
      final total = Fmt.toDouble(
        m['line_total'] ??
            m['line_gross'] ??
            (qty * price * (1 - disc / 100)),
      );
      Map<String, dynamic>? prev;
      if (itemId > 0 && out.isNotEmpty && Fmt.toInt(out.last['item_id']) == itemId) {
        prev = out.last;
      }
      if (prev == null) {
        out.add({
          'item_id': itemId,
          'item_barcode': m['item_barcode'] ?? m['barcode'] ?? m['item_code'],
          'item_name': m['item_name'] ?? m['name'],
          'qty_carton': isCarton ? qty : 0.0,
          'qty_piece': isCarton ? 0.0 : qty,
          'extra_carton': isCarton ? extra : 0.0,
          'extra_piece': isCarton ? 0.0 : extra,
          'unit_price': isCarton ? 0.0 : price,
          'carton_price': isCarton ? price : 0.0,
          'discount_pct': disc,
          'line_total': total,
        });
      } else {
        if (isCarton) {
          prev['qty_carton'] = Fmt.toDouble(prev['qty_carton']) + qty;
          prev['extra_carton'] = Fmt.toDouble(prev['extra_carton']) + extra;
          if (price > 0) prev['carton_price'] = price;
        } else {
          prev['qty_piece'] = Fmt.toDouble(prev['qty_piece']) + qty;
          prev['extra_piece'] = Fmt.toDouble(prev['extra_piece']) + extra;
          if (price > 0) prev['unit_price'] = price;
        }
        prev['line_total'] = Fmt.toDouble(prev['line_total']) + total;
        if (disc > Fmt.toDouble(prev['discount_pct'])) {
          prev['discount_pct'] = disc;
        }
      }
    }
    return out;
  }

  String _qtyPairText(double carton, double piece) {
    final parts = <String>[];
    if (carton > 0) parts.add('كرتونة ${Fmt.trimNum(carton)}');
    if (piece > 0) parts.add('قطعة ${Fmt.trimNum(piece)}');
    return parts.isEmpty ? '—' : parts.join(' + ');
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        final local =
            await OfflineStore.instance.getOrderById(widget.orderId);
        if (!mounted) return;
        if (local == null) {
          setState(() {
            _error = 'الطلب غير متوفر محلياً. حدّث البيانات أولاً.';
            _loading = false;
          });
          return;
        }
        setState(() {
          _order = local;
          _loading = false;
        });
        return;
      }
      final res = await context.read<ApiClient>().getJson(
          AppConfig.customerOrderViewPath,
          query: {'id': widget.orderId});
      if (mounted) {
        setState(() {
          _order = (res['order'] as Map?)?.cast<String, dynamic>() ?? res;
          _loading = false;
        });
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        final local =
            await OfflineStore.instance.getOrderById(widget.orderId);
        if (local != null) {
          setState(() {
            _order = local;
            _loading = false;
          });
          return;
        }
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  bool get _approved =>
      _order['approved'] == true ||
      _order['is_approved'] == true ||
      Fmt.str(_order['status']) == 'approved';

  bool get _sent =>
      _order['is_sent'] == true ||
      _order['is_sent'] == 1 ||
      Fmt.toInt(_order['is_sent']) == 1;

  bool get _managerMode =>
      context.read<SessionController>().can('m_manager_customer_orders');

  bool get _canDeleteOrEdit => !_approved && (!_sent || _managerMode);

  bool get _canApprove => _managerMode && _sent && !_approved;

  bool get _oraclePosted => Fmt.toInt(_order['oracle_v_num']) > 0;

  Future<void> _approveAsManager() async {
    if (!_canApprove) return;
    final ok = await showAppConfirmDialog(
      context,
      title: 'اعتماد الطلب',
      message: 'اعتماد هذا الطلب ليظهر في النظام وكشف الحساب؟',
      confirmLabel: 'اعتماد',
    );
    if (ok != true || !mounted) return;
    try {
      final csrf = await context.read<SessionController>().ensureCsrf();
      final res = await context.read<ApiClient>().postJson(
            AppConfig.managerCustomerOrderApprovePath,
            body: {'id': widget.orderId, 'approve': true},
            csrf: csrf,
          );
      if (!mounted) return;
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty
            ? 'تم اعتماد الطلب.'
            : Fmt.str(res['message']),
      );
      await _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    }
  }

  ({String label, Color color, String hint}) get _workflowStatus {
    if (_approved) {
      return (
        label: _oraclePosted ? 'معتمد · مرحّل Oracle' : 'معتمد',
        color: AppTheme.success,
        hint: 'يظهر في كشف الحساب. إذا فُكّ اعتماده يختفي من الكشف.',
      );
    }
    if (_sent) {
      return (
        label: 'بانتظار الاعتماد',
        color: AppTheme.warn,
        hint: 'بعد اعتماد الإدارة يظهر في كشف الحساب.',
      );
    }
    return (
      label: 'غير مرسل',
      color: AppTheme.warn,
      hint: 'أرسل الطلب من «طلبات غير مرسلة» ثم يُعتمد ليظهر في الكشف.',
    );
  }

  /// بيانات كاملة للـ PDF (رأس + بنود + مجاميع).
  Map<String, dynamic> _pdfOrderData() {
    final rawLines =
        (_order['lines'] as List? ?? _order['items'] as List? ?? []);
    final lines = <Map<String, dynamic>>[];
    var sub = 0.0;
    var disc = 0.0;
    var tax = 0.0;
    var gross = 0.0;
    for (final raw in rawLines) {
      if (raw is! Map) continue;
      final m = raw.cast<String, dynamic>();
      final qty = Fmt.toDouble(m['qty']);
      final price = Fmt.toDouble(m['unit_price'] ?? m['price']);
      final discPct = Fmt.toDouble(m['discount_pct']);
      final taxP = Fmt.toDouble(m['tax_rate_percent']);
      final lineGross = Fmt.toDouble(
        m['line_gross'] ??
            m['line_total'] ??
            (qty * price * (1 - discPct / 100) * (1 + taxP / 100)),
      );
      final lineNet = Fmt.toDouble(
        m['line_net'] ??
            m['line_subtotal'] ??
            (qty * price * (1 - discPct / 100)),
      );
      final lineTax = Fmt.toDouble(m['tax_amount'] ?? (lineGross - lineNet));
      final lineDisc = Fmt.toDouble(
        m['discount_amount'] ?? (qty * price * discPct / 100),
      );
      sub += lineNet;
      disc += lineDisc;
      tax += lineTax;
      gross += lineGross;
      final unitName = Fmt.str(m['unit_name'] ?? m['unit']);
      final qtyLabel = Fmt.str(m['qty_label']).trim().isNotEmpty
          ? Fmt.str(m['qty_label']).trim()
          : (qty > 0
              ? '${Fmt.trimNum(qty)}${unitName.isEmpty ? '' : ' $unitName'}'
              : '');
      final extra = Fmt.toDouble(m['qty_extra']);
      final extraLabel = Fmt.str(m['qty_extra_label']).trim().isNotEmpty
          ? Fmt.str(m['qty_extra_label']).trim()
          : (extra > 0
              ? '${Fmt.trimNum(extra)}${unitName.isEmpty ? '' : ' $unitName'}'
              : '');
      lines.add({
        'item_name': m['item_name'] ?? m['name'],
        'unit_name': unitName,
        'qty': qtyLabel.isEmpty ? qty : qtyLabel,
        'qty_extra': extraLabel.isEmpty ? extra : extraLabel,
        'unit_price': price,
        'unit_price_inclusive': Fmt.toDouble(m['unit_price_inclusive']) > 0
            ? Fmt.toDouble(m['unit_price_inclusive'])
            : price * (1 + taxP / 100),
        'tax_rate_percent': taxP,
        'discount_pct': discPct,
        'line_total': lineGross,
        'line_gross': lineGross,
      });
    }
    final orderSub = Fmt.toDouble(_order['subtotal']);
    final orderDisc = Fmt.toDouble(_order['discount_total']);
    final orderTax = Fmt.toDouble(_order['tax_total']);
    final orderGrand = Fmt.toDouble(
      _order['grand_total'] ?? _order['total'] ?? _order['net_total'],
    );
    return {
      ..._order,
      'order_no': _order['order_no'],
      'order_date': _order['order_date'],
      'delivery_date': _order['delivery_date'],
      'customer_name': _order['customer_name'],
      'customer_code': _order['customer_code'],
      'warehouse_name': _order['warehouse_name'],
      'sales_rep_name': _order['sales_rep_name'] ??
          _order['sales_rep'] ??
          _order['rep_name'],
      'payment_type': _order['payment_type'],
      'notes': _order['notes'],
      'company_name': _order['company_name'],
      'logo_url': _order['logo_url'],
      'subtotal': orderSub > 0 ? orderSub : sub,
      'discount_total': orderDisc > 0 ? orderDisc : disc,
      'tax_total': orderTax > 0 ? orderTax : tax,
      'grand_total': orderGrand > 0 ? orderGrand : gross,
      'lines': lines,
    };
  }

  Future<void> _sharePdf() async {
    showSnack(context, 'جاري تجهيز PDF...');
    try {
      final bytes =
          await CustomerOrderBluetoothReceipt.buildA4Pdf(_pdfOrderData());
      if (!mounted) return;
      await DocumentPrintHelper.sharePdfBytes(
        bytes,
        fileName: 'طلب شراء - ${Fmt.str(_order['order_no'])}',
        context: context,
      );
    } catch (e) {
      if (!mounted) return;
      // احتياط: مشاركة PDF من السيرفر
      await DocumentPrintHelper.sharePdfFromApi(
        context,
        apiPath: AppConfig.customerOrderPdfPath,
        query: {'id': widget.orderId},
        fileName: 'طلب شراء - ${Fmt.str(_order['order_no'])}',
      );
    }
  }

  Future<void> _openPdf() async {
    showSnack(context, 'جاري تجهيز PDF...');
    try {
      final bytes =
          await CustomerOrderBluetoothReceipt.buildA4Pdf(_pdfOrderData());
      if (!mounted) return;
      await DocumentPrintHelper.openPdfBytes(
        context,
        bytes: bytes,
        title: 'طلب شراء',
        fileName: 'طلب شراء - ${Fmt.str(_order['order_no'])}',
      );
    } catch (e) {
      if (!mounted) return;
      try {
        await DocumentPrintHelper.openPdfFromApi(
          context,
          apiPath: AppConfig.customerOrderPdfPath,
          query: {'id': widget.orderId},
          title: 'طلب شراء',
          fileName: 'طلب شراء - ${Fmt.str(_order['order_no'])}',
        );
      } catch (e2) {
        if (mounted) showSnack(context, 'تعذر إنشاء PDF: $e2', error: true);
      }
    }
  }

  Future<void> _preview() async {
    await Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) => ThermalPreviewScreen(
                  title: 'معاينة طلب الشراء',
                  buildPdf: (paper) =>
                      CustomerOrderBluetoothReceipt.buildThermalPdf(_order,
                          paperMm: paper),
                  onPrint: (ctx) async {
                    final err =
                        await CustomerOrderBluetoothReceipt.printOrder(_order);
                    if (ctx.mounted)
                      showSnack(ctx, err ?? 'تمت الطباعة.', error: err != null);
                  },
                )));
  }

  Future<void> _delete() async {
    final ok = await showAppConfirmDialog(
      context,
      title: 'حذف الطلب',
      message: 'هل تريد حذف هذا الطلب نهائياً؟',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (ok != true || !mounted) return;
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        if (widget.orderId < 0) {
          await OfflineStore.instance.deleteLocalOrder(widget.orderId);
        } else {
          await offline.enqueue(
            kind: 'customer_order_delete',
            path: AppConfig.customerOrderDeletePath,
            body: {'id': widget.orderId},
          );
          await OfflineStore.instance.deleteLocalOrder(widget.orderId);
        }
        if (!mounted) return;
        showSnack(context, 'حُذف الطلب محلياً / وُضع في الطابور.');
        context.go('/customer-orders/pending');
        return;
      }
      final res = await context.read<ApiClient>().postJson(
        AppConfig.customerOrderDeletePath,
        body: {'id': widget.orderId},
        csrf: context.read<SessionController>().csrf,
      );
      if (!mounted) return;
      final msg = Fmt.str(res['message']);
      showSnack(context, msg.isEmpty ? 'تم حذف الطلب.' : msg);
      context.go('/customer-orders');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    }
  }

  Widget _infoCell(String label, String value, {bool emphasize = false}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        border: Border.all(color: const Color(0xFFE2E8F0)),
        color: emphasize ? const Color(0xFFECFDF5) : Colors.white,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            label,
            style: const TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: Color(0xFF64748B),
            ),
          ),
          const SizedBox(height: 2),
          Text(
            value.isEmpty ? '—' : value,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: emphasize ? 18 : 14,
              fontWeight: FontWeight.w900,
              color: emphasize
                  ? const Color(0xFF065F46)
                  : const Color(0xFF0F172A),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final lines = _mergedDisplayLines(
      (_order['lines'] as List? ?? _order['items'] as List? ?? []),
    );
    final grand = lines.fold<double>(
      0,
      (s, m) => s + Fmt.toDouble(m['line_total']),
    );
    return MobileScaffold(
      title: const Text('عرض طلب شراء'),
      actions: [
        if (_canDeleteOrEdit && !_loading)
          IconButton(
              onPressed: _delete,
              icon: const Icon(Icons.delete_outline_rounded),
              tooltip: 'حذف'),
        if (!_loading)
          IconButton(
              onPressed: _sharePdf,
              icon: const Icon(Icons.share_outlined),
              tooltip: 'مشاركة PDF'),
        IconButton(
            onPressed: _loading ? null : _openPdf,
            icon: const Icon(Icons.picture_as_pdf_outlined),
            tooltip: 'PDF'),
        IconButton(
            onPressed: _loading ? null : _preview,
            icon: const Icon(Icons.print_outlined),
            tooltip: 'طباعة'),
      ],
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
                children: [
                  Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFFCBD5E1)),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: Column(
                      children: [
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 10),
                          color: const Color(0xFFF1F5F9),
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  'طلب شراء ${Fmt.str(_order['order_no'])}',
                                  style: const TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ),
                              StatusPill(
                                text: _workflowStatus.label,
                                color: _workflowStatus.color,
                              ),
                            ],
                          ),
                        ),
                        Table(
                          border: TableBorder.all(
                            color: const Color(0xFFE2E8F0),
                            width: 1,
                          ),
                          columnWidths: const {
                            0: FlexColumnWidth(1),
                            1: FlexColumnWidth(1),
                            2: FlexColumnWidth(1),
                            3: FlexColumnWidth(1),
                          },
                          children: [
                            TableRow(children: [
                              _infoCell(
                                'رقم الطلب',
                                Fmt.str(_order['order_no']),
                                emphasize: true,
                              ),
                              _infoCell(
                                'التاريخ',
                                Fmt.dmy(Fmt.str(_order['order_date'])),
                              ),
                              _infoCell(
                                'العميل',
                                Fmt.str(_order['customer_name']),
                              ),
                              _infoCell(
                                'المستودع',
                                Fmt.str(_order['warehouse_name']),
                              ),
                            ]),
                            TableRow(children: [
                              _infoCell(
                                'المندوب',
                                Fmt.str(_order['sales_rep_name']),
                              ),
                              _infoCell(
                                'نوع الدفع',
                                Fmt.str(_order['payment_type']) == 'cash'
                                    ? 'نقدي'
                                    : 'ذمم',
                              ),
                              _infoCell(
                                'تاريخ التسليم',
                                Fmt.dmy(Fmt.str(_order['delivery_date'])),
                              ),
                              _infoCell(
                                'الإجمالي',
                                Fmt.money(grand > 0
                                    ? grand
                                    : Fmt.toDouble(_order['total'] ??
                                        _order['grand_total'])),
                                emphasize: true,
                              ),
                            ]),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFFCBD5E1)),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 8),
                          color: const Color(0xFFF8FAFC),
                          child: const Text(
                            'بنود الطلب',
                            style: TextStyle(
                              fontWeight: FontWeight.w900,
                              fontSize: 14,
                            ),
                          ),
                        ),
                        if (lines.isEmpty)
                          const Padding(
                            padding: EdgeInsets.all(16),
                            child: Text('لا توجد بنود.'),
                          )
                        else
                          SingleChildScrollView(
                            scrollDirection: Axis.horizontal,
                            child: DataTable(
                              headingRowHeight: 40,
                              dataRowMinHeight: 44,
                              dataRowMaxHeight: 56,
                              columnSpacing: 12,
                              horizontalMargin: 12,
                              headingRowColor: WidgetStateProperty.all(
                                const Color(0xFFF1F5F9),
                              ),
                              headingTextStyle: const TextStyle(
                                fontSize: 12.5,
                                fontWeight: FontWeight.w900,
                                color: Color(0xFF334155),
                              ),
                              dataTextStyle: const TextStyle(
                                fontSize: 13.5,
                                fontWeight: FontWeight.w700,
                                color: Color(0xFF0F172A),
                              ),
                              border: TableBorder(
                                horizontalInside: BorderSide(
                                  color: Colors.grey.shade200,
                                ),
                              ),
                              columns: const [
                                DataColumn(label: Text('#')),
                                DataColumn(label: Text('المادة')),
                                DataColumn(label: Text('الكمية')),
                                DataColumn(label: Text('مجاني')),
                                DataColumn(label: Text('السعر')),
                                DataColumn(label: Text('خصم%')),
                                DataColumn(label: Text('المجموع')),
                              ],
                              rows: [
                                for (var i = 0; i < lines.length; i++)
                                  DataRow(
                                    color: WidgetStateProperty.all(
                                      i.isEven
                                          ? Colors.white
                                          : const Color(0xFFF8FAFC),
                                    ),
                                    cells: [
                                      DataCell(Text('${i + 1}')),
                                      DataCell(SizedBox(
                                        width: 200,
                                        child: Text(
                                          Fmt.str(lines[i]['item_name']),
                                          maxLines: 2,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      )),
                                      DataCell(SizedBox(
                                        width: 140,
                                        child: Text(
                                          _qtyPairText(
                                            Fmt.toDouble(
                                                lines[i]['qty_carton']),
                                            Fmt.toDouble(
                                                lines[i]['qty_piece']),
                                          ),
                                        ),
                                      )),
                                      DataCell(SizedBox(
                                        width: 140,
                                        child: Text(
                                          _qtyPairText(
                                            Fmt.toDouble(
                                                lines[i]['extra_carton']),
                                            Fmt.toDouble(
                                                lines[i]['extra_piece']),
                                          ),
                                        ),
                                      )),
                                      DataCell(Text(
                                        () {
                                          final piece = Fmt.toDouble(
                                              lines[i]['unit_price']);
                                          final carton = Fmt.toDouble(
                                              lines[i]['carton_price']);
                                          if (piece > 0 && carton > 0) {
                                            return 'ق ${Fmt.money(piece)}\nك ${Fmt.money(carton)}';
                                          }
                                          if (carton > 0) {
                                            return Fmt.money(carton);
                                          }
                                          return Fmt.money(piece);
                                        }(),
                                        textDirection: TextDirection.ltr,
                                      )),
                                      DataCell(Text(
                                        Fmt.trimNum(Fmt.toDouble(
                                            lines[i]['discount_pct'])),
                                        textDirection: TextDirection.ltr,
                                      )),
                                      DataCell(Text(
                                        Fmt.money(Fmt.toDouble(
                                            lines[i]['line_total'])),
                                        textDirection: TextDirection.ltr,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w900,
                                          color: Color(0xFF0F766E),
                                        ),
                                      )),
                                    ],
                                  ),
                              ],
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
              decoration: const BoxDecoration(
                color: Color(0xFFF8FAFC),
                border: Border(top: BorderSide(color: Color(0xFFCBD5E1))),
              ),
              child: Row(children: [
                if (_canDeleteOrEdit)
                  Expanded(
                      child: OutlinedButton.icon(
                          onPressed: () async {
                            await context.push(
                                '/customer-orders/${widget.orderId}/edit');
                            if (mounted) _load();
                          },
                          icon: const Icon(Icons.edit_outlined),
                          label: const Text('تعديل'))),
                if (_canDeleteOrEdit) const SizedBox(width: 8),
                if (_canApprove)
                  Expanded(
                      child: FilledButton.icon(
                          onPressed: _approveAsManager,
                          icon: const Icon(Icons.verified_rounded),
                          style: FilledButton.styleFrom(
                            backgroundColor: AppTheme.success,
                          ),
                          label: const Text('اعتماد'))),
                if (_canApprove) const SizedBox(width: 8),
                if (_canDeleteOrEdit && !_managerMode)
                  Expanded(
                      child: OutlinedButton.icon(
                          onPressed: _delete,
                          icon: const Icon(Icons.delete_outline_rounded),
                          label: const Text('حذف'))),
                if (_canDeleteOrEdit && !_managerMode)
                  const SizedBox(width: 8),
                Expanded(
                    child: FilledButton.icon(
                        onPressed: _preview,
                        icon: const Icon(Icons.print_outlined),
                        label: const Text('طباعة'))),
              ]),
            ),
          ],
        ),
      ),
    );
  }
}
