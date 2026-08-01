import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/format.dart';

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
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load('');
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load(String q) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.itemsSearchPath,
        query: {
          'warehouse_id': widget.warehouseId,
          if (q.isEmpty) 'list': '1' else 'q': q,
        },
      );
      setState(() {
        _items = (res['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
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
                autofocus: true,
                decoration: const InputDecoration(
                  hintText: 'بحث بالاسم أو الرمز...',
                  prefixIcon: Icon(Icons.search),
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
                                return ListTile(
                                  title: Text(name),
                                  subtitle: Text(
                                    'رصيد: ${Fmt.money(stock)}  •  سعر: ${Fmt.money(price)}',
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
