import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/invoice_print_helper.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/item_picker.dart';
import '../../widgets/party_picker.dart';

// ─────────────────────────────────────────────────────────────────────────────
// نموذج البند
// ─────────────────────────────────────────────────────────────────────────────

class _TaxRate {
  _TaxRate({required this.id, required this.name, required this.rate});
  final int id;
  final String name;
  final double rate;
}

class _Line {
  _Line({
    required this.itemId,
    required this.barcode,
    required this.name,
    required this.qty,
    required this.qtyExtra,
    required this.unitPrice,
    required this.taxRateId,
    required this.taxRatePercent,
  });

  final int itemId;
  String barcode;
  String name;
  double qty;
  double qtyExtra;
  double unitPrice;
  int taxRateId;
  double taxRatePercent;
  String discountInput = '';

  double get lineBase => qty * unitPrice;

  double get discountAmount {
    final raw = discountInput.trim();
    if (raw.isEmpty || lineBase <= 0) return 0;
    final isPct = raw.endsWith('%') || raw.endsWith('٪');
    final numPart =
        raw.replaceAll('%', '').replaceAll('٪', '').replaceAll(',', '').trim();
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

// ─────────────────────────────────────────────────────────────────────────────
// الشاشة
// ─────────────────────────────────────────────────────────────────────────────

class InvoiceFormScreen extends StatefulWidget {
  const InvoiceFormScreen({super.key, this.invoiceId});

  final int? invoiceId;

  @override
  State<InvoiceFormScreen> createState() => _InvoiceFormScreenState();
}

class _InvoiceFormScreenState extends State<InvoiceFormScreen> {
  static const _blue = Color(0xFF0B63CE);
  static const _blueDeep = Color(0xFF07396F);
  static const _surface = Color(0xFFFFFFFF);
  static const _bg = Color(0xFFF0F3F8);
  static const _border = Color(0xFFE2E8F0);
  static const _muted = Color(0xFF64748B);
  static const _ink = Color(0xFF0F172A);

  bool _loadingMeta = true;
  bool _busy = false;
  String? _metaError;

  int _invoiceId = 0;
  String _invoiceNo = '';
  String _invoiceDate = '';
  bool _isPosted = false;
  bool _einvSent = false;

  List<Map<String, dynamic>> _warehouses = [];
  List<_TaxRate> _taxRates = [];
  int _warehouseId = 0;
  int _defaultWarehouseId = 0;
  String _paymentType = 'credit';
  double _defaultTaxPercent = 5;
  int _defaultTaxRateId = 0;
  Party? _customer;
  final List<_Line> _lines = [];

  final _barcodeCtrl = TextEditingController();
  final _barcodeFocus = FocusNode();
  bool _lookupBusy = false;

  bool get _isEdit => _invoiceId > 0;
  bool get _canEdit => !_isPosted && !_einvSent;
  bool get _canChangeWarehouse => _warehouses.length > 1 && _canEdit;
  bool get _canSendEinvoice => _isPosted && !_einvSent;

  double get _subTotal => _lines.fold(0.0, (s, l) => s + l.subtotal);
  double get _taxTotal => _lines.fold(0.0, (s, l) => s + l.taxAmount);
  double get _grandTotal => _lines.fold(0.0, (s, l) => s + l.gross);

  @override
  void initState() {
    super.initState();
    _invoiceId = widget.invoiceId ?? 0;
    _invoiceDate = Fmt.todayIso();
    _loadMeta();
  }

  @override
  void dispose() {
    _barcodeCtrl.dispose();
    _barcodeFocus.dispose();
    super.dispose();
  }

  // ── تحميل ──────────────────────────────────────────────────────────────

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
          .map(
            (e) => _TaxRate(
              id: Fmt.toInt(e['id']),
              name: Fmt.str(e['name']),
              rate: Fmt.toDouble(e['rate_percent']),
            ),
          )
          .toList();
      final defaultPct = Fmt.toDouble(res['default_tax_percent']);
      var defaultId = 0;
      if (rates.isNotEmpty) {
        final match = rates.where((r) => (r.rate - defaultPct).abs() < 0.001);
        defaultId = match.isNotEmpty ? match.first.id : rates.first.id;
      }
      final defWh = Fmt.toInt(res['default_warehouse_id']);
      if (!mounted) return;
      setState(() {
        _warehouses = whs;
        _taxRates = rates;
        _defaultTaxPercent = defaultPct > 0
            ? defaultPct
            : (rates.isNotEmpty ? rates.first.rate : 5);
        _defaultTaxRateId = defaultId;
        _defaultWarehouseId = defWh;
        _warehouseId = defWh;
        if (_warehouseId == 0 && whs.isNotEmpty) {
          _warehouseId = Fmt.toInt(whs.first['id']);
        }
      });
      if (_invoiceId > 0) {
        await _loadInvoice();
      } else if (mounted) {
        setState(() => _loadingMeta = false);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _metaError = e.message;
        _loadingMeta = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _metaError = 'تعذر تحميل بيانات الفاتورة.';
        _loadingMeta = false;
      });
    }
  }

  Future<void> _loadInvoice() async {
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceViewPath,
        query: {'id': _invoiceId},
      );
      final inv = (res['invoice'] as Map?)?.cast<String, dynamic>() ?? {};
      if (inv.isEmpty) throw ApiException('الفاتورة غير موجودة.');

      final posted = inv['is_posted'] == true;
      final einv = inv['einv_sent'] == true;
      if (widget.invoiceId != null && (posted || einv)) {
        // مسار التعديل: لا نسمح بفتح المرحّلة هنا — نوجّه للعرض.
        if (!mounted) return;
        context.replace('/invoices/$_invoiceId');
        return;
      }

      final cid = Fmt.toInt(inv['customer_id']);
      final cname = Fmt.str(inv['customer_name']);
      final linesRaw = inv['lines'] ?? inv['items'] ?? inv['rows'];
      final loaded = <_Line>[];
      if (linesRaw is List) {
        for (final e in linesRaw) {
          if (e is! Map) continue;
          final m = e.cast<String, dynamic>();
          final pct = Fmt.toDouble(m['tax_rate_percent']);
          var rateId = _defaultTaxRateId;
          final match = _taxRates.where((r) => (r.rate - pct).abs() < 0.001);
          if (match.isNotEmpty) {
            rateId = match.first.id;
          } else if (_taxRates.isNotEmpty && pct > 0) {
            rateId = _taxRates
                .reduce(
                  (a, b) => (a.rate - pct).abs() < (b.rate - pct).abs() ? a : b,
                )
                .id;
          }
          final ratePct = _taxRates
                  .where((r) => r.id == rateId)
                  .map((r) => r.rate)
                  .firstOrNull ??
              pct;
          loaded.add(
            _Line(
              itemId: Fmt.toInt(m['item_id']),
              barcode: Fmt.str(
                m['barcode'] ?? m['material_number'] ?? m['item_code'] ?? '',
              ),
              name: Fmt.str(
                m['item_name'] ?? m['name_ar'] ?? m['name'] ?? m['line_desc'],
              ),
              qty: Fmt.toDouble(m['qty']),
              qtyExtra: Fmt.toDouble(m['qty_extra']),
              unitPrice: Fmt.toDouble(m['unit_price'] ?? m['price']),
              taxRateId: rateId,
              taxRatePercent: ratePct > 0 ? ratePct : _defaultTaxPercent,
            )..discountInput = Fmt.str(m['line_discount_input']),
          );
        }
      }

      if (!mounted) return;
      setState(() {
        _invoiceNo = Fmt.str(inv['invoice_no']);
        _isPosted = posted;
        _einvSent = einv;
        _customer =
            cid > 0 ? Party(cid, cname, Fmt.str(inv['customer_code'])) : null;
        _warehouseId = Fmt.toInt(inv['warehouse_id']);
        if (_warehouseId == 0 && _warehouses.isNotEmpty) {
          _warehouseId = Fmt.toInt(_warehouses.first['id']);
        }
        _paymentType = Fmt.str(inv['payment_type']).isEmpty
            ? 'credit'
            : Fmt.str(inv['payment_type']);
        final d = Fmt.str(inv['invoice_date']);
        _invoiceDate = d.isEmpty ? Fmt.todayIso() : d;
        _lines
          ..clear()
          ..addAll(loaded);
        _loadingMeta = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _metaError = e.message;
        _loadingMeta = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _metaError = 'تعذر تحميل الفاتورة.';
        _loadingMeta = false;
      });
    }
  }

  // ── اختيار / باركود ────────────────────────────────────────────────────

  Future<void> _pickCustomer() async {
    if (!_canEdit) return;
    final p = await pickParty(context, type: 'customer');
    if (p != null) setState(() => _customer = p);
  }

  Future<void> _pickDate() async {
    if (!_canEdit) return;
    DateTime initial;
    try {
      initial = DateTime.parse(_invoiceDate);
    } catch (_) {
      initial = DateTime.now();
    }
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _invoiceDate =
          '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
    });
  }

  Future<void> _addItemFromPicker() async {
    if (!_canEdit) return;
    if (_warehouseId == 0) {
      showSnack(context, 'اختر المستودع أولاً', error: true);
      return;
    }
    final it = await pickItem(context, warehouseId: _warehouseId);
    if (it == null) return;
    _appendOrBumpLine(it,
        qty: 1, qtyExtra: 0, price: it.price > 0 ? it.price : 0);
  }

  Future<void> _lookupBarcode() async {
    if (!_canEdit || _lookupBusy) return;
    final code = _barcodeCtrl.text.trim();
    if (code.isEmpty) return;
    if (_warehouseId == 0) {
      showSnack(context, 'اختر المستودع أولاً', error: true);
      return;
    }
    setState(() => _lookupBusy = true);
    final api = context.read<ApiClient>();
    try {
      var res = await api.getJson(
        AppConfig.itemsSearchPath,
        query: {'warehouse_id': _warehouseId, 'code': code},
      );
      var items = (res['items'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      if (items.isEmpty) {
        res = await api.getJson(
          AppConfig.itemsSearchPath,
          query: {'warehouse_id': _warehouseId, 'q': code},
        );
        items = (res['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
      }
      if (!mounted) return;
      if (items.isEmpty) {
        showSnack(context, 'لم يُعثر على مادة بهذا الباركود.', error: true);
        return;
      }
      Map<String, dynamic> row = items.first;
      final lower = code.toLowerCase();
      for (final it in items) {
        final bc = Fmt.str(it['barcode'] ?? it['sku']).toLowerCase();
        if (bc == lower) {
          row = it;
          break;
        }
      }
      final price = Fmt.toDouble(
        row['default_sale'] ?? row['sale_price'] ?? row['unit_price'],
      );
      _appendOrBumpLine(
        PickedItem(
          Fmt.toInt(row['id']),
          Fmt.str(row['name_ar'] ?? row['name'] ?? row['sku']),
          price,
          Fmt.toDouble(row['stock_qty']),
          barcode: Fmt.str(row['barcode'] ?? row['sku']),
        ),
        qty: 1,
        qtyExtra: 0,
        price: price > 0 ? price : 0,
      );
      _barcodeCtrl.clear();
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _barcodeFocus.requestFocus();
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _lookupBusy = false);
    }
  }

  void _appendOrBumpLine(
    PickedItem it, {
    required double qty,
    required double qtyExtra,
    required double price,
  }) {
    final existing = _lines.where((l) => l.itemId == it.id).toList();
    setState(() {
      if (existing.isNotEmpty) {
        existing.first.qty += qty;
        existing.first.qtyExtra += qtyExtra;
        if (price > 0) existing.first.unitPrice = price;
      } else {
        _lines.add(
          _Line(
            itemId: it.id,
            barcode: it.barcode,
            name: it.name,
            qty: qty,
            qtyExtra: qtyExtra,
            unitPrice: price,
            taxRateId: _defaultTaxRateId,
            taxRatePercent: _defaultTaxPercent,
          ),
        );
      }
    });
  }

  // ── حفظ / ترحيل / فوترة / طباعة ─────────────────────────────────────────

  Future<int> _save() async {
    if (!_canEdit) {
      showSnack(context, 'لا يمكن تعديل فاتورة مرحّلة أو مُرسلة.', error: true);
      return 0;
    }
    if (_customer == null) {
      showSnack(context, 'اختر العميل', error: true);
      return 0;
    }
    if (_lines.isEmpty) {
      showSnack(context, 'أضف بنداً واحداً على الأقل', error: true);
      return 0;
    }
    final zeroPrice =
        _lines.where((l) => l.unitPrice <= 0 && l.qty > 0).toList();
    if (zeroPrice.isNotEmpty) {
      showSnack(
        context,
        'أدخل سعر الوحدة للمادة: ${zeroPrice.first.name}',
        error: true,
      );
      return 0;
    }
    if (_warehouseId < 1) {
      showSnack(context, 'اختر المستودع', error: true);
      return 0;
    }

    final s = context.read<SessionController>();
    final api = context.read<ApiClient>();
    setState(() => _busy = true);
    try {
      final res = await api.postForm(
        AppConfig.salesInvoiceSaveRoute,
        csrf: s.csrf,
        fields: {
          '_action': 'save_invoice',
          'invoice_id': _invoiceId,
          'invoice_date': _invoiceDate.isEmpty ? Fmt.todayIso() : _invoiceDate,
          'customer_id': _customer!.id,
          'warehouse_id': _warehouseId,
          'payment_type': _paymentType,
          'lines_json': jsonEncode(_lines.map((l) => l.toJson()).toList()),
        },
      );
      final invId = Fmt.toInt(res['invoice_id']);
      final invNo = Fmt.str(res['invoice_no']);
      if (!mounted) return invId;
      setState(() {
        _invoiceId = invId;
        if (invNo.isNotEmpty) _invoiceNo = invNo;
      });
      showSnack(
          context, (res['message'] ?? 'تم حفظ الفاتورة بنجاح.').toString());
      return invId;
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
      return 0;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _post() async {
    var id = _invoiceId;
    if (id < 1 || _canEdit) {
      id = await _save();
      if (id < 1 || !mounted) return;
    }
    if (_isPosted) {
      showSnack(context, 'الفاتورة مرحّلة مسبقاً.');
      return;
    }
    final s = context.read<SessionController>();
    final api = context.read<ApiClient>();
    setState(() => _busy = true);
    try {
      final gps = await LocationService.tryGetPosition();
      final fields = <String, dynamic>{'invoice_id': id};
      if (gps != null) {
        fields['latitude'] = gps.latitude;
        fields['longitude'] = gps.longitude;
        fields['gps_accuracy'] = gps.accuracy;
        fields['gps_source'] = 'mobile';
      }
      final p = await api.postForm(
        AppConfig.salesInvoicePostPath,
        fields: fields,
        csrf: s.csrf,
      );
      if (!mounted) return;
      showSnack(context, (p['message'] ?? 'تم ترحيل الفاتورة.').toString());
      setState(() => _isPosted = true);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _view() async {
    if (_invoiceId < 1) {
      final id = await _save();
      if (id < 1 || !mounted) return;
    }
    if (!mounted) return;
    await context.push('/invoices/$_invoiceId');
    if (mounted) await _loadInvoice();
  }

  Future<void> _print() async {
    if (_invoiceId < 1) {
      final id = await _save();
      if (id < 1 || !mounted) return;
    }
    if (!mounted) return;
    await InvoicePrintHelper.printBluetooth(
      context,
      invoice: {'id': _invoiceId, 'invoice_no': _invoiceNo},
    );
  }

  Future<void> _sendEinvoice() async {
    if (!_canSendEinvoice) {
      if (!_isPosted) {
        showSnack(context, 'يجب ترحيل الفاتورة قبل إرسالها للفوترة.',
            error: true);
      } else {
        showSnack(context, 'الفاتورة مُرسلة للفوترة مسبقاً.');
      }
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('فوترة إلكترونية'),
        content: const Text('إرسال الفاتورة للفوترة الإلكترونية؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('إرسال'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final s = context.read<SessionController>();
    setState(() => _busy = true);
    try {
      final res = await context.read<ApiClient>().postForm(
            AppConfig.salesInvoiceEinvoiceSendPath,
            fields: {'invoice_id': _invoiceId},
            csrf: s.csrf,
          );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الإرسال للفوترة.').toString());
      setState(() => _einvSent = true);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openEdit() async {
    if (_invoiceId < 1) {
      showSnack(context, 'احفظ الفاتورة أولاً.', error: true);
      return;
    }
    if (!_canEdit) {
      showSnack(context, 'لا يمكن تعديل فاتورة مرحّلة أو مُرسلة.', error: true);
      return;
    }
    // نحن أصلاً في شاشة التعديل — أعد التحميل.
    await _loadInvoice();
    if (mounted) showSnack(context, 'جاهز للتعديل.');
  }

  // ── واجهة ──────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _bg,
      appBar: AppBar(
        elevation: 0,
        backgroundColor: _blue,
        foregroundColor: Colors.white,
        title: Column(
          children: [
            Text(
              _isEdit ? 'فاتورة مبيعات' : 'فاتورة بيع جديدة',
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
            ),
            if (_invoiceNo.isNotEmpty)
              Text(
                _invoiceNo,
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.white.withValues(alpha: 0.85),
                  fontWeight: FontWeight.w600,
                ),
              ),
          ],
        ),
        actions: [
          if (_isPosted || _einvSent)
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 12),
              child: Center(
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.18),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    _einvSent ? 'مُرسلة' : 'مرحّلة',
                    style: const TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: Colors.white,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
      body: AsyncView(
        loading: _loadingMeta,
        error: _metaError,
        onRetry: _loadMeta,
        skeleton: false,
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 18),
                children: [
                  _headerCard(),
                  const SizedBox(height: 12),
                  _scanBar(),
                  const SizedBox(height: 12),
                  _linesHeader(),
                  const SizedBox(height: 8),
                  if (_lines.isEmpty) _emptyLines() else ..._buildLineCards(),
                  const SizedBox(height: 12),
                  _totalsCard(),
                ],
              ),
            ),
            _actionDock(),
          ],
        ),
      ),
    );
  }

  Widget _headerCard() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: _border),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0B2545).withValues(alpha: 0.05),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: _fieldShell(
                  label: 'رقم الفاتورة',
                  child: _readonlyValue(
                    _invoiceNo.isEmpty ? '—' : _invoiceNo,
                    ltr: true,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _fieldShell(
                  label: 'التاريخ',
                  child: InkWell(
                    onTap: _canEdit ? _pickDate : null,
                    borderRadius: BorderRadius.circular(12),
                    child: _readonlyValue(Fmt.dmy(_invoiceDate), ltr: true),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          _fieldShell(
            label: 'نوع الدفع',
            child: _paymentSeg(),
          ),
          const SizedBox(height: 12),
          _customerTile(),
          const SizedBox(height: 12),
          _fieldShell(
            label: 'المستودع',
            child: _canChangeWarehouse
                ? DropdownButtonHideUnderline(
                    child: DropdownButton<int>(
                      value: _warehouseId == 0 ? null : _warehouseId,
                      isExpanded: true,
                      hint: const Text('اختر المستودع'),
                      items: _warehouses
                          .map(
                            (w) => DropdownMenuItem<int>(
                              value: Fmt.toInt(w['id']),
                              child: Text(
                                Fmt.str(w['name']),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          )
                          .toList(),
                      onChanged: (v) => setState(() => _warehouseId = v ?? 0),
                    ),
                  )
                : _readonlyValue(_warehouseName()),
          ),
          if (_defaultWarehouseId > 0 &&
              _warehouseId == _defaultWarehouseId &&
              _canChangeWarehouse)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  'تم اختيار المستودع تلقائياً حسب المندوب',
                  style: TextStyle(fontSize: 11, color: _muted),
                ),
              ),
            ),
        ],
      ),
    );
  }

  String _warehouseName() {
    for (final w in _warehouses) {
      if (Fmt.toInt(w['id']) == _warehouseId) return Fmt.str(w['name']);
    }
    return _warehouseId > 0 ? '#$_warehouseId' : '—';
  }

  Widget _paymentSeg() {
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          _segBtn('ذمم', 'credit'),
          _segBtn('نقدي', 'cash'),
        ],
      ),
    );
  }

  Widget _segBtn(String label, String value) {
    final sel = _paymentType == value;
    return Expanded(
      child: Material(
        color: sel ? _blue : Colors.transparent,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          onTap: _canEdit ? () => setState(() => _paymentType = value) : null,
          borderRadius: BorderRadius.circular(10),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Text(
              label,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: 13.5,
                color: sel ? Colors.white : _muted,
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _customerTile() {
    final initial = (_customer?.name ?? '').trim();
    final letter =
        initial.isEmpty ? '' : String.fromCharCodes(initial.runes.take(1));
    return Material(
      color: const Color(0xFFF8FAFC),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: _canEdit ? _pickCustomer : null,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: _border),
          ),
          child: Row(
            children: [
              CircleAvatar(
                radius: 20,
                backgroundColor: _customer == null
                    ? const Color(0xFFE2E8F0)
                    : const Color(0xFFE8F4FC),
                child: _customer == null
                    ? const Icon(Icons.person_outline_rounded,
                        color: _muted, size: 20)
                    : Text(
                        letter,
                        style: const TextStyle(
                          color: _blue,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'اسم العميل',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: _muted,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      _customer?.name ?? 'اضغط لاختيار العميل',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: _customer == null ? _muted : _ink,
                      ),
                    ),
                  ],
                ),
              ),
              if (_canEdit)
                const Icon(Icons.chevron_left_rounded, color: _muted),
            ],
          ),
        ),
      ),
    );
  }

  Widget _scanBar() {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _border),
      ),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _barcodeCtrl,
              focusNode: _barcodeFocus,
              enabled: _canEdit && !_lookupBusy,
              textDirection: TextDirection.ltr,
              textInputAction: TextInputAction.done,
              decoration: InputDecoration(
                hintText: 'باركود / رقم المادة',
                prefixIcon: _lookupBusy
                    ? const Padding(
                        padding: EdgeInsets.all(12),
                        child: SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      )
                    : const Icon(Icons.qr_code_scanner_rounded, size: 20),
                filled: true,
                fillColor: const Color(0xFFF8FAFC),
                isDense: true,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: const BorderSide(color: _border),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: const BorderSide(color: _border),
                ),
              ),
              onSubmitted: (_) => _lookupBarcode(),
            ),
          ),
          const SizedBox(width: 8),
          _iconAction(
            icon: Icons.add_rounded,
            label: 'مادة',
            onTap: _canEdit ? _addItemFromPicker : null,
            filled: true,
          ),
        ],
      ),
    );
  }

  Widget _linesHeader() {
    return Row(
      children: [
        const Text(
          'بنود الفاتورة',
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w900,
            color: _ink,
          ),
        ),
        const Spacer(),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          decoration: BoxDecoration(
            color: const Color(0xFFE8F4FC),
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(
            '${_lines.length} سطر',
            style: const TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w800,
              color: _blue,
            ),
          ),
        ),
      ],
    );
  }

  Widget _emptyLines() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 16),
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFCBD5E1)),
      ),
      child: const Column(
        children: [
          Icon(Icons.inventory_2_outlined, size: 34, color: _muted),
          SizedBox(height: 10),
          Text(
            'لا توجد مواد\nامسح باركوداً أو اضغط «مادة» للإضافة',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: _muted,
              fontWeight: FontWeight.w600,
              height: 1.45,
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _buildLineCards() {
    return [
      for (var i = 0; i < _lines.length; i++) _lineCard(i, _lines[i]),
    ];
  }

  Widget _lineCard(int index, _Line l) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: _border),
      ),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 6, 8),
            child: Row(
              children: [
                Container(
                  width: 28,
                  height: 28,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F4FC),
                    borderRadius: BorderRadius.circular(9),
                  ),
                  child: Text(
                    '${index + 1}',
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      color: _blue,
                      fontSize: 12,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        l.name,
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 13.5,
                          color: _ink,
                        ),
                      ),
                      if (l.barcode.isNotEmpty)
                        Text(
                          l.barcode,
                          textDirection: TextDirection.ltr,
                          style: const TextStyle(
                            fontSize: 11.5,
                            color: _muted,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                    ],
                  ),
                ),
                Text(
                  Fmt.money(l.gross),
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 14.5,
                    color: _blueDeep,
                  ),
                ),
                if (_canEdit)
                  IconButton(
                    tooltip: 'حذف',
                    onPressed: () => setState(() => _lines.removeAt(index)),
                    icon: const Icon(Icons.delete_outline_rounded, size: 19),
                    color: AppTheme.danger,
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
            child: Row(
              children: [
                Expanded(
                  child: _numBox(
                    'الكمية',
                    l.qty,
                    enabled: _canEdit,
                    onChanged: (v) => setState(() => l.qty = v),
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: _numBox(
                    'السعر',
                    l.unitPrice,
                    enabled: _canEdit,
                    onChanged: (v) => setState(() => l.unitPrice = v),
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: _numBox(
                    'إضافية',
                    l.qtyExtra,
                    enabled: _canEdit,
                    onChanged: (v) => setState(() => l.qtyExtra = v),
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: _textBox(
                    'الخصم',
                    l.discountInput,
                    enabled: _canEdit,
                    hint: '5%',
                    onChanged: (v) => setState(() => l.discountInput = v),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _totalsCard() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _border),
      ),
      child: Column(
        children: [
          _totalRow('قبل الضريبة', Fmt.money(_subTotal)),
          _totalRow('الضريبة', Fmt.money(_taxTotal)),
          const SizedBox(height: 8),
          Container(height: 1.5, color: const Color(0xFFE8F4FC)),
          const SizedBox(height: 10),
          Row(
            children: [
              const Text(
                'المجموع',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w900,
                  color: _ink,
                ),
              ),
              const Spacer(),
              Text(
                Fmt.money(_grandTotal),
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w900,
                  color: _blueDeep,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _totalRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Text(label,
              style: const TextStyle(
                  fontSize: 13.5, fontWeight: FontWeight.w600, color: _muted)),
          const Spacer(),
          Text(
            value,
            textDirection: TextDirection.ltr,
            style: const TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color: _ink,
            ),
          ),
        ],
      ),
    );
  }

  Widget _actionDock() {
    return Container(
      decoration: BoxDecoration(
        color: _surface,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0F172A).withValues(alpha: 0.1),
            blurRadius: 24,
            offset: const Offset(0, -6),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 10, 8, 8),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _dockBtn(
                  Icons.save_rounded,
                  'حفظ',
                  onTap: _busy || !_canEdit ? null : () => _save(),
                  primary: true,
                ),
                _dockBtn(
                  Icons.check_circle_outline_rounded,
                  'ترحيل',
                  onTap: _busy || _isPosted ? null : _post,
                ),
                _dockBtn(
                  Icons.visibility_outlined,
                  'عرض',
                  onTap: _busy ? null : _view,
                ),
                _dockBtn(
                  Icons.print_outlined,
                  'طباعة',
                  onTap: _busy ? null : _print,
                ),
                _dockBtn(
                  Icons.send_outlined,
                  'فوترة',
                  onTap: _busy || !_canSendEinvoice ? null : _sendEinvoice,
                ),
                _dockBtn(
                  Icons.edit_outlined,
                  'تعديل',
                  onTap:
                      _busy || !_canEdit || _invoiceId < 1 ? null : _openEdit,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _dockBtn(
    IconData icon,
    String label, {
    VoidCallback? onTap,
    bool primary = false,
  }) {
    final enabled = onTap != null;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Material(
        color: primary
            ? (enabled ? _blue : _blue.withValues(alpha: 0.35))
            : (enabled ? const Color(0xFFF8FAFC) : const Color(0xFFF1F5F9)),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            width: 72,
            padding: const EdgeInsets.symmetric(vertical: 10),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: primary
                  ? null
                  : Border.all(
                      color: enabled ? _border : const Color(0xFFE2E8F0)),
            ),
            child: Column(
              children: [
                if (_busy && primary)
                  const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                else
                  Icon(
                    icon,
                    size: 20,
                    color: primary
                        ? Colors.white
                        : (enabled ? _ink : const Color(0xFF94A3B8)),
                  ),
                const SizedBox(height: 4),
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    color: primary
                        ? Colors.white
                        : (enabled ? _ink : const Color(0xFF94A3B8)),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ── عناصر مساعدة للواجهة ────────────────────────────────────────────────

  Widget _fieldShell({required String label, required Widget child}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w700,
            color: _muted,
          ),
        ),
        const SizedBox(height: 5),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
          constraints: const BoxConstraints(minHeight: 44),
          alignment: Alignment.centerRight,
          decoration: BoxDecoration(
            color: const Color(0xFFF8FAFC),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: _border),
          ),
          child: child,
        ),
      ],
    );
  }

  Widget _readonlyValue(String text, {bool ltr = false}) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Text(
        text,
        textDirection: ltr ? TextDirection.ltr : null,
        style: const TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w700,
          color: _ink,
        ),
      ),
    );
  }

  Widget _iconAction({
    required IconData icon,
    required String label,
    VoidCallback? onTap,
    bool filled = false,
  }) {
    return Material(
      color: filled ? _blue : const Color(0xFFF8FAFC),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          height: 48,
          padding: const EdgeInsets.symmetric(horizontal: 12),
          alignment: Alignment.center,
          child: Row(
            children: [
              Icon(icon, size: 20, color: filled ? Colors.white : _blue),
              const SizedBox(width: 4),
              Text(
                label,
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  color: filled ? Colors.white : _blue,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _numBox(
    String label,
    double value, {
    required bool enabled,
    required ValueChanged<double> onChanged,
  }) {
    return TextFormField(
      key: ValueKey('$label-$value-${identityHashCode(onChanged)}'),
      initialValue: value == 0 ? '' : Fmt.trimNum(value),
      enabled: enabled,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: [
        FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
      ],
      textDirection: TextDirection.ltr,
      textAlign: TextAlign.center,
      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
      decoration: InputDecoration(
        labelText: label,
        isDense: true,
        filled: true,
        fillColor: const Color(0xFFF8FAFC),
        contentPadding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
      ),
      onChanged: (v) => onChanged(double.tryParse(v.replaceAll(',', '')) ?? 0),
    );
  }

  Widget _textBox(
    String label,
    String value, {
    required bool enabled,
    required ValueChanged<String> onChanged,
    String? hint,
  }) {
    return TextFormField(
      key: ValueKey('$label-$value'),
      initialValue: value,
      enabled: enabled,
      textDirection: TextDirection.ltr,
      textAlign: TextAlign.center,
      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        isDense: true,
        filled: true,
        fillColor: const Color(0xFFF8FAFC),
        contentPadding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
      ),
      onChanged: onChanged,
    );
  }
}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull {
    final it = iterator;
    return it.moveNext() ? it.current : null;
  }
}
