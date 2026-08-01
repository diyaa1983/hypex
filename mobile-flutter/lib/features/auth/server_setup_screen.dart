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

  @override
  void initState() {
    super.initState();
    _ctrl =
        TextEditingController(text: context.read<SessionController>().api.base);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _test() async {
    final s = context.read<SessionController>();
    setState(() => _testing = true);
    await s.saveServer(_ctrl.text);
    final ok = await s.ping();
    if (!mounted) return;
    setState(() => _testing = false);
    showSnack(
      context,
      ok ? 'الاتصال بالسيرفر ناجح.' : 'تعذر الاتصال بالسيرفر.',
      error: !ok,
    );
  }

  Future<void> _connect() async {
    final s = context.read<SessionController>();
    await s.saveServer(_ctrl.text);
    if (!mounted) return;
    context.go('/login');
  }

  @override
  Widget build(BuildContext context) {
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
                  const Text(
                    'إعداد السيرفر',
                    style: TextStyle(
                      fontSize: 21,
                      fontWeight: FontWeight.w900,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'أدخل عنوان النظام ثم اضغط اتصال',
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
                          decoration: const InputDecoration(
                            labelText: 'عنوان النظام',
                            hintText: 'https://www.biodev.gppjo.com',
                            prefixIcon: Icon(Icons.link_rounded, size: 20),
                          ),
                        ),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: _testing ? null : _connect,
                          icon: const Icon(Icons.login_rounded, size: 19),
                          label: const Text('اتصال'),
                        ),
                        const SizedBox(height: 10),
                        OutlinedButton.icon(
                          onPressed: _testing ? null : _test,
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
                          'اكتب العنوان بدون /m أو login.php — سيتم ضبطه تلقائياً.',
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
