import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class ServerSetupScreen extends StatefulWidget {
  const ServerSetupScreen({super.key});

  @override
  State<ServerSetupScreen> createState() => _ServerSetupScreenState();
}

class _ServerSetupScreenState extends State<ServerSetupScreen> {
  late final TextEditingController _ctrl;
  bool _testing = false;
  bool _saving = false;

  bool get _firstRun => context.read<SessionController>().needsServerSetup;

  @override
  void initState() {
    super.initState();
    final base = context.read<SessionController>().api.base;
    _ctrl = TextEditingController(text: base);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _test() async {
    final s = context.read<SessionController>();
    final raw = _ctrl.text.trim();
    if (raw.isEmpty) {
      showSnack(context, 'أدخل عنوان السيرفر أو عنوان IP.', error: true);
      return;
    }
    setState(() => _testing = true);
    await s.saveServer(raw);
    if (!mounted) return;
    _ctrl.text = s.api.base;
    final ok = await s.ping();
    if (!mounted) return;
    setState(() => _testing = false);
    showSnack(
      context,
      ok ? 'الاتصال بالسيرفر ناجح.' : 'تعذر الاتصال بالسيرفر. تأكد من العنوان والشبكة.',
      error: !ok,
    );
  }

  Future<void> _connect() async {
    final s = context.read<SessionController>();
    final raw = _ctrl.text.trim();
    if (raw.isEmpty) {
      showSnack(context, 'أدخل عنوان السيرفر أو عنوان IP.', error: true);
      return;
    }
    setState(() => _saving = true);
    await s.saveServer(raw);
    if (!mounted) return;
    _ctrl.text = s.api.base;
    final ok = await s.ping();
    if (!mounted) return;
    setState(() => _saving = false);
    if (!ok) {
      showSnack(
        context,
        'تعذر الاتصال. يمكنك المتابعة لاحقاً أو تصحيح العنوان.',
        error: true,
      );
      // عند أول تثبيت نسمح بالمتابعة حتى لو فشل الفحص (شبكة غير جاهزة)
      if (!_firstRun) return;
    }
    if (!mounted) return;
    context.go('/login');
  }

  @override
  Widget build(BuildContext context) {
    final first = context.watch<SessionController>().needsServerSetup;
    final busy = _testing || _saving;

    return Scaffold(
      backgroundColor: AppTheme.surface,
      body: Stack(
        children: [
          Container(
            height: 240,
            decoration: const BoxDecoration(
              gradient: AppTheme.brandGradient,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(38)),
            ),
          ),
          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(22, 44, 22, 24),
              child: Column(
                children: [
                  Container(
                    width: 70,
                    height: 70,
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.2),
                      borderRadius: BorderRadius.circular(22),
                    ),
                    child: const Icon(
                      Icons.dns_rounded,
                      size: 34,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    first ? 'إعداد الاتصال لأول مرة' : 'إعداد السيرفر',
                    style: const TextStyle(
                      fontSize: 21,
                      fontWeight: FontWeight.w900,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    first
                        ? 'أدخل عنوان IP أو رابط سيرفر الشركة ثم اضغط اتصال'
                        : 'أدخل عنوان النظام ثم اضغط اتصال',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12.5,
                      color: Colors.white.withValues(alpha: 0.85),
                    ),
                  ),
                  const SizedBox(height: 30),
                  Container(
                    padding: const EdgeInsets.all(18),
                    decoration: BoxDecoration(
                      color: AppTheme.surface,
                      borderRadius: BorderRadius.circular(22),
                      boxShadow: AppTheme.softShadow,
                      border: Border.all(color: AppTheme.border),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        TextField(
                          controller: _ctrl,
                          textDirection: TextDirection.ltr,
                          keyboardType: TextInputType.url,
                          enabled: !busy,
                          decoration: const InputDecoration(
                            labelText: 'عنوان السيرفر / IP',
                            hintText: '192.168.1.10   أو   http://IP/hypex',
                            prefixIcon: Icon(Icons.link_rounded, size: 20),
                          ),
                        ),
                        const SizedBox(height: 10),
                        Text(
                          'أمثلة: 192.168.1.10  ·  176.29.176.192/hypex  ·  http://server/hypex',
                          textDirection: TextDirection.ltr,
                          style: const TextStyle(
                            fontSize: 11.5,
                            color: AppTheme.textSoft,
                            height: 1.35,
                          ),
                        ),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: busy ? null : _connect,
                          icon: _saving
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: Colors.white,
                                  ),
                                )
                              : const Icon(Icons.login_rounded, size: 19),
                          label: Text(first ? 'حفظ والاتصال' : 'اتصال'),
                        ),
                        const SizedBox(height: 10),
                        OutlinedButton.icon(
                          onPressed: busy ? null : _test,
                          icon: _testing
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                  ),
                                )
                              : const Icon(Icons.wifi_tethering_rounded,
                                  size: 19),
                          label: const Text('فحص الاتصال'),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 18),
                  const Row(
                    children: [
                      MiniIcon(
                        Icons.info_outline_rounded,
                        color: AppTheme.textSoft,
                        size: 30,
                        iconSize: 16,
                        radius: 9,
                      ),
                      SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'يمكنك إدخال IP فقط — يُضاف http و/hypex تلقائياً للشبكات المحلية. لا تكتب /m أو login.php.',
                          style: TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSoft,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
