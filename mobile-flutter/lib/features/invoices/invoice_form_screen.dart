import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_service.dart';
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
  });

  final int itemId;
  final String name;
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
  const InvoiceFormScreen({super.key, this.invoiceId});

  /// عند التمرير: تعديل فاتورة موجودة غير مرحّلة/غير مُرسلة للفوترة.
  final int? invoiceId;

  @override
  State<InvoiceFormScreen> createState() => _InvoiceFormScreenState();
}

class _InvoiceFormScreenState extends State<InvoiceFormScreen> {
  bool _loadingMeta = true;
  bool _saving = false;
  String? _metaError;
  int _editInvoiceId = 0;
  String _invoiceDate = '';

  List<Map<String, dynamic>> _warehouses = [];
  List<_TaxRate> _taxRates = [];
  int _warehouseId = 0;
  String _paymentType = 'credit';
  double _defaultTaxPercent = 5;
  int _defaultTaxRateId = 0;
  Party? _customer;
  final List<_Line> _lines = [];
  final _headerDiscount = TextEditingController();
  final _notes = TextEditingController();

  /// شريط إدخال PDA: باركود → كمية → إضافية → سعر → مادة تالية.
  final _barcodeCtrl = TextEditingController();
  final _entryQtyCtrl = TextEditingController(text: '1');
  final _entryExtraCtrl = TextEditingController(text: '0');
  final _entryPriceCtrl = TextEditingController();
  final _barcodeFocus = FocusNode();
  final _entryQtyFocus = FocusNode();
  final _entryExtraFocus = FocusNode();
  final _entryPriceFocus = FocusNode();
  PickedItem? _pendingItem;
  bool _lookupBusy = false;

  bool get _isEdit => _editInvoiceId > 0;

  @override
  void initState() {
    super.initState();
    _editInvoiceId = widget.invoiceId ?? 0;
    _invoiceDate = Fmt.todayIso();
    _loadMeta();
  }

