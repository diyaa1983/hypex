import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_tracking_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  static const _intervals = <int, String>{
    30: 'كل 30 ثانية',
    60: 'كل دقيقة',
    120: 'كل دقيقتين',
    300: 'كل 5 دقائق',
    600: 'كل 10 دقائق',
  };

  static const _distances = <int, String>{
    0: 'دائماً (بدون شرط)',
    15: 'بعد 15 متر',
    30: 'بعد 30 متر',
    50: 'بعد 50 متر',
    100: 'بعد 100 متر',
  };

  TrackingStatus? _status;
  bool _busy = false;
  Timer? _refresh;

  @override
  void initState() {
    super.initState();
    _loadStatus();
    _refresh = Timer.periodic(
      const Duration(seconds: 5),
      (_) => _loadStatus(),
    );
    FlutterForegroundTask.addTaskDataCallback(_onTaskData);
  }

  @override
  void dispose() {
    _refresh?.cancel();
    FlutterForegroundTask.removeTaskDataCallback(_onTaskData);
    super.dispose();
  }

  void _onTaskData(Object data) => _loadStatus();

  Future<void> _loadStatus() async {
    final s = await LocationTrackingService.status();
    if (mounted) setState(() => _status = s);
  }

  Future<void> _toggle(bool on) async {
    setState(() => _busy = true);
    try {
      if (on) {
        final error = await LocationTrackingService.start();
        if (!mounted) return;
        if (error != null) {
          showSnack(context, error, error: true);
        } else {
          showSnack(context, 'تم تشغيل خدمة تتبّع الموقع.');
        }
      } else {
        await LocationTrackingService.stop();
        if (!mounted) return;
        showSnack(context, 'تم إيقاف خدمة التتبّع.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
      await _loadStatus();
    }
  }

  Future<void> _pickInterval() async {
    final current = _status?.intervalSec ?? LocationTrackingService.defaultIntervalSec;
    final picked = await _pickOption(
      title: 'مدة الإرسال',
      options: _intervals,
      current: current,
    );
    if (picked == null) return;
    await LocationTrackingService.setInterval(picked);
    await _loadStatus();
  }

  Future<void> _pickDistance() async {
    final current = _status?.minDistance ?? LocationTrackingService.defaultMinDistance;
    final picked = await _pickOption(
      title: 'أقل مسافة للإرسال',
      options: _distances,
      current: current,
    );
    if (picked == null) return;
    await LocationTrackingService.setMinDistance(picked);
    await _loadStatus();
  }

  Future<int?> _pickOption({
    required String title,
    required Map<int, String> options,
    required int current,
  }) {
    return showModalBottomSheet<int>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 12),
            Container(
              width: 42,
              height: 4,
              decoration: BoxDecoration(
                color: AppTheme.border,
                borderRadius: BorderRadius.circular(4),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(
                title,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            ...options.entries.map(
              (e) => ListTile(
                leading: Icon(
                  e.key == current
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_unchecked_rounded,
                  color: e.key == current ? AppTheme.primary : AppTheme.textSoft,
                  size: 20,
                ),
                title: Text(e.value),
                onTap: () => Navigator.pop(ctx, e.key),
              ),
            ),
            const SizedBox(height: 10),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    final st = _status;
    final running = st?.running ?? false;

    return Scaffold(
      appBar: AppBar(title: const Text('الإعدادات')),
      body: RefreshIndicator(
        onRefresh: _loadStatus,
        child: ListView(
          padding: const EdgeInsets.all(14),
          children: [
            AppCard(
              child: Row(
                children: [
                  Container(
                    width: 46,
                    height: 46,
                    decoration: const BoxDecoration(
                      gradient: AppTheme.brandGradient,
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(
                      Icons.person_rounded,
                      color: Colors.white,
                      size: 24,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          s.userName ?? 'مستخدم',
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          s.api.base.replaceFirst(RegExp(r'^https?://'), ''),
                          style: const TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSoft,
                          ),
                          textDirection: TextDirection.ltr,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            const SectionTitle('تتبّع الموقع', icon: Icons.my_location_rounded),
            AppCard(
              padding: EdgeInsets.zero,
              child: Column(
                children: [
                  SwitchListTile(
                    value: running,
                    onChanged: _busy ? null : _toggle,
                    secondary: MiniIcon(
                      running
                          ? Icons.location_on_rounded
                          : Icons.location_off_rounded,
                      color: running ? AppTheme.success : AppTheme.textSoft,
                    ),
                    title: const Text(
                      'خدمة التتبّع في الخلفية',
                      style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    subtitle: Text(
                      running
                          ? 'تعمل الآن وتستمر بعد إغلاق التطبيق'
                          : 'متوقفة — لن يُرسل موقعك',
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppTheme.textSoft,
                      ),
                    ),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    enabled: !_busy,
                    leading: const MiniIcon(
                      Icons.timer_outlined,
                      color: AppTheme.violet,
                    ),
                    title: const Text('مدة الإرسال'),
                    subtitle: Text(
                      _intervals[st?.intervalSec] ??
                          LocationTrackingService.humanInterval(
                            st?.intervalSec ??
                                LocationTrackingService.defaultIntervalSec,
                          ),
                    ),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: _pickInterval,
                  ),
                  const Divider(height: 1),
                  ListTile(
                    enabled: !_busy,
                    leading: const MiniIcon(
                      Icons.social_distance_rounded,
                      color: AppTheme.teal,
                    ),
                    title: const Text('أقل مسافة للإرسال'),
                    subtitle: Text(_distances[st?.minDistance] ?? 'بعد 30 متر'),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: _pickDistance,
                  ),
                ],
              ),
            ),

            if (st != null) ...[
              const SectionTitle('حالة الخدمة', icon: Icons.insights_rounded),
              AppCard(
                child: Column(
                  children: [
                    Row(
                      children: [
                        StatusPill(
                          text: running ? 'نشِطة' : 'متوقفة',
                          color: running ? AppTheme.success : AppTheme.textSoft,
                          icon: running
                              ? Icons.play_circle_fill_rounded
                              : Icons.pause_circle_filled_rounded,
                          dense: false,
                        ),
                        const Spacer(),
                        Text(
                          '${st.sentCount} نقطة مُرسلة',
                          style: const TextStyle(
                            fontSize: 12.5,
                            color: AppTheme.textSoft,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                    const Divider(),
                    InfoRow('آخر إرسال', _fmtTime(st.lastPing)),
                    if (st.lastLat != null && st.lastLng != null)
                      InfoRow(
                        'آخر إحداثية',
                        '${st.lastLat!.toStringAsFixed(5)}, '
                            '${st.lastLng!.toStringAsFixed(5)}',
                        ltr: true,
                      ),
                    if (st.lastStatus.isNotEmpty)
                      InfoRow('الحالة', st.lastStatus),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: ActionChipButton(
                            icon: Icons.send_rounded,
                            label: 'إرسال الآن',
                            color: AppTheme.primary,
                            onTap: running
                                ? () {
                                    LocationTrackingService
                                        .requestImmediatePing();
                                    showSnack(context, 'تم طلب إرسال الموقع.');
                                  }
                                : null,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: ActionChipButton(
                            icon: Icons.battery_saver_rounded,
                            label: 'استثناء البطارية',
                            color: AppTheme.amber,
                            onTap: () => FlutterForegroundTask
                                .requestIgnoreBatteryOptimization(),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],

            const SectionTitle('عام', icon: Icons.tune_rounded),
            AppCard(
              padding: EdgeInsets.zero,
              child: Column(
                children: [
                  ListTile(
                    leading: const MiniIcon(
                      Icons.dns_rounded,
                      color: AppTheme.primary,
                    ),
                    title: const Text('عنوان السيرفر'),
                    subtitle: Text(
                      s.api.base,
                      textDirection: TextDirection.ltr,
                      overflow: TextOverflow.ellipsis,
                    ),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: () => context.push('/server'),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const MiniIcon(
                      Icons.app_settings_alt_rounded,
                      color: AppTheme.violet,
                    ),
                    title: const Text('أذونات التطبيق'),
                    subtitle: const Text('الموقع والإشعارات'),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: () async {
                      final err =
                          await LocationTrackingService.requestPermissions();
                      if (!context.mounted) return;
                      showSnack(
                        context,
                        err ?? 'كل الأذونات ممنوحة.',
                        error: err != null,
                      );
                    },
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const MiniIcon(
                      Icons.logout_rounded,
                      color: AppTheme.danger,
                    ),
                    title: const Text(
                      'تسجيل الخروج',
                      style: TextStyle(color: AppTheme.danger),
                    ),
                    onTap: () => _confirmLogout(s),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            const Center(
              child: Text(
                'النماء • الإصدار 1.0.0',
                style: TextStyle(fontSize: 12, color: AppTheme.textSoft),
              ),
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }

  Future<void> _confirmLogout(SessionController s) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تسجيل الخروج'),
        content: const Text('سيتم إيقاف خدمة التتبّع ومسح بيانات الدخول.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppTheme.danger,
              minimumSize: const Size(100, 42),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('خروج'),
          ),
        ],
      ),
    );
    if (ok == true) await s.logout();
  }

  String _fmtTime(DateTime? t) {
    if (t == null) return 'لم يُرسل بعد';
    final diff = DateTime.now().difference(t);
    final hh = t.hour.toString().padLeft(2, '0');
    final mm = t.minute.toString().padLeft(2, '0');
    if (diff.inMinutes < 1) return 'الآن ($hh:$mm)';
    if (diff.inMinutes < 60) return 'قبل ${diff.inMinutes} دقيقة ($hh:$mm)';
    if (diff.inHours < 24) return 'قبل ${diff.inHours} ساعة ($hh:$mm)';
    return '${t.year}-${t.month.toString().padLeft(2, '0')}-'
        '${t.day.toString().padLeft(2, '0')} $hh:$mm';
  }
}
