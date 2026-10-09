import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

/// اعتماد طلبات الخروج اليدوي من زيارة المندوب.
class VisitCheckoutApproveScreen extends StatefulWidget {
  const VisitCheckoutApproveScreen({super.key});

  @override
  State<VisitCheckoutApproveScreen> createState() =>
      _VisitCheckoutApproveScreenState();
}

class _VisitCheckoutApproveScreenState
    extends State<VisitCheckoutApproveScreen> {
  bool _loading = true;
  bool _busy = false;
  String? _error;
  String _status = 'pending';
  List<Map<String, dynamic>> _rows = [];

  static const _filters = {
    'pending': 'معلّق',
    'approved': 'موافق',
    'rejected': 'مرفوض',
    'all': 'الكل',
  };

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
      final data = await context.read<ApiClient>().getJson(
            AppConfig.visitCheckoutApprovePath,
            query: {'status': _status},
          );
      if (!mounted) return;
      final rows = (data['rows'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _rows = rows;
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

  Future<void> _decide(Map<String, dynamic> row, bool approve) async {
    final id = Fmt.toInt(row['id']);
    if (id < 1 || _busy) return;
    final noteCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(
          approve ? 'موافقة على الخروج اليدوي' : 'رفض الخروج اليدوي',
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '${Fmt.str(row['customer_name'])} — ${Fmt.str(row['sales_rep_name'])}',
              style: const TextStyle(height: 1.4),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: noteCtrl,
              decoration: const InputDecoration(
                labelText: 'ملاحظة (اختياري)',
                border: OutlineInputBorder(),
              ),
              maxLines: 2,
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: FilledButton.styleFrom(
              backgroundColor: approve ? AppTheme.success : AppTheme.danger,
            ),
            child: Text(approve ? 'موافقة' : 'رفض'),
          ),
        ],
      ),
    );
    final note = noteCtrl.text.trim();
    noteCtrl.dispose();
    if (ok != true || !mounted) return;

    setState(() => _busy = true);
    try {
      final csrf = await context.read<SessionController>().ensureCsrf();
      final res = await context.read<ApiClient>().postJson(
            AppConfig.visitCheckoutApprovePath,
            body: {
              'id': id,
              'action': approve ? 'approve' : 'reject',
              if (note.isNotEmpty) 'note': note,
            },
            csrf: csrf,
          );
      if (!mounted) return;
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty
            ? (approve ? 'تمت الموافقة' : 'تم الرفض')
            : Fmt.str(res['message']),
      );
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _statusLabel(String st) {
    switch (st) {
      case 'pending':
        return 'معلّق';
      case 'approved':
        return 'موافق';
      case 'rejected':
        return 'مرفوض';
      default:
        return st;
    }
  }

  Color _statusColor(String st) {
    switch (st) {
      case 'pending':
        return AppTheme.amber;
      case 'approved':
        return AppTheme.success;
      case 'rejected':
        return AppTheme.danger;
      default:
        return AppTheme.textSoft;
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('اعتماد خروج يدوي'),
      backgroundColor: const Color(0xFFF0F4F8),
      actions: [
        IconButton(
          tooltip: 'تحديث',
          onPressed: _busy || _loading ? null : _load,
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: Column(
        children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
            child: Row(
              children: [
                for (final e in _filters.entries) ...[
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: ChoiceChip(
                      label: Text(e.value),
                      selected: _status == e.key,
                      onSelected: _busy
                          ? null
                          : (_) {
                              if (_status == e.key) return;
                              setState(() => _status = e.key);
                              _load();
                            },
                    ),
                  ),
                ],
              ],
            ),
          ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _rows.isEmpty
                  ? const Center(
                      child: Text(
                        'لا توجد طلبات.',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: AppTheme.textSoft,
                        ),
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: const EdgeInsets.fromLTRB(12, 8, 12, 20),
                        itemCount: _rows.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) {
                          final r = _rows[i];
                          final st = Fmt.str(r['status']);
                          final pending = st == 'pending';
                          final dist = r['request_distance_m'];
                          final distTxt = dist == null || '$dist' == ''
                              ? '—'
                              : '${Fmt.toInt(dist)} م';
                          return Material(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(14),
                            child: Padding(
                              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      MiniIcon(
                                        Icons.logout_rounded,
                                        color: AppTheme.warn,
                                      ),
                                      const SizedBox(width: 10),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              Fmt.str(r['customer_name']),
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w800,
                                                fontSize: 15,
                                              ),
                                            ),
                                            if (Fmt.str(r['customer_code'])
                                                .isNotEmpty)
                                              Text(
                                                Fmt.str(r['customer_code']),
                                                style: const TextStyle(
                                                  color: AppTheme.textSoft,
                                                  fontSize: 12.5,
                                                ),
                                              ),
                                          ],
                                        ),
                                      ),
                                      StatusPill(
                                        text: _statusLabel(st),
                                        color: _statusColor(st),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 10),
                                  Text(
                                    'المندوب: ${Fmt.str(r['sales_rep_name']).isEmpty ? '—' : Fmt.str(r['sales_rep_name'])}',
                                    style: const TextStyle(fontSize: 13.5),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'السبب: ${Fmt.str(r['reason']).isEmpty ? '—' : Fmt.str(r['reason'])}',
                                    style: const TextStyle(
                                      fontSize: 13.5,
                                      height: 1.35,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'المسافة: $distTxt  •  ${Fmt.dmyHm(Fmt.str(r['created_at']))}',
                                    style: const TextStyle(
                                      fontSize: 12.5,
                                      color: AppTheme.textSoft,
                                    ),
                                  ),
                                  if (pending) ...[
                                    const SizedBox(height: 12),
                                    Row(
                                      children: [
                                        Expanded(
                                          child: FilledButton.icon(
                                            onPressed: _busy
                                                ? null
                                                : () => _decide(r, true),
                                            icon: const Icon(
                                              Icons.check_rounded,
                                              size: 18,
                                            ),
                                            label: const Text('موافقة'),
                                            style: FilledButton.styleFrom(
                                              backgroundColor: AppTheme.success,
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        Expanded(
                                          child: OutlinedButton.icon(
                                            onPressed: _busy
                                                ? null
                                                : () => _decide(r, false),
                                            icon: const Icon(
                                              Icons.close_rounded,
                                              size: 18,
                                            ),
                                            label: const Text('رفض'),
                                            style: OutlinedButton.styleFrom(
                                              foregroundColor: AppTheme.danger,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ],
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