  @override
  void dispose() {
    _headerDiscount.dispose();
    _notes.dispose();
    _barcodeCtrl.dispose();
    _entryQtyCtrl.dispose();
    _entryExtraCtrl.dispose();
    _entryPriceCtrl.dispose();
    _barcodeFocus.dispose();
    _entryQtyFocus.dispose();
    _entryExtraFocus.dispose();
    _entryPriceFocus.dispose();
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
      if (!mounted) return;
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
      });
      if (_isEdit) {
        await _loadInvoiceForEdit();
      } else if (mounted) {
        setState(() => _loadingMeta = false);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _metaError = e.message;
        _loadingMeta = false;
      });
    }
  }

  Future<void> _loadInvoiceForEdit() async {
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceViewPath,
        query: {'id': _editInvoiceId},
      );
      final inv = (res['invoice'] as Map?)?.cast<String, dynamic>() ?? {};
      if (inv.isEmpty) {
        throw ApiException('الفاتورة غير موجودة.');
      }
      if (inv['is_posted'] == true || inv['einv_sent'] == true) {
        throw ApiException(
          'لا يمكن تعديل فاتورة مرحّلة أو مُرسلة للفوترة.',
        );
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
            // أقرب نسبة ضريبة متاحة.
            rateId = _taxRates.reduce(
              (a, b) => (a.rate - pct).abs() < (b.rate - pct).abs() ? a : b,
            ).id;
          }
          final ratePct = _taxRates
                  .where((r) => r.id == rateId)
                  .map((r) => r.rate)
                  .firstOrNull ??
              pct;
          loaded.add(
            _Line(
              itemId: Fmt.toInt(m['item_id']),
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
        _customer = cid > 0
            ? Party(cid, cname, Fmt.str(inv['customer_code']))
            : null;
        _warehouseId = Fmt.toInt(inv['warehouse_id']);
        if (_warehouseId == 0 && _warehouses.isNotEmpty) {
          _warehouseId = Fmt.toInt(_warehouses.first['id']);
        }
        _paymentType = Fmt.str(inv['payment_type']).isEmpty
            ? 'credit'
            : Fmt.str(inv['payment_type']);
        final d = Fmt.str(inv['invoice_date']);
        _invoiceDate = d.isEmpty ? Fmt.todayIso() : d;
        _headerDiscount.text = Fmt.str(
          inv['invoice_discount'] ?? inv['discount_input'] ?? '',
        );
        if (_headerDiscount.text.isEmpty) {
          final discAmt = Fmt.toDouble(inv['discount_amount']);
          if (discAmt > 0) _headerDiscount.text = Fmt.trimNum(discAmt);
        }
        _notes.text = Fmt.str(inv['notes']);
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
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _metaError = 'تعذر تحميل الفاتورة للتعديل.';
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

  Future<void> _pickInvoiceDate() async {
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

  String get _customerInitial {
    final n = (_customer?.name ?? '').trim();
    if (n.isEmpty) return '';
    return String.fromCharCodes(n.runes.take(1));
  }

  void _resetEntryStrip({bool keepFocus = true}) {
    _pendingItem = null;
    _barcodeCtrl.clear();
    _entryQtyCtrl.text = '1';
    _entryExtraCtrl.text = '0';
    _entryPriceCtrl.clear();
    if (keepFocus) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _barcodeFocus.requestFocus();
      });
    }
  }

  void _setPendingItem(PickedItem it) {
    setState(() {
      _pendingItem = it;
      _barcodeCtrl.text =
          it.barcode.isNotEmpty ? it.barcode : it.name;
      _entryQtyCtrl.text = '1';
      _entryExtraCtrl.text = '0';
      _entryPriceCtrl.text =
          it.price > 0 ? Fmt.trimNum(it.price) : '';
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _entryQtyFocus.requestFocus();
      _entryQtyCtrl.selection = TextSelection(
        baseOffset: 0,
        extentOffset: _entryQtyCtrl.text.length,
      );
    });
  }

  void _focusSelect(TextEditingController ctrl, FocusNode node) {
    node.requestFocus();
    ctrl.selection = TextSelection(
      baseOffset: 0,
      extentOffset: ctrl.text.length,
    );
  }

  Future<void> _lookupBarcode() async {
    if (_lookupBusy) return;
    final code = _barcodeCtrl.text.trim();
    if (code.isEmpty) return;
    if (_warehouseId == 0) {
      showSnack(context, 'اختر المستودع أولاً', error: true);
      return;
    }
    setState(() => _lookupBusy = true);
    final api = context.read<ApiClient>();
    try {
      final res = await api.getJson(
        AppConfig.itemsSearchPath,
        query: {
          'warehouse_id': _warehouseId,
          'code': code,
        },
      );
      var items = (res['items'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      if (items.isEmpty) {
        final soft = await api.getJson(
          AppConfig.itemsSearchPath,
          query: {
            'warehouse_id': _warehouseId,
            'q': code,
          },
        );
        items = (soft['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
      }
      if (!mounted) return;
      if (items.isEmpty) {
        showSnack(context, 'لم يُعثر على مادة بهذا الباركود.', error: true);
        _barcodeCtrl.selection = TextSelection(
          baseOffset: 0,
          extentOffset: _barcodeCtrl.text.length,
        );
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
      _setPendingItem(
        PickedItem(
          Fmt.toInt(row['id']),
          Fmt.str(row['name_ar'] ?? row['name'] ?? row['sku']),
          Fmt.toDouble(
            row['default_sale'] ?? row['sale_price'] ?? row['unit_price'],
          ),
          Fmt.toDouble(row['stock_qty']),
          barcode: Fmt.str(row['barcode'] ?? row['sku']),
        ),
      );
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _lookupBusy = false);
    }
  }

  void _commitPendingLine() {
    final it = _pendingItem;
    if (it == null) {
      showSnack(context, 'اختر مادة بالباركود أو البحث أولاً.', error: true);
      _barcodeFocus.requestFocus();
      return;
    }
    final qty =
        double.tryParse(_entryQtyCtrl.text.replaceAll(',', '')) ?? 0;
    final qtyExtra =
        double.tryParse(_entryExtraCtrl.text.replaceAll(',', '')) ?? 0;
    final price =
        double.tryParse(_entryPriceCtrl.text.replaceAll(',', '')) ?? 0;
    if (qty <= 0 && qtyExtra <= 0) {
      showSnack(context, 'أدخل كمية أو كمية إضافية.', error: true);
      _entryQtyFocus.requestFocus();
      return;
    }
    if (price <= 0) {
      showSnack(context, 'أدخل سعر الوحدة.', error: true);
      _entryPriceFocus.requestFocus();
      return;
    }
    final existing = _lines.where((l) => l.itemId == it.id).toList();
    setState(() {
      if (existing.isNotEmpty) {
        existing.first.qty += qty;
        existing.first.qtyExtra += qtyExtra;
        existing.first.unitPrice = price;
      } else {
        _lines.add(
          _Line(
            itemId: it.id,
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
    _resetEntryStrip();
  }

  Future<void> _addItem() async {
    if (_warehouseId == 0) {
      showSnack(context, 'اختر المستودع أولاً', error: true);
      return;
    }
    final it = await pickItem(context, warehouseId: _warehouseId);
    if (it == null) return;
    _setPendingItem(it);
  }

  Future<void> _clearLines() async {
    if (_lines.isEmpty) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('مسح البنود'),
        content: const Text('سيتم حذف جميع البنود من الفاتورة الحالية.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppTheme.danger,
              minimumSize: const Size(100, 42),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('مسح'),
          ),
        ],
      ),
    );
    if (ok == true) setState(_lines.clear);
  }

  /// يحفظ الفاتورة ويُرجع رقمها، أو 0 عند الفشل.
  Future<int> _save({bool thenPost = false}) async {
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

    final s = context.read<SessionController>();
    final api = context.read<ApiClient>();
    setState(() => _saving = true);
    try {
      final fields = <String, dynamic>{
        '_action': 'save_invoice',
        'invoice_id': _editInvoiceId,
        'invoice_date': _invoiceDate.isEmpty ? Fmt.todayIso() : _invoiceDate,
        'customer_id': _customer!.id,
        'warehouse_id': _warehouseId,
        'payment_type': _paymentType,
        'lines_json': jsonEncode(_lines.map((l) => l.toJson()).toList()),
      };
      final headerDisc = _headerDiscount.text.trim();
      if (headerDisc.isNotEmpty) fields['invoice_discount'] = headerDisc;
      final notes = _notes.text.trim();
      if (notes.isNotEmpty) fields['notes'] = notes;

      final res = await api.postForm(
        AppConfig.salesInvoiceSaveRoute,
        csrf: s.csrf,
        fields: fields,
      );
      final invId = Fmt.toInt(res['invoice_id']);
      if (!mounted) return invId;
      showSnack(context, (res['message'] ?? 'تم حفظ الفاتورة').toString());

      if (thenPost && invId > 0) {
        final gps = await LocationService.tryGetPosition();
        final postFields = <String, dynamic>{'invoice_id': invId};
        if (gps != null) {
          postFields['latitude'] = gps.latitude;
          postFields['longitude'] = gps.longitude;
          postFields['gps_accuracy'] = gps.accuracy;
          postFields['gps_source'] = 'mobile';
        }
        try {
          final p = await api.postForm(
            AppConfig.salesInvoicePostPath,
            fields: postFields,
            csrf: s.csrf,
          );
          if (mounted) {
            showSnack(context, (p['message'] ?? 'تم الترحيل').toString());
          }
        } on ApiException catch (e) {
          if (mounted) showSnack(context, e.message, error: true);
        }
      }

      if (!mounted) return invId;
      if (invId > 0) {
        if (_isEdit) {
          context.pop(true);
        } else {
          context.pushReplacement('/invoices/$invId');
        }
      } else {
        context.pop();
      }
      return invId;
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
      return 0;
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF3F5F9),
      appBar: AppBar(
        title: Text(_isEdit ? 'تعديل الفاتورة' : 'فاتورة جديدة'),
        actions: [
          if (_lines.isNotEmpty)
            IconButton(
              tooltip: 'مسح البنود',
              onPressed: _clearLines,
              icon: const Icon(Icons.delete_sweep_outlined),
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
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 16),
                children: [
                  _sheetCard(),
                  const SizedBox(height: 14),
                  _linesSectionHead(),
                  const SizedBox(height: 8),
                  if (_lines.isEmpty)
                    _emptyLines()
                  else
                    ..._lines.asMap().entries.map((e) => _lineCard(e.key, e.value)),
                  const SizedBox(height: 12),
                  _totalsCard(),
                  const SizedBox(height: 8),
                ],
              ),
            ),
            _actionDock(),
          ],
        ),
      ),
    );
  }

  Widget _sheetCard() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0F172A).withValues(alpha: 0.06),
            blurRadius: 12,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: _pickCustomer,
              borderRadius: BorderRadius.circular(14),
              child: Ink(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: const Color(0xFFE8EDF3)),
                  gradient: const LinearGradient(
                    begin: Alignment.topRight,
                    end: Alignment.bottomLeft,
                    colors: [Color(0xFFF8FAFC), Color(0xFFF1F5F9)],
                  ),
                ),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                  child: Row(
                    children: [
                      CircleAvatar(
                        radius: 22,
                        backgroundColor: _customer == null
                            ? const Color(0xFFE2E8F0)
                            : const Color(0xFFE8F4FC),
                        child: _customer == null
                            ? const Icon(Icons.person_outline_rounded,
                                color: Color(0xFF94A3B8), size: 22)
                            : Text(
                                _customerInitial,
                                style: const TextStyle(
                                  color: Color(0xFF0572CE),
                                  fontWeight: FontWeight.w800,
                                  fontSize: 16,
                                ),
                              ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'العميل',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                color: Color(0xFF64748B),
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              _customer?.name ?? 'اضغط لاختيار العميل',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w700,
                                color: _customer == null
                                    ? const Color(0xFF94A3B8)
                                    : const Color(0xFF0F172A),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.chevron_left_rounded,
                          color: Color(0xFF94A3B8), size: 28),
                    ],
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(height: 10),
          SizedBox(
            height: 46,
            child: DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                gradient: const LinearGradient(
                  begin: Alignment.centerRight,
                  end: Alignment.centerLeft,
                  colors: [Color(0xFF1A8FE8), Color(0xFF0572CE), Color(0xFF024D8F)],
                ),
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFF0572CE).withValues(alpha: 0.28),
                    blurRadius: 14,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: _addItem,
                  borderRadius: BorderRadius.circular(12),
                  child: const Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.add_rounded, color: Colors.white, size: 22),
                      SizedBox(width: 8),
                      Text(
                        'إضافة مواد',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(height: 10),
          _pdaStrip(),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(child: _metaField(
                label: 'التاريخ',
                child: InkWell(
                  onTap: _pickInvoiceDate,
                  borderRadius: BorderRadius.circular(10),
                  child: Container(
                    height: 42,
                    alignment: Alignment.center,
                    decoration: _metaBox(),
                    child: Text(
                      Fmt.dmy(_invoiceDate),
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF0F172A),
                      ),
                    ),
                  ),
                ),
              )),
              const SizedBox(width: 8),
              Expanded(child: _metaField(
                label: 'النوع',
                child: Container(
                  height: 42,
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  decoration: _metaBox(),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<String>(
                      value: _paymentType,
                      isExpanded: true,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF0F172A),
                      ),
                      items: const [
                        DropdownMenuItem(value: 'credit', child: Text('ذمة')),
                        DropdownMenuItem(value: 'cash', child: Text('نقدي')),
                      ],
                      onChanged: (v) {
                        if (v != null) setState(() => _paymentType = v);
                      },
                    ),
                  ),
                ),
              )),
              const SizedBox(width: 8),
              Expanded(child: _metaField(
                label: 'المستودع',
                child: Container(
                  height: 42,
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  decoration: _metaBox(),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int>(
                      value: _warehouseId == 0 ? null : _warehouseId,
                      hint: const Text('—', style: TextStyle(fontSize: 13)),
                      isExpanded: true,
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF0F172A),
                      ),
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
                  ),
                ),
              )),
            ],
          ),
          const SizedBox(height: 10),
          const Text(
            'ملاحظات',
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: Color(0xFF64748B),
            ),
          ),
          const SizedBox(height: 4),
          TextField(
            controller: _notes,
            decoration: InputDecoration(
              hintText: 'اختياري',
              filled: true,
              fillColor: const Color(0xFFF8FAFC),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
              isDense: true,
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _headerDiscount,
            textDirection: TextDirection.ltr,
            decoration: InputDecoration(
              labelText: 'خصم الفاتورة',
              hintText: '10 أو 10%',
              filled: true,
              fillColor: const Color(0xFFF8FAFC),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
              ),
              contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
              isDense: true,
            ),
            onChanged: (_) => setState(() {}),
          ),
        ],
      ),
    );
  }

  BoxDecoration _metaBox() {
    return BoxDecoration(
      color: const Color(0xFFF8FAFC),
      borderRadius: BorderRadius.circular(10),
      border: Border.all(color: const Color(0xFFE2E8F0)),
    );
  }

  Widget _metaField({required String label, required Widget child}) {
    return Column(
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 10,
            fontWeight: FontWeight.w700,
            color: Color(0xFF64748B),
          ),
        ),
        const SizedBox(height: 4),
        child,
      ],
    );
  }

  Widget _pdaStrip() {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_pendingItem != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                'المادة: ',
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
              ),
            ),
          TextField(
            controller: _barcodeCtrl,
            focusNode: _barcodeFocus,
            textDirection: TextDirection.ltr,
            textInputAction: TextInputAction.done,
            enabled: !_lookupBusy,
            decoration: InputDecoration(
              labelText: 'باركود / رمز',
              hintText: 'امسح ثم Enter',
              prefixIcon: _lookupBusy
                  ? const Padding(
                      padding: EdgeInsets.all(12),
                      child: SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  : const Icon(Icons.qr_code_scanner, size: 20),
              isDense: true,
              filled: true,
              fillColor: Colors.white,
            ),
            onSubmitted: (_) => _lookupBarcode(),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _entryQtyCtrl,
                  focusNode: _entryQtyFocus,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.center,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'كمية',
                    isDense: true,
                    filled: true,
                    fillColor: Colors.white,
                  ),
                  onSubmitted: (_) =>
                      _focusSelect(_entryExtraCtrl, _entryExtraFocus),
                ),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: TextField(
                  controller: _entryExtraCtrl,
                  focusNode: _entryExtraFocus,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.center,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'إض.',
                    isDense: true,
                    filled: true,
                    fillColor: Colors.white,
                  ),
                  onSubmitted: (_) =>
                      _focusSelect(_entryPriceCtrl, _entryPriceFocus),
                ),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: TextField(
                  controller: _entryPriceCtrl,
                  focusNode: _entryPriceFocus,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.center,
                  textInputAction: TextInputAction.done,
                  decoration: const InputDecoration(
                    labelText: 'سعر',
                    isDense: true,
                    filled: true,
                    fillColor: Colors.white,
                  ),
                  onSubmitted: (_) => _commitPendingLine(),
                ),
              ),
              const SizedBox(width: 6),
              FilledButton(
                onPressed: _commitPendingLine,
                style: FilledButton.styleFrom(
                  minimumSize: const Size(44, 44),
                  padding: EdgeInsets.zero,
                  backgroundColor: const Color(0xFF0572CE),
                ),
                child: const Icon(Icons.add_rounded),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _linesSectionHead() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: Row(
        children: [
          const Text(
            'بنود الفاتورة',
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
              color: Color(0xFF0F172A),
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
              ' سطر',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: Color(0xFF0572CE),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _emptyLines() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 22),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFCBD5E1), style: BorderStyle.solid),
      ),
      child: const Text(
        'لا توجد بنود — اضغط «إضافة مواد»، اختر المادة، أدخل الكمية والسعر.',
        textAlign: TextAlign.center,
        style: TextStyle(
          fontSize: 13,
          height: 1.45,
          color: Color(0xFF64748B),
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }

  Widget _totalsCard() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0F172A).withValues(alpha: 0.06),
            blurRadius: 12,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        children: [
          _totalRow('قبل الضريبة', Fmt.money(_subTotal)),
          _totalRow('الضريبة', Fmt.money(_taxTotal)),
          const SizedBox(height: 6),
          Container(height: 2, color: const Color(0xFFE8F4FC)),
          const SizedBox(height: 8),
          Row(
            children: [
              const Text(
                'الإجمالي',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  color: Color(0xFF0F172A),
                ),
              ),
              const Spacer(),
              Text(
                Fmt.money(_grandTotal),
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFF024D8F),
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
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Text(
            label,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: Color(0xFF475569),
            ),
          ),
          const Spacer(),
          Text(
            value,
            textDirection: TextDirection.ltr,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: Color(0xFF475569),
            ),
          ),
        ],
      ),
    );
  }

  Widget _actionDock() {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(18)),
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
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: _saving ? null : () => _save(),
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size.fromHeight(48),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  child: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('حفظ', style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                flex: 2,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(12),
                    gradient: const LinearGradient(
                      begin: Alignment.centerRight,
                      end: Alignment.centerLeft,
                      colors: [Color(0xFF1A8FE8), Color(0xFF0572CE), Color(0xFF024D8F)],
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFF0572CE).withValues(alpha: 0.25),
                        blurRadius: 8,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Material(
                    color: Colors.transparent,
                    child: InkWell(
                      onTap: _saving ? null : () => _save(thenPost: true),
                      borderRadius: BorderRadius.circular(12),
                      child: const SizedBox(
                        height: 48,
                        child: Center(
                          child: Text(
                            'حفظ وترحيل',
                            style: TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w800,
                              fontSize: 15,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }



  Widget _lineCard(int index, _Line l) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0F172A).withValues(alpha: 0.06),
            blurRadius: 10,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [Color(0xFFF8FAFC), Colors.white],
              ),
              border: Border(bottom: BorderSide(color: Color(0xFFF1F5F9))),
            ),
            child: Row(
              children: [
                Container(
                  width: 26,
                  height: 26,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F4FC),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    '${index + 1}',
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                      color: Color(0xFF0572CE),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    l.name,
                    style: const TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 14,
                      color: Color(0xFF0F172A),
                      height: 1.35,
                    ),
                  ),
                ),
                Text(
                  Fmt.money(l.gross),
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: Color(0xFF024D8F),
                  ),
                ),
                IconButton(
                  tooltip: 'حذف البند',
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(Icons.delete_outline_rounded, size: 19),
                  color: AppTheme.danger,
                  onPressed: () => setState(() => _lines.removeAt(index)),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 12),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: _numField(
                        label: 'كمية',
                        value: l.qty,
                        onChanged: (v) => setState(() => l.qty = v),
                      ),
                    ),
                    const SizedBox(width: 5),
                    Expanded(
                      child: _numField(
                        label: 'إض.',
                        value: l.qtyExtra,
                        onChanged: (v) => setState(() => l.qtyExtra = v),
                      ),
                    ),
                    const SizedBox(width: 5),
                    Expanded(
                      child: _numField(
                        label: 'سعر',
                        value: l.unitPrice,
                        onChanged: (v) => setState(() => l.unitPrice = v),
                      ),
                    ),
                    const SizedBox(width: 5),
                    Expanded(
                      child: TextFormField(
                        initialValue: l.discountInput,
                        textDirection: TextDirection.ltr,
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 12.5),
                        decoration: const InputDecoration(
                          labelText: 'خصم',
                          hintText: '5%',
                          isDense: true,
                          filled: true,
                          fillColor: Color(0xFFF8FAFC),
                          contentPadding: EdgeInsets.symmetric(horizontal: 4, vertical: 8),
                        ),
                        onChanged: (v) => setState(() => l.discountInput = v),
                      ),
                    ),
                    const SizedBox(width: 5),
                    Expanded(
                      child: DropdownButtonFormField<int>(
                        initialValue: _taxRates.any((r) => r.id == l.taxRateId)
                            ? l.taxRateId
                            : (_taxRates.isNotEmpty ? _taxRates.first.id : null),
                        isExpanded: true,
                        style: const TextStyle(fontSize: 12, color: AppTheme.textMain),
                        decoration: const InputDecoration(
                          labelText: 'ضريبة',
                          isDense: true,
                          filled: true,
                          fillColor: Color(0xFFF8FAFC),
                          contentPadding: EdgeInsets.symmetric(horizontal: 4, vertical: 8),
                        ),
                        items: (_taxRates.isEmpty
                                ? [_TaxRate(id: 0, name: 'افتراضي', rate: _defaultTaxPercent)]
                                : _taxRates)
                            .map(
                              (r) => DropdownMenuItem<int>(
                                value: r.id,
                                child: Text('${Fmt.money(r.rate)}%', overflow: TextOverflow.ellipsis),
                              ),
                            )
                            .toList(),
                        onChanged: (v) {
                          if (v == null) return;
                          final rate = _taxRates.where((r) => r.id == v).map((r) => r.rate).firstOrNull;
                          setState(() {
                            l.taxRateId = v;
                            l.taxRatePercent = rate ?? _defaultTaxPercent;
                          });
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    _tiny('صافي', Fmt.money(l.subtotal)),
                    _tiny('ضريبة', Fmt.money(l.taxAmount)),
                    _tiny('الإجمالي', Fmt.money(l.gross), strong: true),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _tiny(String label, String value, {bool strong = false}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Text(label, style: const TextStyle(fontSize: 10.5, color: AppTheme.textSoft)),
        const SizedBox(height: 2),
        Text(
          value,
          textDirection: TextDirection.ltr,
          style: TextStyle(
            fontSize: strong ? 13.5 : 12.5,
            fontWeight: strong ? FontWeight.w900 : FontWeight.w700,
            color: strong ? const Color(0xFF024D8F) : AppTheme.textMain,
          ),
        ),
      ],
    );
  }

  Widget _numField({
    required String label,
    required double value,
    required ValueChanged<double> onChanged,
  }) {
    return TextFormField(
      initialValue: value == 0 ? '' : Fmt.trimNum(value),
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      textDirection: TextDirection.ltr,
      textAlign: TextAlign.center,
      style: const TextStyle(fontSize: 12.5),
      decoration: InputDecoration(
        labelText: label,
        isDense: true,
        filled: true,
        fillColor: const Color(0xFFF8FAFC),
        contentPadding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
      ),
      onChanged: (v) => onChanged(double.tryParse(v.replaceAll(',', '')) ?? 0),
    );
  }

}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull {
    final it = iterator;
    return it.moveNext() ? it.current : null;
  }
}
