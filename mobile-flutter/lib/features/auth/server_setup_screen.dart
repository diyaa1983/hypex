import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

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
    final s = context.read<SessionController>();
    _ctrl = TextEditingController(text: s.api.base);
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
    showSnack(context, ok ? 'الاتصال ناجح' : 'تعذر الاتصال بالسيرفر',
        error: !ok);
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
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.dns_outlined, size: 64, color: AppTheme.primary),
                const SizedBox(height: 16),
                const Text('إعداد السيرفر',
                    style:
                        TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                const Text('أدخل عنوان النظام ثم اضغط اتصال',
                    style: TextStyle(color: Colors.black54)),
                const SizedBox(height: 24),
                TextField(
                  controller: _ctrl,
                  textDirection: TextDirection.ltr,
                  keyboardType: TextInputType.url,
                  decoration: const InputDecoration(
                    labelText: 'عنوان النظام',
                    hintText: 'https://www.biodev.gppjo.com',
                    prefixIcon: Icon(Icons.link),
                  ),
                ),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: _testing ? null : _connect,
                  child: const Text('اتصال'),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: _testing ? null : _test,
                  icon: _testing
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.wifi_tethering),
                  label: const Text('فحص الاتصال'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
