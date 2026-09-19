import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/format.dart';
import '../offline/offline_controller.dart';
import '../offline/offline_store.dart';

class ItemUnitOpt {
  const ItemUnitOpt({
    required this.unitId,
    required this.name,
    required this.factor,
    this.isBase = false,
    this.isDefault = false,
  });

  final int unitId;
  final String name;
  final double factor;
  final bool isBase;
  final bool isDefault;

  factory ItemUnitOpt.fromJson(Map<String, dynamic> m) => ItemUnitOpt(
        unitId: Fmt.toInt(m['unit_id'] ?? m['id']),
        name: Fmt.str(m['name'] ?? m['unit_name']),
        factor: Fmt.toDouble(m['factor'] ?? m['factor_to_base'] ?? 1),
        isBase: m['is_base'] == true,
        isDefault: m['is_default'] == true || m['is_default_issue'] == true,
      );
}

class PickedItem {
  PickedItem(
    this.id,
    this.name,
    this.price,
    this.stock, {
    this.barcode = '',
    this.units = const [],
  });
  final int id;
  final String name;
  final double price;
  final double stock;
  final String barcode;
  final List<ItemUnitOpt> units;

  ItemUnitOpt? get defaultUnit {
    for (final u in units) {
      if (u.isDefault) return u;
    }
    for (final u in units) {
      if (u.isBase) return u;
    }
    return units.isEmpty ? null : units.first;
  }
}

/// اختيار مادة عبر بحث (items_search) ضمن مستودع محدد.
Future<PickedItem?> pickItem(
  BuildContext context, {
  required int warehouseId,
}) {
  return showModalBottomSheet<PickedItem>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.white,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
    ),
    builder: (_) => _ItemPickerSheet(warehouseId: warehouseId),
  );
}

class _ItemPickerSheet extends StatefulWidget {
  const _ItemPickerSheet({required this.warehouseId});
  final int warehouseId;

  @override
  State<_ItemPickerSheet> createState() => _ItemPickerSheetState();
}

class _ItemPickerSheetState extends State<_ItemPickerSheet> {
  final _search = TextEditingController();
  final _searchFocus = FocusNode();
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  bool _keyboardEnabled = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load('');
  }

  @override
  void dispose() {
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _toggleKeyboard() {
    if (_keyboardEnabled) {
      _searchFocus.unfocus();
      setState(() => _keyboardEnabled = false);
      return;
    }
    setState(() => _keyboardEnabled = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _searchFocus.requestFocus();
    });
  }

  Future<void> _load(String q) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final offline = context.read<OfflineController>();

    Future<List<Map<String, dynamic>>> fromLocal() async {
      final rows = await OfflineStore.instance.searchItems(
        warehouseId: widget.warehouseId,
        q: q,
      );
      return rows.map((e) {
        return <String, dynamic>{
          'id': e['id'],
          'name_ar': e['name'],
          'name': e['name'],
          'sku': e['sku'],
          'barcode': e['barcode'],
          'default_sale': e['sale_price'],
          'sale_price': e['sale_price'],
          'stock_qty': e['stock_qty'] ?? 0,
          'units': _parseUnitsJson(e['units_json']?.toString() ?? '[]'),
        };
      }).toList();
    }

    try {
      List<Map<String, dynamic>> list;
      if (!offline.online && offline.catalogReady) {
        list = await fromLocal();
      } else {
        try {
          final res = await context.read<ApiClient>().getJson(
            AppConfig.itemsSearchPath,
            query: {
              'warehouse_id': widget.warehouseId,
              if (q.isEmpty) 'list': '1' else 'q': q,
            },
          );
          list = (res['items'] as List? ?? [])
              .whereType<Map>()
              .map((e) => e.cast<String, dynamic>())
              .toList();
        } on ApiException catch (e) {
          if (offline.catalogReady &&
              (e.message.contains('تعذر الاتصال') ||
                  e.message.contains('الإنترنت'))) {
            list = await fromLocal();
          } else {
            rethrow;
          }
        }
      }
      setState(() {
        _items = list;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  List<dynamic> _parseUnitsJson(String raw) {
    try {
      final decoded = jsonDecode(raw.isEmpty ? '[]' : raw);
      return decoded is List ? decoded : const [];
    } catch (_) {
      return const [];
    }
  }

  double _price(Map<String, dynamic> it) {
    return Fmt.toDouble(
        it['default_sale'] ?? it['sale_price'] ?? it['unit_price']);
  }

  List<ItemUnitOpt> _units(Map<String, dynamic> it) {
    final raw = it['units'];
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((e) => ItemUnitOpt.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.85,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
              child: Row(
                children: [
                  const Text('اختيار مادة',
                      style:
                          TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.pop(context),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: TextField(
                controller: _search,
                focusNode: _searchFocus,
                readOnly: !_keyboardEnabled,
                showCursor: _keyboardEnabled,
                decoration: InputDecoration(
                  hintText: 'بحث بالاسم أو الرمز...',
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: IconButton(
                    tooltip: _keyboardEnabled
                        ? 'إخفاء لوحة المفاتيح'
                        : 'إظهار لوحة المفاتيح',
                    onPressed: _toggleKeyboard,
                    icon: Icon(
                      _keyboardEnabled
                          ? Icons.keyboard_hide_rounded
                          : Icons.keyboard_rounded,
                    ),
                  ),
                ),
                onChanged: (v) => _load(v.trim()),
              ),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                      ? Center(child: Text(_error!))
                      : _items.isEmpty
                          ? const Center(child: Text('لا نتائج'))
                          : ListView.separated(
                              itemCount: _items.length,
                              separatorBuilder: (_, __) =>
                                  const Divider(height: 1),
                              itemBuilder: (_, i) {
                                final it = _items[i];
                                final name = Fmt.str(
                                    it['name_ar'] ?? it['name'] ?? it['sku']);
                                final stock = Fmt.toDouble(it['stock_qty']);
                                final price = _price(it);
                                final barcode = Fmt.str(
                                  it['barcode'] ?? it['sku'],
                                );
                                return ListTile(
                                  title: Text(name),
                                  subtitle: Text(
                                    [
                                      if (barcode.isNotEmpty) barcode,
                                      'رصيد: ${Fmt.money(stock)}',
                                      'سعر: ${Fmt.money(price)}',
                                    ].join('  •  '),
                                    textDirection: TextDirection.ltr,
                                  ),
                                  onTap: () => Navigator.pop(
                                    context,
                                    PickedItem(
                                      Fmt.toInt(it['id']),
                                      name,
                                      price,
                                      stock,
                                      barcode: Fmt.str(
                                        it['barcode'] ?? it['sku'],
                                      ),
                                      units: _units(it),
                                    ),
                                  ),
                                );
                              },
                            ),
            ),
          ],
        ),
      ),
    );
  }
}
