import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
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

  @override
  Widget build(BuildContext context) => MobileScaffold(
        title: const Text('عرض طلب شراء'),
        actions: [
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
                for (final raw in (_order['lines'] as List? ??
                    _order['items'] as List? ??
                    []))
                  if (raw is Map)
                    AppCard(
                        child: Row(children: [
                      Expanded(
                          child:
                              Text(Fmt.str(raw['item_name'] ?? raw['name']))),
                      Text(
                          '${Fmt.str(raw['unit_name'] ?? raw['unit'])}  •  ${Fmt.trimNum(Fmt.toDouble(raw['qty']))}'),
                    ])),
                const SizedBox(height: 10),
                Row(children: [
                  if (!_approved)
                    Expanded(
                        child: OutlinedButton.icon(
                            onPressed: () => context.push(
                                '/customer-orders/${widget.orderId}/edit'),
                            icon: const Icon(Icons.edit_outlined),
                            label: const Text('تعديل'))),
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
