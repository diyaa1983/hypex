import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _user = TextEditingController();
  final _pass = TextEditingController();
  bool _remember = true;
  bool _obscure = true;

  @override
  void initState() {
    super.initState();
    _prefill();
  }

  Future<void> _prefill() async {
    final creds = await context.read<SessionController>().savedCredentials();
    if (!mounted) return;
    if (creds.u != null) _user.text = creds.u!;
    if (creds.p != null) _pass.text = creds.p!;
  }

  @override
  void dispose() {
    _user.dispose();
    _pass.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final s = context.read<SessionController>();
    FocusScope.of(context).unfocus();
    final ok = await s.login(
      _user.text.trim(),
      _pass.text,
      remember: _remember,
    );
    if (!mounted) return;
    if (ok) {
      context.go('/home');
    } else {
      showSnack(context, s.lastError ?? 'تعذر تسجيل الدخول', error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.account_circle,
                    size: 72, color: AppTheme.primary),
                const SizedBox(height: 12),
                const Text('تسجيل الدخول',
                    style:
                        TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                const SizedBox(height: 24),
                TextField(
                  controller: _user,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'اسم المستخدم',
                    prefixIcon: Icon(Icons.person_outline),
                  ),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _pass,
                  obscureText: _obscure,
                  onSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                    labelText: 'كلمة المرور',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(
                          _obscure ? Icons.visibility : Icons.visibility_off),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Checkbox(
                      value: _remember,
                      onChanged: (v) =>
                          setState(() => _remember = v ?? true),
                    ),
                    const Text('تذكّرني'),
                  ],
                ),
                const SizedBox(height: 8),
                FilledButton(
                  onPressed: s.busy ? null : _submit,
                  child: s.busy
                      ? const SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white),
                        )
                      : const Text('دخول'),
                ),
                const SizedBox(height: 12),
                TextButton.icon(
                  onPressed: () => context.go('/server'),
                  icon: const Icon(Icons.settings_outlined, size: 18),
                  label: Text('تغيير عنوان السيرفر (${_short(s.api.base)})'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  String _short(String url) =>
      url.replaceFirst(RegExp(r'^https?://'), '').replaceAll(RegExp(r'/$'), '');
}
