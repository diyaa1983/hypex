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
  bool _remember = false;
  bool _obscure = true;

  static const _panelBg = Color(0xFFF5F7FB);
  static const _labelColor = Color(0xFF334155);

  @override
  void initState() {
    super.initState();
    _prefill();
  }

  Future<void> _prefill() async {
    final s = context.read<SessionController>();
    final creds = await s.savedCredentials();
    if (!mounted) return;
    if (creds.u != null) _user.text = creds.u!;
    if (creds.p != null) _pass.text = creds.p!;
    if (creds.u != null && creds.p != null) {
      setState(() => _remember = true);
    }
    final err = s.lastError;
    if (err != null && err.isNotEmpty) {
      showSnack(context, err, error: true);
    }
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

  static const _fieldHeight = 48.0;

  InputDecoration _fieldDecoration({
    String? hint,
    Widget? prefixIcon,
    Widget? suffixIcon,
  }) {
    return InputDecoration(
      filled: true,
      fillColor: Colors.white,
      prefixIcon: prefixIcon,
      suffixIcon: suffixIcon,
      suffixIconConstraints: suffixIcon != null
          ? const BoxConstraints(minWidth: 44, minHeight: 44)
          : null,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFFD0D7E2)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFFD0D7E2)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: AppTheme.primary, width: 1.4),
      ),
      hintText: hint,
      hintStyle: const TextStyle(color: AppTheme.textSoft, fontSize: 15),
    );
  }

  Widget _loginField({
    required String label,
    required Widget field,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w600,
            color: _labelColor,
          ),
        ),
        const SizedBox(height: 6),
        SizedBox(height: _fieldHeight, width: double.infinity, child: field),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    final h = MediaQuery.of(context).size.height;

    return Scaffold(
      backgroundColor: _panelBg,
      body: Column(
        children: [
          Container(
            width: double.infinity,
            height: h * 0.34,
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topRight,
                end: Alignment.bottomLeft,
                colors: [
                  Color(0xFF0B1220),
                  Color(0xFF111827),
                  Color(0xFF1E3A5F),
                ],
              ),
            ),
            child: SafeArea(
              bottom: false,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    width: 52,
                    height: 52,
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: Colors.white.withValues(alpha: 0.15),
                      ),
                    ),
                    child: Image.asset(
                      'assets/branding/logo.png',
                      fit: BoxFit.contain,
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Hypex',
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'نظام المبيعات والمندوبين',
                    style: TextStyle(
                      fontSize: 13,
                      color: Colors.white.withValues(alpha: 0.82),
                    ),
                  ),
                ],
              ),
            ),
          ),
          Expanded(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(22, 20, 22, 24),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: Container(
                    padding: const EdgeInsets.fromLTRB(20, 22, 20, 20),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(18),
                      border: Border.all(color: const Color(0xFFE6EAF0)),
                      boxShadow: [
                        BoxShadow(
                          color: const Color(0xFF0F172A).withValues(alpha: 0.06),
                          blurRadius: 24,
                          offset: const Offset(0, 8),
                        ),
                      ],
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          'مرحباً بعودتك',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.4,
                            color: AppTheme.textSoft.withValues(alpha: 0.95),
                          ),
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          'تسجيل الدخول',
                          style: TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                            color: AppTheme.textMain,
                          ),
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          'أدخل بياناتك للوصول إلى النظام',
                          style: TextStyle(
                            fontSize: 13,
                            color: AppTheme.textSoft,
                          ),
                        ),
                        const SizedBox(height: 20),
                        _loginField(
                          label: 'اسم المستخدم',
                          field: TextField(
                            controller: _user,
                            textInputAction: TextInputAction.next,
                            textAlignVertical: TextAlignVertical.center,
                            style: const TextStyle(fontSize: 15),
                            decoration: _fieldDecoration(
                              hint: 'أدخل اسم المستخدم',
                              prefixIcon: const Icon(
                                Icons.person_outline,
                                size: 20,
                                color: AppTheme.textSoft,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),
                        _loginField(
                          label: 'كلمة المرور',
                          field: TextField(
                            controller: _pass,
                            obscureText: _obscure,
                            onSubmitted: (_) => _submit(),
                            textAlignVertical: TextAlignVertical.center,
                            style: const TextStyle(fontSize: 15),
                            decoration: _fieldDecoration(
                              hint: 'أدخل كلمة المرور',
                              prefixIcon: const Icon(
                                Icons.lock_outline,
                                size: 20,
                                color: AppTheme.textSoft,
                              ),
                              suffixIcon: IconButton(
                                icon: Icon(
                                  _obscure
                                      ? Icons.visibility_outlined
                                      : Icons.visibility_off_outlined,
                                  size: 20,
                                  color: AppTheme.textSoft,
                                ),
                                onPressed: () =>
                                    setState(() => _obscure = !_obscure),
                              ),
                            ),
                          ),
                        ),
                        CheckboxListTile(
                          contentPadding: EdgeInsets.zero,
                          dense: true,
                          value: _remember,
                          onChanged: (v) =>
                              setState(() => _remember = v ?? false),
                          title: const Text(
                            'تذكّرني',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        const SizedBox(height: 8),
                        SizedBox(
                          height: 46,
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(10),
                              gradient: const LinearGradient(
                                begin: Alignment.topCenter,
                                end: Alignment.bottomCenter,
                                colors: [Color(0xFF3B82F6), Color(0xFF2563EB)],
                              ),
                              boxShadow: [
                                BoxShadow(
                                  color: const Color(0xFF2563EB)
                                      .withValues(alpha: 0.28),
                                  blurRadius: 12,
                                  offset: const Offset(0, 4),
                                ),
                              ],
                            ),
                            child: Material(
                              color: Colors.transparent,
                              child: InkWell(
                                borderRadius: BorderRadius.circular(10),
                                onTap: s.busy ? null : _submit,
                                child: Center(
                                  child: s.busy
                                      ? const SizedBox(
                                          width: 22,
                                          height: 22,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            color: Colors.white,
                                          ),
                                        )
                                      : const Text(
                                          'دخول',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 16,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                ),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
