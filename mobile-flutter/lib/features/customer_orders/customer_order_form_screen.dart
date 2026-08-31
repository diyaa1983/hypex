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
import '../../widgets/app_confirm_dialog.dart';
import '../../services/customer_order_bluetooth_receipt.dart';
import '../../services/document_print_helper.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/item_picker.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/numeric_keypad_dialog.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/cheques_under_collection.dart';
import '../../widgets/order_statement_workflow_note.dart';
import '../../widgets/thermal_preview_screen.dart';
import '../../widgets/ui_kit.dart';

class CustomerOrderFormScreen extends StatefulWidget {
  const CustomerOrderFormScreen({
    super.key,
    this.orderId,
    this.initialCustomerId,
    this.initialCustomerName,
    this.initialCustomerCode,
    this.visitRouteLineId,
    this.embedded = false,
    this.hideCustomerPicker = false,
    this.onSaved,
    this.onDeleted,
  });
  final int? orderId;
  final int? initialCustomerId;
  final String? initialCustomerName;
  final String? initialCustomerCode;
  final int? visitRouteLineId;

  /// داخل تبويب — بدون MobileScaffold.
  final bool embedded;

  /// إخفاء اختيار العميل (مُثبَّت من السياق).
  final bool hideCustomerPicker;

  final void Function(int orderId)? onSaved;
  final VoidCallback? onDeleted;

  @override
  CustomerOrderFormScreenState createState() =>
      CustomerOrderFormScreenState();
}

class _TaxRate {
  const _TaxRate({required this.id, required this.name, required this.rate});
  final int id;
  final String name;
  final double rate;
}

class _OrderLine {
  _OrderLine(
    this.item, {
    this.taxRateId = 0,
    this.taxRatePercent = 0,
  }) {
    final d = item.defaultUnit;
    unitId = d?.unitId ?? 0;
    unitName = d?.name ?? '';
    unitFactor = d?.factor ?? 1;
    if (unitFactor <= 0) unitFactor = 1;
    basePrice = item.price;
    unitPrice = basePrice * unitFactor;
  }

  final PickedItem item;
  int qty = 1;
  int qtyExtra = 0;
  int unitId = 0;
  String unitName = '';
  double unitFactor = 1;
  double basePrice = 0;
  double unitPrice = 0;
  double discountPct = 0;
  int taxRateId;
  double taxRatePercent;

  String get barcode => item.barcode;

  double get lineBase => qty * unitPrice;
  double get discountAmount =>
      discountPct <= 0 ? 0 : lineBase * (discountPct.clamp(0, 100) / 100);
  double get lineTotal => (lineBase - discountAmount).clamp(0, double.infinity);
  double get taxAmount => lineTotal * taxRatePercent / 100;
  double get lineGross => lineTotal + taxAmount;
  double get unitPriceInclusive => unitPrice * (1 + taxRatePercent / 100);

  Map<String, dynamic> toJson() => {
        'item_id': item.id,
        'item_name': item.name,
        'unit_id': unitId,
        'unit_name': unitName,
        'unit_factor': unitFactor <= 0 ? 1 : unitFactor,
        'qty': qty,
        'qty_extra': qtyExtra,
        'qty_base': (qty + qtyExtra) * (unitFactor <= 0 ? 1 : unitFactor),
        'unit_price': unitPrice,
        'tax_rate_id': taxRateId,
        'tax_rate_percent': taxRatePercent,
        'discount_pct': discountPct,
        'line_discount_input':
            discountPct > 0 ? '${Fmt.trimNum(discountPct)}%' : '',
      };

  void applyUnit(ItemUnitOpt? u,
      {int? unitIdOverride, String? unitNameOverride}) {
    if (u != null) {
      unitId = u.unitId;
      unitName = u.name;
      unitFactor = u.factor <= 0 ? 1 : u.factor;
    } else {
      if (unitIdOverride != null) unitId = unitIdOverride;
      if (unitNameOverride != null) unitName = unitNameOverride;
    }
    if (basePrice > 0) {
      unitPrice = basePrice * unitFactor;
    }
  }
}

class _LineTableMetrics {
  const _LineTableMetrics({
    required this.item,
    required this.unit,
    required this.qty,
    required this.extra,
    required this.price,
    required this.priceInc,
    required this.tax,
    required this.disc,
    required this.total,
    required this.del,
    required this.font,
    required this.heading,
    required this.colSpacing,
    required this.hMargin,
    required this.compactTax,
  });

  final double item;
  final double unit;
  final double qty;
  final double extra;
  final double price;
  final double priceInc;
  final double tax;
  final double disc;
  final double total;
  final double del;
  final double font;
  final double heading;
  final double colSpacing;
  final double hMargin;
  final bool compactTax;

  double get minWidth =>
      item +
      unit +
      qty +
      extra +
      price +
      priceInc +
      tax +
      disc +
      total +
      del +
      hMargin * 2 +
      colSpacing * 9;

  factory _LineTableMetrics.fit(double avail, {required bool compact}) {
    const weights = [
      2.10,
      1.05,
      0.72,
      0.72,
      0.98,
      0.98,
      0.80,
      0.70,
      0.95,
      0.42,
    ];
    final sum = weights.fold<double>(0, (s, w) => s + w);
    final tight = avail < 780;
    final spacing = tight ? 3.0 : (avail < 1000 ? 5.0 : 10.0);
    final margin = tight ? 3.0 : 6.0;
    final inner = (avail - margin * 2 - spacing * 9).clamp(360.0, 4000.0);
    final w = weights.map((x) => inner * x / sum).toList();
    return _LineTableMetrics(
      item: w[0],
      unit: w[1],
      qty: w[2],
      extra: w[3],
      price: w[4],
      priceInc: w[5],
      tax: w[6],
      disc: w[7],
      total: w[8],
      del: w[9],
      font: avail < 700 ? 10.5 : (avail < 900 ? 11.5 : 13),
      heading: avail < 700 ? 10 : (avail < 900 ? 11 : 13),
      colSpacing: spacing,
      hMargin: margin,
      compactTax: avail < 980,
    );
  }

  factory _LineTableMetrics.scroll({required bool compact}) {
    return _LineTableMetrics(
      item: 160,
      unit: 92,
      qty: 58,
      extra: 58,
      price: 86,
      priceInc: 86,
      tax: compact ? 90 : 120,
      disc: 56,
      total: 74,
      del: 32,
      font: compact ? 13 : 14,
      heading: 13,
      colSpacing: compact ? 8 : 10,
      hMargin: compact ? 6 : 8,
      compactTax: compact,
    );
  }
}

class CustomerOrderFormScreenState extends State<CustomerOrderFormScreen> {
  static CustomerOrderFormScreenState? active;
  bool _loading = true, _busy = false, _approved = false;
  String? _error, _orderNo, _salesRepName;
  String _paymentType = 'credit';
  String _orderDate = '';
  int _id = 0, _warehouseId = 0, _visitRouteLineId = 0;
  List<Map<String, dynamic>> _warehouses = [];
  List<_TaxRate> _taxRates = [];
  int _defaultTaxRateId = 0;
  double _defaultTaxPercent = 0;
  Party? _customer;
  final _lines = <_OrderLine>[];
  Map<String, dynamic>? _arSummary;
  bool _arLoading = false;
  String? _arError;
  bool _autoSendOrders = true;
  bool _isSent = false;
  final _orderNoCtrl = TextEditingController();
  String _cleanFingerprint = '';

  bool get _editable => !_approved;

  String _fingerprint() {
    final lines = _lines
        .map((l) =>
            '${l.item.id}:${l.qty}:${l.qtyExtra}:${l.unitId}:${l.unitPrice}:${l.discountPct}:${l.taxRateId}')
        .join('|');
    return '${_customer?.id}|$_warehouseId|$_paymentType|$lines';
  }

