import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';

class RepVisitsScreen extends StatefulWidget {
  const RepVisitsScreen({super.key});

  @override
  State<RepVisitsScreen> createState() => _RepVisitsScreenState();
}

class _RepVisitsScreenState extends State<RepVisitsScreen> {
  bool _loading = true;
  String? _error;
  String _routeDate = '';
  int _radiusM = 200;
  List<Map<String, dynamic>> _visits = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _routeDate = Fmt.todayIso();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitListPath,
            query: {'date': _routeDate},
          );
      if (!mounted) return;
      setState(() {
        _visits = (res['visits'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _routeDate = Fmt.str(res['route_date']).isEmpty
            ? _routeDate
            : Fmt.str(res['route_date']);
        _radiusM = Fmt.toInt(res['visit_radius_m']);
        if (_radiusM < 1) _radiusM = 200;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  String _statusLabel(String s) {
    switch (s) {
      case 'checked_in':
        return 'داخل الزيارة';
      case 'checked_out':
        return 'تم الخروج';
      case 'pending_manual_checkout':
        return 'بانتظار موافقة المدير';
      default:
        return 'لم يُسجَّل دخول';
    }
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'checked_in':
        return AppTheme.teal;
      case 'checked_out':
        return AppTheme.success;
      case 'pending_manual_checkout':
        return Colors.orange.shade800;
      default:
        return AppTheme.textSoft;
    }
  }

  Future<Map<String, dynamic>?> _gpsFields() async {
    final pos = await LocationService.tryGetPosition();
    if (pos == null) return null;
    return {
      'latitude': pos.latitude,
      'longitude': pos.longitude,
      'accuracy': pos.accuracy,
    };
  }

  Future<void> _checkin(Map<String, dynamic> v, {required bool manual}) async {
    if (_busy) return;
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    setState(() => _busy = true);
    try {
      Map<String, dynamic> gps = {};
      if (!manual) {
        final g = await _gpsFields();
        if (g == null) {
          if (!mounted) return;
          showSnack(context, 'تعذّر قراءة GPS. جرّب دخولاً يدوياً.', error: true);
          return;
        }
        gps = g;
      } else {
        gps = await _gpsFields() ?? {};
      }
      final res = await api.postJson(
            AppConfig.repVisitCheckinPath,
            body: {
              'customer_id': v['customer_id'],
              'method': manual ? 'MANUAL' : 'GPS',
              ...gps,
            },
            csrf: csrf,
          );
      if (!mounted) return;
      showSnack(context, Fmt.str(res['message']).isEmpty ? 'تم' : Fmt.str(res['message']));
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _checkout(Map<String, dynamic> v, {required bool manual}) async {
    if (_busy) return;
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    String? reason;
    if (manual) {
      reason = await showDialog<String>(
        context: context,
        builder: (ctx) {
          final c = TextEditingController(text: 'نسي الخروج بـ GPS من موقع العميل');
          return AlertDialog(
            title: const Text('خروج يدوي'),
            content: TextField(
              controller: c,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'السبب',
                hintText: 'لماذا الخروج يدوياً؟',
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, c.text.trim()),
                child: const Text('متابعة'),
              ),
            ],
          );
        },
      );
      if (reason == null) return;
    }
    setState(() => _busy = true);
    try {
      Map<String, dynamic> gps = {};
      if (!manual) {
        final g = await _gpsFields();
        if (g == null) {
          if (!mounted) return;
          showSnack(context, 'تعذّر قراءة GPS. جرّب خروجاً يدوياً.', error: true);
          return;
        }
        gps = g;
      } else {
        gps = await _gpsFields() ?? {};
      }
      final res = await api.postJson(
            AppConfig.repVisitCheckoutPath,
            body: {
              'customer_id': v['customer_id'],
              'method': manual ? 'MANUAL' : 'GPS',
              if (reason != null && reason.isNotEmpty) 'reason': reason,
              ...gps,
            },
            csrf: csrf,
          );
      if (!mounted) return;
      final msg = Fmt.str(res['message']);
      final needsApproval = res['requires_approval'] == true;
      showSnack(
        context,
        msg.isEmpty
            ? (needsApproval ? 'بانتظار موافقة المدير' : 'تم')
            : msg,
      );
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openActions(Map<String, dynamic> v) async {
    final status = Fmt.str(v['status']);
    await showModalBottomSheet<void>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  Fmt.str(v['name']),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                ),
                Text(
                  Fmt.str(v['code']),
                  style: TextStyle(color: AppTheme.textSoft, fontSize: 13),
                ),
                const SizedBox(height: 6),
                Text(
                  _statusLabel(status),
                  style: TextStyle(
                    color: _statusColor(status),
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 14),
                if (status == 'idle' || status == 'checked_out') ...[
                  FilledButton.icon(
                    onPressed: () {
                      Navigator.pop(ctx);
                      _checkin(v, manual: false);
                    },
                    icon: const Icon(Icons.my_location_rounded),
                    label: const Text('تسجيل دخول GPS (افتراضي)'),
                  ),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () {
                      Navigator.pop(ctx);
                      _checkin(v, manual: true);
                    },
                    icon: const Icon(Icons.edit_location_alt_rounded),
                    label: const Text('تسجيل دخول يدوي'),
                  ),
                ],
                if (status == 'checked_in') ...[
                  FilledButton.icon(
                    onPressed: () {
                      Navigator.pop(ctx);
                      _checkout(v, manual: false);
                    },
                    icon: const Icon(Icons.logout_rounded),
                    label: const Text('تسجيل خروج GPS'),
                  ),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () {
                      Navigator.pop(ctx);
                      _checkout(v, manual: true);
                    },
                    icon: const Icon(Icons.logout_rounded),
                    label: const Text('تسجيل خروج يدوي'),
                  ),
                ],
                if (status == 'pending_manual_checkout')
                  const Text(
                    'طلب الخروج اليدوي بانتظار موافقة المسؤول من شاشة ويندوز.',
                    style: TextStyle(height: 1.4),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('دخول / خروج زيارة'),
      actions: [
        IconButton(
          onPressed: _busy ? null : _load,
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 8, 14, 4),
            child: Text(
              'نصف القطر المسموح حول العميل: $_radiusM م · التاريخ $_routeDate',
              style: TextStyle(color: AppTheme.textSoft, fontSize: 12.5),
            ),
          ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _visits.isEmpty
                  ? const EmptyState(
                      message: 'لا عملاء في خط سير اليوم. تأكد من الجولة وربط العملاء بالمندوب.',
                      icon: Icons.route_rounded,
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(12, 6, 12, 24),
                      itemCount: _visits.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (_, i) {
                        final v = _visits[i];
                        final status = Fmt.str(v['status']);
                        return Material(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(14),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(14),
                            onTap: _busy ? null : () => _openActions(v),
                            child: Padding(
                              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    backgroundColor:
                                        _statusColor(status).withValues(alpha: 0.12),
                                    child: Icon(
                                      status == 'checked_in'
                                          ? Icons.login_rounded
                                          : status == 'checked_out'
                                              ? Icons.check_rounded
                                              : Icons.storefront_rounded,
                                      color: _statusColor(status),
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          Fmt.str(v['name']),
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w800,
                                          ),
                                        ),
                                        Text(
                                          Fmt.str(v['code']),
                                          style: TextStyle(
                                            color: AppTheme.textSoft,
                                            fontSize: 12.5,
                                          ),
                                        ),
                                        const SizedBox(height: 4),
                                        Text(
                                          _statusLabel(status),
                                          style: TextStyle(
                                            color: _statusColor(status),
                                            fontWeight: FontWeight.w700,
                                            fontSize: 12.5,
                                          ),
                                        ),
                                        if (Fmt.str(v['checkin_method']).isNotEmpty)
                                          Text(
                                            'دخول: ${Fmt.str(v['checkin_method'])}'
                                            '${Fmt.str(v['checkout_method']).isNotEmpty ? ' · خروج: ${Fmt.str(v['checkout_method'])}' : ''}',
                                            style: TextStyle(
                                              color: AppTheme.textSoft,
                                              fontSize: 11.5,
                                            ),
                                          ),
                                      ],
                                    ),
                                  ),
                                  const Icon(Icons.chevron_left_rounded),
                                ],
                              ),
                            ),
                          ),
                        );
                      },
                    ),
            ),
          ),
          if (_busy)
            const LinearProgressIndicator(minHeight: 2),
        ],
      ),
    );
  }
}
