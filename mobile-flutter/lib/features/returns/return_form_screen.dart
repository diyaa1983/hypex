import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/ui_kit.dart';

class _RetLine {
  _RetLine({
    required this.invoiceLineId,
    required this.itemId,
    required this.name,
    required this.barcode,
    required this.qtyRemaining,
    required this.unitPrice,
  });

  final int invoiceLineId;
  final int itemId;
  final String name;
  final String barcode;
  final double qtyRemaining;
  final double unitPrice;
  double qty = 0;
  final qtyCtrl = TextEditingController();
  final qtyFocus = FocusNode();

  void dispose() {
    qtyCtrl.dispose();
    qtyFocus.dispose();
  }
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
  bool _scanning = false;

  final _barcodeCtrl = TextEditingController();
  final _barcodeFocus = FocusNode();
  final _nameFilterCtrl = TextEditingController();

  @override
  void dispose() {
    _barcodeCtrl.dispose();
    _barcodeFocus.dispose();
    _nameFilterCtrl.dispose();
    for (final l in _lines) {
      l.dispose();
    }
    super.dispose();
  }

  List<_RetLine> get _visibleLines {
    final q = _nameFilterCtrl.text.trim().toLowerCase();
    if (q.isEmpty) return _lines;
    return _lines
        .where(
          (l) =>
              l.name.toLowerCase().contains(q) ||
              l.barcode.toLowerCase().contains(q),
        )
        .toList();
  }

