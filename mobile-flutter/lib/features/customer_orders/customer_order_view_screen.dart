import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/customer_order_bluetooth_receipt.dart';
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

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
          AppConfig.customerOrderViewPath,
          query: {'id': widget.orderId});
      if (mounted)
        setState(() {
          _order = (res['order'] as Map?)?.cast<String, dynamic>() ?? res;
          _loading = false;
        });
    } on ApiException catch (e) {
      if (mounted)
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
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف الطلب'),
        content: const Text('هل تريد حذف هذا الطلب نهائياً؟'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('حذف')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await context.read<ApiClient>().postJson(
        AppConfig.customerOrderDeletePath,
        body: {'id': widget.orderId},
        csrf: context.read<SessionController>().csrf,
      );
      if (!mounted) return;
      showSnack(context, 'تم حذف الطلب.');
      context.go('/customer-orders');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) => MobileScaffold(
        title: const Text('عرض طلب شراء'),
        actions: [
          if (!_approved && !_loading)
            IconButton(
                onPressed: _delete,
                icon: const Icon(Icons.delete_outline_rounded),
                tooltip: 'حذف'),
          IconButton(
              onPressed: _loading ? null : _preview,
              icon: const Icon(Icons.print_outlined))
        ],
        body: AsyncView(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView(
              padding: const EdgeInsets.all(14),
              children: [
                DocumentHeaderCard(
                    title: 'طلب ${Fmt.str(_order['order_no'])}',
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(children: [
                            const Text('الحالة: '),
                            StatusPill(
                                text: _approved ? 'معتمد' : 'مسودة',
                                color: _approved
                                    ? AppTheme.success
                                    : AppTheme.warn)
                          ]),
                          const SizedBox(height: 8),
                          Text(
                              'التاريخ: ${Fmt.dmy(Fmt.str(_order['order_date']))}'),
                          Text('العميل: ${Fmt.str(_order['customer_name'])}'),
                          Text(
                              'المستودع: ${Fmt.str(_order['warehouse_name'])}'),
                          if (Fmt.str(_order['sales_rep_name']).isNotEmpty)
                            Text(
                                'المندوب: ${Fmt.str(_order['sales_rep_name'])}'),
                        ])),
                const DocumentSectionDivider('بنود الطلب'),
                if ((_order['lines'] as List? ?? _order['items'] as List? ?? [])
                    .isNotEmpty)
                  AppCard(
                    padding: EdgeInsets.zero,
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: DataTable(
                        headingRowHeight: 40,
                        dataRowMinHeight: 44,
                        columnSpacing: 14,
                        horizontalMargin: 10,
                        columns: const [
                          DataColumn(label: Text('الباركود')),
                          DataColumn(label: Text('المادة')),
                          DataColumn(label: Text('الوحدة')),
                          DataColumn(label: Text('الكمية')),
                          DataColumn(label: Text('إضافية')),
                          DataColumn(label: Text('السعر')),
                          DataColumn(label: Text('خصم %')),
                          DataColumn(label: Text('المجموع')),
                        ],
                        rows: [
                          for (final raw in (_order['lines'] as List? ??
                              _order['items'] as List? ??
                              []))
                            if (raw is Map)
                              () {
                                final m = raw.cast<String, dynamic>();
                                final qty = Fmt.toDouble(m['qty']);
                                final price = Fmt.toDouble(m['unit_price']);
                                final disc = Fmt.toDouble(m['discount_pct']);
                                final total = Fmt.toDouble(
                                  m['line_total'] ??
                                      m['line_gross'] ??
                                      (qty * price * (1 - disc / 100)),
                                );
                                return DataRow(cells: [
                                  DataCell(Text(
                                    Fmt.str(m['item_barcode'] ??
                                        m['barcode'] ??
                                        m['item_code']),
                                    textDirection: TextDirection.ltr,
                                  )),
                                  DataCell(SizedBox(
                                    width: 140,
                                    child: Text(
                                      Fmt.str(m['item_name'] ?? m['name']),
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  )),
                                  DataCell(Text(
                                      Fmt.str(m['unit_name'] ?? m['unit']))),
                                  DataCell(Text(Fmt.trimNum(qty),
                                      textDirection: TextDirection.ltr)),
                                  DataCell(Text(
                                      Fmt.trimNum(
                                          Fmt.toDouble(m['qty_extra'])),
                                      textDirection: TextDirection.ltr)),
                                  DataCell(Text(Fmt.money(price),
                                      textDirection: TextDirection.ltr)),
                                  DataCell(Text(Fmt.trimNum(disc),
                                      textDirection: TextDirection.ltr)),
                                  DataCell(Text(Fmt.money(total),
                                      textDirection: TextDirection.ltr,
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w800))),
                                ]);
                              }(),
                        ],
                      ),
                    ),
                  ),
                const SizedBox(height: 10),
                Row(children: [
                  if (!_approved)
                    Expanded(
                        child: OutlinedButton.icon(
                            onPressed: () async {
                              await context.push(
                                  '/customer-orders/${widget.orderId}/edit');
                              if (mounted) _load();
                            },
                            icon: const Icon(Icons.edit_outlined),
                            label: const Text('تعديل'))),
                  if (!_approved) const SizedBox(width: 8),
                  if (!_approved)
                    Expanded(
                        child: OutlinedButton.icon(
                            onPressed: _delete,
                            icon: const Icon(Icons.delete_outline_rounded),
                            label: const Text('حذف'))),
                  if (!_approved) const SizedBox(width: 8),
                  Expanded(
                      child: FilledButton.icon(
                          onPressed: _preview,
                          icon: const Icon(Icons.print_outlined),
                          label: const Text('طباعة'))),
                ]),
              ],
            )),
      );
}
