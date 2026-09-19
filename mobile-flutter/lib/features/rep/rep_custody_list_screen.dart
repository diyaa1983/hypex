import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/list_page_bar.dart';
import '../../widgets/ui_kit.dart';

class RepCustodyListScreen extends StatefulWidget {
  const RepCustodyListScreen({super.key});

  @override
  State<RepCustodyListScreen> createState() => _RepCustodyListScreenState();
}

class _RepCustodyListScreenState extends State<RepCustodyListScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  Map<String, dynamic>? _pager;
  int _page = 1;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.repCustodyListPath,
        query: {'filter': 'all', 'page': _page},
      );
      if (!mounted) return;
      final pager = (res['pager'] is Map)
          ? (res['pager'] as Map).cast<String, dynamic>()
          : null;
      final serverPage = (pager?['page'] as num?)?.toInt();
      setState(() {
        _rows = (res['moves'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _pager = pager;
        if (serverPage != null && serverPage > 0) _page = serverPage;
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

  void _changePage(int page) {
    if (page < 1 || page == _page) return;
    setState(() => _page = page);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('قائمة العهدات'),
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
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _rows.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 60),
                          EmptyState(
                            message: 'لا توجد سندات عهدة.',
                            icon: Icons.fact_check_outlined,
                          ),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 12, 14, 20),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) {
                          final r = _rows[i];
                          final posted = r['is_posted'] == true;
                          final isReturn =
                              (r['direction'] ?? '').toString() == 'return';
                          return AppCard(
                            padding: const EdgeInsets.all(12),
                            child: Row(
                              children: [
                                MiniIcon(
                                  isReturn
                                      ? Icons.undo_rounded
                                      : Icons.local_shipping_rounded,
                                  color:
                                      isReturn ? AppTheme.rose : AppTheme.amber,
                                ),
                                const SizedBox(width: 11),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        '${isReturn ? 'إرجاع' : 'تحميل'} '
                                        '#${r['move_no_fmt'] ?? r['move_no'] ?? '—'}',
                                        style: const TextStyle(
                                          fontSize: 14,
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        '${r['move_date_dmy'] ?? r['move_date'] ?? ''}'
                                        '  •  ${r['lines_count'] ?? r['items_count'] ?? r['line_count'] ?? 0} صنف',
                                        textDirection: TextDirection.ltr,
                                        style: const TextStyle(
                                          fontSize: 12,
                                          color: AppTheme.textSoft,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                StatusPill(
                                  text: posted ? 'مرحّل' : 'مسودة',
                                  color: posted
                                      ? AppTheme.success
                                      : AppTheme.warn,
                                ),
                              ],
                            ),
                          );
                        },
                      ),
              ),
            ),
          ),
          ListPageBar.fromPager(_pager, onPageChanged: _changePage),
        ],
      ),
    );
  }
}
