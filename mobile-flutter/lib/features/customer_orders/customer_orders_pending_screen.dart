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
import '../../widgets/async_view.dart';
import '../../widgets/list_page_bar.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/order_statement_workflow_note.dart';
import '../../widgets/ui_kit.dart';

/// طلبات غير مرسلة — اختيار وإرسال / تعديل / حذف.
class CustomerOrdersPendingScreen extends StatefulWidget {
  const CustomerOrdersPendingScreen({super.key});

  @override
  State<CustomerOrdersPendingScreen> createState() =>
      _CustomerOrdersPendingScreenState();
}

class _CustomerOrdersPendingScreenState
    extends State<CustomerOrdersPendingScreen> {
  bool _loading = true;
  bool _busy = false;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
  Map<String, dynamic>? _pager;
  int _page = 1;
  final _selected = <int>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _loadLocal() async {
    const perPage = 30;
    final store = OfflineStore.instance;
    final total = await store.countOrders(isSent: 0);
    final offset = (_page - 1) * perPage;
    final orders = await store.listOrders(
      isSent: 0,
      limit: perPage,
      offset: offset,
    );
    final pages = total == 0 ? 1 : ((total + perPage - 1) ~/ perPage);
    if (!mounted) return;
    setState(() {
      _orders = orders;
      _pager = {
        'page': _page,
        'pages': pages,
        'total': total,
        'per_page': perPage,
      };
      _selected.removeWhere(
        (id) => !orders.any((o) => Fmt.toInt(o['id']) == id),
      );
      _loading = false;
    });
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        await _loadLocal();
        return;
      }
      final data = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderListPath,
            query: {'is_sent': 0, 'page': _page},
          );
      if (!mounted) return;
      final pager = (data['pager'] is Map)
          ? (data['pager'] as Map).cast<String, dynamic>()
          : null;
      final serverPage = (pager?['page'] as num?)?.toInt();
      final orders = (data['orders'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _orders = orders;
        _pager = pager;
        if (serverPage != null && serverPage > 0) _page = serverPage;
        _selected.removeWhere(
          (id) => !orders.any((o) => Fmt.toInt(o['id']) == id),
        );
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        await _loadLocal();
        return;
      }
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

  void _toggle(int id, bool? v) {
    setState(() {
      if (v == true) {
        _selected.add(id);
      } else {
        _selected.remove(id);
      }
    });
  }

  Future<void> _sendSelected() async {
    if (_selected.isEmpty) {
      showSnack(context, 'اختر طلباً واحداً على الأقل.', error: true);
      return;
    }
    setState(() => _busy = true);
    final offline = context.read<OfflineController>();
    try {
      final serverIds =
          _selected.where((id) => id > 0).toList();
      final localIds =
          _selected.where((id) => id < 0).toList();

      Future<void> queueSend(List<int> ids) async {
        if (ids.isEmpty) return;
        await offline.enqueue(
          kind: 'customer_order_send',
          path: AppConfig.customerOrderSendPath,
          body: {'ids': ids},
        );
        // محلياً: أظهرها كمرسلة بعد وضعها في الطابور فقط للسالبين بعد save؛
        // للسيرفر ids تُعلَّم بعد الترحيل. للـ offline المحلي يمكن تعليمها كمرسلة مؤقتاً بعد أن يكون الـ save قد رُحّل — نبقيها غير مرسلة حتى flush.
      }

      if (!offline.online && offline.catalogReady) {
        // طلبات محلية غير مُرحَّلة: send بعد save عبر ترتيب الطابور
        await queueSend([...serverIds, ...localIds]);
        if (!mounted) return;
        showSnack(
          context,
          'وُضع الإرسال في الطابور — سيُرحَّل عند عودة الاتصال.',
        );
        _selected.clear();
        await _load();
        return;
      }
      try {
        final res = await context.read<ApiClient>().postJson(
              AppConfig.customerOrderSendPath,
              body: {'ids': _selected.toList()},
              csrf: context.read<SessionController>().csrf,
            );
        if (!mounted) return;
        showSnack(
          context,
          Fmt.str(res['message']).isEmpty
              ? 'تم الإرسال.'
              : Fmt.str(res['message']),
        );
        _selected.clear();
        await _load();
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await queueSend(_selected.toList());
          if (!mounted) return;
          showSnack(
            context,
            'انقطع الاتصال — وُضع الإرسال في الطابور.',
          );
          _selected.clear();
          await _load();
        } else {
          if (mounted) showSnack(context, e.message, error: true);
        }
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editSelected() async {
    if (_selected.length != 1) {
      showSnack(context, 'اختر طلباً واحداً للتعديل.', error: true);
      return;
    }
    final id = _selected.first;
    await context.push('/customer-orders/$id/edit');
    if (mounted) _load();
  }

  Future<void> _deleteSelected() async {
    if (_selected.isEmpty) {
      showSnack(context, 'اختر طلباً للحذف.', error: true);
      return;
    }
    final ok = await showAppConfirmDialog(
      context,
      title: 'حذف الطلبات',
      message: 'حذف ${_selected.length} طلب نهائياً؟',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    final offline = context.read<OfflineController>();
    var deleted = 0;
    try {
      for (final id in _selected.toList()) {
        if (!offline.online && offline.catalogReady) {
          if (id < 0) {
            await OfflineStore.instance.deleteLocalOrder(id);
          } else {
            await offline.enqueue(
              kind: 'customer_order_delete',
              path: AppConfig.customerOrderDeletePath,
              body: {'id': id},
            );
            await OfflineStore.instance.deleteLocalOrder(id);
          }
          deleted++;
          continue;
        }
        try {
          await api.postJson(
            AppConfig.customerOrderDeletePath,
            body: {'id': id},
            csrf: csrf,
          );
          await OfflineStore.instance.deleteLocalOrder(id);
          deleted++;
        } on ApiException catch (e) {
          if (offline.catalogReady &&
              (e.message.contains('تعذر الاتصال') ||
                  e.message.contains('الإنترنت'))) {
            await offline.enqueue(
              kind: 'customer_order_delete',
              path: AppConfig.customerOrderDeletePath,
              body: {'id': id},
            );
            await OfflineStore.instance.deleteLocalOrder(id);
            deleted++;
          } else {
            rethrow;
          }
        }
      }
      if (!mounted) return;
      showSnack(context, 'تم حذف $deleted طلب.');
      _selected.clear();
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        showSnack(context, e.message, error: true);
        await _load();
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('طلبات غير مرسلة'),
      actions: [
        IconButton(
          tooltip: 'إرسال المحددة',
          onPressed: _busy || _loading ? null : _sendSelected,
          icon: const Icon(Icons.send_rounded),
        ),
        IconButton(
          tooltip: 'تعديل',
          onPressed: _busy || _loading ? null : _editSelected,
          icon: const Icon(Icons.edit_rounded),
        ),
        IconButton(
          tooltip: 'حذف',
          onPressed: _busy || _loading ? null : _deleteSelected,
          icon: const Icon(Icons.delete_outline_rounded),
        ),
        IconButton(
          onPressed: _busy || _loading ? null : _load,
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: Column(
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(14, 10, 14, 0),
            child: OrderStatementWorkflowNote(compact: true),
          ),
          if (_selected.isNotEmpty)
            Material(
              color: AppTheme.primary.withValues(alpha: 0.08),
              child: Padding(
                padding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        'محدد: ${_selected.length}',
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                    TextButton(
                      onPressed: _busy ? null : _sendSelected,
                      child: const Text('إرسال المحددة'),
                    ),
                  ],
                ),
              ),
            ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _orders.isEmpty
                  ? ListView(
                      children: const [
                        SizedBox(height: 70),
                        EmptyState(
                          message: 'لا توجد طلبات غير مرسلة.',
                          icon: Icons.outbox_rounded,
                        ),
                      ],
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 8, 14, 24),
                        itemCount: _orders.length,
                        itemBuilder: (_, i) {
                          final o = _orders[i];
                          final id = Fmt.toInt(o['id']);
                          final checked = _selected.contains(id);
                          return AppCard(
                            onTap: () => context.push(
                              '/customer-orders/$id/edit',
                            ),
                            child: Row(
                              children: [
                                Checkbox(
                                  value: checked,
                                  onChanged: (v) => _toggle(id, v),
                                ),
                                const SizedBox(width: 4),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        Fmt.str(o['order_no']).isEmpty
                                            ? 'طلب #$id'
                                            : Fmt.str(o['order_no']),
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 14,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        '${Fmt.str(o['customer_name']).isEmpty ? '—' : Fmt.str(o['customer_name'])} · ${Fmt.dmy(Fmt.str(o['order_date']))}',
                                        style: const TextStyle(
                                          color: AppTheme.textSoft,
                                          fontWeight: FontWeight.w600,
                                          fontSize: 12.5,
                                        ),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ],
                                  ),
                                ),
                                Text(
                                  Fmt.money(Fmt.toDouble(o['total'])),
                                  textDirection: TextDirection.ltr,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w900,
                                    fontSize: 15,
                                    color: Color(0xFF0F766E),
                                  ),
                                ),
                                const SizedBox(width: 4),
                                const Icon(
                                  Icons.edit_outlined,
                                  size: 18,
                                  color: AppTheme.textSoft,
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
          if (_busy) const LinearProgressIndicator(minHeight: 2),
        ],
      ),
    );
  }
}