  bool get isDirty => _fingerprint() != _cleanFingerprint;

  void _markClean() {
    _cleanFingerprint = _fingerprint();
  }

  Future<bool> confirmLeave() async {
    if (!isDirty) return true;
    final choice = await showUnsavedChangesDialog(context);
    if (choice == 'discard') return true;
    if (choice == 'save') {
      final id = await _save();
      return id != 0;
    }
    return false;
  }

  @override
  void initState() {
    super.initState();
    active = this;
    _id = widget.orderId ?? 0;
    _load();
  }

  @override
  void dispose() {
    if (identical(active, this)) active = null;
    _orderNoCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final api = context.read<ApiClient>();
      final offline = context.read<OfflineController>();
      Map<String, dynamic> meta;
      try {
        if (!offline.online && offline.catalogReady) {
          meta = await _metaFromLocal();
        } else {
          meta = await api.getJson(AppConfig.customerOrderMetaPath);
        }
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          meta = await _metaFromLocal();
        } else {
          rethrow;
        }
      }
      Map<String, dynamic> order = {};
      if (_id != 0) {
        if (!offline.online) {
          final local = await OfflineStore.instance.getOrderById(_id);
          if (local == null) {
            throw ApiException(
              'الطلب غير متوفر محلياً. حدّث البيانات أو اتصل بالإنترنت.',
            );
          }
          order = local;
        } else {
          try {
            final result = await api.getJson(
              AppConfig.customerOrderViewPath,
              query: {'id': _id},
            );
            order =
                (result['order'] as Map?)?.cast<String, dynamic>() ?? result;
          } on ApiException {
            if (offline.catalogReady) {
              final local = await OfflineStore.instance.getOrderById(_id);
              if (local != null) {
                order = local;
              } else {
                rethrow;
              }
            } else {
              rethrow;
            }
          }
        }
      }
      if (!mounted) return;
      final ws = (meta['warehouses'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      final rates = (meta['tax_rates'] as List? ?? [])
          .whereType<Map>()
          .map(
            (e) => _TaxRate(
              id: Fmt.toInt(e['id']),
              name: Fmt.str(e['name']),
              rate: Fmt.toDouble(e['rate_percent']),
            ),
          )
          .toList();
      final defaultTax = Fmt.toDouble(meta['default_tax_percent']);
      var defaultTaxId = rates.isEmpty ? 0 : rates.first.id;
      for (final rate in rates) {
        if ((rate.rate - defaultTax).abs() < 0.001) {
          defaultTaxId = rate.id;
          break;
        }
      }
      final loaded = <_OrderLine>[];
      for (final raw
          in (order['lines'] as List? ?? order['items'] as List? ?? [])) {
        if (raw is! Map) continue;
        final m = raw.cast<String, dynamic>();
        final unitsRaw = m['units'];
        final units = unitsRaw is List
            ? unitsRaw
                .whereType<Map>()
                .map((e) => ItemUnitOpt.fromJson(e.cast<String, dynamic>()))
                .toList()
            : <ItemUnitOpt>[
                if (Fmt.str(m['unit_name']).isNotEmpty)
                  ItemUnitOpt(
                    unitId: Fmt.toInt(m['unit_id']),
                    name: Fmt.str(m['unit_name']),
                    factor: Fmt.toDouble(m['unit_factor'] ?? 1),
                    isDefault: true,
                  ),
              ];
        final item = PickedItem(
          Fmt.toInt(m['item_id'] ?? m['id']),
          Fmt.str(m['item_name'] ?? m['name']),
          () {
            final up = Fmt.toDouble(m['unit_price']);
            final f = Fmt.toDouble(m['unit_factor'] ?? 1);
            if (up > 0 && f > 0) return up / f;
            return Fmt.toDouble(m['sale_price'] ?? m['price']);
          }(),
          0,
          barcode: Fmt.str(
              m['item_barcode'] ?? m['barcode'] ?? m['sku'] ?? m['item_code']),
          units: units,
        );
        final hasStoredTax =
            m.containsKey('tax_rate_percent') && m['tax_rate_percent'] != null;
        final lineTax =
            hasStoredTax ? Fmt.toDouble(m['tax_rate_percent']) : defaultTax;
        var lineTaxId = defaultTaxId;
        for (final rate in rates) {
          if ((rate.rate - lineTax).abs() < 0.001) {
            lineTaxId = rate.id;
            break;
          }
        }
        final line = _OrderLine(
          item,
          taxRateId: lineTaxId,
          taxRatePercent: lineTax,
        )
          ..qty = Fmt.toDouble(m['qty']).round().clamp(1, 999999999)
          ..qtyExtra = Fmt.toDouble(m['qty_extra']).round().clamp(0, 999999999)
          ..unitId = Fmt.toInt(m['unit_id'])
          ..unitName = Fmt.str(m['unit_name'] ?? m['unit'])
          ..unitFactor = Fmt.toDouble(m['unit_factor'] ?? 1)
          ..unitPrice = Fmt.toDouble(m['unit_price'])
          ..discountPct = Fmt.toDouble(m['discount_pct']);
        if (line.unitFactor <= 0) line.unitFactor = 1;
        if (line.unitPrice <= 0 && item.price > 0) {
          line.basePrice = item.price;
          line.unitPrice = item.price * line.unitFactor;
        } else if (line.unitFactor > 0) {
          line.basePrice = line.unitPrice / line.unitFactor;
        }
        if (line.unitName.isEmpty && item.defaultUnit != null) {
          line.unitId = item.defaultUnit!.unitId;
          line.unitName = item.defaultUnit!.name;
          line.unitFactor = item.defaultUnit!.factor;
          if (line.basePrice > 0) {
            line.unitPrice = line.basePrice * line.unitFactor;
          }
        }
        loaded.add(line);
      }
      setState(() {
        _warehouses = ws;
        _taxRates = rates;
        _autoSendOrders = _flagOn(
          meta.containsKey('auto_send_orders')
              ? meta['auto_send_orders']
              : meta['auto_send'],
          defaultOn: true,
        );
        _defaultTaxPercent = defaultTax > 0
            ? defaultTax
            : (rates.isEmpty ? 0 : rates.first.rate);
        _defaultTaxRateId = defaultTaxId;
        _warehouseId =
            Fmt.toInt(order['warehouse_id'] ?? meta['default_warehouse_id']);
        if (_warehouseId == 0 && ws.isNotEmpty) {
          _warehouseId = Fmt.toInt(ws.first['id']);
        }
        final cid = Fmt.toInt(order['customer_id']);
        _customer = cid == 0
            ? null
            : Party(cid, Fmt.str(order['customer_name']),
                Fmt.str(order['customer_code']));
        if (_customer == null &&
            widget.orderId == null &&
            (widget.initialCustomerId ?? 0) > 0) {
          _customer = Party(
            widget.initialCustomerId!,
            (widget.initialCustomerName ?? '').trim().isEmpty
                ? 'عميل #${widget.initialCustomerId}'
                : widget.initialCustomerName!.trim(),
            widget.initialCustomerCode ?? '',
          );
        }
        _approved = order['approved'] == true ||
            order['is_approved'] == true ||
            Fmt.str(order['status']) == 'approved';
        _orderNo = Fmt.str(order['order_no']);
        _orderDate = Fmt.str(order['order_date']);
        _isSent = order['is_sent'] == true ||
            order['is_sent'] == 1 ||
            '${order['is_sent']}' == '1';
        _visitRouteLineId = Fmt.toInt(order['visit_route_line_id']);
        final pay = Fmt.str(order['payment_type']);
        _paymentType = pay == 'cash' ? 'cash' : 'credit';
        final loadedRep = Fmt.str(order['sales_rep_name']);
        if (loadedRep.isNotEmpty) _salesRepName = loadedRep;
        _lines
          ..clear()
          ..addAll(loaded);
        _loading = false;
      });
      _orderNoCtrl.text = _orderNo ?? '';
      _markClean();
      if (_customer != null) {
        await _loadArSummary(_customer!.id);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _loading = false;
        });
      }
    }
  }

  Future<void> _loadArSummary(int customerId) async {
    if (customerId < 1) {
      setState(() {
        _arSummary = null;
        _arError = null;
        _arLoading = false;
      });
      return;
    }
    setState(() {
      _arLoading = true;
      _arError = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.oracleCustomerArSummaryPath,
        query: {'customer_id': customerId},
      );
      if (!mounted) return;
      setState(() {
        _arSummary = res;
        _arLoading = false;
        _arError = res['ok'] == true
            ? null
            : (Fmt.str(res['message']).isEmpty
                ? 'تعذر جلب ملخص الحساب'
                : Fmt.str(res['message']));
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _arSummary = null;
        _arLoading = false;
        _arError = e.message;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _arSummary = null;
        _arLoading = false;
        _arError = e.toString();
      });
    }
  }

  Future<void> _pickCustomer() async {
    final party = await pickParty(context, type: 'customer');
    if (party != null && mounted) {
      setState(() => _customer = party);
      await _loadArSummary(party.id);
    }
  }

  Future<void> _addLine() async {
    if (_warehouseId == 0) {
      showSnack(context, 'اختر المستودع أولاً.', error: true);
      return;
    }
    final item = await pickItem(context, warehouseId: _warehouseId);
    if (item == null || !mounted) return;
    setState(() => _lines.add(
          _OrderLine(
            item,
            taxRateId: _defaultTaxRateId,
            taxRatePercent: _defaultTaxPercent,
          ),
        ));
  }

  Future<Map<String, dynamic>> _metaFromLocal() async {
    final store = OfflineStore.instance;
    final ws = await store.warehouses();
    final rates = await store.taxRates();
    final defWh = int.tryParse(await store.getMeta('default_warehouse_id') ?? '') ?? 0;
    final defTax =
        double.tryParse(await store.getMeta('default_tax_percent') ?? '') ?? 0;
    return {
      'warehouses': ws
          .map((w) => {'id': w['id'], 'name': w['name']})
          .toList(),
      'tax_rates': rates
          .map((r) => {
                'id': r['id'],
                'name': r['name'],
                'rate_percent': r['rate_percent'],
              })
          .toList(),
      'default_warehouse_id': defWh,
      'default_tax_percent': defTax,
      'auto_send_orders':
          (await store.getMeta('auto_send_orders')) == '1' ? 1 : 0,
    };
  }

  Future<int> _save() async {
    if (!_editable) {
      showSnack(context, 'لا يمكن تعديل طلب معتمد.', error: true);
      return 0;
    }
    if (_customer == null || _warehouseId == 0 || _lines.isEmpty) {
      showSnack(context, 'اختر العميل والمستودع وأضف بنداً واحداً على الأقل.',
          error: true);
      return 0;
    }
    setState(() => _busy = true);
    try {
      final session = context.read<SessionController>();
      final body = <String, dynamic>{
        'id': _id,
        'customer_id': _customer!.id,
        'warehouse_id': _warehouseId,
        'payment_type': _paymentType,
        'lines': _lines.map((l) => l.toJson()).toList(),
      };
      final visitLine = widget.visitRouteLineId ?? 0;
      if (visitLine > 0) {
        body['visit_route_line_id'] = visitLine;
      } else if (visitLine < 0) {
        body['offline_visit'] = true;
        // يُستبدل بـ route_line_id الحقيقي عند ترحيل check-in
      }
      if (session.gpsConfig.repVisitGeofence) {
        final offline = context.read<OfflineController>();
        if (offline.online) {
          final gps = await LocationService.requirePosition();
          body['latitude'] = gps.latitude;
          body['longitude'] = gps.longitude;
          body['gps_accuracy'] = gps.accuracy;
          body['gps_source'] = 'mobile';
        } else {
          final g = await LocationService.tryGetPosition();
          if (g != null) {
            body['latitude'] = g.latitude;
            body['longitude'] = g.longitude;
            body['gps_accuracy'] = g.accuracy;
            body['gps_source'] = 'mobile_offline';
          }
        }
      }
      if (!mounted) return 0;
      final offline = context.read<OfflineController>();
      if (!offline.online) {
        if (!offline.catalogReady) {
          showSnack(
            context,
            'لا اتصال ولا بيانات محلية. حدّث البيانات وأنت متصل أولاً.',
            error: true,
          );
          return 0;
        }
        var localId = _id;
        if (localId <= 0) {
          localId = await OfflineStore.instance.nextLocalOrderId();
        }
        final orderNo = (_orderNo != null && _orderNo!.isNotEmpty)
            ? _orderNo!
            : 'OFF-${DateTime.now().millisecondsSinceEpoch % 100000}';
        body['id'] = localId < 0 ? 0 : localId;
        body['local_order_id'] = localId;
        final uuid = await offline.enqueue(
          kind: 'customer_order_save',
          path: AppConfig.customerOrderSavePath,
          body: body,
        );
        final lines = _lines.map((l) => l.toJson()).toList();
        await OfflineStore.instance.upsertLocalOrder(
          {
            'id': localId,
            'order_no': orderNo,
            'order_date': Fmt.todayIso(),
            'customer_id': _customer!.id,
            'customer_name': _customer!.name,
            'warehouse_id': _warehouseId,
            'warehouse_name': '',
            'status': 'draft',
            'is_sent': 0,
            'total': 0,
            'line_count': lines.length,
            'lines': lines,
            'payment_type': _paymentType,
          },
          clientUuid: uuid,
        );
        if (mounted) {
          setState(() {
            _id = localId;
            _orderNo = orderNo;
          });
          _orderNoCtrl.text = orderNo;
          _markClean();
          showSnack(
            context,
            _autoSendOrders
                ? 'حُفظ الطلب محلياً. اضغط «ترحيل» بعد عودة الاتصال أو من هنا.'
                : 'حُفظ الطلب محلياً — سيُرحَّل تلقائياً عند عودة الاتصال.',
          );
          widget.onSaved?.call(localId);
        }
        return localId;
      }
      try {
        final result = await context.read<ApiClient>().postJson(
              AppConfig.customerOrderSavePath,
              csrf: session.csrf,
              body: body,
            );
        final id = Fmt.toInt(result['order_id'] ?? result['id']);
        final sent = _flagOn(result['is_sent']);
        if (mounted) {
          setState(() {
            _id = id == 0 ? _id : id;
            _orderNo = Fmt.str(result['order_no']) == ''
                ? _orderNo
                : Fmt.str(result['order_no']);
            _orderNoCtrl.text = _orderNo ?? '';
            _isSent = sent;
            _markClean();
            if (result.containsKey('auto_send') ||
                result.containsKey('auto_send_orders')) {
              _autoSendOrders = _flagOn(
                result['auto_send'] ?? result['auto_send_orders'],
                defaultOn: true,
              );
            }
          });
          showSnack(
              context,
              Fmt.str(result['message']).isEmpty
                  ? 'تم حفظ الطلب.'
                  : Fmt.str(result['message']));
          widget.onSaved?.call(_id);
        }
        return _id;
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          var localId = _id;
          if (localId <= 0) {
            localId = await OfflineStore.instance.nextLocalOrderId();
          }
          body['id'] = localId < 0 ? 0 : localId;
          body['local_order_id'] = localId;
          final uuid = await offline.enqueue(
            kind: 'customer_order_save',
            path: AppConfig.customerOrderSavePath,
            body: body,
          );
          final orderNo = (_orderNo != null && _orderNo!.isNotEmpty)
              ? _orderNo!
              : 'OFF-${DateTime.now().millisecondsSinceEpoch % 100000}';
          await OfflineStore.instance.upsertLocalOrder(
            {
              'id': localId,
              'order_no': orderNo,
              'order_date': Fmt.todayIso(),
              'customer_id': _customer!.id,
              'customer_name': _customer!.name,
              'warehouse_id': _warehouseId,
              'status': 'draft',
              'is_sent': 0,
              'lines': _lines.map((l) => l.toJson()).toList(),
            },
            clientUuid: uuid,
          );
          if (mounted) {
            setState(() {
              _id = localId;
              _orderNo = orderNo;
            });
            _orderNoCtrl.text = orderNo;
            _markClean();
            showSnack(
              context,
              'انقطع الاتصال — حُفظ الطلب محلياً وسيُرحَّل لاحقاً.',
            );
            widget.onSaved?.call(localId);
          }
          return localId;
        }
        rethrow;
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
      return 0;
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
      return 0;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Map<String, dynamic> _printData() {
    final sub = _lines.fold<double>(0, (s, l) => s + l.lineTotal);
    final disc = _lines.fold<double>(0, (s, l) => s + l.discountAmount);
    final tax = _lines.fold<double>(0, (s, l) => s + l.taxAmount);
    final gross = _lines.fold<double>(0, (s, l) => s + l.lineGross);
    return {
        'order_no': _orderNo,
        'order_date': _orderDate.isEmpty ? Fmt.todayIso() : _orderDate,
        'customer_name': _customer?.name,
        'payment_type': _paymentType,
        'sales_rep_name': (_salesRepName ?? '').trim().isNotEmpty
            ? _salesRepName
            : (context.read<SessionController>().userName ?? ''),
        'subtotal': sub,
        'discount_total': disc,
        'tax_total': tax,
        'grand_total': gross,
        'lines': _lines
            .map((l) => {
                  'item_name': l.item.name,
                  'unit_name': l.unitName,
                  'qty': l.qty,
                  'qty_extra': l.qtyExtra,
                  'unit_price': l.unitPrice,
                  'unit_price_inclusive': l.unitPriceInclusive,
                  'tax_rate_percent': l.taxRatePercent,
                  'tax_amount': l.taxAmount,
                  'discount_pct': l.discountPct,
                  'line_total': l.lineGross,
                  'line_gross': l.lineGross,
                })
            .toList(),
      };
  }

  bool _flagOn(dynamic v, {bool defaultOn = false}) {
    if (v == null) return defaultOn;
    return v == true || v == 1 || '$v' == '1';
  }

  bool get _canPost => _autoSendOrders && !_isSent && !_approved;

  Future<void> _post() async {
    if (!_canPost || _busy) return;
    if (_id == 0) {
      final saved = await _save();
      if (saved == 0 || !mounted) return;
    }
    if (_isSent) return;
    setState(() => _busy = true);
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        await offline.enqueue(
          kind: 'customer_order_send',
          path: AppConfig.customerOrderSendPath,
          body: {
            'ids': [_id],
          },
        );
        if (!mounted) return;
        showSnack(
          context,
          'وُضع الترحيل في الطابور — سيُرسل عند عودة الاتصال.',
        );
        return;
      }
      final res = await context.read<ApiClient>().postJson(
            AppConfig.customerOrderSendPath,
            body: {
              'ids': [_id],
            },
            csrf: context.read<SessionController>().csrf,
          );
      if (_id > 0) {
        await OfflineStore.instance.markOrderSent([_id]);
      }
      if (!mounted) return;
      setState(() => _isSent = true);
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty
            ? 'تم ترحيل الطلب إلى النظام.'
            : Fmt.str(res['message']),
      );
    } on ApiException catch (e) {
      if (offline.catalogReady &&
          (e.message.contains('تعذر الاتصال') ||
              e.message.contains('الإنترنت'))) {
        await offline.enqueue(
          kind: 'customer_order_send',
          path: AppConfig.customerOrderSendPath,
          body: {
            'ids': [_id],
          },
        );
        if (mounted) {
          showSnack(context, 'انقطع الاتصال — وُضع الترحيل في الطابور.');
        }
      } else if (mounted) {
        showSnack(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    if (_id < 1 || _approved) return;
    final ok = await showAppConfirmDialog(
      context,
      title: 'حذف الطلب',
      message: 'هل تريد حذف هذا الطلب نهائياً؟',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final res = await context.read<ApiClient>().postJson(
            AppConfig.customerOrderDeletePath,
            body: {'id': _id},
            csrf: context.read<SessionController>().csrf,
          );
      if (!mounted) return;
      final msg = Fmt.str(res['message']);
      showSnack(context, msg.isEmpty ? 'تم حذف الطلب.' : msg);
      widget.onDeleted?.call();
      setState(() {
        _id = 0;
        _orderNo = '';
        _orderNoCtrl.clear();
        _lines.clear();
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _actionBtn({
    required String label,
    required IconData icon,
    required VoidCallback? onPressed,
    bool filled = false,
    bool danger = false,
    Color? color,
  }) {
    final fill = color ?? (danger ? AppTheme.danger : AppTheme.primary);
    final h = widget.embedded ? 38.0 : 46.0;
    final style = filled
        ? FilledButton.styleFrom(
            backgroundColor: fill,
            minimumSize: Size(0, h),
            visualDensity: widget.embedded
                ? VisualDensity.compact
                : VisualDensity.standard,
            textStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
          )
        : OutlinedButton.styleFrom(
            foregroundColor: danger ? AppTheme.danger : AppTheme.textMain,
            side: BorderSide(color: danger ? AppTheme.danger : AppTheme.border),
            minimumSize: Size(0, h),
            visualDensity: widget.embedded
                ? VisualDensity.compact
                : VisualDensity.standard,
            textStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
          );
    final child = filled
        ? FilledButton.icon(
            style: style,
            onPressed: onPressed,
            icon: Icon(icon, size: 18),
            label: Text(label),
          )
        : OutlinedButton.icon(
            style: style,
            onPressed: onPressed,
            icon: Icon(icon, size: 18),
            label: Text(label),
          );
    return Expanded(child: child);
  }

  Widget _buildActionBar() {
    return Container(
      padding: EdgeInsets.fromLTRB(12, widget.embedded ? 4 : 8, 12, widget.embedded ? 4 : 8),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        border: Border(bottom: BorderSide(color: AppTheme.border)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        children: [
          if (_editable) ...[
            _actionBtn(
              label: 'إضافة',
              icon: Icons.add_rounded,
              onPressed: _busy ? null : _addLine,
            ),
            const SizedBox(width: 8),
          ],
          _actionBtn(
            label: 'حفظ',
            icon: Icons.save_outlined,
            filled: true,
            onPressed: _busy || !_editable ? null : _save,
          ),
          if (_canPost) ...[
            const SizedBox(width: 8),
            _actionBtn(
              label: 'ترحيل',
              icon: Icons.send_rounded,
              filled: true,
              color: AppTheme.teal,
              onPressed: _busy ? null : _post,
            ),
          ],
          const SizedBox(width: 8),
          _actionBtn(
            label: 'طباعة',
            icon: Icons.print_outlined,
            onPressed: _busy ? null : _print,
          ),
          if (widget.embedded && _id > 0 && !_approved) ...[
            const SizedBox(width: 8),
            _actionBtn(
              label: 'حذف',
              icon: Icons.delete_outline_rounded,
              danger: true,
              onPressed: _busy ? null : _delete,
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _view() async {
    if (_id == 0 && await _save() == 0) return;
    if (mounted) context.push('/customer-orders/$_id');
  }

  Future<int> _ensureSavedId() async {
    if (_id != 0) return _id;
    return _save();
  }

  Future<void> _print() async {
    if (await _ensureSavedId() == 0 || !mounted) return;
    final data = _printData();
    await Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) => ThermalPreviewScreen(
                  title: 'معاينة طلب الشراء',
                  buildPdf: (paper) =>
                      CustomerOrderBluetoothReceipt.buildThermalPdf(data,
                          paperMm: paper),
                  onPrint: (ctx) async {
                    final err =
                        await CustomerOrderBluetoothReceipt.printOrder(data);
                    if (ctx.mounted) {
                      showSnack(ctx, err ?? 'تمت الطباعة.', error: err != null);
                    }
                  },
                )));
  }

  Future<void> _openPdf() async {
    if (_lines.isEmpty) {
      showSnack(context, 'أضف مادة أولاً.', error: true);
      return;
    }
    showSnack(context, 'جاري تجهيز PDF...');
    try {
      final bytes = await CustomerOrderBluetoothReceipt.buildA4Pdf(_printData());
      if (!mounted) return;
      await DocumentPrintHelper.openPdfBytes(
        context,
        bytes: bytes,
        title: 'طلب شراء',
        fileName: 'طلب شراء - ${_orderNo ?? 'جديد'}',
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر إنشاء PDF: $e', error: true);
    }
  }

  Future<void> _sharePdf() async {
    if (_lines.isEmpty) {
      showSnack(context, 'أضف مادة أولاً.', error: true);
      return;
    }
    showSnack(context, 'جاري تجهيز PDF...');
    try {
      final bytes = await CustomerOrderBluetoothReceipt.buildA4Pdf(_printData());
      if (!mounted) return;
      await DocumentPrintHelper.sharePdfBytes(
        bytes,
        fileName: 'طلب شراء - ${_orderNo ?? 'جديد'}',
        context: context,
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر مشاركة PDF: $e', error: true);
    }
  }

  Future<void> _searchOrderByNoPad() async {
    final v = await showNumericKeypadDialog(
      context,
      title: 'رقم الطلب',
      initial: _orderNoCtrl.text,
      decimal: false,
    );
    if (v == null || !mounted) return;
    setState(() => _orderNoCtrl.text = v);
    await _searchOrderByNo();
  }

  Future<void> _searchOrderByNo() async {
    final q = _orderNoCtrl.text.trim();
    if (q.isEmpty) {
      showSnack(context, 'أدخل رقم الطلب للبحث.', error: true);
      return;
    }
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      final offline = context.read<OfflineController>();
      List<Map<String, dynamic>> orders = [];
      if (!offline.online && offline.catalogReady) {
        orders = await OfflineStore.instance.listOrders(
          q: q,
          limit: 30,
        );
      } else {
        try {
          final query = <String, dynamic>{'q': q, 'page': 1};
          final data = await api.getJson(
            AppConfig.customerOrderListPath,
            query: query,
          );
          orders = (data['orders'] as List? ?? [])
              .whereType<Map>()
              .map((e) => e.cast<String, dynamic>())
              .toList();
        } on ApiException {
          if (!offline.catalogReady) rethrow;
          orders = await OfflineStore.instance.listOrders(
            q: q,
            limit: 30,
          );
        }
      }
      if (!mounted) return;
      final needle = q.toLowerCase();
      final exact = orders
          .where((o) => Fmt.str(o['order_no']).toLowerCase() == needle)
          .toList();
      final starts = orders
          .where((o) => Fmt.str(o['order_no']).toLowerCase().startsWith(needle))
          .toList();
      Map<String, dynamic>? picked;
      if (exact.length == 1) {
        picked = exact.first;
      } else if (starts.length == 1) {
        picked = starts.first;
      } else if (orders.length == 1) {
        picked = orders.first;
      } else if (orders.isEmpty) {
        showSnack(context, 'لا يوجد طلب بهذا الرقم.', error: true);
        return;
      } else {
        picked = await showDialog<Map<String, dynamic>>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('اختر الطلب'),
            content: SizedBox(
              width: 420,
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final o in (exact.isNotEmpty ? exact : starts.isNotEmpty ? starts : orders))
                    ListTile(
                      title: Text(
                        Fmt.str(o['order_no']).isEmpty
                            ? '#${Fmt.toInt(o['id'])}'
                            : Fmt.str(o['order_no']),
                        textDirection: TextDirection.ltr,
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      subtitle: Text(
                        '${Fmt.dmy(Fmt.str(o['order_date']))} · ${Fmt.money(Fmt.toDouble(o['total']))}',
                      ),
                      onTap: () => Navigator.pop(ctx, o),
                    ),
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('إلغاء'),
              ),
            ],
          ),
        );
      }
      if (picked == null || !mounted) return;
      final foundId = Fmt.toInt(picked['id']);
      if (foundId == 0) {
        showSnack(context, 'تعذر فتح الطلب.', error: true);
        return;
      }
      if (foundId == _id) {
        showSnack(context, 'الطلب مفتوح حالياً.');
        return;
      }
      if (_lines.isNotEmpty) {
        final ok = await showAppConfirmDialog(
          context,
          title: 'فتح طلب آخر',
          message: 'سيتم استبدال البيانات الحالية بالطلب المحدد. المتابعة؟',
          confirmLabel: 'فتح',
        );
        if (ok != true || !mounted) return;
      }
      setState(() => _id = foundId);
      await _load();
      if (mounted) widget.onSaved?.call(foundId);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool _useWideOrderLayout(BuildContext context) {
    final s = MediaQuery.sizeOf(context);
    return s.shortestSide >= 550 || s.width >= 900;
  }

  double get _orderGross =>
      _lines.fold<double>(0, (s, l) => s + l.lineGross);

  Widget _wideHeaderCard() {
    final date = _orderDate.isEmpty ? Fmt.todayIso() : _orderDate;
    return Card(
      elevation: 2,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
      child: Padding(
        padding: const EdgeInsets.all(8),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
            Row(
              children: [
                Expanded(child: _wideOrderNoSearch()),
                const SizedBox(width: 6),
                Expanded(
                  child: _wideLabeledBox('التاريخ', Fmt.dmy(date)),
                ),
              ],
            ),
            const SizedBox(height: 6),
            _orderHeaderField(
              label: 'اسم العميل',
              expand: false,
              child: _orderHeaderCustomer(),
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: _orderHeaderField(
                    label: 'نوع الدفع',
                    expand: false,
                    child: _paymentSeg(compact: true),
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: _orderHeaderField(
                    label: 'المستودع',
                    expand: false,
                    child: _orderHeaderWarehouse(),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            SizedBox(
              height: 36,
              child: FilledButton.icon(
                onPressed: _busy || !_editable ? null : _addLine,
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF2196F3),
                  visualDensity: VisualDensity.compact,
                ),
                icon: const Icon(Icons.add_rounded, size: 16),
                label: const Text(
                  'إضافة مادة',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800),
                ),
              ),
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: _wideDocBtn(
                    'حراري',
                    Icons.print_outlined,
                    _busy ? null : _print,
                  ),
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: _wideDocBtn(
                    'PDF',
                    Icons.picture_as_pdf_outlined,
                    _busy ? null : _openPdf,
                  ),
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: _wideDocBtn(
                    'مشاركة',
                    Icons.share_outlined,
                    _busy ? null : _sharePdf,
                  ),
                ),
              ],
            ),
          ],
          ),
        ),
      ),
    );
  }

  Widget _wideOrderNoSearch() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'رقم الطلب',
          style: TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
            color: AppTheme.textSoft,
          ),
        ),
        const SizedBox(height: 4),
        SizedBox(
          height: 38,
          child: Material(
            color: Colors.white,
            child: InkWell(
              onTap: _busy ? null : _searchOrderByNoPad,
              borderRadius: BorderRadius.circular(8),
              child: InputDecorator(
                decoration: InputDecoration(
                  hintText: 'بحث برقم الطلب',
                  hintStyle: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                  isDense: true,
                  contentPadding: const EdgeInsetsDirectional.only(
                    start: 8,
                    end: 4,
                    top: 8,
                    bottom: 8,
                  ),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                  suffixIcon: const Icon(Icons.search_rounded, size: 20),
                ),
                child: Text(
                  _orderNoCtrl.text.isEmpty ? 'بحث برقم الطلب' : _orderNoCtrl.text,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textDirection: TextDirection.ltr,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: _orderNoCtrl.text.isEmpty
                        ? AppTheme.textSoft
                        : AppTheme.textMain,
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _wideDocBtn(String label, IconData icon, VoidCallback? onPressed) {
    return SizedBox(
      height: 34,
      child: OutlinedButton(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          padding: const EdgeInsets.symmetric(horizontal: 4),
          visualDensity: VisualDensity.compact,
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 14),
            const SizedBox(width: 3),
            Flexible(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _wideLabeledBox(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
            color: AppTheme.textSoft,
          ),
        ),
        const SizedBox(height: 4),
        Container(
          height: 38,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          alignment: AlignmentDirectional.centerStart,
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: AppTheme.border),
          ),
          child: Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          ),
        ),
      ],
    );
  }

  Widget _wideSummaryBar() {
    final balance = Fmt.toDouble(_arSummary?['balance']);
    final cheques = Fmt.toDouble(_arSummary?['cheque_total']);
    return Container(
      width: double.infinity,
      color: const Color(0xFFE0E0E0),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: InkWell(
              onTap: () => _showArDetails(focusCheques: false),
              child: _sumCell(
                'رصيد العميل',
                Fmt.money(balance),
                const Color(0xFFD32F2F),
              ),
            ),
          ),
          Expanded(
            child: InkWell(
              onTap: () => _showArDetails(focusCheques: true),
              child: _sumCell(
                'شيكات قيد التحصيل',
                Fmt.money(cheques),
                const Color(0xFFF57C00),
              ),
            ),
          ),
          Expanded(
            child: _sumCell(
              'المجموع النهائي',
              Fmt.money(_orderGross),
              const Color(0xFF388E3C),
              large: true,
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _showArDetails({required bool focusCheques}) async {
    final cheques = ChequeUnderCollection.fromResult(_arSummary);
    final total = ChequeUnderCollection.totalOf(cheques, _arSummary);
    final balance = Fmt.toDouble(_arSummary?['balance']);
    final debit = Fmt.toDouble(_arSummary?['total_debit']);
    final credit = Fmt.toDouble(_arSummary?['total_credit']);
    final opening = Fmt.toDouble(_arSummary?['opening']);
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(
          focusCheques ? 'شيكات قيد التحصيل' : 'رصيد العميل',
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
        content: SizedBox(
          width: 520,
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                InfoRow('الرصيد', Fmt.money(balance), ltr: true),
                InfoRow('مدين', Fmt.money(debit), ltr: true),
                InfoRow('دائن', Fmt.money(credit), ltr: true),
                InfoRow('افتتاحي', Fmt.money(opening), ltr: true),
                const SizedBox(height: 10),
                if (cheques.isEmpty)
                  const Text(
                    'لا توجد شيكات قيد التحصيل.',
                    style: TextStyle(color: AppTheme.textSoft),
                  )
                else
                  ChequesUnderCollectionTable(rows: cheques, total: total),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('إغلاق'),
          ),
        ],
      ),
    );
  }

  Widget _sumCell(String label, String value, Color color, {bool large = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          '$label: ',
          style: TextStyle(
            fontSize: large ? 13 : 12,
            fontWeight: FontWeight.w800,
          ),
        ),
        Flexible(
          child: Text(
            value,
            textDirection: TextDirection.ltr,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: large ? 14 : 12,
              fontWeight: FontWeight.w900,
              color: color,
            ),
          ),
        ),
      ],
    );
  }

  Widget _wideActionBar() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
      child: Row(
        children: [
          Expanded(
            child: SizedBox(
              height: 38,
              child: FilledButton(
                onPressed: _busy || !_editable ? null : _save,
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFFFF9800),
                ),
                child: const Text(
                  'حفظ',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: SizedBox(
              height: 38,
              child: FilledButton(
                onPressed: _busy || !_canPost ? null : _post,
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF4CAF50),
                ),
                child: const Text(
                  'ترحيل',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildWideOrderBody() {
    final screenW = MediaQuery.sizeOf(context).width;
    final sideW = screenW < 1100
        ? (screenW * 0.28).clamp(236.0, 286.0)
        : 340.0;
    return ColoredBox(
      color: const Color(0xFFF5F5F5),
      child: Column(
        children: [
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 6, 8, 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(width: sideW, child: _wideHeaderCard()),
                  const SizedBox(width: 8),
                  Expanded(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(color: AppTheme.border),
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(6),
                        child: _lines.isEmpty
                            ? const Center(
                                child: Text(
                                  'لا توجد مواد. اضغط «إضافة مادة».',
                                  style: TextStyle(
                                    color: AppTheme.textSoft,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              )
                            : _buildLinesTableScroll(
                                compact: true,
                                fitToWidth: true,
                              ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          _wideSummaryBar(),
          _wideActionBar(),
        ],
      ),
    );
  }

  Widget _buildLinesTableScroll({
    required bool compact,
    bool fitToWidth = false,
  }) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final avail = constraints.maxWidth;
        final metrics = fitToWidth && avail.isFinite && avail > 80
            ? _LineTableMetrics.fit(avail, compact: compact)
            : _LineTableMetrics.scroll(compact: compact);
        final table = _buildLinesDataTable(metrics, compact: compact);
        if (fitToWidth && avail.isFinite && avail > 80) {
          return SingleChildScrollView(
            child: SizedBox(width: avail, child: table),
          );
        }
        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: ConstrainedBox(
            constraints: BoxConstraints(minWidth: metrics.minWidth),
            child: table,
          ),
        );
      },
    );
  }

  InputDecoration _lineFieldDecoration(_LineTableMetrics m) {
    return InputDecoration(
      isDense: true,
      contentPadding: EdgeInsets.symmetric(
        horizontal: m.compactTax ? 2 : 4,
        vertical: 2,
      ),
      border: const OutlineInputBorder(),
    );
  }

  Future<void> _editLineNumber({
    required String title,
    required String initial,
    required bool decimal,
    double? maxValue,
    required void Function(String value) apply,
  }) async {
    if (!_editable || _busy) return;
    final v = await showNumericKeypadDialog(
      context,
      title: title,
      initial: initial,
      decimal: decimal,
      maxValue: maxValue,
    );
    if (v == null || !mounted) return;
    setState(() => apply(v));
  }

  Widget _numPadField({
    required double width,
    required String text,
    required TextStyle style,
    required _LineTableMetrics m,
    String hint = '',
    required VoidCallback? onTap,
  }) {
    return SizedBox(
      width: width,
      child: Material(
        color: Colors.white,
        child: InkWell(
          onTap: onTap,
          child: InputDecorator(
            decoration: _lineFieldDecoration(m),
            child: Text(
              text.isEmpty ? hint : text,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              textDirection: TextDirection.ltr,
              style: text.isEmpty
                  ? style.copyWith(color: AppTheme.textSoft)
                  : style,
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildLinesDataTable(_LineTableMetrics m, {required bool compact}) {
    final cellStyle = TextStyle(
      fontSize: m.font,
      height: 1.15,
      fontWeight: FontWeight.w700,
      color: const Color(0xFF0F172A),
    );
    return DefaultTextStyle.merge(
      style: cellStyle,
      child: DataTable(
        headingRowHeight: compact ? 32 : 42,
        dataRowMinHeight: compact ? 36 : 50,
        dataRowMaxHeight: compact ? 40 : 54,
        columnSpacing: m.colSpacing,
        horizontalMargin: m.hMargin,
        headingTextStyle: TextStyle(
          fontSize: m.heading,
          fontWeight: FontWeight.w900,
          color: const Color(0xFF475569),
        ),
        dataTextStyle: cellStyle,
        columns: [
          DataColumn(label: _tableHead('المادة', m.item, m.heading)),
          DataColumn(label: _tableHead('الوحدة', m.unit, m.heading)),
          DataColumn(label: _tableHead('الكمية', m.qty, m.heading)),
          DataColumn(label: _tableHead('إضافية', m.extra, m.heading)),
          DataColumn(label: _tableHead('السعر غ ش', m.price, m.heading)),
          DataColumn(label: _tableHead('السعر ش', m.priceInc, m.heading)),
          DataColumn(label: _tableHead('الضريبة', m.tax, m.heading)),
          DataColumn(label: _tableHead('خصم %', m.disc, m.heading)),
          DataColumn(label: _tableHead('المجموع', m.total, m.heading)),
          DataColumn(label: SizedBox(width: m.del)),
        ],
        rows: [
          for (var i = 0; i < _lines.length; i++)
            DataRow(cells: [
              DataCell(
                SizedBox(
                  width: m.item,
                  child: Text(
                    _lines[i].item.name,
                    maxLines: 1,
                    softWrap: false,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: m.font,
                    ),
                  ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.unit,
                  child: DropdownButtonFormField<int>(
                    key: ValueKey(
                        'unit-${_lines[i].item.id}-$i-${_lines[i].unitId}'),
                    isExpanded: true,
                    isDense: true,
                    style: cellStyle,
                    initialValue: _lines[i].unitId == 0
                        ? (_lines[i].item.units.isEmpty
                            ? null
                            : _lines[i].item.units.first.unitId)
                        : _lines[i].unitId,
                    decoration: _lineFieldDecoration(m),
                    items: [
                      for (final u in _lines[i].item.units)
                        DropdownMenuItem(
                          value: u.unitId,
                          child: Text(
                            u.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(fontSize: m.font),
                          ),
                        ),
                      if (_lines[i].item.units.isEmpty &&
                          _lines[i].unitName.isNotEmpty)
                        DropdownMenuItem(
                          value: _lines[i].unitId,
                          child: Text(
                            _lines[i].unitName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(fontSize: m.font),
                          ),
                        ),
                    ],
                    onChanged: !_editable
                        ? null
                        : (v) {
                            final u = _lines[i]
                                .item
                                .units
                                .where((x) => x.unitId == v)
                                .cast<ItemUnitOpt?>()
                                .followedBy([null]).first;
                            setState(() {
                              _lines[i].applyUnit(u, unitIdOverride: v);
                            });
                          },
                  ),
                ),
              ),
              DataCell(
                _numPadField(
                  width: m.qty,
                  text: '${_lines[i].qty}',
                  style: cellStyle,
                  m: m,
                  onTap: !_editable
                      ? null
                      : () => _editLineNumber(
                            title: 'الكمية',
                            initial: '${_lines[i].qty}',
                            decimal: false,
                            apply: (v) {
                              _lines[i].qty =
                                  int.tryParse(v)?.clamp(1, 999999999) ?? 1;
                            },
                          ),
                ),
              ),
              DataCell(
                _numPadField(
                  width: m.extra,
                  text: '${_lines[i].qtyExtra}',
                  style: cellStyle,
                  m: m,
                  onTap: !_editable
                      ? null
                      : () => _editLineNumber(
                            title: 'كمية إضافية',
                            initial: '${_lines[i].qtyExtra}',
                            decimal: false,
                            apply: (v) {
                              _lines[i].qtyExtra =
                                  int.tryParse(v)?.clamp(0, 999999999) ?? 0;
                            },
                          ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.price,
                  child: TextFormField(
                    key: ValueKey(
                        'price-${_lines[i].item.id}-$i-${_lines[i].unitId}'),
                    initialValue: Fmt.trimNum(_lines[i].unitPrice),
                    enabled: _editable,
                    style: cellStyle.copyWith(fontWeight: FontWeight.w800),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: _lineFieldDecoration(m),
                    onChanged: (v) => setState(() {
                      final p = double.tryParse(v.replaceAll(',', '')) ?? 0;
                      _lines[i].unitPrice = p;
                      if (_lines[i].unitFactor > 0) {
                        _lines[i].basePrice = p / _lines[i].unitFactor;
                      }
                    }),
                  ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.priceInc,
                  child: Text(
                    Fmt.money(_lines[i].unitPriceInclusive),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: m.font,
                      fontWeight: FontWeight.w900,
                      color: const Color(0xFF0F766E),
                    ),
                  ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.tax,
                  child: DropdownButtonFormField<int>(
                    key: ValueKey(
                        'tax-${_lines[i].item.id}-$i-${_lines[i].taxRateId}'),
                    initialValue: _lines[i].taxRateId,
                    isExpanded: true,
                    isDense: true,
                    decoration: _lineFieldDecoration(m),
                    items: [
                      for (final tax in _taxRates)
                        DropdownMenuItem(
                          value: tax.id,
                          child: Text(
                            m.compactTax
                                ? '${Fmt.trimNum(tax.rate)}%'
                                : '${tax.name} (${Fmt.trimNum(tax.rate)}%)',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontSize: m.font,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                    ],
                    onChanged: !_editable
                        ? null
                        : (id) {
                            final matches =
                                _taxRates.where((t) => t.id == id);
                            if (matches.isEmpty) return;
                            setState(() {
                              _lines[i].taxRateId = matches.first.id;
                              _lines[i].taxRatePercent = matches.first.rate;
                            });
                          },
                  ),
                ),
              ),
              DataCell(
                _numPadField(
                  width: m.disc,
                  text: _lines[i].discountPct <= 0
                      ? ''
                      : Fmt.trimNum(_lines[i].discountPct),
                  style: cellStyle,
                  m: m,
                  hint: '0',
                  onTap: !_editable
                      ? null
                      : () => _editLineNumber(
                            title: 'خصم %',
                            initial: _lines[i].discountPct <= 0
                                ? ''
                                : Fmt.trimNum(_lines[i].discountPct),
                            decimal: true,
                            maxValue: 100,
                            apply: (v) {
                              _lines[i].discountPct = (double.tryParse(
                                        v.replaceAll(',', ''),
                                      ) ??
                                      0)
                                  .clamp(0, 100);
                            },
                          ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.total,
                  child: Text(
                    Fmt.money(_lines[i].lineGross),
                    maxLines: 1,
                    softWrap: false,
                    overflow: TextOverflow.ellipsis,
                    textDirection: TextDirection.ltr,
                    style: TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: m.font,
                    ),
                  ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.del,
                  child: _editable
                      ? IconButton(
                          visualDensity: VisualDensity.compact,
                          padding: EdgeInsets.zero,
                          constraints: BoxConstraints(
                            minWidth: m.del,
                            minHeight: 28,
                          ),
                          onPressed: () => setState(() => _lines.removeAt(i)),
                          icon: Icon(
                            Icons.delete_outline_rounded,
                            size: m.font + 4,
                            color: const Color(0xFFB91C1C),
                          ),
                        )
                      : const SizedBox.shrink(),
                ),
              ),
            ]),
        ],
      ),
    );
  }

  Widget _tableHead(String label, double width, double font) {
    return SizedBox(
      width: width,
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          fontSize: font,
          fontWeight: FontWeight.w900,
          color: const Color(0xFF475569),
        ),
      ),
    );
  }


  Widget _wrapLeaveGuard(Widget child) {
    return PopScope(
      canPop: !isDirty,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final ok = await confirmLeave();
        if (ok && mounted) Navigator.of(context).maybePop();
      },
      child: child,
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_useWideOrderLayout(context)) {
      final wide = AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: _buildWideOrderBody(),
      );
      if (widget.embedded) {
        return _wrapLeaveGuard(
          Material(color: const Color(0xFFF5F5F5), child: wide),
        );
      }
      return _wrapLeaveGuard(
        MobileScaffold(
          title: const Text('طلب شراء عميل'),
          body: wide,
        ),
      );
    }
    final body = AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: ListView(
          padding: EdgeInsets.all(widget.embedded ? 8 : 14),
          children: [
            DocumentHeaderCard(
                title: widget.embedded
                    ? null
                    : (_orderNo == null || _orderNo!.isEmpty
                        ? 'بيانات الطلب'
                        : 'الطلب $_orderNo'),
                padding: EdgeInsets.all(widget.embedded ? 8 : 14),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _orderHeaderField(
                      label: 'المستودع',
                      child: _orderHeaderWarehouse(),
                    ),
                    const SizedBox(width: 8),
                    _orderHeaderField(
                      label: 'نوع الدفع',
                      child: _paymentSeg(compact: true),
                    ),
                    if (!widget.hideCustomerPicker || _customer != null) ...[
                      const SizedBox(width: 8),
                      _orderHeaderField(
                        label: 'العميل',
                        child: _orderHeaderCustomer(),
                      ),
                    ],
                  ],
                )),
            if (_customer != null && !widget.embedded) ...[
              const DocumentSectionDivider('ملخص حساب العميل'),
              const Padding(
                padding: EdgeInsets.only(bottom: 10),
                child: OrderStatementWorkflowNote(compact: true),
              ),
              AppCard(
                child: _arLoading
                    ? const Padding(
                        padding: EdgeInsets.all(12),
                        child: Center(child: CircularProgressIndicator()),
                      )
                    : (_arError != null
                        ? Text(
                            _arError!,
                            style: const TextStyle(
                              color: Color(0xFFB91C1C),
                              fontWeight: FontWeight.w700,
                            ),
                          )
                        : Column(
                            children: [
                              InfoRow(
                                'مدين',
                                Fmt.money(
                                  Fmt.toDouble(_arSummary?['total_debit']),
                                ),
                                ltr: true,
                              ),
                              InfoRow(
                                'دائن',
                                Fmt.money(
                                  Fmt.toDouble(_arSummary?['total_credit']),
                                ),
                                ltr: true,
                              ),
                              InfoRow(
                                'المبلغ المستحق',
                                Fmt.money(
                                  Fmt.toDouble(_arSummary?['balance']),
                                ),
                                ltr: true,
                              ),
                              InfoRow(
                                'شيكات قيد التحصيل',
                                '${Fmt.toInt(_arSummary?['cheque_count'])} · ${Fmt.money(Fmt.toDouble(_arSummary?['cheque_total']))}',
                                ltr: true,
                              ),
                              const SizedBox(height: 10),
                              ChequesUnderCollectionTable(
                                rows: ChequeUnderCollection.fromResult(
                                  _arSummary,
                                ),
                                total: ChequeUnderCollection.totalOf(
                                  ChequeUnderCollection.fromResult(_arSummary),
                                  _arSummary,
                                ),
                              ),
                            ],
                          )),
              ),
            ],
            const DocumentSectionDivider('بنود الطلب'),
            if (_lines.isNotEmpty)
              AppCard(
                padding: EdgeInsets.zero,
                child: _buildLinesTableScroll(compact: widget.embedded),
              ),
            if (_lines.isNotEmpty) ...[
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerLeft,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      'قبل الضريبة: ${Fmt.money(_lines.fold<double>(0, (s, l) => s + l.lineTotal))}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 14,
                      ),
                    ),
                    Text(
                      'الضريبة: ${Fmt.money(_lines.fold<double>(0, (s, l) => s + l.taxAmount))}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 14,
                      ),
                    ),
                    Text(
                      'الإجمالي شامل الضريبة: ${Fmt.money(_lines.fold<double>(0, (s, l) => s + l.lineGross))}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: 17,
                        color: Color(0xFF0F766E),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ));
    if (widget.embedded) {
      return _wrapLeaveGuard(
        Material(
          color: Colors.transparent,
          child: Column(
            children: [
              _buildActionBar(),
              Expanded(child: body),
            ],
          ),
        ),
      );
    }
    return _wrapLeaveGuard(
      MobileScaffold(
        title: Text(_id > 0 ? 'تعديل طلب شراء' : 'طلب شراء جديد'),
        body: Column(
          children: [
            _buildActionBar(),
            Expanded(child: body),
          ],
        ),
      ),
    );
  }

  Widget _orderHeaderField({
    required String label,
    required Widget child,
    bool expand = true,
  }) {
    final field = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
            color: AppTheme.textSoft,
          ),
        ),
        const SizedBox(height: 4),
        SizedBox(height: 38, child: child),
      ],
    );
    return expand ? Expanded(child: field) : field;
  }

  Widget _orderHeaderShell({required Widget child}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.border),
      ),
      alignment: AlignmentDirectional.centerStart,
      child: child,
    );
  }

  Widget _orderHeaderWarehouse() {
    return _orderHeaderShell(
      child: DropdownButtonHideUnderline(
        child: DropdownButton<int>(
          isExpanded: true,
          isDense: true,
          value: _warehouseId == 0 ? null : _warehouseId,
          hint: const Text(
            '—',
            style: TextStyle(fontSize: 12, color: AppTheme.textSoft),
          ),
          style: const TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: AppTheme.textMain,
          ),
          icon: const Icon(Icons.expand_more_rounded, size: 18),
          items: _warehouses
              .map(
                (w) => DropdownMenuItem(
                  value: Fmt.toInt(w['id']),
                  child: Text(
                    Fmt.str(w['name'] ?? w['name_ar']),
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 12),
                  ),
                ),
              )
              .toList(),
          onChanged:
              _editable ? (v) => setState(() => _warehouseId = v ?? 0) : null,
        ),
      ),
    );
  }

  Widget _orderHeaderCustomer() {
    final canPick = _editable && !widget.hideCustomerPicker;
    final name = _customer?.name ?? 'اضغط للاختيار';
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: canPick ? _pickCustomer : null,
        borderRadius: BorderRadius.circular(12),
        child: _orderHeaderShell(
          child: Row(
            children: [
              Expanded(
                child: Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: _customer == null
                        ? AppTheme.textSoft
                        : AppTheme.textMain,
                  ),
                ),
              ),
              if (canPick)
                const Icon(
                  Icons.chevron_left_rounded,
                  size: 16,
                  color: AppTheme.textSoft,
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _paymentSeg({bool compact = false}) {
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.border),
      ),
      child: Row(
        children: [
          _segBtn('ذمم', 'credit', compact: compact),
          _segBtn('نقدي', 'cash', compact: compact),
        ],
      ),
    );
  }

  Widget _segBtn(String label, String value, {bool compact = false}) {
    final sel = _paymentType == value;
    return Expanded(
      child: Material(
        color: sel ? AppTheme.primary : Colors.transparent,
        borderRadius: BorderRadius.circular(9),
        child: InkWell(
          onTap: _editable ? () => setState(() => _paymentType = value) : null,
          borderRadius: BorderRadius.circular(9),
          child: Padding(
            padding: EdgeInsets.symmetric(vertical: compact ? 7 : 8),
            child: Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: compact ? 11.5 : 13,
                color: sel ? Colors.white : AppTheme.textSoft,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
