import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/device_layout.dart';
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
    this.toolbarLeading,
    this.onSaved,
    this.onDeleted,
    this.onPosted,
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

  /// عناصر تُعرض في بداية شريط التابلت (مسودات / طلب جديد) على نفس السطر.
  final Widget? toolbarLeading;

  final void Function(int orderId)? onSaved;
  final VoidCallback? onDeleted;

  /// بعد الترحيل الناجح — يُفرَّغ النموذج ويُزال من غير المرسلة.
  final VoidCallback? onPosted;

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
  int qtyPiece = 0;
  int qtyCarton = 0;
  int extraPiece = 0;
  int extraCarton = 0;
  int unitId = 0;
  String unitName = '';
  double unitFactor = 1;
  double basePrice = 0;
  double unitPrice = 0;
  double discountPct = 0;
  int taxRateId;
  double taxRatePercent;

  String get barcode => item.barcode;

  ItemUnitOpt? get pieceUnit {
    ItemUnitOpt? base;
    for (final u in item.units) {
      if (!(u.isBase || u.factor <= 1.001)) continue;
      if (base == null || (u.isBase && !base.isBase) || u.factor < base.factor) {
        base = u;
      }
    }
    return base;
  }

  ItemUnitOpt? get cartonUnit {
    final piece = pieceUnit;
    ItemUnitOpt? box;
    for (final u in item.units) {
      if (piece != null && u.unitId == piece.unitId) continue;
      final name = u.name;
      final looksBox = name.contains('كرتون') || u.factor > 1.001;
      if (!looksBox && u.factor <= 1.001) continue;
      if (box == null || u.factor > box.factor) box = u;
    }
    return box;
  }

  double _priceOf(ItemUnitOpt? u) {
    final f = (u == null || u.factor <= 0) ? 1.0 : u.factor;
    return Fmt.roundUnitPrice(basePrice * f);
  }

  double get piecePrice => _priceOf(pieceUnit);
  double get cartonPrice => cartonUnit == null ? 0 : _priceOf(cartonUnit);
  double get piecePriceInclusive =>
      Fmt.roundAmount(piecePrice * (1 + taxRatePercent.clamp(0, 100) / 100));
  double get cartonPriceInclusive =>
      Fmt.roundAmount(cartonPrice * (1 + taxRatePercent.clamp(0, 100) / 100));

  /// عدد القطع في الكرتونة (معامل وحدة الكرتون).
  int get packSize {
    final c = cartonUnit;
    if (c == null || c.factor <= 1.001) return 0;
    return c.factor.round().clamp(0, 999999);
  }

  /// وحدات البطاقة من الأكبر إلى الأصغر. وحدة واحدة تبقى حقلاً واحداً.
  List<ItemUnitOpt> get orderUnits {
    if (item.units.isEmpty) {
      return [
        ItemUnitOpt(
          unitId: unitId,
          name: unitName.isEmpty ? 'قطعة' : unitName,
          factor: unitFactor <= 0 ? 1 : unitFactor,
          isBase: unitFactor <= 1.001,
        ),
      ];
    }
    final list = [...item.units]..sort((a, b) => b.factor.compareTo(a.factor));
    return list;
  }

  final Map<int, int> qtyOther = {};
  final Map<int, int> extraOther = {};

  int qtyFor(ItemUnitOpt u, {required bool extra}) {
    if (pieceUnit != null && u.unitId == pieceUnit!.unitId) {
      return extra ? extraPiece : qtyPiece;
    }
    if (cartonUnit != null && u.unitId == cartonUnit!.unitId) {
      return extra ? extraCarton : qtyCarton;
    }
    return (extra ? extraOther : qtyOther)[u.unitId] ?? 0;
  }

  void setQtyFor(ItemUnitOpt u, int v, {required bool extra}) {
    if (pieceUnit != null && u.unitId == pieceUnit!.unitId) {
      if (extra) {
        extraPiece = v;
      } else {
        qtyPiece = v;
      }
      return;
    }
    if (cartonUnit != null && u.unitId == cartonUnit!.unitId) {
      if (extra) {
        extraCarton = v;
      } else {
        qtyCarton = v;
      }
      return;
    }
    (extra ? extraOther : qtyOther)[u.unitId] = v;
  }

  bool get hasQty {
    for (final u in orderUnits) {
      if (qtyFor(u, extra: false) > 0 || qtyFor(u, extra: true) > 0) return true;
    }
    return qtyPiece > 0 || qtyCarton > 0 || extraPiece > 0 || extraCarton > 0;
  }

  double get lineBase {
    final piece = pieceUnit;
    final carton = cartonUnit;
    var n = qtyPiece * _priceOf(piece) + qtyCarton * _priceOf(carton);
    for (final u in orderUnits) {
      final isPiece = piece != null && u.unitId == piece.unitId;
      final isCarton = carton != null && u.unitId == carton.unitId;
      if (isPiece || isCarton) continue;
      n += qtyFor(u, extra: false) * _priceOf(u);
    }
    return n;
  }
  double get discountAmount =>
      discountPct <= 0 ? 0 : lineBase * (discountPct.clamp(0, 100) / 100);
  double get lineTotal => (lineBase - discountAmount).clamp(0, double.infinity);
  double get taxAmount => lineTotal * taxRatePercent / 100;
  double get lineGross => lineTotal + taxAmount;
  double get unitPriceInclusive {
    final units = orderUnits;
    final u = units.isEmpty ? pieceUnit : units.last;
    return _priceOf(u) * (1 + taxRatePercent / 100);
  }

  Map<String, dynamic> _saveMap(ItemUnitOpt? u, int qty, int extra) {
    final factor = (u == null || u.factor <= 0) ? 1.0 : u.factor;
    final price = basePrice * factor;
    return {
      'item_id': item.id,
      'item_name': item.name,
      'unit_id': u?.unitId ?? 0,
      'unit_name': u?.name ?? 'قطعة',
      'unit_factor': factor,
      'qty': qty,
      'qty_extra': extra,
      'qty_base': (qty + extra) * factor,
      'unit_price': price,
      'tax_rate_id': taxRateId,
      'tax_rate_percent': taxRatePercent,
      'discount_pct': discountPct,
      'line_discount_input':
          discountPct > 0 ? '${Fmt.trimNum(discountPct)}%' : '',
    };
  }

  /// سطر للكرتونة ثم القطعة، حتى لو بطاقة المادة لا تعرض إلا وحدة واحدة.
  List<Map<String, dynamic>> toSaveLines() {
    final out = <Map<String, dynamic>>[];
    final carton = cartonUnit;
    final piece = pieceUnit;
    if (qtyCarton > 0 || extraCarton > 0) {
      out.add(_saveMap(
        carton ??
            const ItemUnitOpt(unitId: 0, name: 'كرتونة', factor: 1),
        qtyCarton,
        extraCarton,
      ));
    }
    if (qtyPiece > 0 || extraPiece > 0) {
      out.add(_saveMap(
        piece ??
            const ItemUnitOpt(
                unitId: 0, name: 'قطعة', factor: 1, isBase: true),
        qtyPiece,
        extraPiece,
      ));
    }
    for (final u in orderUnits) {
      final isPiece = piece != null && u.unitId == piece.unitId;
      final isCarton = carton != null && u.unitId == carton.unitId;
      if (isPiece || isCarton) continue;
      final q = qtyFor(u, extra: false);
      final e = qtyFor(u, extra: true);
      if (q > 0 || e > 0) out.add(_saveMap(u, q, e));
    }
    return out;
  }

  Map<String, dynamic> toJson() => {
        'item_id': item.id,
        'item_name': item.name,
        'unit_id': pieceUnit?.unitId ?? unitId,
        'unit_name': pieceUnit?.name ?? unitName,
        'unit_factor': pieceUnit?.factor ?? unitFactor,
        'qty': qtyPiece,
        'qty_carton': qtyCarton,
        'qty_extra': extraPiece,
        'qty_extra_carton': extraCarton,
        'qty_base': (qtyPiece + extraPiece) * (pieceUnit?.factor ?? 1) +
            (qtyCarton + extraCarton) * (cartonUnit?.factor ?? 1),
        'unit_price': piecePrice,
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
    required this.pack,
    required this.carton,
    required this.piece,
    required this.freeCarton,
    required this.freePiece,
    required this.pricePieceEx,
    required this.pricePieceInc,
    required this.priceCartonEx,
    required this.priceCartonInc,
    required this.disc,
    required this.total,
    required this.del,
    required this.font,
    required this.heading,
    required this.colSpacing,
    required this.hMargin,
  });

  final double item;
  final double pack;
  final double carton;
  final double piece;
  final double freeCarton;
  final double freePiece;
  final double pricePieceEx;
  final double pricePieceInc;
  final double priceCartonEx;
  final double priceCartonInc;
  final double disc;
  final double total;
  final double del;
  final double font;
  final double heading;
  final double colSpacing;
  final double hMargin;

  // توافق مع استدعاءات قديمة لحقل الضريبة في الديكور
  bool get compactTax => true;
  bool get showTax => false;
  double get tax => 0;

  static const _colGaps = 12;

  double get minWidth =>
      item +
      pack +
      carton +
      piece +
      freeCarton +
      freePiece +
      pricePieceEx +
      pricePieceInc +
      priceCartonEx +
      priceCartonInc +
      disc +
      total +
      del +
      hMargin * 2 +
      colSpacing * _colGaps;

  factory _LineTableMetrics.fit(double avail, {required bool compact}) {
    // أعمدة أضيق ومتناسقة — أسعار غ.ش / ش كل واحد بحقل
    final tiny = avail < 720;
    final tight = avail < 900;
    final weights = tiny
        ? const [
            2.05, 0.36, 0.44, 0.44, 0.44, 0.44, 0.62, 0.62, 0.62, 0.62, 0.38,
            0.70, 0.26,
          ]
        : const [
            1.95, 0.38, 0.46, 0.46, 0.46, 0.46, 0.68, 0.68, 0.68, 0.68, 0.40,
            0.72, 0.28,
          ];
    final sum = weights.fold<double>(0, (s, w) => s + w);
    final spacing = tiny ? 2.0 : (tight ? 3.0 : 4.0);
    final margin = tiny ? 2.0 : 3.0;
    final inner =
        (avail - margin * 2 - spacing * _colGaps).clamp(360.0, 4000.0);
    final w = weights.map((x) => inner * x / sum).toList();
    final qtyW = w[2].clamp(tiny ? 36.0 : 40.0, 52.0);
    final priceW = w[6].clamp(tiny ? 48.0 : 54.0, 78.0);
    return _LineTableMetrics(
      item: w[0].clamp(tiny ? 100.0 : 118.0, 220.0),
      pack: w[1].clamp(30.0, 44.0),
      carton: qtyW,
      piece: qtyW,
      freeCarton: qtyW,
      freePiece: qtyW,
      pricePieceEx: priceW,
      pricePieceInc: priceW,
      priceCartonEx: priceW,
      priceCartonInc: priceW,
      disc: w[10].clamp(34.0, 46.0),
      total: w[11].clamp(52.0, 78.0),
      del: w[12].clamp(22.0, 30.0),
      font: tiny ? 10.0 : (tight ? 11.0 : 12.0),
      heading: tiny ? 8.5 : (tight ? 9.5 : 10.5),
      colSpacing: spacing,
      hMargin: margin,
    );
  }

  factory _LineTableMetrics.scroll({required bool compact}) {
    return _LineTableMetrics.fit(900, compact: compact);
  }
}

