import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../services/customer_order_bluetooth_receipt.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/item_picker.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/thermal_preview_screen.dart';
import '../../widgets/ui_kit.dart';

class CustomerOrderFormScreen extends StatefulWidget {
  const CustomerOrderFormScreen({
    super.key,
    this.orderId,
    this.initialCustomerId,
    this.initialCustomerName,
    this.initialCustomerCode,
  });
  final int? orderId;
  final int? initialCustomerId;
  final String? initialCustomerName;
  final String? initialCustomerCode;

  @override
  State<CustomerOrderFormScreen> createState() =>
      _CustomerOrderFormScreenState();
}

class _OrderLine {
  _OrderLine(this.item) {
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

  String get barcode => item.barcode;

  double get lineBase => qty * unitPrice;
  double get discountAmount =>
      discountPct <= 0 ? 0 : lineBase * (discountPct.clamp(0, 100) / 100);
  double get lineTotal => (lineBase - discountAmount).clamp(0, double.infinity);

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
        'discount_pct': discountPct,
        'line_discount_input':
            discountPct > 0 ? '${Fmt.trimNum(discountPct)}%' : '',
      };

  void applyUnit(ItemUnitOpt? u, {int? unitIdOverride, String? unitNameOverride}) {
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

class _CustomerOrderFormScreenState extends State<CustomerOrderFormScreen> {
  bool _loading = true, _busy = false, _approved = false;
  String? _error, _orderNo;
  int _id = 0, _warehouseId = 0;
  List<Map<String, dynamic>> _warehouses = [];
  Party? _customer;
  final _lines = <_OrderLine>[];
  Map<String, dynamic>? _arSummary;
  bool _arLoading = false;
  String? _arError;

  bool get _editable => !_approved;

  @override
  void initState() {
    super.initState();
    _id = widget.orderId ?? 0;
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final api = context.read<ApiClient>();
      final meta = await api.getJson(AppConfig.customerOrderMetaPath);
      Map<String, dynamic> order = {};
      if (_id > 0) {
        final result = await api
            .getJson(AppConfig.customerOrderViewPath, query: {'id': _id});
        order = (result['order'] as Map?)?.cast<String, dynamic>() ?? result;
      }
      if (!mounted) return;
      final ws = (meta['warehouses'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
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
          barcode: Fmt.str(m['item_barcode'] ?? m['barcode'] ?? m['sku'] ?? m['item_code']),
          units: units,
        );
        final line = _OrderLine(item)
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
        _lines
          ..clear()
          ..addAll(loaded);
        _loading = false;
      });
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
    setState(() => _lines.add(_OrderLine(item)));
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
        'lines': _lines.map((l) => l.toJson()).toList(),
      };
      if (session.gpsConfig.repVisitGeofence) {
        final gps = await LocationService.requirePosition();
        body['latitude'] = gps.latitude;
        body['longitude'] = gps.longitude;
        body['gps_accuracy'] = gps.accuracy;
        body['gps_source'] = 'mobile';
      }
      if (!mounted) return 0;
      final result = await context.read<ApiClient>().postJson(
        AppConfig.customerOrderSavePath,
        csrf: session.csrf,
        body: body,
      );
      final id = Fmt.toInt(result['order_id'] ?? result['id']);
      if (mounted) {
        setState(() {
          _id = id == 0 ? _id : id;
          _orderNo = Fmt.str(result['order_no']) == ''
              ? _orderNo
              : Fmt.str(result['order_no']);
        });
        showSnack(
            context,
            Fmt.str(result['message']).isEmpty
                ? 'تم حفظ الطلب.'
                : Fmt.str(result['message']));
      }
      return _id;
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

  Map<String, dynamic> _printData() => {
        'order_no': _orderNo,
        'customer_name': _customer?.name,
        'warehouse_name': _warehouses
            .where((w) => Fmt.toInt(w['id']) == _warehouseId)
            .map((w) => Fmt.str(w['name'] ?? w['name_ar']))
            .cast<String>()
            .followedBy(const [''])
            .first,
        'lines': _lines
            .map((l) => {
                  'item_name': l.item.name,
                  'item_barcode': l.barcode,
                  'barcode': l.barcode,
                  'unit_name': l.unitName,
                  'qty': l.qty,
                  'qty_extra': l.qtyExtra,
                  'unit_price': l.unitPrice,
                  'discount_pct': l.discountPct,
                  'line_total': l.lineTotal,
                })
            .toList(),
      };

  Future<void> _view() async {
    if (_id == 0 && await _save() == 0) return;
    if (mounted) context.push('/customer-orders/$_id');
  }

  Future<void> _print() async {
    if (_id == 0 && await _save() == 0) return;
    if (!mounted) return;
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

  @override
  Widget build(BuildContext context) => MobileScaffold(
        title: Text(_id > 0 ? 'تعديل طلب شراء' : 'طلب شراء جديد'),
        body: AsyncView(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView(
              padding: const EdgeInsets.all(14),
              children: [
                DocumentHeaderCard(
                    title: _orderNo == null || _orderNo!.isEmpty
                        ? 'بيانات الطلب'
                        : 'الطلب $_orderNo',
                    child: Column(children: [
                      DropdownButtonFormField<int>(
                          initialValue: _warehouseId == 0 ? null : _warehouseId,
                          decoration:
                              const InputDecoration(labelText: 'المستودع'),
                          items: _warehouses
                              .map((w) => DropdownMenuItem(
                                  value: Fmt.toInt(w['id']),
                                  child:
                                      Text(Fmt.str(w['name'] ?? w['name_ar']))))
                              .toList(),
                          onChanged: _editable
                              ? (v) => setState(() => _warehouseId = v ?? 0)
                              : null),
                      const SizedBox(height: 10),
                      ListTile(
                          onTap: _editable ? _pickCustomer : null,
                          contentPadding: EdgeInsets.zero,
                          title: const Text('العميل'),
                          subtitle: Text(_customer?.name ?? 'اضغط للاختيار'),
                          trailing: const Icon(Icons.chevron_left_rounded)),
                    ])),
                if (_customer != null) ...[
                  const DocumentSectionDivider('ملخص حساب العميل (Oracle)'),
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
                                  const SizedBox(height: 8),
                                  SizedBox(
                                    width: double.infinity,
                                    child: OutlinedButton.icon(
                                      onPressed: () {
                                        final c = _customer!;
                                        final q = {
                                          'customer_id': '${c.id}',
                                          if (c.name.isNotEmpty)
                                            'customer_name': c.name,
                                          if (c.code.isNotEmpty)
                                            'customer_code': c.code,
                                        };
                                        final qs = q.entries
                                            .map((e) =>
                                                '${Uri.encodeQueryComponent(e.key)}=${Uri.encodeQueryComponent(e.value)}')
                                            .join('&');
                                        context.push('/statement?$qs');
                                      },
                                      icon: const Icon(Icons.menu_book_outlined),
                                      label: const Text(
                                          'كشف حساب عميل Oracle'),
                                    ),
                                  ),
                                  if ((_arSummary?['cheques'] is List) &&
                                      (_arSummary!['cheques'] as List)
                                          .isNotEmpty) ...[
                                    const Divider(),
                                    const Align(
                                      alignment: Alignment.centerRight,
                                      child: Text(
                                        'الشيكات المستحقة',
                                        style: TextStyle(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 6),
                                    ...((_arSummary!['cheques'] as List)
                                        .whereType<Map>()
                                        .take(8)
                                        .map((c) {
                                      final m = c.cast<String, dynamic>();
                                      final no = Fmt.str(
                                        m['cheque_no'] ??
                                            m['check_no'] ??
                                            m['num'] ??
                                            m['doc_no'],
                                      );
                                      final amt = Fmt.money(
                                        Fmt.toDouble(
                                          m['amount'] ?? m['amt'] ?? m['value'],
                                        ),
                                      );
                                      final due = Fmt.dmy(
                                        Fmt.str(
                                          m['due_date'] ??
                                              m['date'] ??
                                              m['cheque_date'],
                                        ),
                                      );
                                      return Padding(
                                        padding:
                                            const EdgeInsets.only(bottom: 4),
                                        child: Row(
                                          children: [
                                            Expanded(
                                              child: Text(
                                                no.isEmpty ? 'شيك' : no,
                                                style: const TextStyle(
                                                  fontSize: 12.5,
                                                  fontWeight: FontWeight.w700,
                                                ),
                                              ),
                                            ),
                                            Text(
                                              due,
                                              style: const TextStyle(
                                                fontSize: 11.5,
                                                color: Color(0xFF64748B),
                                              ),
                                            ),
                                            const SizedBox(width: 8),
                                            Text(
                                              amt,
                                              textDirection: TextDirection.ltr,
                                              style: const TextStyle(
                                                fontSize: 12.5,
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                          ],
                                        ),
                                      );
                                    })),
                                  ],
                                ],
                              )),
                  ),
                ],
                const DocumentSectionDivider('بنود الطلب'),
                if (_lines.isNotEmpty)
                  AppCard(
                    padding: EdgeInsets.zero,
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: DefaultTextStyle.merge(
                        style: const TextStyle(fontSize: 11, height: 1.1),
                        child: ConstrainedBox(
                        constraints: const BoxConstraints(minWidth: 700),
                        child: DataTable(
                          headingRowHeight: 30,
                          dataRowMinHeight: 34,
                          dataRowMaxHeight: 36,
                          columnSpacing: 6,
                          horizontalMargin: 6,
                          headingTextStyle: const TextStyle(
                            fontSize: 10.5,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF475569),
                          ),
                          dataTextStyle: const TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w600,
                            color: Color(0xFF0F172A),
                          ),
                          columns: const [
                            DataColumn(label: Text('الباركود')),
                            DataColumn(label: Text('المادة')),
                            DataColumn(label: Text('الوحدة')),
                            DataColumn(label: Text('الكمية')),
                            DataColumn(label: Text('إضافية')),
                            DataColumn(label: Text('السعر')),
                            DataColumn(label: Text('خصم %')),
                            DataColumn(label: Text('المجموع')),
                            DataColumn(label: Text('')),
                          ],
                          rows: [
                            for (var i = 0; i < _lines.length; i++)
                              DataRow(cells: [
                                DataCell(
                                  SizedBox(
                                    width: 96,
                                    child: Text(
                                      _lines[i].barcode.isEmpty
                                          ? '—'
                                          : _lines[i].barcode,
                                      maxLines: 1,
                                      softWrap: false,
                                      overflow: TextOverflow.ellipsis,
                                      textDirection: TextDirection.ltr,
                                      style: const TextStyle(
                                        fontSize: 10.5,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 130,
                                    child: Text(
                                      _lines[i].item.name,
                                      maxLines: 1,
                                      softWrap: false,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w700,
                                        fontSize: 11,
                                      ),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 92,
                                    child: DropdownButtonFormField<int>(
                                      key: ValueKey(
                                          'unit-${_lines[i].item.id}-$i-${_lines[i].unitId}'),
                                      isExpanded: true,
                                      isDense: true,
                                      style: const TextStyle(
                                        fontSize: 11,
                                        color: Color(0xFF0F172A),
                                      ),
                                      initialValue: _lines[i].unitId == 0
                                          ? (_lines[i].item.units.isEmpty
                                              ? null
                                              : _lines[i]
                                                  .item
                                                  .units
                                                  .first
                                                  .unitId)
                                          : _lines[i].unitId,
                                      decoration: const InputDecoration(
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 4,
                                          vertical: 2,
                                        ),
                                        border: OutlineInputBorder(),
                                      ),
                                      items: [
                                        for (final u in _lines[i].item.units)
                                          DropdownMenuItem(
                                            value: u.unitId,
                                            child: Text(
                                              u.name,
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style:
                                                  const TextStyle(fontSize: 11),
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
                                              style:
                                                  const TextStyle(fontSize: 11),
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
                                                _lines[i].applyUnit(u,
                                                    unitIdOverride: v);
                                              });
                                            },
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 58,
                                    child: TextFormField(
                                      key: ValueKey(
                                          'qty-${_lines[i].item.id}-$i'),
                                      initialValue: '${_lines[i].qty}',
                                      enabled: _editable,
                                      style: const TextStyle(fontSize: 11),
                                      keyboardType: TextInputType.number,
                                      inputFormatters: [
                                        FilteringTextInputFormatter.digitsOnly
                                      ],
                                      decoration: const InputDecoration(
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 4,
                                          vertical: 2,
                                        ),
                                        border: OutlineInputBorder(),
                                      ),
                                      onChanged: (v) => setState(() {
                                        _lines[i].qty = int.tryParse(v)
                                                ?.clamp(1, 999999999) ??
                                            1;
                                      }),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 58,
                                    child: TextFormField(
                                      key: ValueKey(
                                          'extra-${_lines[i].item.id}-$i'),
                                      initialValue: '${_lines[i].qtyExtra}',
                                      enabled: _editable,
                                      style: const TextStyle(fontSize: 11),
                                      keyboardType: TextInputType.number,
                                      inputFormatters: [
                                        FilteringTextInputFormatter.digitsOnly
                                      ],
                                      decoration: const InputDecoration(
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 4,
                                          vertical: 2,
                                        ),
                                        border: OutlineInputBorder(),
                                      ),
                                      onChanged: (v) => setState(() {
                                        _lines[i].qtyExtra = int.tryParse(v)
                                                ?.clamp(0, 999999999) ??
                                            0;
                                      }),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 72,
                                    child: TextFormField(
                                      key: ValueKey(
                                          'price-${_lines[i].item.id}-$i-${_lines[i].unitId}'),
                                      initialValue:
                                          Fmt.trimNum(_lines[i].unitPrice),
                                      enabled: _editable,
                                      style: const TextStyle(fontSize: 11),
                                      keyboardType:
                                          const TextInputType.numberWithOptions(
                                        decimal: true,
                                      ),
                                      decoration: const InputDecoration(
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 4,
                                          vertical: 2,
                                        ),
                                        border: OutlineInputBorder(),
                                      ),
                                      onChanged: (v) => setState(() {
                                        final p = double.tryParse(
                                              v.replaceAll(',', ''),
                                            ) ??
                                            0;
                                        _lines[i].unitPrice = p;
                                        if (_lines[i].unitFactor > 0) {
                                          _lines[i].basePrice =
                                              p / _lines[i].unitFactor;
                                        }
                                      }),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 56,
                                    child: TextFormField(
                                      key: ValueKey(
                                          'disc-${_lines[i].item.id}-$i'),
                                      initialValue: _lines[i].discountPct <= 0
                                          ? ''
                                          : Fmt.trimNum(_lines[i].discountPct),
                                      enabled: _editable,
                                      style: const TextStyle(fontSize: 11),
                                      keyboardType:
                                          const TextInputType.numberWithOptions(
                                        decimal: true,
                                      ),
                                      decoration: const InputDecoration(
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 4,
                                          vertical: 2,
                                        ),
                                        border: OutlineInputBorder(),
                                        hintText: '0',
                                      ),
                                      onChanged: (v) => setState(() {
                                        _lines[i].discountPct =
                                            (double.tryParse(
                                                      v.replaceAll(',', ''),
                                                    ) ??
                                                    0)
                                                .clamp(0, 100);
                                      }),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  SizedBox(
                                    width: 74,
                                    child: Text(
                                      Fmt.money(_lines[i].lineTotal),
                                      maxLines: 1,
                                      softWrap: false,
                                      overflow: TextOverflow.ellipsis,
                                      textDirection: TextDirection.ltr,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w800,
                                        fontSize: 11,
                                      ),
                                    ),
                                  ),
                                ),
                                DataCell(
                                  _editable
                                      ? IconButton(
                                          visualDensity: VisualDensity.compact,
                                          padding: EdgeInsets.zero,
                                          constraints: const BoxConstraints(
                                            minWidth: 28,
                                            minHeight: 28,
                                          ),
                                          onPressed: () => setState(
                                              () => _lines.removeAt(i)),
                                          icon: const Icon(
                                            Icons.delete_outline_rounded,
                                            size: 18,
                                            color: Color(0xFFB91C1C),
                                          ),
                                        )
                                      : const SizedBox.shrink(),
                                ),
                              ]),
                          ],
                        ),
                        ),
                      ),
                    ),
                  ),
                if (_lines.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      'إجمالي البنود: ${Fmt.money(_lines.fold<double>(0, (s, l) => s + l.lineTotal))}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                      ),
                    ),
                  ),
                ],
                if (_editable)
                  OutlinedButton.icon(
                      onPressed: _addLine,
                      icon: const Icon(Icons.add_rounded),
                      label: const Text('إضافة مادة')),
                const SizedBox(height: 12),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  FilledButton.icon(
                      onPressed: _busy || !_editable ? null : _save,
                      icon: const Icon(Icons.save_outlined),
                      label: const Text('حفظ')),
                  OutlinedButton.icon(
                      onPressed: _busy || _id == 0 || _approved
                          ? null
                          : () => context.push('/customer-orders/$_id/edit'),
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('تعديل')),
                  OutlinedButton.icon(
                      onPressed: _busy ? null : _view,
                      icon: const Icon(Icons.visibility_outlined),
                      label: const Text('عرض')),
                  OutlinedButton.icon(
                      onPressed: _busy ? null : _print,
                      icon: const Icon(Icons.print_outlined),
                      label: const Text('طباعة')),
                ]),
              ],
            )),
      );
}
