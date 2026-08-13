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
import '../../widgets/party_picker.dart';

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
  Map<String, dynamic>? _selected;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _routeDate = Fmt.todayIso();
    _load();
  }

  Future<void> _load({int? keepCustomerId}) async {
    final keepId = keepCustomerId ?? Fmt.toInt(_selected?['customer_id']);
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
      final visits = (res['visits'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      Map<String, dynamic>? selected;
      if (keepId > 0) {
        for (final v in visits) {
          if (Fmt.toInt(v['customer_id']) == keepId) {
            selected = v;
            break;
          }
        }
      }
      setState(() {
        _visits = visits;
        _selected = selected;
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

  String _statusOf(Map<String, dynamic>? v) => Fmt.str(v?['status']);

  String _statusLabel(String s) {
    switch (s) {
      case 'checked_in':
        return 'داخل الزيارة';
      case 'checked_out':
        return 'تم الخروج';
      case 'pending_manual_checkout':
        return 'بانتظار موافقة المدير';
      default:
        return 'جاهز للدخول';
    }
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'checked_in':
        return AppTheme.teal;
      case 'checked_out':
        return AppTheme.success;
      case 'pending_manual_checkout':
        return AppTheme.warn;
      default:
        return AppTheme.primary;
    }
  }

  IconData _statusIcon(String s) {
    switch (s) {
      case 'checked_in':
        return Icons.login_rounded;
      case 'checked_out':
        return Icons.check_circle_rounded;
      case 'pending_manual_checkout':
        return Icons.hourglass_top_rounded;
      default:
        return Icons.storefront_rounded;
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

  Future<void> _pickCustomer() async {
    if (_busy) return;
    if (_visits.isNotEmpty) {
      final picked = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (ctx) => _CustomerPickSheet(visits: _visits),
      );
      if (picked != null && mounted) {
        setState(() => _selected = picked);
      }
      return;
    }
    final party = await pickParty(context);
    if (party == null || !mounted) return;
    setState(() {
      _selected = {
        'customer_id': party.id,
        'name': party.name,
        'code': party.code,
        'status': 'idle',
        'checkin_method': '',
        'checkout_method': '',
      };
    });
  }

  Future<void> _checkin({required bool manual}) async {
    final v = _selected;
    if (v == null || _busy) return;
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
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty ? 'تم تسجيل الدخول' : Fmt.str(res['message']),
      );
      await _load(keepCustomerId: Fmt.toInt(v['customer_id']));
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _checkout({required bool manual}) async {
    final v = _selected;
    if (v == null || _busy) return;
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
            ? (needsApproval ? 'بانتظار موافقة المدير' : 'تم تسجيل الخروج')
            : msg,
      );
      await _load(keepCustomerId: Fmt.toInt(v['customer_id']));
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('تسجيل زيارة'),
      backgroundColor: const Color(0xFFF0F4F8),
      actions: [
        IconButton(
          onPressed: _busy
              ? null
              : () => _load(keepCustomerId: Fmt.toInt(_selected?['customer_id'])),
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: AsyncView(
        loading: _loading && _selected == null,
        error: _error,
        onRetry: _load,
        child: Stack(
          children: [
            Positioned(
              top: -80,
              left: -40,
              child: _blob(160, AppTheme.primary.withValues(alpha: 0.08)),
            ),
            Positioned(
              bottom: 40,
              right: -50,
              child: _blob(200, AppTheme.teal.withValues(alpha: 0.07)),
            ),
            if (_selected == null) _buildEmpty() else _buildVisit(),
            if (_busy)
              const Positioned(
                left: 0,
                right: 0,
                top: 0,
                child: LinearProgressIndicator(minHeight: 2),
              ),
          ],
        ),
      ),
    );
  }

  Widget _blob(double size, Color color) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(shape: BoxShape.circle, color: color),
      ),
    );
  }

  Widget _buildEmpty() {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(28, 24, 28, 40),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 120,
              height: 120,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  begin: Alignment.topRight,
                  end: Alignment.bottomLeft,
                  colors: [
                    AppTheme.primary.withValues(alpha: 0.14),
                    AppTheme.teal.withValues(alpha: 0.12),
                  ],
                ),
                boxShadow: AppTheme.softShadow,
              ),
              child: const Icon(
                Icons.person_search_rounded,
                size: 54,
                color: AppTheme.primary,
              ),
            ),
            const SizedBox(height: 28),
            const Text(
              'تسجيل زيارة عميل',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 24,
                fontWeight: FontWeight.w800,
                letterSpacing: -0.3,
                color: AppTheme.textMain,
              ),
            ),
            const SizedBox(height: 10),
            const Text(
              'اختر العميل من القائمة ثم سجّل الدخول\nبـ GPS أو يدوياً.',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 14.5,
                height: 1.55,
                color: AppTheme.textSoft,
              ),
            ),
            const SizedBox(height: 32),
            SizedBox(
              width: double.infinity,
              height: 54,
              child: FilledButton.icon(
                onPressed: _busy ? null : _pickCustomer,
                icon: const Icon(Icons.list_alt_rounded),
                label: const Text(
                  'اختيار العميل',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                ),
                style: FilledButton.styleFrom(
                  backgroundColor: AppTheme.primary,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 14),
            Text(
              'نصف القطر المسموح حول العميل: $_radiusM م',
              style: TextStyle(
                fontSize: 12.5,
                color: AppTheme.textSoft.withValues(alpha: 0.9),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildVisit() {
    final v = _selected!;
    final status = _statusOf(v);
    final color = _statusColor(status);
    final canIn = status == 'idle' || status == 'checked_out' || status.isEmpty;
    final canOut = status == 'checked_in';
    final pending = status == 'pending_manual_checkout';

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      child: Column(
        children: [
          _CustomerChip(
            name: Fmt.str(v['name']),
            code: Fmt.str(v['code']),
            onChange: _busy
                ? null
                : () {
                    setState(() => _selected = null);
                    _pickCustomer();
                  },
            onClear: _busy ? null : () => setState(() => _selected = null),
          ),
          const SizedBox(height: 28),
          _StatusHero(
            icon: _statusIcon(status),
            color: color,
            label: _statusLabel(status),
            subtitle: _statusSubtitle(v, status),
          ),
          const SizedBox(height: 28),
          if (canIn)
            _ActionCard(
              title: 'تسجيل الدخول',
              child: Column(
                children: [
                  _BigActionButton(
                    label: 'دخول GPS',
                    hint: 'الافتراضي · داخل $_radiusM م',
                    icon: Icons.my_location_rounded,
                    color: AppTheme.primary,
                    onPressed: _busy ? null : () => _checkin(manual: false),
                  ),
                  const SizedBox(height: 10),
                  _BigActionButton(
                    label: 'دخول يدوي',
                    hint: 'بدون شرط الموقع',
                    icon: Icons.edit_location_alt_rounded,
                    color: AppTheme.teal,
                    outlined: true,
                    onPressed: _busy ? null : () => _checkin(manual: true),
                  ),
                ],
              ),
            ),
          if (canOut)
            _ActionCard(
              title: 'تسجيل الخروج',
              child: Column(
                children: [
                  _BigActionButton(
                    label: 'خروج GPS',
                    hint: 'من موقع العميل',
                    icon: Icons.logout_rounded,
                    color: AppTheme.teal,
                    onPressed: _busy ? null : () => _checkout(manual: false),
                  ),
                  const SizedBox(height: 10),
                  _BigActionButton(
                    label: 'خروج يدوي',
                    hint: 'يحتاج موافقة إذا كان الدخول GPS',
                    icon: Icons.output_rounded,
                    color: AppTheme.warn,
                    outlined: true,
                    onPressed: _busy ? null : () => _checkout(manual: true),
                  ),
                ],
              ),
            ),
          if (pending)
            const _ActionCard(
              title: 'بانتظار الموافقة',
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    Icon(Icons.notifications_active_rounded, color: AppTheme.warn),
                    SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'تم إرسال إشعار للمسؤول على الويندوز لاعتماد الخروج اليدوي.',
                        style: TextStyle(height: 1.45, fontSize: 14),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          if (Fmt.str(v['checkin_method']).isNotEmpty ||
              Fmt.str(v['checkout_method']).isNotEmpty) ...[
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: [
                if (Fmt.str(v['checkin_method']).isNotEmpty)
                  _MetaChip(
                    icon: Icons.login_rounded,
                    text: 'دخول ${Fmt.str(v['checkin_method'])}',
                  ),
                if (Fmt.str(v['checkout_method']).isNotEmpty)
                  _MetaChip(
                    icon: Icons.logout_rounded,
                    text: 'خروج ${Fmt.str(v['checkout_method'])}',
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  String _statusSubtitle(Map<String, dynamic> v, String status) {
    if (status == 'checked_in') {
      final at = Fmt.str(v['visit_checkin_at']);
      return at.isEmpty ? 'الزيارة مفتوحة الآن' : 'دخول: $at';
    }
    if (status == 'checked_out') {
      final at = Fmt.str(v['visit_checkout_at']);
      return at.isEmpty ? 'انتهت الزيارة' : 'خروج: $at';
    }
    if (status == 'pending_manual_checkout') {
      return 'لن تُغلق الزيارة قبل اعتماد المدير';
    }
    return 'اضغط دخول GPS وأنت عند العميل';
  }
}

class _CustomerChip extends StatelessWidget {
  const _CustomerChip({
    required this.name,
    required this.code,
    this.onChange,
    this.onClear,
  });

  final String name;
  final String code;
  final VoidCallback? onChange;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppTheme.border),
        boxShadow: AppTheme.softShadow,
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              gradient: AppTheme.brandGradient,
            ),
            child: const Icon(Icons.business_rounded, color: Colors.white, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5),
                ),
                if (code.isNotEmpty)
                  Text(
                    code,
                    style: const TextStyle(color: AppTheme.textSoft, fontSize: 12.5),
                  ),
              ],
            ),
          ),
          TextButton(onPressed: onChange, child: const Text('تغيير')),
          IconButton(
            tooltip: 'مسح',
            onPressed: onClear,
            icon: const Icon(Icons.close_rounded, size: 20),
            color: AppTheme.textSoft,
          ),
        ],
      ),
    );
  }
}