  Future<void> _pickCustomer() async {
    final p = await pickParty(context, type: 'customer');
    if (p == null) return;
    for (final l in _lines) {
      l.dispose();
    }
    setState(() {
      _customer = p;
      _invoices = [];
      _invoiceId = 0;
      _invoiceNo = '';
      _lines = [];
      _nameFilterCtrl.clear();
      _barcodeCtrl.clear();
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
      if (!mounted) return;
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
    for (final l in _lines) {
      l.dispose();
    }
    setState(() {
      _loadingLines = true;
      _invoiceId = invoiceId;
      _lines = [];
      _barcodeCtrl.clear();
      _nameFilterCtrl.clear();
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.returnLinesPath,
        query: {'invoice_id': invoiceId, 'customer_id': _customer!.id},
      );
      if (!mounted) return;
      setState(() {
        _invoiceNo = (res['invoice_no'] ?? '').toString();
        _lines = (res['lines'] as List? ?? []).whereType<Map>().map((e) {
          final m = e.cast<String, dynamic>();
          return _RetLine(
            invoiceLineId: Fmt.toInt(m['invoice_line_id']),
            itemId: Fmt.toInt(m['item_id']),
            name: Fmt.str(m['item_name'] ?? m['name_ar'] ?? m['name']),
            barcode: Fmt.str(m['barcode'] ?? m['sku']),
            qtyRemaining: Fmt.toDouble(m['qty_remaining']),
            unitPrice: Fmt.toDouble(m['unit_price']),
          );
        }).toList();
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _barcodeFocus.requestFocus();
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _loadingLines = false);
    }
  }

  _RetLine? _findByBarcode(String code) {
    final c = code.trim().toLowerCase();
    if (c.isEmpty) return null;
    for (final l in _lines) {
      if (l.barcode.trim().toLowerCase() == c) return l;
    }
    for (final l in _lines) {
      if (l.barcode.trim().toLowerCase().contains(c) ||
          l.name.toLowerCase().contains(c)) {
        return l;
      }
    }
    return null;
  }

  Future<void> _onBarcodeSubmit() async {
    if (_scanning || _invoiceId < 1) return;
    final code = _barcodeCtrl.text.trim();
    if (code.isEmpty) return;
    setState(() => _scanning = true);
    try {
      final line = _findByBarcode(code);
      if (line == null) {
        showSnack(context, 'المادة غير موجودة في هذه الفاتورة.', error: true);
        _barcodeCtrl.selection = TextSelection(
          baseOffset: 0,
          extentOffset: _barcodeCtrl.text.length,
        );
        return;
      }
      if (line.qty <= 0) {
        line.qty = 1;
        if (line.qty > line.qtyRemaining) line.qty = line.qtyRemaining;
        line.qtyCtrl.text = Fmt.trimNum(line.qty);
      } else {
        final next = line.qty + 1;
        line.qty = next > line.qtyRemaining ? line.qtyRemaining : next;
        line.qtyCtrl.text = Fmt.trimNum(line.qty);
      }
      setState(() {});
      _barcodeCtrl.clear();
      line.qtyFocus.requestFocus();
      line.qtyCtrl.selection = TextSelection(
        baseOffset: 0,
        extentOffset: line.qtyCtrl.text.length,
      );
    } finally {
      if (mounted) setState(() => _scanning = false);
    }
  }

  Future<void> _pickLineByName() async {
    if (_lines.isEmpty) return;
    final chosen = await showModalBottomSheet<_RetLine>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        final search = TextEditingController();
        var rows = List<_RetLine>.from(_lines);
        return StatefulBuilder(
          builder: (ctx, setLocal) {
            return Padding(
              padding: EdgeInsets.only(
                bottom: MediaQuery.of(ctx).viewInsets.bottom,
              ),
              child: SizedBox(
                height: MediaQuery.of(ctx).size.height * 0.75,
                child: Column(
                  children: [
                    const Padding(
                      padding: EdgeInsets.fromLTRB(16, 14, 16, 8),
                      child: Text(
                        'بحث عن مادة في الفاتورة',
                        style: TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: TextField(
                        controller: search,
                        autofocus: true,
                        decoration: const InputDecoration(
                          hintText: 'الاسم أو الباركود...',
                          prefixIcon: Icon(Icons.search),
                        ),
                        onChanged: (v) {
                          final q = v.trim().toLowerCase();
                          setLocal(() {
                            rows = _lines
                                .where(
                                  (l) =>
                                      q.isEmpty ||
                                      l.name.toLowerCase().contains(q) ||
                                      l.barcode.toLowerCase().contains(q),
                                )
                                .toList();
                          });
                        },
                      ),
                    ),
                    const SizedBox(height: 8),
                    Expanded(
                      child: ListView.separated(
                        itemCount: rows.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (_, i) {
                          final l = rows[i];
                          return ListTile(
                            title: Text(l.name),
                            subtitle: Text(
                              '${l.barcode.isEmpty ? '—' : l.barcode}  •  متبقي ${Fmt.money(l.qtyRemaining)}',
                              textDirection: TextDirection.ltr,
                            ),
                            onTap: () => Navigator.pop(ctx, l),
                          );
                        },
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
    if (chosen == null || !mounted) return;
    if (chosen.qty <= 0) {
      chosen.qty = 1;
      chosen.qtyCtrl.text = '1';
    }
    setState(() {});
    chosen.qtyFocus.requestFocus();
    chosen.qtyCtrl.selection = TextSelection(
      baseOffset: 0,
      extentOffset: chosen.qtyCtrl.text.length,
    );
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
          'lines_json': jsonEncode(
            picked
                .map(
                  (l) => {
                    'invoice_line_id': l.invoiceLineId,
                    'item_id': l.itemId,
                    'qty': l.qty,
                  },
                )
                .toList(),
          ),
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
    final visible = _visibleLines;
    return Scaffold(
      appBar: AppBar(title: const Text('مرتجع جديد')),
      body: Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 20),
              children: [
                AppCard(
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  child: ListTile(
                    leading: MiniIcon(
                      Icons.person_rounded,
                      color: _customer == null
                          ? AppTheme.textSoft
                          : AppTheme.primary,
                    ),
                    title: Text(
                      _customer?.name ?? 'اختر العميل',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: _customer == null
                            ? AppTheme.textSoft
                            : AppTheme.textMain,
                      ),
                    ),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: _pickCustomer,
                  ),
                ),
                if (_customer != null) ...[
                  const SizedBox(height: 10),
                  AppCard(
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
                                  labelText: 'فاتورة البيع',
                                ),
                                items: _invoices
                                    .map(
                                      (inv) => DropdownMenuItem<int>(
                                        value: Fmt.toInt(inv['id']),
                                        child: Text(
                                          'فاتورة ${inv['invoice_no']} - ${Fmt.dmy(Fmt.str(inv['invoice_date']))}',
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                    )
                                    .toList(),
                                onChanged: (v) {
                                  if (v != null) _loadLines(v);
                                },
                              ),
                  ),
                ],
                if (_loadingLines)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Center(child: CircularProgressIndicator()),
                  )
                else if (_invoiceId != 0) ...[
                  const SizedBox(height: 12),
                  SectionTitle(
                    'بنود الفاتورة $_invoiceNo',
                    icon: Icons.list_alt_rounded,
                    trailing: Text(
                      '${_lines.length}',
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppTheme.textSoft,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  AppCard(
                    padding: const EdgeInsets.all(10),
                    child: Column(
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: TextField(
                                controller: _barcodeCtrl,
                                focusNode: _barcodeFocus,
                                textDirection: TextDirection.ltr,
                                textInputAction: TextInputAction.done,
                                decoration: const InputDecoration(
                                  labelText: 'باركود',
                                  hintText: 'امسح أو اكتب ثم Enter',
                                  prefixIcon: Icon(Icons.qr_code_scanner),
                                  isDense: true,
                                ),
                                onSubmitted: (_) => _onBarcodeSubmit(),
                              ),
                            ),
                            const SizedBox(width: 8),
                            IconButton.filledTonal(
                              tooltip: 'بحث بالاسم',
                              onPressed: _pickLineByName,
                              icon: const Icon(Icons.search_rounded),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        TextField(
                          controller: _nameFilterCtrl,
                          decoration: const InputDecoration(
                            labelText: 'تصفية القائمة بالاسم',
                            prefixIcon: Icon(Icons.filter_list_rounded),
                            isDense: true,
                          ),
                          onChanged: (_) => setState(() {}),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  if (_lines.isEmpty)
                    const EmptyState(message: 'لا توجد بنود قابلة للإرجاع.')
                  else
                    _linesTable(visible),
                ],
              ],
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
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

  Widget _linesTable(List<_RetLine> rows) {
    return AppCard(
      padding: const EdgeInsets.fromLTRB(8, 8, 8, 4),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
            decoration: BoxDecoration(
              color: AppTheme.surfaceAlt,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Row(
              children: [
                Expanded(flex: 3, child: Text('المادة', style: _th)),
                Expanded(child: Text('متبقي', textAlign: TextAlign.center, style: _th)),
                Expanded(child: Text('سعر', textAlign: TextAlign.center, style: _th)),
                Expanded(child: Text('إرجاع', textAlign: TextAlign.center, style: _th)),
              ],
            ),
          ),
          const SizedBox(height: 4),
          ...rows.map(_lineRow),
        ],
      ),
    );
  }

  Widget _lineRow(_RetLine l) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 3,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                  ),
                ),
                if (l.barcode.isNotEmpty)
                  Text(
                    l.barcode,
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(
                      fontSize: 11,
                      color: AppTheme.textSoft,
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: Text(
              Fmt.money(l.qtyRemaining),
              textAlign: TextAlign.center,
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700),
            ),
          ),
          Expanded(
            child: Text(
              Fmt.money(l.unitPrice),
              textAlign: TextAlign.center,
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700),
            ),
          ),
          Expanded(
            child: TextField(
              controller: l.qtyCtrl,
              focusNode: l.qtyFocus,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              textInputAction: TextInputAction.next,
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
              ],
              decoration: const InputDecoration(
                isDense: true,
                contentPadding:
                    EdgeInsets.symmetric(horizontal: 6, vertical: 10),
              ),
              onChanged: (v) {
                var q = double.tryParse(v.replaceAll(',', '')) ?? 0;
                if (q > l.qtyRemaining) q = l.qtyRemaining;
                l.qty = q;
              },
              onSubmitted: (_) {
                _barcodeFocus.requestFocus();
              },
            ),
          ),
        ],
      ),
    );
  }
}

const _th = TextStyle(
  fontSize: 11.5,
  fontWeight: FontWeight.w800,
  color: AppTheme.textSoft,
);
