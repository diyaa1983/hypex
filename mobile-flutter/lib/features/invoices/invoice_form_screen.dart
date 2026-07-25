import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../widgets/async_view.dart';
import '../../widgets/item_picker.dart';
import '../../widgets/party_picker.dart';

class _Line {
  _Line({
    required this.itemId,
    required this.name,
    required this.qty,
    required this.qtyExtra,
    required this.unitPrice,
  });

  final int itemId;
  final String name;
  double qty;
  double qtyExtra;
  double unitPrice;

  double get total => qty * unitPrice;

  Map<String, dynamic> toJson() => {
        'item_id': itemId,
        'qty': qty,
        'qty_extra': qtyExtra,
        'unit_price': unitPrice,
      };
}

class InvoiceFormScreen extends StatefulWidget {
  const InvoiceFormScreen({super.key});

  @override
  State<InvoiceFormScreen> createState() => _InvoiceFormScreenState();
}

class _InvoiceFormScreenState extends State<InvoiceFormScreen> {
  bool _loadingMeta = true;
  bool _saving = false;
  String? _metaError;

  List<Map<String, dynamic>> _warehouses = [];
  int _warehouseId = 0;
  String _paymentType = 'credit';
  Party? _customer;
  final List<_Line> _lines = [];

  @override
  void initState() {
    super.initState();
    _loadMeta();
  }

  Future<void> _loadMeta() async {
    setState(() {
      _loadingMeta = true;
      _metaError = null;
    });
    try {
      final res =
          await context.read<ApiClient>().getJson(AppConfig.invoiceMetaPath);
      final whs = (res['warehouses'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _warehouses = whs;
        _warehouseId = Fmt.toInt(res['default_warehouse_id']);
        if (_warehouseId == 0 && whs.isNotEmpty) {
          _warehouseId = Fmt.toInt(whs.first['id']);
        }
        _loadingMeta = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _metaError = e.message;
        _loadingMeta = false;
      });
    }
  }

  double get _grandTotal =>
      _lines.fold(0.0, (sum, l) => sum + l.total);

  Future<void> _pickCustomer() async {
    final p = await pickParty(context, type: 'customer');
    if (p != null) setState(() => _customer = p);
  }

  Future<void> _addItem() async {
    if (_warehouseId == 0) {
      showSnack(context, 'اختر المستودع أولاً', error: true);
      return;
    }
    final it = await pickItem(context, warehouseId: _warehouseId);
    if (it == null) return;
    final existing = _lines.where((l) => l.itemId == it.id).firstOrNull;
    if (existing != null) {
      setState(() => existing.qty += 1);
    } else {
      setState(() => _lines.add(_Line(
            itemId: it.id,
            name: it.name,
            qty: 1,
            qtyExtra: 0,
            unitPrice: it.price,
          )));
    }
  }