class _StatusHero extends StatelessWidget {
  const _StatusHero({
    required this.icon,
    required this.color,
    required this.label,
    required this.subtitle,
  });

  final IconData icon;
  final Color color;
  final String label;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: 148,
          height: 148,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: Colors.white,
            boxShadow: [
              BoxShadow(
                color: color.withValues(alpha: 0.22),
                blurRadius: 28,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: Center(
            child: Container(
              width: 118,
              height: 118,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    color.withValues(alpha: 0.18),
                    color.withValues(alpha: 0.05),
                  ],
                ),
                border: Border.all(color: color.withValues(alpha: 0.35), width: 2),
              ),
              child: Icon(icon, size: 56, color: color),
            ),
          ),
        ),
        const SizedBox(height: 18),
        Text(
          label,
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 13.5, height: 1.4, color: AppTheme.textSoft),
        ),
      ],
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppTheme.border),
        boxShadow: AppTheme.softShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 14,
              color: AppTheme.textSoft,
            ),
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}

class _BigActionButton extends StatelessWidget {
  const _BigActionButton({
    required this.label,
    required this.hint,
    required this.icon,
    required this.color,
    this.outlined = false,
    this.onPressed,
  });

  final String label;
  final String hint;
  final IconData icon;
  final Color color;
  final bool outlined;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(16);
    final content = Row(
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: (outlined ? color : Colors.white).withValues(alpha: outlined ? 0.1 : 0.18),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icon, color: outlined ? color : Colors.white, size: 22),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: TextStyle(
                  color: outlined ? color : Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 15.5,
                ),
              ),
              Text(
                hint,
                style: TextStyle(
                  color: (outlined ? color : Colors.white).withValues(alpha: 0.78),
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
      ],
    );

    if (outlined) {
      return OutlinedButton(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          foregroundColor: color,
          side: BorderSide(color: color.withValues(alpha: 0.45)),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: radius),
        ),
        child: content,
      );
    }
    return FilledButton(
      onPressed: onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: color,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: radius),
      ),
      child: content,
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: AppTheme.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: AppTheme.textSoft),
          const SizedBox(width: 6),
          Text(text, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _CustomerPickSheet extends StatefulWidget {
  const _CustomerPickSheet({required this.visits});

  final List<Map<String, dynamic>> visits;

  @override
  State<_CustomerPickSheet> createState() => _CustomerPickSheetState();
}

class _CustomerPickSheetState extends State<_CustomerPickSheet> {
  final _q = TextEditingController();

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final q = _q.text.trim().toLowerCase();
    final items = widget.visits.where((v) {
      if (q.isEmpty) return true;
      final name = Fmt.str(v['name']).toLowerCase();
      final code = Fmt.str(v['code']).toLowerCase();
      return name.contains(q) || code.contains(q);
    }).toList();

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.72,
      minChildSize: 0.45,
      maxChildSize: 0.92,
      builder: (ctx, scroll) {
        return Container(
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
          ),
          child: Column(
            children: [
              const SizedBox(height: 10),
              Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: AppTheme.border,
                  borderRadius: BorderRadius.circular(99),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 14, 18, 8),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'اختيار العميل',
                        style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
                      ),
                    ),
                    Text('${items.length}', style: const TextStyle(color: AppTheme.textSoft)),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                child: TextField(
                  controller: _q,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    hintText: 'بحث بالاسم أو الرمز…',
                    prefixIcon: const Icon(Icons.search_rounded),
                    filled: true,
                    fillColor: AppTheme.surfaceAlt,
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide: BorderSide.none,
                    ),
                  ),
                ),
              ),
              Expanded(
                child: ListView.separated(
                  controller: scroll,
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 6),
                  itemBuilder: (_, i) {
                    final v = items[i];
                    final status = Fmt.str(v['status']);
                    final color = switch (status) {
                      'checked_in' => AppTheme.teal,
                      'checked_out' => AppTheme.success,
                      'pending_manual_checkout' => AppTheme.warn,
                      _ => AppTheme.primary,
                    };
                    return Material(
                      color: AppTheme.surfaceAlt,
                      borderRadius: BorderRadius.circular(14),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: () => Navigator.pop(ctx, v),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                          child: Row(
                            children: [
                              CircleAvatar(
                                backgroundColor: color.withValues(alpha: 0.12),
                                child: Icon(
                                  status == 'checked_in'
                                      ? Icons.login_rounded
                                      : Icons.storefront_rounded,
                                  color: color,
                                  size: 20,
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      Fmt.str(v['name']),
                                      style: const TextStyle(fontWeight: FontWeight.w700),
                                    ),
                                    Text(
                                      Fmt.str(v['code']),
                                      style: const TextStyle(
                                        color: AppTheme.textSoft,
                                        fontSize: 12.5,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const Icon(Icons.chevron_left_rounded, color: AppTheme.textSoft),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
