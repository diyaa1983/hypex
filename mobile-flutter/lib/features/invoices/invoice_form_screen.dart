import 'dart:convert';
import 'dart:math' as math;

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

class _TaxRate {
  _TaxRate({required this.id, required this.name, required this.rate});
  final int id;
  final String name;
  final double rate;
}

class _Line {
  _Line({
    required this.itemId,
    required this.name,
    required this.qty,
    required this.qtyExtra,
    required this.unitPrice,
    required this.taxRateId,
    required this.taxRatePercent,
    this.discountInput = '',
  });

  final int itemId;
  final String name;
  double qty;
  double qtyExtra;
  double unitPrice;
  int taxRateId;
  double taxRatePercent;
  String discountInput;

  double get lineBase => qty * unitPrice;

  double get discountAmount {
    final raw = discountInput.trim();
    if (raw.isEmpty || lineBase <= 0) return 0;
    final isPct = raw.endsWith('%') || raw.endsWith('٪');
    final numPart = raw
        .replaceAll('%', '')
        .replaceAll('٪', '')
        .replaceAll(',', '')
        .trim();
    final v = double.tryParse(numPart) ?? 0;
    if (v <= 0) return 0;
    if (isPct) {
      return math.min(lineBase, lineBase * (math.min(v, 100) / 100));
    }
    return math.min(lineBase, v);
  }

  double get subtotal => math.max(0, lineBase - discountAmount);
  double get taxAmount => subtotal * taxRatePercent / 100;
  double get gross => subtotal + taxAmount;

  Map<String, dynamic> toJson() => {
        'item_id': itemId,
        'qty': qty,
        'qty_extra': qtyExtra,
        'unit_price': unitPrice,
        'line_discount_input': discountInput.trim(),
        'discount_amount': discountAmount,
        'tax_rate_id': taxRateId,
        'tax_rate_percent': taxRatePercent,
        'amount_driver': 'unit',
        'line_subtotal': subtotal,
        'tax_amount': taxAmount,
        'line_gross': gross,
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
  List<_TaxRate> _taxRates = [];
  int _warehouseId = 0;
  String _paymentType = 'credit';
  double _defaultTaxPercent = 5;
  int _defaultTaxRateId = 0;
  Party? _customer;
  final List<_Line> _lines = [];
  final _headerDiscount = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadMeta();
  }

  @override
  void dispose() {
    _headerDiscount.dispose();
    super.dispose();
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
      final rates = (res['tax_rates'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _TaxRate(
                id: Fmt.toInt(e['id']),
                name: Fmt.str(e['name']),
                rate: Fmt.toDouble(e['rate_percent']),
              ))
          .toList();
      final defaultPct = Fmt.toDouble(res['default_tax_percent']);
      var defaultId = 0;
      if (rates.isNotEmpty) {
        final match = rates.where((r) => (r.rate - defaultPct).abs() < 0.001);
        defaultId = match.isNotEmpty ? match.first.id : rates.first.id;
      }
      setState(() {
        _warehouses = whs;
        _taxRates = rates;
        _defaultTaxPercent = defaultPct > 0
            ? defaultPct
            : (rates.isNotEmpty ? rates.first.rate : 5);
        _defaultTaxRateId = defaultId;
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

  double get _subTotal => _lines.fold(0.0, (s, l) => s + l.subtotal);
  double get _taxTotal => _lines.fold(0.0, (s, l) => s + l.taxAmount);
  double get _grandTotal => _lines.fold(0.0, (s, l) => s + l.gross);

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
    final existing = _lines.where((l) => l.itemId == it.id).toList();
    if (existing.isNotEmpty) {
      setState(() => existing.first.qty += 1);
    } else {
      setState(() => _lines.add(_Line(
            itemId: it.id,
            name: it.name,
            qty: 1,
            qtyExtra: 0,
            unitPrice: it.price,
            taxRateId: _defaultTaxRateId,
            taxRatePercent: _defaultTaxPercent,
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
      final fields = <String, dynamic>{
        '_action': 'save_invoice',
        'invoice_id': 0,
        'invoice_date': Fmt.todayIso(),
        'customer_id': _customer!.id,
        'warehouse_id': _warehouseId,
        'payment_type': _paymentType,
        'lines_json': jsonEncode(_lines.map((l) => l.toJson()).toList()),
      };
      final headerDisc = _headerDiscount.text.trim();
      if (headerDisc.isNotEmpty) {
        fields['invoice_discount'] = headerDisc;
      }
      final res = await context.read<ApiClient>().postForm(
        AppConfig.salesInvoiceSaveRoute,
        csrf: s.csrf,
        fields: fields,
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
                            value: _warehouseId == 0 ? null : _warehouseId,
                            decoration:
                                const InputDecoration(labelText: 'المستودع'),
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
                          TextField(
                            controller: _headerDiscount,
                            textDirection: TextDirection.ltr,
                            decoration: const InputDecoration(
                              labelText: 'خصم الفاتورة (اختياري)',
                              hintText: 'مثل 10 أو 10%',
                              prefixIcon: Icon(Icons.percent),
                            ),
                            onChanged: (_) => setState(() {}),
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
                    ..._lines
                        .asMap()
                        .entries
                        .map((e) => _lineCard(e.key, e.value)),
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
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    initialValue: l.discountInput,
                    textDirection: TextDirection.ltr,
                    decoration: const InputDecoration(
                      labelText: 'خصم',
                      hintText: '5 أو 5%',
                      isDense: true,
                      contentPadding:
                          EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                    ),
                    onChanged: (v) => setState(() => l.discountInput = v),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: DropdownButtonFormField<int>(
                    value: _taxRates.any((r) => r.id == l.taxRateId)
                        ? l.taxRateId
                        : (_taxRates.isNotEmpty ? _taxRates.first.id : null),
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'ضريبة',
                      isDense: true,
                      contentPadding:
                          EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                    ),
                    items: (_taxRates.isEmpty
                            ? [
                                _TaxRate(
                                    id: 0,
                                    name: 'افتراضي',
                                    rate: _defaultTaxPercent)
                              ]
                            : _taxRates)
                        .map((r) => DropdownMenuItem<int>(
                              value: r.id,
                              child: Text('${Fmt.money(r.rate)}%'),
                            ))
                        .toList(),
                    onChanged: (v) {
                      if (v == null) return;
                      final rate = _taxRates
                          .where((r) => r.id == v)
                          .map((r) => r.rate)
                          .firstOrNull;
                      setState(() {
                        l.taxRateId = v;
                        l.taxRatePercent = rate ?? _defaultTaxPercent;
                      });
                    },
                  ),
                ),
              ],
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.only(top: 8, left: 4),
                child: Text(
                  'صافي: ${Fmt.money(l.subtotal)}  •  ضريبة: ${Fmt.money(l.taxAmount)}  •  الإجمالي: ${Fmt.money(l.gross)}',
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12),
                ),
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
      initialValue: value == 0 ? '' : value.toString(),
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
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('قبل الضريبة: ${Fmt.money(_subTotal)}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(fontSize: 12, color: Colors.black54)),
                  Text('الضريبة: ${Fmt.money(_taxTotal)}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(fontSize: 12, color: Colors.black54)),
                  Text(Fmt.money(_grandTotal),
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                          fontSize: 18, fontWeight: FontWeight.bold)),
                ],
              ),
            ),
            SizedBox(
              width: 150,
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