  Future<void> _save() async {
    if (_customer == null) {
      showSnack(context, 'اختر العميل', error: true);
      return;
    }
    if (_lines.isEmpty) {
      showSnack(context, 'أضف بنداً واحداً على الأقل', error: true);
      return;
    }
    final s = context.read<SessionController>();
    setState(() => _saving = true);
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.salesInvoiceSaveRoute,
        csrf: s.csrf,
        fields: {
          '_action': 'save_invoice',
          'invoice_id': 0,
          'invoice_date': Fmt.todayIso(),
          'customer_id': _customer!.id,
          'warehouse_id': _warehouseId,
          'payment_type': _paymentType,
          'lines_json': jsonEncode(_lines.map((l) => l.toJson()).toList()),
        },
      );
      if (!mounted) return;
      final invId = Fmt.toInt(res['invoice_id']);
      showSnack(context, (res['message'] ?? 'تم حفظ الفاتورة').toString());
      if (invId > 0) {
        context.pushReplacement('/invoices/$invId');
      } else {
        context.pop();
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('فاتورة جديدة')),
      body: AsyncView(
        loading: _loadingMeta,
        error: _metaError,
        onRetry: _loadMeta,
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(12),
                children: [
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        children: [
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.person_outline),
                            title: Text(_customer?.name ?? 'اختر العميل'),
                            trailing: const Icon(Icons.chevron_left),
                            onTap: _pickCustomer,
                          ),
                          const Divider(),
                          DropdownButtonFormField<int>(
                            initialValue: _warehouseId == 0 ? null : _warehouseId,
                            decoration: const InputDecoration(
                                labelText: 'المستودع'),
                            items: _warehouses
                                .map((w) => DropdownMenuItem<int>(
                                      value: Fmt.toInt(w['id']),
                                      child: Text(Fmt.str(w['name'])),
                                    ))
                                .toList(),
                            onChanged: (v) =>
                                setState(() => _warehouseId = v ?? 0),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            children: [
                              const Text('نوع الدفع: '),
                              const SizedBox(width: 8),
                              ChoiceChip(
                                label: const Text('ذمة'),
                                selected: _paymentType == 'credit',
                                onSelected: (_) =>
                                    setState(() => _paymentType = 'credit'),
                              ),
                              const SizedBox(width: 8),
                              ChoiceChip(
                                label: const Text('نقدي'),
                                selected: _paymentType == 'cash',
                                onSelected: (_) =>
                                    setState(() => _paymentType = 'cash'),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                  Row(
                    children: [
                      const Text('البنود',
                          style: TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 16)),
                      const Spacer(),
                      TextButton.icon(
                        onPressed: _addItem,
                        icon: const Icon(Icons.add),
                        label: const Text('إضافة مادة'),
                      ),
                    ],
                  ),
                  if (_lines.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 20),
                      child: EmptyState(message: 'لم تُضف بنود بعد.'),
                    )
                  else
                    ..._lines.asMap().entries.map((e) => _lineCard(e.key, e.value)),
                ],
              ),
            ),
            _totalBar(),
          ],
        ),
      ),
    );
  }

  Widget _lineCard(int index, _Line l) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
        child: Column(
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(l.name,
                      style: const TextStyle(fontWeight: FontWeight.bold)),
                ),
                IconButton(
                  icon: const Icon(Icons.delete_outline, color: Colors.red),
                  onPressed: () => setState(() => _lines.removeAt(index)),
                ),
              ],
            ),
            Row(
              children: [
                Expanded(
                  child: _numField(
                    label: 'كمية',
                    value: l.qty,
                    onChanged: (v) => setState(() => l.qty = v),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _numField(
                    label: 'إضافية',
                    value: l.qtyExtra,
                    onChanged: (v) => setState(() => l.qtyExtra = v),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _numField(
                    label: 'السعر',
                    value: l.unitPrice,
                    onChanged: (v) => setState(() => l.unitPrice = v),
                  ),
                ),
              ],
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.only(top: 6, left: 8),
                child: Text('الإجمالي: ${Fmt.money(l.total)}',
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(fontWeight: FontWeight.w600)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _numField({
    required String label,
    required double value,
    required ValueChanged<double> onChanged,
  }) {
    return TextFormField(
      initialValue: value == 0 ? '' : Fmt.money(value),
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      textDirection: TextDirection.ltr,
      decoration: InputDecoration(
        labelText: label,
        isDense: true,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      ),
      onChanged: (v) => onChanged(double.tryParse(v.replaceAll(',', '')) ?? 0),
    );
  }

  Widget _totalBar() {
    return SafeArea(
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: const BoxDecoration(
          color: Colors.white,
          boxShadow: [
            BoxShadow(color: Colors.black12, blurRadius: 6, offset: Offset(0, -2)),
          ],
        ),
        child: Row(
          children: [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text('الإجمالي', style: TextStyle(color: Colors.black54)),
                Text(Fmt.money(_grandTotal),
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(
                        fontSize: 18, fontWeight: FontWeight.bold)),
              ],
            ),
            const Spacer(),
            SizedBox(
              width: 160,
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.save_outlined),
                label: const Text('حفظ'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull {
    final it = iterator;
    return it.moveNext() ? it.current : null;
  }
}
