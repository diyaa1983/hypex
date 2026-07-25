import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

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
      appBar: AppBar(title: const Text('رصيد العهدة')),
      body: Column(
        children: [
          if (_repName.isNotEmpty || _whName.isNotEmpty)
            Container(
              width: double.infinity,
              color: AppTheme.primary.withValues(alpha: 0.08),
              padding: const EdgeInsets.all(12),
              child: Text(
                '${_repName.isEmpty ? '' : 'المندوب: $_repName'}${_whName.isEmpty ? '' : '   •   المستودع: $_whName'}',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'بحث عن مادة...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.arrow_circle_left_outlined),
                  onPressed: _load,
                ),
              ),
              onSubmitted: (_) => _load(),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _items.isEmpty
                    ? ListView(children: const [
                        SizedBox(height: 100),
                        EmptyState(message: 'لا توجد مواد في العهدة.'),
                      ])
                    : ListView.separated(
                        itemCount: _items.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (_, i) {
                          final it = _items[i];
                          final qty = Fmt.toDouble(it['qty']);
                          return ListTile(
                            title: Text(Fmt.str(it['item_name'] ?? it['name'])),
                            subtitle: Text(Fmt.str(it['item_sku'] ?? it['sku']),
                                textDirection: TextDirection.ltr),
                            trailing: Text(
                              Fmt.money(qty),
                              textDirection: TextDirection.ltr,
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                                color:
                                    qty > 0 ? AppTheme.success : AppTheme.danger,
                              ),
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
