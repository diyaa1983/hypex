import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../widgets/async_view.dart';
import '../../widgets/party_picker.dart';

class _RetLine {
  _RetLine({
    required this.itemId,
    required this.name,
    required this.qtyRemaining,
    required this.unitPrice,
  });

  final int itemId;
  final String name;
  final double qtyRemaining;
  final double unitPrice;
  double qty = 0;
}

class ReturnFormScreen extends StatefulWidget {
  const ReturnFormScreen({super.key});

  @override
  State<ReturnFormScreen> createState() => _ReturnFormScreenState();
}

class _ReturnFormScreenState extends State<ReturnFormScreen> {
  Party? _customer;
  List<Map<String, dynamic>> _invoices = [];
  int _invoiceId = 0;
  String _invoiceNo = '';
  List<_RetLine> _lines = [];

  bool _loadingInvoices = false;
  bool _loadingLines = false;
  bool _saving = false;

  Future<void> _pickCustomer() async {
    final p = await pickParty(context, type: 'customer');
    if (p == null) return;
    setState(() {
      _customer = p;
      _invoices = [];
      _invoiceId = 0;
      _lines = [];
    });
    await _loadInvoices();
  }

  Future<void> _loadInvoices() async {
    if (_customer == null) return;
    setState(() => _loadingInvoices = true);
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.returnInvoicesPath,
        query: {'customer_id': _customer!.id},
      );
      setState(() {
        _invoices = (res['invoices'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _loadingInvoices = false);
    }
  }

  Future<void> _loadLines(int invoiceId) async {
    setState(() {
      _loadingLines = true;
      _invoiceId = invoiceId;
      _lines = [];
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.returnLinesPath,
        query: {'invoice_id': invoiceId, 'customer_id': _customer!.id},
      );
      setState(() {
        _invoiceNo = (res['invoice_no'] ?? '').toString();
        _lines = (res['lines'] as List? ?? [])
            .whereType<Map>()
            .map((e) => _RetLine(
                  itemId: Fmt.toInt(e['item_id']),
                  name: Fmt.str(e['item_name'] ?? e['name_ar'] ?? e['name']),
                  qtyRemaining: Fmt.toDouble(e['qty_remaining']),
                  unitPrice: Fmt.toDouble(e['unit_price']),
                ))
            .toList();
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _loadingLines = false);
    }
  }

  Future<void> _save() async {
    final picked = _lines.where((l) => l.qty > 0).toList();
    if (_customer == null || _invoiceId == 0) {
      showSnack(context, 'اختر العميل والفاتورة', error: true);
      return;
    }
    if (picked.isEmpty) {
      showSnack(context, 'حدّد كمية إرجاع لبند واحد على الأقل', error: true);
      return;
    }
    final s = context.read<SessionController>();
    setState(() => _saving = true);
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.returnSaveRoute,
        csrf: s.csrf,
        fields: {
          '_action': 'save_return',
          'return_id': 0,
          'customer_id': _customer!.id,
          'invoice_id': _invoiceId,
          'return_date': Fmt.todayIso(),
          'lines_json': jsonEncode(picked
              .map((l) => {'item_id': l.itemId, 'qty': l.qty})
              .toList()),
        },
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم حفظ المرتجع').toString());
      context.pop();
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
      appBar: AppBar(title: const Text('مرتجع جديد')),
      body: Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(12),
              children: [
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.person_outline),
                    title: Text(_customer?.name ?? 'اختر العميل'),
                    trailing: const Icon(Icons.chevron_left),
                    onTap: _pickCustomer,
                  ),
                ),
                if (_customer != null)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: _loadingInvoices
                          ? const Center(
                              child: Padding(
                                padding: EdgeInsets.all(8),
                                child: CircularProgressIndicator(),
                              ),
                            )
                          : _invoices.isEmpty
                              ? const Text('لا توجد فواتير قابلة للإرجاع.')
                              : DropdownButtonFormField<int>(
                                  initialValue:
                                      _invoiceId == 0 ? null : _invoiceId,
                                  isExpanded: true,
                                  decoration: const InputDecoration(
                                      labelText: 'فاتورة البيع'),
                                  items: _invoices
                                      .map((inv) => DropdownMenuItem<int>(
                                            value: Fmt.toInt(inv['id']),
                                            child: Text(
                                              'فاتورة ${inv['invoice_no']} - ${inv['invoice_date']}',
                                              overflow: TextOverflow.ellipsis,
                                            ),
                                          ))
                                      .toList(),
                                  onChanged: (v) {
                                    if (v != null) _loadLines(v);
                                  },
                                ),
                    ),
                  ),
                if (_loadingLines)
                  const Padding(
                    padding: EdgeInsets.all(20),
                    child: Center(child: CircularProgressIndicator()),
                  )
                else if (_invoiceId != 0) ...[
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text('بنود الفاتورة $_invoiceNo',
                        style: const TextStyle(
                            fontWeight: FontWeight.bold, fontSize: 16)),
                  ),
                  if (_lines.isEmpty)
                    const EmptyState(message: 'لا توجد بنود قابلة للإرجاع.')
                  else
                    ..._lines.map(_lineCard),
                ],
              ],
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.save_outlined),
                label: const Text('حفظ المرتجع'),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _lineCard(_RetLine l) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l.name,
                      style: const TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 4),
                  Text('المتبقي للإرجاع: ${Fmt.money(l.qtyRemaining)}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                          fontSize: 12, color: Colors.black54)),
                ],
              ),
            ),
            SizedBox(
              width: 90,
              child: TextFormField(
                keyboardType:
                    const TextInputType.numberWithOptions(decimal: true),
                textDirection: TextDirection.ltr,
                decoration: const InputDecoration(
                  labelText: 'إرجاع',
                  isDense: true,
                ),
                onChanged: (v) {
                  var q = double.tryParse(v.replaceAll(',', '')) ?? 0;
                  if (q > l.qtyRemaining) q = l.qtyRemaining;
                  l.qty = q;
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
