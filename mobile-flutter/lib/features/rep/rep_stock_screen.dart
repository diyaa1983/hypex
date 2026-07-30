import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../services/document_print_helper.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class RepStockScreen extends StatefulWidget {
  const RepStockScreen({super.key});

  @override
  State<RepStockScreen> createState() => _RepStockScreenState();
}

class _RepStockScreenState extends State<RepStockScreen> {
  bool _loading = true;
  bool _printing = false;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _warehouses = [];
  int? _warehouseId;
  String _repName = '';
  String _whName = '';
  bool _isVan = false;
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final query = <String, dynamic>{'q': _search.text.trim()};
      if (_warehouseId != null && _warehouseId! > 0) {
        query['warehouse_id'] = _warehouseId;
      }
      final res = await context.read<ApiClient>().getJson(
        AppConfig.repStockPath,
        query: query,
      );
      if (!mounted) return;

      final wh = (res['warehouse'] as Map?)?.cast<String, dynamic>();
      final whs = (res['warehouses'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      final selectedId = int.tryParse('${wh?['id'] ?? ''}') ?? 0;

      setState(() {
        _items = (res['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _warehouses = whs;
        _warehouseId = selectedId > 0 ? selectedId : _warehouseId;
        _repName = Fmt.str(res['rep_name']);
        _whName = Fmt.str(wh?['name_ar']);
        _isVan = wh?['is_van'] == true || Fmt.str(res['source']) == 'van';
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _openPdf() async {
    if (_warehouseId == null || _warehouseId! < 1) {
      showSnack(context, 'اختر مستودعاً أولاً.', error: true);
      return;
    }
    setState(() => _printing = true);
    try {
      await DocumentPrintHelper.openPdfFromApi(
        context,
        apiPath: AppConfig.repStockPdfPath,
        query: {
          'warehouse_id': _warehouseId,
          'q': _search.text.trim(),
        },
        title: 'رصيد المستودع',
        fileName: 'رصيد_${_whName.isEmpty ? _warehouseId : _whName}.pdf',
      );
    } finally {
      if (mounted) setState(() => _printing = false);
    }
  }

  Future<void> _printBluetooth() async {
    if (_warehouseId == null || _warehouseId! < 1) {
      showSnack(context, 'اختر مستودعاً أولاً.', error: true);
      return;
    }
    setState(() => _printing = true);
    try {
      await DocumentPrintHelper.printFromApi(
        context,
        apiPath: AppConfig.repStockPdfPath,
        query: {
          'warehouse_id': _warehouseId,
          'q': _search.text.trim(),
        },
        jobName: 'رصيد_${_whName.isEmpty ? _warehouseId : _whName}',
      );
    } finally {
      if (mounted) setState(() => _printing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('رصيد المستودع'),
        actions: [
          IconButton(
            tooltip: 'PDF (A4)',
            onPressed: (_loading || _printing || _warehouseId == null)
                ? null
                : _openPdf,
            icon: const Icon(Icons.picture_as_pdf_outlined),
          ),
          IconButton(
            tooltip: 'طباعة Bluetooth',
            onPressed: (_loading || _printing || _warehouseId == null)
                ? null
                : _printBluetooth,
            icon: _printing
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.print_outlined),
          ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            color: AppTheme.surface,
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Column(
              children: [
                if (_repName.isNotEmpty || _whName.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Row(
                      children: [
                        MiniIcon(
                          _isVan
                              ? Icons.local_shipping_rounded
                              : Icons.store_mall_directory_rounded,
                          color: AppTheme.teal,
                          size: 32,
                          iconSize: 17,
                          radius: 10,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              if (_repName.isNotEmpty)
                                Text(
                                  'المندوب: $_repName',
                                  style: const TextStyle(
                                    fontSize: 12.5,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                              if (_whName.isNotEmpty) ...[
                                if (_repName.isNotEmpty)
                                  const SizedBox(height: 2),
                                Text(
                                  _isVan
                                      ? 'مستودع العهدة: $_whName'
                                      : 'المستودع: $_whName',
                                  style: const TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                    color: AppTheme.textSoft,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                        if (_items.isNotEmpty)
                          StatusPill(
                            text: '${_items.length} مادة',
                            color: AppTheme.primary,
                          ),
                      ],
                    ),
                  ),
                if (_warehouses.length > 1) ...[
                  DropdownButtonFormField<int>(
                    value: _warehouseId != null &&
                            _warehouses.any(
                              (w) =>
                                  (int.tryParse('${w['id']}') ?? 0) ==
                                  _warehouseId,
                            )
                        ? _warehouseId
                        : null,
                    decoration: const InputDecoration(
                      labelText: 'المستودع',
                      prefixIcon: Icon(Icons.store_outlined, size: 20),
                      isDense: true,
                    ),
                    items: _warehouses.map((w) {
                      final id = int.tryParse('${w['id']}') ?? 0;
                      final name = Fmt.str(w['name_ar']);
                      final van = w['is_van'] == true;
                      return DropdownMenuItem(
                        value: id,
                        child: Text(
                          van ? '$name (عهدة)' : name,
                          overflow: TextOverflow.ellipsis,
                        ),
                      );
                    }).toList(),
                    onChanged: _loading
                        ? null
                        : (v) {
                            if (v == null) return;
                            setState(() => _warehouseId = v);
                            _load();
                          },
                  ),
                  const SizedBox(height: 10),
                ],
                TextField(
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: 'بحث بالاسم أو الرمز...',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20),
                    suffixIcon: _search.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.close_rounded, size: 18),
                            onPressed: () {
                              _search.clear();
                              _load();
                            },
                          ),
                  ),
                  onChanged: (_) => setState(() {}),
                  onSubmitted: (_) => _load(),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _items.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 60),
                          EmptyState(
                            message: 'لا توجد مواد برصيد في هذا المستودع.',
                            icon: Icons.inventory_2_outlined,
                          ),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 12, 14, 20),
                        itemCount: _items.length,
                        itemBuilder: (_, i) {
                          final it = _items[i];
                          final qty = Fmt.toDouble(it['qty']);
                          final sku = Fmt.str(it['item_sku'] ?? it['sku']);
                          final unit = Fmt.str(it['unit_name']);
                          final cat = Fmt.str(it['category_name']);
                          return AppCard(
                            padding: const EdgeInsets.all(12),
                            child: Row(
                              children: [
                                MiniIcon(
                                  Icons.inventory_2_outlined,
                                  color: qty > 0
                                      ? AppTheme.teal
                                      : AppTheme.textSoft,
                                  size: 36,
                                  iconSize: 18,
                                  radius: 11,
                                ),
                                const SizedBox(width: 11),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        Fmt.str(it['item_name'] ?? it['name']),
                                        style: const TextStyle(
                                          fontSize: 13.5,
                                          fontWeight: FontWeight.w700,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        [
                                          if (sku.isNotEmpty) sku,
                                          if (unit.isNotEmpty) unit,
                                          if (cat.isNotEmpty) cat,
                                        ].join('  •  '),
                                        textDirection: sku.isNotEmpty
                                            ? TextDirection.ltr
                                            : TextDirection.rtl,
                                        style: const TextStyle(
                                          fontSize: 11.5,
                                          color: AppTheme.textSoft,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    StatusPill(
                                      text: Fmt.money(qty),
                                      color: qty > 0
                                          ? AppTheme.success
                                          : AppTheme.danger,
                                      dense: false,
                                    ),
                                    if (unit.isNotEmpty) ...[
                                      const SizedBox(height: 4),
                                      Text(
                                        unit,
                                        style: const TextStyle(
                                          fontSize: 11,
                                          color: AppTheme.textSoft,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ],
                            ),
                          );
                        },
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
