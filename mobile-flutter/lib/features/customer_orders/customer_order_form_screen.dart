import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../services/customer_order_bluetooth_receipt.dart';
import '../../widgets/async_view.dart';
import '../../widgets/item_picker.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/thermal_preview_screen.dart';
import '../../widgets/ui_kit.dart';

class CustomerOrderFormScreen extends StatefulWidget {
  const CustomerOrderFormScreen({super.key, this.orderId});
  final int? orderId;

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
  }

  final PickedItem item;
  int qty = 1;
  int unitId = 0;
  String unitName = '';
  double unitFactor = 1;

  Map<String, dynamic> toJson() => {
        'item_id': item.id,
        'item_name': item.name,
        'unit_id': unitId,
        'unit_name': unitName,
        'unit_factor': unitFactor,
        'qty': qty,
        'qty_base': qty * unitFactor,
      };
}

class _CustomerOrderFormScreenState extends State<CustomerOrderFormScreen> {
  bool _loading = true, _busy = false, _approved = false;
  String? _error, _orderNo;
  int _id = 0, _warehouseId = 0;
  List<Map<String, dynamic>> _warehouses = [];
  Party? _customer;
  final _lines = <_OrderLine>[];

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
          0,
          0,
          units: units,
        );
        final line = _OrderLine(item)
          ..qty = Fmt.toDouble(m['qty']).round().clamp(1, 999999999)
          ..unitId = Fmt.toInt(m['unit_id'])
          ..unitName = Fmt.str(m['unit_name'] ?? m['unit'])
          ..unitFactor = Fmt.toDouble(m['unit_factor'] ?? 1);
        if (line.unitName.isEmpty && item.defaultUnit != null) {
          line.unitId = item.defaultUnit!.unitId;
          line.unitName = item.defaultUnit!.name;
          line.unitFactor = item.defaultUnit!.factor;
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
        _approved = order['approved'] == true ||
            order['is_approved'] == true ||
            Fmt.str(order['status']) == 'approved';
        _orderNo = Fmt.str(order['order_no']);
        _lines
          ..clear()
          ..addAll(loaded);
        _loading = false;
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _loading = false;
        });
      }
    }
  }

  Future<void> _pickCustomer() async {
    final party = await pickParty(context, type: 'customer');
    if (party != null && mounted) setState(() => _customer = party);
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
      final result = await context.read<ApiClient>().postJson(
        AppConfig.customerOrderSavePath,
        csrf: context.read<SessionController>().csrf,
        body: {
          'id': _id,
          'customer_id': _customer!.id,
          'warehouse_id': _warehouseId,
          'lines': _lines.map((l) => l.toJson()).toList()
        },
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
                  'unit_name': l.unitName,
                  'qty': l.qty
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
                const DocumentSectionDivider('بنود الطلب'),
                for (var i = 0; i < _lines.length; i++)
                  AppCard(
                      child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(children: [
                        Expanded(
                            child: Text(_lines[i].item.name,
                                style: const TextStyle(
                                    fontWeight: FontWeight.w700))),
                        if (_editable)
                          IconButton(
                              onPressed: () =>
                                  setState(() => _lines.removeAt(i)),
                              icon: const Icon(Icons.delete_outline_rounded)),
                      ]),
                      const SizedBox(height: 8),
                      Row(children: [
                        Expanded(
                          flex: 3,
                          child: DropdownButtonFormField<int>(
                            initialValue: _lines[i].unitId == 0
                                ? (_lines[i].item.units.isEmpty
                                    ? null
                                    : _lines[i].item.units.first.unitId)
                                : _lines[i].unitId,
                            decoration:
                                const InputDecoration(labelText: 'الوحدة'),
                            items: [
                              for (final u in _lines[i].item.units)
                                DropdownMenuItem(
                                    value: u.unitId, child: Text(u.name)),
                              if (_lines[i].item.units.isEmpty &&
                                  _lines[i].unitName.isNotEmpty)
                                DropdownMenuItem(
                                    value: _lines[i].unitId,
                                    child: Text(_lines[i].unitName)),
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
                                      _lines[i].unitId = v ?? 0;
                                      if (u != null) {
                                        _lines[i].unitName = u.name;
                                        // الكمية تبقى؛ العدد = كمية × معامل التعبئة
                                        _lines[i].unitFactor =
                                            u.factor <= 0 ? 1.0 : u.factor;
                                      }
                                    });
                                  },
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          flex: 2,
                          child: TextFormField(
                              initialValue: '${_lines[i].qty}',
                              enabled: _editable,
                              keyboardType: TextInputType.number,
                              inputFormatters: [
                                FilteringTextInputFormatter.digitsOnly
                              ],
                              decoration:
                                  const InputDecoration(labelText: 'الكمية'),
                              onChanged: (v) => setState(() {
                                    _lines[i].qty = int.tryParse(v)
                                            ?.clamp(1, 999999999) ??
                                        1;
                                  }),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          flex: 2,
                          child: InputDecorator(
                            decoration:
                                const InputDecoration(labelText: 'العدد'),
                            child: Text(
                              '${(_lines[i].qty * (_lines[i].unitFactor <= 0 ? 1 : _lines[i].unitFactor)).round()}',
                              style: const TextStyle(fontWeight: FontWeight.w700),
                            ),
                          ),
                        ),
                      ]),
                    ],
                  )),
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
