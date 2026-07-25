import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class RepStockScreen extends StatefulWidget {
  const RepStockScreen({super.key});

  @override
  State<RepStockScreen> createState() => _RepStockScreenState();
}

class _RepStockScreenState extends State<RepStockScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  String _repName = '';
  String _whName = '';
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
      final res = await context.read<ApiClient>().getJson(
        AppConfig.repStockPath,
        query: {'q': _search.text.trim()},
      );
      setState(() {
        _items = (res['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _repName = Fmt.str(res['rep_name']);
        _whName = Fmt.str((res['warehouse'] as Map?)?['name_ar']);
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('رصيد العهدة'),
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: _load,
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
                        const MiniIcon(
                          Icons.person_rounded,
                          color: AppTheme.teal,
                          size: 32,
                          iconSize: 17,
                          radius: 10,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            [
                              if (_repName.isNotEmpty) 'المندوب: $_repName',
                              if (_whName.isNotEmpty) 'المستودع: $_whName',
                            ].join('  •  '),
                            style: const TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w700,
                              color: AppTheme.textSoft,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                TextField(
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: const InputDecoration(
                    hintText: 'بحث عن مادة...',
                    prefixIcon: Icon(Icons.search_rounded, size: 20),
                  ),
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
                            message: 'لا توجد مواد في العهدة.',
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
                          return AppCard(
                            padding: const EdgeInsets.all(12),
                            child: Row(
                              children: [
                                MiniIcon(
                                  Icons.inventory_2_outlined,
                                  color: qty > 0
                                      ? AppTheme.teal
                                      : AppTheme.textSoft,
                                  size: 32,
                                  iconSize: 17,
                                  radius: 10,
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
                                      if (sku.isNotEmpty) ...[
                                        const SizedBox(height: 3),
                                        Text(
                                          sku,
                                          textDirection: TextDirection.ltr,
                                          style: const TextStyle(
                                            fontSize: 11.5,
                                            color: AppTheme.textSoft,
                                          ),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                                StatusPill(
                                  text: Fmt.money(qty),
                                  color: qty > 0
                                      ? AppTheme.success
                                      : AppTheme.danger,
                                  dense: false,
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
