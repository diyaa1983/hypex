import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
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
  bool _iosNeedsAlways = false;
  bool _verifying = false;
  Timer? _refresh;
  final _adminUserCtrl = TextEditingController();
  final _adminPassCtrl = TextEditingController();
  String? _unlockError;

  @override
  void initState() {
    super.initState();
    _loadStatus();
    _refresh = Timer.periodic(
      const Duration(seconds: 5),
      (_) => _loadStatus(),
    );
    FlutterForegroundTask.addTaskDataCallback(_onTaskData);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final s = context.read<SessionController>();
      if ((_adminUserCtrl.text.isEmpty) &&
          (s.userUsername?.isNotEmpty ?? false) &&
          s.isSystemAdmin) {
        _adminUserCtrl.text = s.userUsername!;
      }
    });
  }

  @override
  void dispose() {
    _refresh?.cancel();
    FlutterForegroundTask.removeTaskDataCallback(_onTaskData);
    _adminUserCtrl.dispose();
    _adminPassCtrl.dispose();
    super.dispose();
  }

  void _onTaskData(Object data) => _loadStatus();

  Future<void> _loadStatus() async {
    final s = await LocationTrackingService.status();
    bool needsAlways = false;
    if (!kIsWeb && Platform.isIOS) {
      needsAlways = !(await LocationTrackingService.hasAlwaysPermission);
    }
    if (mounted) {
      setState(() {
        _status = s;
        _iosNeedsAlways = needsAlways;
      });
    }
  }

  Future<void> _unlock() async {
    final user = _adminUserCtrl.text.trim();
    final pass = _adminPassCtrl.text;
    if (user.isEmpty || pass.isEmpty) {
      setState(() => _unlockError = 'أدخل اسم مستخدم المدير وكلمة المرور.');
      return;
    }
    setState(() {
      _verifying = true;
      _unlockError = null;
    });
    final err =
        await context.read<SessionController>().verifyAdminPassword(user, pass);
    if (!mounted) return;
    setState(() {
      _verifying = false;
      _unlockError = err;
      if (err == null) {
        _adminPassCtrl.clear();
      }
    });
    if (err == null) {
      showSnack(context, 'تم فتح الإعدادات بصلاحية المدير.');
      await _loadStatus();
    }
  }

  Future<void> _toggle(bool on) async {
    setState(() => _busy = true);
    try {
      final session = context.read<SessionController>();
      if (!session.settingsUnlocked) {
        showSnack(context, 'يلزم فتح الإعدادات بكلمة مرور المدير أولاً.',
            error: true);
        return;
      }
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
          final tip = await LocationTrackingService.backgroundPermissionTip();
          if (!mounted) return;
          if (tip != null) {
            showSnack(context, tip);
          } else {
            showSnack(context, 'تم تشغيل خدمة تتبّع الموقع.');
          }
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
    if (!s.settingsUnlocked) {
      return _buildLocked(s);
    }
    return _buildUnlocked(s);
  }

  Widget _buildLocked(SessionController s) {
    final st = _status;
    final running = st?.running ?? false;

    return Scaffold(
      appBar: AppBar(title: const Text('الإعدادات')),
      body: ListView(
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
                        running ? 'تتبّع الموقع يعمل' : 'تتبّع الموقع متوقف',
                        style: TextStyle(
                          fontSize: 12,
                          color: running ? AppTheme.success : AppTheme.textSoft,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SectionTitle('فتح الإعدادات', icon: Icons.lock_rounded),
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'تعديل تتبّع الموقع متاح لمدير النظام فقط. أدخل بيانات أي حساب ضمن مجموعة ADMINS.',
                  style: TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSoft,
                    height: 1.45,
                  ),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _adminUserCtrl,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'اسم مستخدم المدير',
                    prefixIcon: Icon(Icons.admin_panel_settings_rounded),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _adminPassCtrl,
                  obscureText: true,
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _verifying ? null : _unlock(),
                  decoration: const InputDecoration(
                    labelText: 'كلمة مرور المدير',
                    prefixIcon: Icon(Icons.password_rounded),
                  ),
                ),
                if (_unlockError != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    _unlockError!,
                    style: const TextStyle(
                      color: AppTheme.danger,
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
                const SizedBox(height: 14),
                FilledButton.icon(
                  onPressed: _verifying ? null : _unlock,
                  icon: _verifying
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.lock_open_rounded),
                  label: Text(_verifying ? 'جاري التحقق...' : 'فتح الإعدادات'),
                ),
              ],
            ),
          ),
          const SectionTitle('عام', icon: Icons.tune_rounded),
          AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
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
          ),
        ],
      ),
    );
  }

  Widget _buildUnlocked(SessionController s) {
    final st = _status;
    final running = st?.running ?? false;
    final gps = s.gpsConfig;

    return Scaffold(
      appBar: AppBar(
        title: const Text('الإعدادات'),
        actions: [
          TextButton(
            onPressed: () {
              s.lockSettings();
              showSnack(context, 'تم إغلاق الإعدادات.');
            },
            child: const Text('إغلاق القفل'),
          ),
        ],
      ),
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
                  const StatusPill(
                    text: 'مدير',
                    color: AppTheme.primary,
                    dense: true,
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
                          ? (!kIsWeb && Platform.isIOS
                              ? (_iosNeedsAlways
                                  ? 'تعمل — فعّل «دائماً» لاستمرار الخلفية'
                                  : 'تعمل في الخلفية على الآيفون')
                              : 'تعمل الآن وتستمر بعد إغلاق التطبيق')
                          : 'متوقفة — لن يُرسل موقعك',
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppTheme.textSoft,
                      ),
                    ),
                  ),
                  if (!kIsWeb && Platform.isIOS && _iosNeedsAlways) ...[
                    const Divider(height: 1),
                    ListTile(
                      leading: const MiniIcon(
                        Icons.phone_iphone_rounded,
                        color: AppTheme.warn,
                      ),
                      title: const Text('إذن الموقع على الآيفون'),
                      subtitle: const Text(
                        'اختر «دائماً» حتى يستمر التتبّع والتطبيق مغلق أو في الخلفية.',
                        style: TextStyle(fontSize: 12, color: AppTheme.textSoft),
                      ),
                      trailing: TextButton(
                        onPressed: () =>
                            LocationTrackingService.openAppSettings(),
                        child: const Text('الإعدادات'),
                      ),
                    ),
                  ],
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
                              final ok = await LocationPresenceService.pingNow(
                                force: true,
                              );
                              if (!context.mounted) return;
                              showSnack(
                                context,
                                ok
                                    ? (LocationPresenceService
                                            .lastMessage.isEmpty
                                        ? 'تم إرسال الموقع.'
                                        : LocationPresenceService.lastMessage)
                                    : (LocationPresenceService
                                            .lastMessage.isEmpty
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