class CustomerOrderFormScreenState extends State<CustomerOrderFormScreen> {
  static CustomerOrderFormScreenState? active;
  OfflineController? _offline;
  bool _autoSendBound = false;
  bool _loading = true, _busy = false, _approved = false;
  String? _error, _orderNo, _salesRepName;
  String _paymentType = 'credit';
  String _orderDate = '';
  String _deliveryDate = '';
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
  bool _autoSendOrders = false;
  bool _isSent = false;
  bool _managerMode = false;
  int _prevId = 0;
  int _nextId = 0;
  int _firstId = 0;
  int _lastId = 0;
  final _orderNoCtrl = TextEditingController();
  final _notesCtrl = TextEditingController();
  String _cleanFingerprint = '';

  /// المدير يعدّل الطلب بعد ترحيل المندوب وقبل الاعتماد.
  bool get _editable => !_approved && (!_isSent || _managerMode);

  bool get _canDelete =>
      !_approved && (!_isSent || _managerMode) && (_id > 0 || _lines.isNotEmpty);

  bool get _canManagerApprove =>
      _managerMode && _id > 0 && _isSent && !_approved;

  String _fingerprint() {
    final lines = _lines
        .map((l) =>
            '${l.item.id}:${l.orderUnits.map((u) => '${u.unitId}:${l.qtyFor(u, extra: false)}:${l.qtyFor(u, extra: true)}').join(',')}:${l.discountPct}:${l.taxRateId}')
        .join('|');
    return '${_customer?.id}|$_warehouseId|$_paymentType|$_deliveryDate|${_notesCtrl.text.trim()}|$lines';
  }

