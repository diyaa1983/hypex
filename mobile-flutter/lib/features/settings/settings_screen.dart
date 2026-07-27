import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_presence_service.dart';
import '../../services/location_tracking_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
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
      final session = context.read<SessionController>();
      if (on) {
        final error = await LocationTrackingService.start();
        if (!mounted) return;
        if (error != null) {
          showSnack(context, error, error: true);
        } else {
        await LocationPresenceService.start(
          api: session.api,
          csrf: session.csrf,
          intervalSec: session.gpsConfig.intervalSec,
        );
          if (!mounted) return;
          showSnack(context, 'تم تشغيل خدمة تتبّع الموقع.');
        }
      } else {
        await LocationPresenceService.stop();
        await LocationTrackingService.stop();
        if (!mounted) return;
        showSnack(context, 'تم إيقاف خدمة التتبّع.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
      await _loadStatus();
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    final st = _status;
    final running = st?.running ?? false;
    final gps = s.gpsConfig;
    final canToggle = gps.userCanDisable;

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
                  if (canToggle)
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
                    )
                  else
                    ListTile(
                      leading: MiniIcon(
                        running
                            ? Icons.location_on_rounded
                            : Icons.location_off_rounded,
                        color: running ? AppTheme.success : AppTheme.textSoft,
                      ),
                      title: const Text(
                        'تتبّع الموقع',
                        style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      subtitle: Text(
                        gps.autoEnable
                            ? (running
                                ? 'مفعّل تلقائياً من النظام — يعمل في الخلفية'
                                : 'مفعّل من النظام — بانتظار الأذونات أو GPS')
                            : 'يُدار من إعدادات النظام على السيرفر',
                        style: const TextStyle(
                          fontSize: 12,
                          color: AppTheme.textSoft,
                        ),
                      ),
                      trailing: StatusPill(
                        text: running ? 'نشِط' : 'متوقف',
                        color: running ? AppTheme.success : AppTheme.textSoft,
                        dense: true,
                      ),
                    ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const MiniIcon(
                      Icons.timer_outlined,
                      color: AppTheme.violet,
                    ),
                    title: const Text('مدة الإرسال'),
                    subtitle: Text(gps.intervalLabel),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const MiniIcon(
                      Icons.social_distance_rounded,
                      color: AppTheme.teal,
                    ),
                    title: const Text('أقل مسافة للإرسال'),
                    subtitle: Text(gps.distanceLabel),
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
                            onTap: () async {
                              LocationTrackingService.requestImmediatePing();
                              final session = context.read<SessionController>();
                              LocationPresenceService.bind(
                                session.api,
                                csrf: session.csrf,
                              );
                              final ok =
                                  await LocationPresenceService.pingNow(
                                force: true,
                              );
                              if (!context.mounted) return;
                              showSnack(
                                context,
                                ok
                                    ? (LocationPresenceService.lastMessage.isEmpty
                                        ? 'تم إرسال الموقع.'
                                        : LocationPresenceService.lastMessage)
                                    : (LocationPresenceService.lastMessage.isEmpty
                                        ? 'تعذّر الإرسال.'
                                        : LocationPresenceService.lastMessage),
                                error: !ok,
                              );
                              await _loadStatus();
                            },
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