  int get orderId => _id;

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
    // تثبيت العرض دائماً على شاشة الطلب
    SystemChrome.setPreferredOrientations(const [
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    _load();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _managerMode =
        context.read<SessionController>().can('m_manager_customer_orders');
    if (_autoSendBound) return;
    _autoSendBound = true;
    _offline = context.read<OfflineController>();
    _offline!.skipDirtyOrderId = () => isDirty ? _id : 0;
    _offline!.onOrdersSentFromSync = (ids) {
      if (!mounted) return;
      if (_id != 0 && ids.contains(_id)) {
        _afterPostedClear();
      }
    };
  }

  Future<void> _approveAsManager() async {
    if (!_canManagerApprove || _busy) return;
    if (isDirty) {
      final savedId = await _save();
      if (savedId == 0) return;
    }
    final ok = await showAppConfirmDialog(
      context,
      title: 'اعتماد الطلب',
      message: 'اعتماد هذا الطلب ليظهر في النظام وكشف الحساب؟',
      confirmLabel: 'اعتماد',
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final csrf = await context.read<SessionController>().ensureCsrf();
      final res = await context.read<ApiClient>().postJson(
            AppConfig.managerCustomerOrderApprovePath,
            body: {'id': _id, 'approve': true},
            csrf: csrf,
          );
      if (!mounted) return;
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty
            ? 'تم اعتماد الطلب.'
            : Fmt.str(res['message']),
      );
      setState(() => _approved = true);
      _markClean();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    if (identical(active, this)) {
      active = null;
      _offline?.skipDirtyOrderId = null;
      _offline?.onOrdersSentFromSync = null;
    }
    _orderNoCtrl.dispose();
    _notesCtrl.dispose();
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
      var prevId = 0, nextId = 0, firstId = 0, lastId = 0;
      Future<void> applyNav(Map<String, dynamic> result) async {
        prevId = Fmt.toInt(result['prev_id']);
        nextId = Fmt.toInt(result['next_id']);
        firstId = Fmt.toInt(result['first_id']);
        lastId = Fmt.toInt(result['last_id']);
      }

      if (_id != 0) {
        // مسودات الجهاز (id سالب) تُقرأ محلياً دائماً — لا تُطلب من السيرفر.
        if (_id < 0 || !offline.online) {
          final local = await OfflineStore.instance.getOrderById(_id);
          if (local == null) {
            throw ApiException(
              'الطلب غير متوفر محلياً. حدّث البيانات أو اتصل بالإنترنت.',
            );
          }
          order = local;
        } else {
          try {
            final query = <String, dynamic>{'id': _id};
            final cid = _customer?.id ?? widget.initialCustomerId ?? 0;
            if (cid > 0) query['customer_id'] = cid;
            final result = await api.getJson(
              AppConfig.customerOrderViewPath,
              query: query,
            );
            await applyNav(result);
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
      } else if (offline.online) {
        try {
          final query = <String, dynamic>{};
          final cid = _customer?.id ?? widget.initialCustomerId ?? 0;
          if (cid > 0) query['customer_id'] = cid;
          final result = await api.getJson(
            AppConfig.customerOrderViewPath,
            query: query,
          );
          await applyNav(result);
        } catch (_) {
          /* التنقل اختياري لمستند جديد */
        }
      }
      if (!mounted) return;
      // خانات الأسعار/المبالغ من إعدادات النظام (ويندوز)
      if (meta.containsKey('decimal_places') ||
          meta.containsKey('invoice_unit_price_decimal_places')) {
        Fmt.applySettings(
          decimalPlaces: meta['decimal_places'] ?? 3,
          unitPriceDecimalPlaces:
              meta['invoice_unit_price_decimal_places'] ??
                  meta['decimal_places'] ??
                  3,
        );
      } else {
        await OfflineStore.instance.applyDecimalSettings();
      }
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
          ..qtyPiece = 0
          ..qtyCarton = 0
          ..extraPiece = 0
          ..extraCarton = 0
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
        final storedCarton = m.containsKey('qty_carton') ||
            m.containsKey('qty_extra_carton');
        final factor = line.unitFactor <= 0 ? 1.0 : line.unitFactor;
        final name = line.unitName;
        final isCarton = !storedCarton &&
            (factor > 1.001 || name.contains('كرتون'));
        final q = Fmt.toDouble(m['qty']).round().clamp(0, 999999999);
        final e = Fmt.toDouble(m['qty_extra']).round().clamp(0, 999999999);
        ItemUnitOpt? matched;
        for (final u in line.orderUnits) {
          if (line.unitId != 0 && u.unitId == line.unitId) {
            matched = u;
            break;
          }
        }
        if (storedCarton) {
          line.qtyPiece = q;
          line.qtyCarton =
              Fmt.toDouble(m['qty_carton']).round().clamp(0, 999999999);
          line.extraPiece = e;
          line.extraCarton = Fmt.toDouble(m['qty_extra_carton'])
              .round()
              .clamp(0, 999999999);
        } else if (matched != null) {
          line.setQtyFor(matched, q, extra: false);
          line.setQtyFor(matched, e, extra: true);
        } else if (isCarton) {
          line.qtyCarton = q;
          line.extraCarton = e;
        } else {
          line.qtyPiece = q;
          line.extraPiece = e;
        }
        if (loaded.isNotEmpty && loaded.last.item.id == line.item.id) {
          final prev = loaded.last;
          if (matched != null && !storedCarton) {
            prev.setQtyFor(
              matched,
              prev.qtyFor(matched, extra: false) + q,
              extra: false,
            );
            prev.setQtyFor(
              matched,
              prev.qtyFor(matched, extra: true) + e,
              extra: true,
            );
          } else {
            prev.qtyPiece += line.qtyPiece;
            prev.qtyCarton += line.qtyCarton;
            prev.extraPiece += line.extraPiece;
            prev.extraCarton += line.extraCarton;
          }
        } else {
          loaded.add(line);
        }
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
        _deliveryDate = Fmt.str(order['delivery_date']).length >= 10
            ? Fmt.str(order['delivery_date']).substring(0, 10)
            : '';
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
        _prevId = prevId;
        _nextId = nextId;
        _firstId = firstId;
        _lastId = lastId;
        _loading = false;
      });
      // حقل البحث للبحث فقط — لا نكرر رقم المسودة المحلية OFF-* داخله
      final no = (_orderNo ?? '').trim();
      _orderNoCtrl.text =
          no.toUpperCase().startsWith('OFF-') ? '' : no;
      _notesCtrl.text = Fmt.str(order['notes']);
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
      showSnack(context, 'لا يوجد مستودع مرتبط بالمندوب.', error: true);
      return;
    }
    final item = await pickItem(context, warehouseId: _warehouseId);
    if (item == null || !mounted) return;
    final split = await _askQtySplit();
    if (split == null || !mounted) return;
    if (split.$1 <= 0 && split.$2 <= 0) {
      showSnack(context, 'أدخل كمية الكرتونة أو القطعة.', error: true);
      return;
    }
    setState(() => _lines.add(
          _OrderLine(
            item,
            taxRateId: _defaultTaxRateId,
            taxRatePercent: _defaultTaxPercent,
          )
            ..qtyCarton = split.$1
            ..qtyPiece = split.$2,
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
      'decimal_places':
          int.tryParse(await store.getMeta('decimal_places') ?? '') ?? 3,
      'invoice_unit_price_decimal_places': int.tryParse(
            await store.getMeta('invoice_unit_price_decimal_places') ?? '',
          ) ??
          3,
      'auto_send_orders':
          (await store.getMeta('auto_send_orders')) == '1' ? 1 : 0,
    };
  }

  /// بناء جسم الحفظ للإرسال لاحقاً عند الترحيل فقط.
  Future<Map<String, dynamic>> _buildSaveBody() async {
      final session = context.read<SessionController>();
      final body = <String, dynamic>{
      'id': _id > 0 ? _id : 0,
        'customer_id': _customer!.id,
        'warehouse_id': _warehouseId,
      'payment_type': _paymentType,
      'delivery_date': _deliveryDate,
      'notes': _notesCtrl.text.trim(),
        'lines': _lines.expand((l) => l.toSaveLines()).toList(),
      };
    final visitLine = widget.visitRouteLineId ?? 0;
    if (visitLine > 0) {
      body['visit_route_line_id'] = visitLine;
    } else if (visitLine < 0) {
      body['offline_visit'] = true;
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
    return body;
  }

  /// حفظ على الجهاز فقط — لا يُرسل للنظام حتى الترحيل.
  Future<int> _persistLocalDraft(Map<String, dynamic> body) async {
    final visitLine = widget.visitRouteLineId ?? 0;
    var localId = _id;
    if (localId == 0) {
      localId = await OfflineStore.instance.nextLocalOrderId();
    }
    final orderNo = (_orderNo != null && _orderNo!.isNotEmpty)
        ? _orderNo!
        : 'OFF-${DateTime.now().millisecondsSinceEpoch % 100000}';
    final saveBody = Map<String, dynamic>.from(body);
    saveBody['id'] = localId > 0 ? localId : 0;
    saveBody['local_order_id'] = localId;
    final lines = _lines.expand((l) => l.toSaveLines()).toList();
    final prev = await OfflineStore.instance.getOrderById(localId);
    final keepPending = prev != null &&
        (prev['pending_send'] == true ||
            prev['pending_send'] == 1 ||
            '${prev['pending_send']}' == '1');
    await OfflineStore.instance.upsertLocalOrder({
      'id': localId,
      'order_no': orderNo,
      'order_date': Fmt.todayIso(),
      'customer_id': _customer!.id,
      'customer_name': _customer!.name,
      'warehouse_id': _warehouseId,
      'warehouse_name': '',
      'status': 'draft',
      'is_sent': 0,
      if (keepPending) 'pending_send': 1,
      'total': 0,
      'line_count': lines.length,
      'lines': lines,
      'payment_type': _paymentType,
      'delivery_date': _deliveryDate,
      'notes': _notesCtrl.text.trim(),
      'pending_save_body': saveBody,
      if (visitLine != 0) 'visit_route_line_id': visitLine,
      if (visitLine < 0) 'offline_visit': true,
    });
      if (mounted) {
        setState(() {
        _id = localId;
        _orderNo = orderNo;
        _isSent = false;
      });
      // لا تملأ صندوق البحث برقم OFF المحلي
      if (!orderNo.toUpperCase().startsWith('OFF-')) {
        _orderNoCtrl.text = orderNo;
      }
      _markClean();
    }
    return localId;
  }

  void _afterPostedClear() {
    if (!mounted) return;
    setState(() {
      _id = 0;
      _orderNo = null;
      _orderDate = Fmt.todayIso();
      _deliveryDate = '';
      _orderNoCtrl.clear();
      _notesCtrl.clear();
      _lines.clear();
      _isSent = false;
      _approved = false;
      _prevId = _lastId;
      _nextId = 0;
      _visitRouteLineId = widget.visitRouteLineId ?? 0;
      _error = null;
    });
    _markClean();
    widget.onSaved?.call(0);
    widget.onPosted?.call();
  }

  Future<int> _save() async {
    if (!_editable) {
      showSnack(context, 'لا يمكن تعديل طلب معتمد.', error: true);
      return 0;
    }
    if (_warehouseId == 0) {
      showSnack(context, 'لا يوجد مستودع مرتبط بالمندوب.', error: true);
      return 0;
    }
    if (_customer == null || _lines.isEmpty) {
      showSnack(context, 'اختر العميل وأضف بنداً واحداً على الأقل.',
          error: true);
      return 0;
    }
    for (var i = 0; i < _lines.length; i++) {
      final ln = _lines[i];
      if (!ln.hasQty) {
        showSnack(
            context,
          'أدخل الكمية أو الكمية المجانية للبند رقم ${i + 1}.',
          error: true,
        );
        return 0;
      }
      if (ln.orderUnits.any((u) => ln.qtyFor(u, extra: false) > 0) &&
          ln.basePrice <= 0) {
        showSnack(
          context,
          'سعر المادة في البطاقة صفر. عدّله من شاشة تعديل الأسعار.',
          error: true,
        );
        return 0;
      }
    }
    setState(() => _busy = true);
    try {
      final offline = context.read<OfflineController>();
      if (!offline.online && !offline.catalogReady) {
        showSnack(
          context,
          'لا اتصال ولا بيانات محلية. حدّث البيانات وأنت متصل أولاً.',
          error: true,
        );
        return 0;
      }
      final body = await _buildSaveBody();
      if (!mounted) return 0;
      final localId = await _persistLocalDraft(body);
      if (mounted) {
        showSnack(
          context,
          'حُفظ الطلب على الجهاز في «غير المرسلة». اضغط «ترحيل» لإرساله إلى النظام.',
        );
        widget.onSaved?.call(localId);
      }
      return localId;
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
        'delivery_date': _deliveryDate,
        'notes': _notesCtrl.text.trim(),
        'lines': _lines
            .map((l) => {
                  'item_name': l.item.name,
                  'unit_name': l.orderUnits.map((u) => u.name).join(' / '),
                  'qty': l.orderUnits
                      .where((u) => l.qtyFor(u, extra: false) > 0)
                      .map((u) => '${l.qtyFor(u, extra: false)} ${u.name}')
                      .join(' + '),
                  'qty_piece': l.qtyPiece,
                  'qty_carton': l.qtyCarton,
                  'qty_extra': l.orderUnits
                      .where((u) => l.qtyFor(u, extra: true) > 0)
                      .map((u) => '${l.qtyFor(u, extra: true)} ${u.name}')
                      .join(' + '),
                  'unit_price': l.piecePrice,
                  'unit_price_inclusive': l.piecePriceInclusive,
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

  bool get _isSaved => _id != 0;

  /// الترحيل متاح طالما الطلب قابل للتعديل وفيه مواد ولم يُرحَّل بعد.
  /// عند الضغط: يُحفظ محلياً أولاً ثم يُرسل — الحفظ وحده لا يرسل للنظام.
  /// المدير يعتمد بعد الترحيل ولا يعيد «ترحيل».
  bool get _canPost =>
      !_managerMode &&
      _editable &&
      !_isSent &&
      !_approved &&
      _lines.isNotEmpty;

  bool get _canStartNew => _isSaved && !_busy;

  Future<void> _refreshBrowseNav({int orderId = 0}) async {
    try {
      final offline = context.read<OfflineController>();
      if (!offline.online) return;
      final query = <String, dynamic>{};
      if (orderId > 0) query['id'] = orderId;
      final cid = _customer?.id ?? widget.initialCustomerId ?? 0;
      if (cid > 0) query['customer_id'] = cid;
      final result = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderViewPath,
            query: query,
          );
      if (!mounted) return;
      setState(() {
        _prevId = Fmt.toInt(result['prev_id']);
        _nextId = Fmt.toInt(result['next_id']);
        _firstId = Fmt.toInt(result['first_id']);
        _lastId = Fmt.toInt(result['last_id']);
      });
    } catch (_) {
      /* التنقل اختياري */
    }
  }

  Future<void> _startNewOrder() async {
    if (!_canStartNew) return;
    if (isDirty) {
      final ok = await confirmLeave();
      if (!ok || !mounted) return;
    }
    setState(() {
      _id = 0;
      _orderNo = null;
      _orderDate = Fmt.todayIso();
      _deliveryDate = '';
      _orderNoCtrl.clear();
      _notesCtrl.clear();
      _lines.clear();
      _isSent = false;
      _approved = false;
      _prevId = _lastId;
      _nextId = 0;
      _visitRouteLineId = widget.visitRouteLineId ?? 0;
      _error = null;
    });
    _markClean();
    await _refreshBrowseNav();
    if (mounted) {
      showSnack(context, 'طلب جديد — أضف المواد ثم احفظ.');
    }
  }

  Future<Map<String, dynamic>> _resolveSaveBodyForPost() async {
    final stored = await OfflineStore.instance.getOrderById(_id);
    final pending = stored?['pending_save_body'];
    if (pending is Map) {
      final body = pending.cast<String, dynamic>();
      body['local_order_id'] = _id;
      body['id'] = _id > 0 ? _id : 0;
      return body;
    }
    return _buildSaveBody();
  }

  Future<void> _post() async {
    if (_busy) return;
    if (!_canPost) {
      if (_lines.isEmpty) {
        showSnack(context, 'أضف مادة واحدةً على الأقل قبل الترحيل.', error: true);
      }
      return;
    }
    if (_isSent) return;
    if (_customer == null || _warehouseId == 0) {
      showSnack(
        context,
        _warehouseId == 0
            ? 'لا يوجد مستودع مرتبط بالمندوب.'
            : 'اختر العميل قبل الترحيل.',
        error: true,
      );
      return;
    }
    for (var i = 0; i < _lines.length; i++) {
      final ln = _lines[i];
      if (!ln.hasQty) {
        showSnack(
          context,
          'أدخل الكمية أو الكمية المجانية للبند رقم ${i + 1}.',
          error: true,
        );
        return;
      }
    }
    setState(() => _busy = true);
    final offline = context.read<OfflineController>();
    final session = context.read<SessionController>();
    try {
      // احفظ على الجهاز أولاً (بدون إرسال) ثم رحّل — حتى لو كان الطلب جديداً أو معدّلاً.
      if (!_isSaved || isDirty) {
        final draftBody = await _buildSaveBody();
        final savedId = await _persistLocalDraft(draftBody);
        if (!mounted) return;
        if (savedId == 0) {
          showSnack(context, 'تعذر حفظ الطلب قبل الترحيل.', error: true);
          return;
        }
      }
      final body = await _resolveSaveBodyForPost();
      if (!mounted) return;

      // أوفلاين: طابور حفظ ثم ترحيل — يُرسل عند عودة الاتصال.
      if (!offline.online && offline.catalogReady) {
        body['id'] = _id > 0 ? _id : 0;
        body['local_order_id'] = _id;
        await offline.enqueue(
          kind: 'customer_order_save',
          path: AppConfig.customerOrderSavePath,
          body: body,
        );
        await OfflineStore.instance.markOrderSendRequested(_id);
        await offline.enqueue(
          kind: 'customer_order_send',
          path: AppConfig.customerOrderSendPath,
          body: {'ids': [_id]},
        );
        if (!mounted) return;
        showSnack(
          context,
          'تم طلب الترحيل — سيُرسل إلى النظام تلقائياً عند عودة الاتصال.',
        );
        _afterPostedClear();
        return;
      }

      // أونلاين: حفظ على السيرفر ثم ترحيل (is_sent=1).
      // إن كان المعرّف المحلي يشير لطلب حُذف من السيرفر نُنشئ طلباً جديداً.
      Map<String, dynamic> saveRes;
      try {
        saveRes = await context.read<ApiClient>().postJson(
              AppConfig.customerOrderSavePath,
              csrf: session.csrf,
              body: {
                ...body,
                'id': _id > 0 ? _id : 0,
                'local_order_id': _id,
              },
            );
      } on ApiException catch (e) {
        final missing = e.message.contains('الطلب غير موجود');
        if (!missing || _id <= 0) rethrow;
        final visitLine = widget.visitRouteLineId ?? 0;
        saveRes = await context.read<ApiClient>().postJson(
              AppConfig.customerOrderSavePath,
              csrf: session.csrf,
              body: {
                ...body,
                'id': 0,
                'local_order_id': _id,
                if (visitLine > 0) 'visit_route_line_id': visitLine,
              },
            );
      }
      final serverId = Fmt.toInt(saveRes['order_id'] ?? saveRes['id']);
      final localId = _id;
      if (localId != 0 && serverId > 0 && localId != serverId) {
        await OfflineStore.instance.rewriteLocalOrderId(
          localId: localId,
          serverId: serverId,
          orderNo: Fmt.str(saveRes['order_no']),
        );
      }
      final sendId = serverId > 0 ? serverId : localId;
      if (sendId <= 0) {
        throw ApiException('تعذر حفظ الطلب قبل الترحيل.');
      }
      final res = await context.read<ApiClient>().postJson(
            AppConfig.customerOrderSendPath,
            body: {'ids': [sendId]},
            csrf: session.csrf,
          );
      await OfflineStore.instance.markOrderSent([sendId]);
      if (localId != 0 && localId != sendId) {
        await OfflineStore.instance.markOrderSent([localId]);
      }
      if (!mounted) return;
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty
            ? 'تم ترحيل الطلب إلى النظام.'
            : Fmt.str(res['message']),
      );
      _afterPostedClear();
    } on ApiException catch (e) {
      if (offline.catalogReady &&
          (e.message.contains('تعذر الاتصال') ||
              e.message.contains('الإنترنت'))) {
        try {
          final body = await _resolveSaveBodyForPost();
          body['id'] = _id > 0 ? _id : 0;
          body['local_order_id'] = _id;
          await offline.enqueue(
            kind: 'customer_order_save',
            path: AppConfig.customerOrderSavePath,
            body: body,
          );
          await OfflineStore.instance.markOrderSendRequested(_id);
          await offline.enqueue(
            kind: 'customer_order_send',
            path: AppConfig.customerOrderSendPath,
            body: {'ids': [_id]},
          );
          if (mounted) {
            showSnack(
              context,
              'انقطع الاتصال — سيُرحَّل الطلب تلقائياً عند عودة الشبكة.',
            );
            _afterPostedClear();
          }
        } catch (_) {
          if (mounted) showSnack(context, e.message, error: true);
        }
      } else if (mounted) {
        showSnack(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    if (!_canDelete) return;
    final ok = await showAppConfirmDialog(
      context,
      title: 'حذف الطلب',
      message: _id > 0
          ? 'هل تريد حذف هذا الطلب نهائياً؟'
          : 'هل تريد مسح مواد الطلب الحالي؟',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (ok != true || !mounted) return;
    if (_id < 1) {
      if (_id < 0) {
        await OfflineStore.instance.deleteLocalOrder(_id);
      }
      if (!mounted) return;
      setState(() {
        _id = 0;
        _lines.clear();
        _orderNo = '';
        _orderNoCtrl.clear();
        _deliveryDate = '';
        _notesCtrl.clear();
        _isSent = false;
        _approved = false;
      });
      _markClean();
      widget.onDeleted?.call();
      showSnack(context, 'تم حذف الطلب من الجهاز.');
      return;
    }
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
        _deliveryDate = '';
        _notesCtrl.clear();
        _lines.clear();
        _isSent = false;
        _approved = false;
      });
      _markClean();
    } on ApiException catch (e) {
      final missing = e.message.contains('الطلب غير موجود');
      if (missing && _id != 0) {
        await OfflineStore.instance.deleteLocalOrder(_id);
        if (!mounted) return;
        showSnack(context, 'الطلب غير موجود على النظام — حُذف من الجهاز.');
        widget.onDeleted?.call();
        setState(() {
          _id = 0;
          _orderNo = '';
          _orderNoCtrl.clear();
          _deliveryDate = '';
          _notesCtrl.clear();
          _lines.clear();
          _isSent = false;
          _approved = false;
        });
        _markClean();
        return;
      }
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
          const SizedBox(width: 8),
          if (_canManagerApprove)
            _actionBtn(
              label: 'اعتماد',
              icon: Icons.verified_rounded,
              filled: true,
              color: AppTheme.success,
              onPressed: _busy ? null : _approveAsManager,
            )
          else if (!_managerMode)
            _actionBtn(
              label: 'ترحيل',
              icon: Icons.send_rounded,
              filled: true,
              color: AppTheme.teal,
              onPressed: _busy || !_canPost ? null : _post,
            ),
          if (!_managerMode) ...[
            const SizedBox(width: 8),
            _actionBtn(
              label: 'طلب جديد',
              icon: Icons.note_add_outlined,
              onPressed: _canStartNew ? _startNewOrder : null,
            ),
          ],
          const SizedBox(width: 8),
          _actionBtn(
            label: 'طباعة',
            icon: Icons.print_outlined,
            onPressed: _busy ? null : _print,
          ),
          if (_canDelete) ...[
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
                  title: 'عرض الطلب',
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
          customerId: _customer?.id ?? widget.initialCustomerId,
          limit: 30,
        );
      } else {
        try {
          final query = <String, dynamic>{'q': q, 'page': 1};
          final cid = _customer?.id ?? widget.initialCustomerId ?? 0;
          if (cid > 0) query['customer_id'] = cid;
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
    return DeviceLayout.isTablet(context);
  }

  double get _orderGross =>
      _lines.fold<double>(0, (s, l) => s + l.lineGross);

  Future<void> _pickDeliveryDate() async {
    if (!_editable || _busy) return;
    final now = DateTime.now();
    DateTime initial = now;
    if (_deliveryDate.length >= 10) {
      final p = DateTime.tryParse(_deliveryDate);
      if (p != null) initial = p;
    }
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 3),
      helpText: 'تاريخ التسليم',
      cancelText: 'إلغاء',
      confirmText: 'موافق',
    );
    if (picked == null || !mounted) return;
    setState(() {
      _deliveryDate =
          '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
    });
  }

  Future<void> _editNotes() async {
    if (!mounted) return;
    final canEdit = _editable && !_busy;
    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) {
        return AlertDialog(
          insetPadding: const EdgeInsets.symmetric(horizontal: 28, vertical: 12),
          title: const Text(
            'ملاحظات الطلب',
            style: TextStyle(fontWeight: FontWeight.w900),
          ),
          content: SizedBox(
            width: 560,
            child: TextField(
              controller: _notesCtrl,
              autofocus: canEdit,
              enabled: canEdit,
              minLines: 5,
              maxLines: 8,
              keyboardType: TextInputType.multiline,
              textInputAction: TextInputAction.newline,
                                  style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w600,
                height: 1.45,
              ),
              decoration: InputDecoration(
                hintText: 'اكتب الملاحظات هنا…',
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
            ),
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(canEdit ? 'تم' : 'إغلاق'),
            ),
          ],
        );
      },
    );
    if (mounted) setState(() {});
  }

  Future<void> _browseToOrder(int targetId) async {
    if (targetId < 1 || targetId == _id || _busy) return;
    if (isDirty) {
      final ok = await showAppConfirmDialog(
        context,
        title: 'فتح طلب آخر',
        message: 'هناك تعديلات غير محفوظة. المتابعة وفتح الطلب؟',
        confirmLabel: 'فتح',
      );
      if (ok != true || !mounted) return;
    }
    setState(() => _id = targetId);
    await _load();
    if (mounted) widget.onSaved?.call(targetId);
  }

  String get _orderStatusLabel {
    if (_id == 0 && (_orderNo == null || _orderNo!.isEmpty)) return 'جديد';
    if (_approved) return 'معتمد';
    if (_isSent) return 'مُرحَّل';
    if (_id != 0 || (_orderNo != null && _orderNo!.isNotEmpty)) return 'محفوظ';
    return 'جديد';
  }

  Color get _orderStatusColor {
    if (_approved) return const Color(0xFF15803D);
    if (_isSent) return const Color(0xFF1D4ED8);
    if (_id != 0 || (_orderNo != null && _orderNo!.isNotEmpty)) {
      return const Color(0xFFB45309);
    }
    return const Color(0xFF64748B);
  }

  Widget _wideSummaryBar() {
    final balance = Fmt.toDouble(_arSummary?['balance']);
    final cheques = Fmt.toDouble(_arSummary?['cheque_total']);
    return Container(
      width: double.infinity,
      color: const Color(0xFFE8E8E8),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: InkWell(
              onTap: () => _showArDetails(focusCheques: false),
              child: _sumCell(
                'رصيد',
                Fmt.money(balance),
                const Color(0xFFD32F2F),
              ),
            ),
          ),
          Expanded(
            child: InkWell(
              onTap: () => _showArDetails(focusCheques: true),
              child: _sumCell(
                'شيكات',
                Fmt.money(cheques),
                const Color(0xFFF57C00),
              ),
            ),
          ),
          Expanded(
            child: _sumCell(
              'المجموع',
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
    const btnH = 34.0;
    Widget btn(String label, Color color, VoidCallback? onPressed) {
      return Expanded(
        child: SizedBox(
          height: btnH,
          child: FilledButton(
            onPressed: onPressed,
            style: FilledButton.styleFrom(
              backgroundColor: color,
              padding: const EdgeInsets.symmetric(horizontal: 6),
            ),
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5),
            ),
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(6, 2, 6, 6),
      child: Row(
        children: [
          if (_canDelete) ...[
            btn('حذف', const Color(0xFFE0453C), _busy ? null : _delete),
            const SizedBox(width: 6),
          ],
          btn(
            'حفظ',
            const Color(0xFFFF9800),
            _busy || !_editable ? null : _save,
          ),
          const SizedBox(width: 6),
          if (_canManagerApprove)
            btn('اعتماد', AppTheme.success, _busy ? null : _approveAsManager)
          else if (!_managerMode)
            btn(
              'ترحيل',
              const Color(0xFF4CAF50),
              _busy || !_canPost ? null : _post,
            ),
          if (!_managerMode) ...[
            const SizedBox(width: 6),
            btn('طلب جديد', AppTheme.primary, _canStartNew ? _startNewOrder : null),
          ],
        ],
      ),
    );
  }

  Widget _buildWideOrderBody() {
    return ColoredBox(
      color: const Color(0xFFF5F5F5),
      child: Column(
        children: [
          _wideTopToolbar(),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(6, 0, 6, 4),
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
          ),
          _wideSummaryBar(),
          _wideActionBar(),
        ],
      ),
    );
  }

  /// شريط علوي مضغوط: كل الحقول ظاهرة بدون تمرير
  /// رقم النظام فقط (ليس مسودة OFF المحلية)
  String get _systemOrderNo {
    final no = (_orderNo ?? '').trim();
    if (no.isEmpty || no.toUpperCase().startsWith('OFF-')) return '';
    return no;
  }

  Widget _wideTopToolbar() {
    const fieldH = 32.0;
    const gap = 4.0;
    final date = _orderDate.isEmpty ? Fmt.todayIso() : _orderDate;
    final sysNo = _systemOrderNo;
    final showCustomer = !widget.hideCustomerPicker;
    final hasLeading = widget.toolbarLeading != null;
    // عند وجود شريط مسودات خارجي لا نكرر شارة «مسودة»
    final showStatusChip = !hasLeading || sysNo.isNotEmpty;

    Widget chip(String text, {Color? bg, Color? fg, Color? border}) {
      return Container(
        height: fieldH,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: bg ?? const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: border ?? AppTheme.border),
        ),
        child: Text(
          text,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textDirection: TextDirection.ltr,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w900,
            color: fg ?? AppTheme.textMain,
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(6, 4, 6, 4),
      child: Material(
        color: Colors.white,
        elevation: 1,
        borderRadius: BorderRadius.circular(8),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(6, 4, 6, 4),
          child: SizedBox(
            height: fieldH,
            child: Row(
              children: [
                if (hasLeading) ...[
                  ConstrainedBox(
                    constraints: BoxConstraints(
                      maxWidth: MediaQuery.sizeOf(context).width * 0.30,
                    ),
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: widget.toolbarLeading!,
                    ),
                  ),
                  const SizedBox(width: gap),
                ],
                if (sysNo.isNotEmpty) ...[
                  chip(
                    sysNo,
                    bg: const Color(0xFFECFDF5),
                    fg: const Color(0xFF065F46),
                    border: const Color(0xFF34D399),
                  ),
                  const SizedBox(width: gap),
                ],
                if (showStatusChip) ...[
                  chip(
                    sysNo.isEmpty && _id != 0 ? 'مسودة' : _orderStatusLabel,
                    bg: _orderStatusColor.withValues(alpha: 0.12),
                    fg: _orderStatusColor,
                    border: _orderStatusColor.withValues(alpha: 0.35),
                  ),
                  const SizedBox(width: gap),
                ],
                SizedBox(
                  width: 100,
                  child: TextField(
                    controller: _orderNoCtrl,
                    enabled: !_busy,
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                    ),
                    decoration: InputDecoration(
                      hintText: 'رقم الطلب',
                      hintStyle: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: AppTheme.textSoft,
                      ),
                      isDense: true,
                      filled: true,
                      fillColor: const Color(0xFFF8FAFC),
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 6,
                        vertical: 7,
                      ),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(6),
                      ),
                    ),
                    onSubmitted: (_) {
                      if (!_busy) _searchOrderByNo();
                    },
                  ),
                ),
                const SizedBox(width: 3),
                SizedBox(
                  height: fieldH,
                  width: 32,
                  child: FilledButton(
                    onPressed: _busy ? null : _searchOrderByNo,
                    style: FilledButton.styleFrom(
                      backgroundColor: const Color(0xFF2563EB),
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(32, fieldH),
                    ),
                    child: const Icon(Icons.search_rounded, size: 16),
                  ),
                ),
                const SizedBox(width: gap),
                SizedBox(
                  width: 118,
                  child: _wideInlineLabelValue('التاريخ', Fmt.dmy(date)),
                ),
                const SizedBox(width: gap),
                SizedBox(
                  width: 100,
                  child: _wideInlineDateTap(
                    label: _deliveryDate.isEmpty
                        ? 'التسليم'
                        : Fmt.dmy(_deliveryDate),
                    empty: _deliveryDate.isEmpty,
                    onTap: _busy || !_editable ? null : _pickDeliveryDate,
                  ),
                ),
                if (showCustomer) ...[
                  const SizedBox(width: gap),
                  Expanded(
                    child: _wideCustomerInline(fieldHeight: fieldH),
                  ),
                ],
                const SizedBox(width: gap),
                SizedBox(
                  width: showCustomer ? 110 : 140,
                  child: _wideInlineDateTap(
                    label: () {
                      final t = _notesCtrl.text.trim();
                      if (t.isEmpty) {
                        return _editable ? 'ملاحظات…' : 'بدون ملاحظات';
                      }
                      return t;
                    }(),
                    empty: _notesCtrl.text.trim().isEmpty,
                    icon: Icons.edit_note_rounded,
                    onTap: _busy ? null : _editNotes,
                  ),
                ),
                const SizedBox(width: gap),
                SizedBox(
                  height: fieldH,
                  child: FilledButton.icon(
                    onPressed: _busy || !_editable ? null : _addLine,
                    style: FilledButton.styleFrom(
                      backgroundColor: const Color(0xFF2196F3),
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      minimumSize: const Size(0, fieldH),
                    ),
                    icon: const Icon(Icons.add_rounded, size: 16),
                    label: const Text(
                      'مادة',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 3),
                SizedBox(
                  height: fieldH,
                  width: 32,
                  child: OutlinedButton(
                    onPressed: _busy ? null : _print,
                    style: OutlinedButton.styleFrom(
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(32, fieldH),
                    ),
                    child: const Icon(Icons.print_outlined, size: 15),
                  ),
                ),
                const SizedBox(width: 3),
                SizedBox(
                  height: fieldH,
                  width: 32,
                  child: OutlinedButton(
                    onPressed: _busy ? null : _openPdf,
                    style: OutlinedButton.styleFrom(
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(32, fieldH),
                    ),
                    child: const Icon(
                      Icons.picture_as_pdf_outlined,
                      size: 15,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _wideInlineLabelValue(String label, String value) {
    return Container(
      height: 32,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: AppTheme.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            '$label: ',
            style: const TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: AppTheme.textSoft,
            ),
          ),
          Text(
            value,
            style: const TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }

  Widget _wideInlineDateTap({
    required String label,
    required bool empty,
    VoidCallback? onTap,
    IconData icon = Icons.event_rounded,
  }) {
    return Material(
      color: Colors.white,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(6),
          child: Container(
          height: 32,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(6),
            border: Border.all(color: AppTheme.border),
          ),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: empty ? AppTheme.textSoft : AppTheme.textMain,
                  ),
                ),
              ),
              Icon(icon, size: 16, color: AppTheme.textSoft),
            ],
          ),
        ),
      ),
    );
  }

  Widget _wideCustomerInline({double fieldHeight = 32}) {
    final canPick = _editable && !widget.hideCustomerPicker;
    final hasCustomer = _customer != null;
    final name = hasCustomer ? _customer!.name : 'اختر العميل…';
    final code = (_customer?.code ?? '').trim();
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: canPick ? _pickCustomer : null,
        borderRadius: BorderRadius.circular(6),
        child: Container(
          height: fieldHeight,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(
            color: hasCustomer ? const Color(0xFFF0F7FF) : Colors.white,
            borderRadius: BorderRadius.circular(6),
            border: Border.all(
              color: hasCustomer ? const Color(0xFF90CAF9) : AppTheme.border,
            ),
          ),
          child: Row(
            children: [
              const Icon(
                Icons.storefront_rounded,
                size: 16,
                color: Color(0xFF1565C0),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  code.isEmpty ? name : '$name  ($code)',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w800,
                    color: hasCustomer ? AppTheme.textMain : AppTheme.textSoft,
                  ),
                ),
              ),
              if (canPick)
                const Icon(
                  Icons.chevron_left_rounded,
                  size: 18,
                  color: AppTheme.textSoft,
                ),
            ],
          ),
        ),
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
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: ConstrainedBox(
              constraints: BoxConstraints(minWidth: metrics.minWidth),
              child: table,
            ),
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

  Future<(int, int)?> _askQtySplit() async {
    // بدون كيبورد النظام — لوحة أرقام داخل التطبيق حتى لا يختفي الحقل.
    var carton = 0;
    var piece = 0;
    final result = await showDialog<(int, int)>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (ctx, setLocal) {
            Future<void> editField({
              required String title,
              required int current,
              required void Function(int v) apply,
            }) async {
              final v = await showNumericKeypadDialog(
                ctx,
                title: title,
                initial: current > 0 ? '$current' : '',
                decimal: false,
              );
              if (v == null) return;
              apply(int.tryParse(v.trim())?.clamp(0, 999999999) ?? 0);
              setLocal(() {});
            }

            Widget qtyTap({
              required String label,
              required int value,
              required VoidCallback onTap,
            }) {
              final filled = value > 0;
              return Material(
                color: filled
                    ? const Color(0xFFF0F9FF)
                    : const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(10),
                child: InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: onTap,
                  child: Container(
                    height: 88,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(
                        color: filled
                            ? const Color(0xFF60A5FA)
                            : const Color(0xFFCBD5E1),
                        width: 1.5,
                      ),
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          label,
                          style: const TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF64748B),
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          filled ? '$value' : 'اضغط للإدخال',
                          textDirection: TextDirection.ltr,
                          style: TextStyle(
                            fontSize: filled ? 28 : 14,
                            fontWeight: FontWeight.w900,
                            color: filled
                                ? const Color(0xFF0F172A)
                                : const Color(0xFF94A3B8),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            }

            return AlertDialog(
              insetPadding:
                  const EdgeInsets.symmetric(horizontal: 40, vertical: 20),
              title: const Text(
                'كمية المادة',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
              content: SizedBox(
                width: 440,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Text(
                      'اضغط على كرتونة أو قطعة لإدخال الكمية من لوحة الأرقام',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 13,
                        color: AppTheme.textSoft,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: qtyTap(
                            label: 'كرتونة',
                            value: carton,
                            onTap: () => editField(
                              title: 'كمية كرتونة',
                              current: carton,
                              apply: (v) => carton = v,
                            ),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: qtyTap(
                            label: 'قطعة',
                            value: piece,
                            onTap: () => editField(
                              title: 'كمية قطعة',
                              current: piece,
                              apply: (v) => piece = v,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('إلغاء'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(ctx, (carton, piece)),
                  child: const Text('موافق'),
                ),
              ],
            );
          },
        );
      },
    );
    return result;
  }

  Widget _qtyCell({
    required double width,
    required String title,
    required int value,
    required TextStyle style,
    required _LineTableMetrics m,
    required bool enabled,
    required void Function(int value) apply,
    Color? tint,
  }) {
    final filled = value > 0;
    return SizedBox(
      width: width,
      child: Material(
        color: tint ??
            (filled ? const Color(0xFFF0F9FF) : const Color(0xFFF8FAFC)),
        borderRadius: BorderRadius.circular(6),
        child: InkWell(
          borderRadius: BorderRadius.circular(6),
          onTap: !enabled
              ? null
              : () => _editLineNumber(
                    title: title,
                    initial: value > 0 ? '$value' : '',
                    decimal: false,
                    apply: (v) {
                      apply(int.tryParse(v)?.clamp(0, 999999999) ?? 0);
                    },
                  ),
          child: Container(
            alignment: Alignment.center,
            padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 2),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(5),
              border: Border.all(
                color: filled
                    ? const Color(0xFF93C5FD)
                    : const Color(0xFFCBD5E1),
              ),
            ),
            child: Text(
              filled ? '$value' : '—',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              textDirection: TextDirection.ltr,
              style: style.copyWith(
                fontWeight: FontWeight.w900,
                fontSize: m.font,
                height: 1.05,
                color: filled
                    ? const Color(0xFF0F172A)
                    : const Color(0xFF94A3B8),
              ),
            ),
          ),
        ),
      ),
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

  Widget _priceCell({
    required double width,
    required double value,
    required TextStyle style,
    bool inclusive = false,
  }) {
    // غير شامل: خانات سعر الوحدة | شامل: خانات مبالغ النظام (مثل ويندوز)
    final text = value > 0
        ? (inclusive ? Fmt.money(value).replaceAll(',', '') : Fmt.unitPrice(value))
        : '—';
    return SizedBox(
      width: width,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 1),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: inclusive ? const Color(0xFFECFDF5) : const Color(0xFFF8FAFC),
          borderRadius: BorderRadius.circular(4),
          border: Border.all(
            color: inclusive
                ? const Color(0xFFA7F3D0)
                : const Color(0xFFE2E8F0),
          ),
        ),
        child: Text(
          text,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.center,
          textDirection: TextDirection.ltr,
          style: style.copyWith(
            fontSize: (style.fontSize ?? 12) - 0.5,
            fontWeight: FontWeight.w900,
            height: 1.05,
            color: inclusive
                ? const Color(0xFF0F766E)
                : const Color(0xFF334155),
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
        headingRowHeight: compact ? 28 : 36,
        dataRowMinHeight: compact ? 30 : 40,
        dataRowMaxHeight: compact ? 34 : 48,
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
          DataColumn(
              label: _tableHead('تعبئة', m.pack, m.heading, center: true)),
          DataColumn(
              label: _tableHead('كرتونة', m.carton, m.heading, center: true)),
          DataColumn(
              label: _tableHead('قطعة', m.piece, m.heading, center: true)),
          DataColumn(
              label:
                  _tableHead('مج.ك', m.freeCarton, m.heading, center: true)),
          DataColumn(
              label:
                  _tableHead('مج.ق', m.freePiece, m.heading, center: true)),
          DataColumn(
              label: _tableHead('ق غ.ش', m.pricePieceEx, m.heading,
                  center: true)),
          DataColumn(
              label: _tableHead('ق ش', m.pricePieceInc, m.heading,
                  center: true)),
          DataColumn(
              label: _tableHead('ك غ.ش', m.priceCartonEx, m.heading,
                  center: true)),
          DataColumn(
              label: _tableHead('ك ش', m.priceCartonInc, m.heading,
                  center: true)),
          DataColumn(
              label: _tableHead('خصم%', m.disc, m.heading, center: true)),
          DataColumn(
              label: _tableHead('المجموع', m.total, m.heading, center: true)),
          DataColumn(label: SizedBox(width: m.del)),
        ],
        rows: [
          for (var i = 0; i < _lines.length; i++)
            DataRow(cells: [
              DataCell(
                SizedBox(
                  width: m.item,
                  child: Tooltip(
                    message: _lines[i].item.name,
                    child: Text(
                      _lines[i].item.name,
                      maxLines: compact ? 1 : 2,
                      softWrap: !compact,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: m.font,
                        height: 1.05,
                      ),
                    ),
                  ),
                ),
              ),
              DataCell(
                SizedBox(
                  width: m.pack,
                  child: Container(
                    alignment: Alignment.center,
                    padding: const EdgeInsets.symmetric(vertical: 2, horizontal: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF7ED),
                      borderRadius: BorderRadius.circular(4),
                      border: Border.all(color: const Color(0xFFFDBA74)),
                    ),
                    child: Text(
                      _lines[i].packSize > 0 ? '${_lines[i].packSize}' : '—',
                      textAlign: TextAlign.center,
                      textDirection: TextDirection.ltr,
                      style: cellStyle.copyWith(
                        fontWeight: FontWeight.w900,
                        color: const Color(0xFF9A3412),
                      ),
                    ),
                  ),
                ),
              ),
              DataCell(
                _qtyCell(
                  width: m.carton,
                  title: 'كمية كرتونة',
                  value: _lines[i].qtyCarton,
                  style: cellStyle,
                  m: m,
                  enabled: _editable,
                  apply: (v) => _lines[i].qtyCarton = v,
                ),
              ),
              DataCell(
                _qtyCell(
                  width: m.piece,
                  title: 'كمية قطعة',
                  value: _lines[i].qtyPiece,
                  style: cellStyle,
                  m: m,
                  enabled: _editable,
                  apply: (v) => _lines[i].qtyPiece = v,
                ),
              ),
              DataCell(
                _qtyCell(
                  width: m.freeCarton,
                  title: 'مجاني كرتونة',
                  value: _lines[i].extraCarton,
                  style: cellStyle,
                  m: m,
                  enabled: _editable,
                  tint: const Color(0xFFF0FDF4),
                  apply: (v) => _lines[i].extraCarton = v,
                ),
              ),
              DataCell(
                _qtyCell(
                  width: m.freePiece,
                  title: 'مجاني قطعة',
                  value: _lines[i].extraPiece,
                  style: cellStyle,
                  m: m,
                  enabled: _editable,
                  tint: const Color(0xFFF0FDF4),
                  apply: (v) => _lines[i].extraPiece = v,
                ),
              ),
              DataCell(
                _priceCell(
                  width: m.pricePieceEx,
                  value: _lines[i].piecePrice,
                  style: cellStyle,
                ),
              ),
              DataCell(
                _priceCell(
                  width: m.pricePieceInc,
                  value: _lines[i].piecePriceInclusive,
                  style: cellStyle,
                  inclusive: true,
                ),
              ),
              DataCell(
                _priceCell(
                  width: m.priceCartonEx,
                  value: _lines[i].cartonPrice,
                  style: cellStyle,
                ),
              ),
              DataCell(
                _priceCell(
                  width: m.priceCartonInc,
                  value: _lines[i].cartonPriceInclusive,
                  style: cellStyle,
                  inclusive: true,
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
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: m.font,
                      color: const Color(0xFF0F766E),
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

  Widget _tableHead(String label, double width, double font,
      {bool center = false}) {
    return SizedBox(
      width: width,
      child: Text(
        label,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        textAlign: center ? TextAlign.center : TextAlign.start,
        style: TextStyle(
          fontSize: font,
          fontWeight: FontWeight.w900,
          color: const Color(0xFF475569),
          height: 1.1,
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
                child: (!widget.hideCustomerPicker || _customer != null)
                    ? _orderHeaderField(
                        label: 'العميل',
                        child: _orderHeaderCustomer(),
                      )
                    : const SizedBox.shrink(),
              ),
            const SizedBox(height: 8),
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'تاريخ التسليم',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppTheme.textSoft,
                    ),
                  ),
                  const SizedBox(height: 6),
                  InkWell(
                    onTap: _busy || !_editable ? null : _pickDeliveryDate,
                    borderRadius: BorderRadius.circular(8),
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        hintText: 'اختر تاريخ التسليم…',
                        isDense: true,
                        suffixIcon: Icon(Icons.event_rounded),
                      ),
                      child: Text(
                        _deliveryDate.isEmpty
                            ? 'اختر تاريخ التسليم…'
                            : Fmt.dmy(_deliveryDate),
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          color: _deliveryDate.isEmpty
                              ? AppTheme.textSoft
                              : AppTheme.textMain,
                        ),
                      ),
                    ),
                  ),
                const SizedBox(height: 12),
                  const Text(
                    'ملاحظات',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppTheme.textSoft,
                    ),
                  ),
                  const SizedBox(height: 6),
                  TextField(
                    controller: _notesCtrl,
                    enabled: _editable && !_busy,
                    minLines: 2,
                    maxLines: 4,
                    decoration: const InputDecoration(
                      hintText: 'اختياري… تظهر على الطلب بعد الترحيل',
                      isDense: true,
                    ),
                  ),
                ],
              ),
            ),
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
    double fieldHeight = 38,
    double labelSize = 11.5,
  }) {
    final field = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontSize: labelSize,
            fontWeight: FontWeight.w700,
            color: AppTheme.textSoft,
          ),
        ),
        const SizedBox(height: 2),
        SizedBox(height: fieldHeight, child: child),
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
                    fontSize: 14,
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
                  size: 24,
                  color: AppTheme.textSoft,
                ),
            ],
          ),
        ),
      ),
    );
  }
}
