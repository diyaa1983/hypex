import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
  final _userFocus = FocusNode();
  final _passFocus = FocusNode();
  bool _remember = false;
  bool _obscure = true;

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
    _userFocus.dispose();
    _passFocus.dispose();
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

  InputDecoration _fieldDecoration({
    required bool tablet,
    String? hint,
    Widget? prefixIcon,
    Widget? suffixIcon,
  }) {
    final r = tablet ? 16.0 : 12.0;
    return InputDecoration(
      filled: true,
      fillColor: Colors.white.withValues(alpha: 0.88),
      prefixIcon: prefixIcon,
      suffixIcon: suffixIcon,
      suffixIconConstraints: suffixIcon != null
          ? BoxConstraints(minWidth: tablet ? 52 : 44, minHeight: tablet ? 52 : 44)
          : null,
      contentPadding: EdgeInsets.symmetric(
        horizontal: tablet ? 18 : 14,
        vertical: tablet ? 18 : 14,
      ),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: BorderSide(color: Colors.white.withValues(alpha: 0.55)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: BorderSide(color: Colors.white.withValues(alpha: 0.45)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(r),
        borderSide: const BorderSide(color: Color(0xFF93C5FD), width: 1.8),
      ),
      hintText: hint,
      hintStyle: TextStyle(
        color: AppTheme.textSoft.withValues(alpha: 0.85),
        fontSize: tablet ? 17 : 15,
      ),
    );
  }

  Widget _loginField({
    required String label,
    required Widget field,
    required bool tablet,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: tablet ? 15 : 13,
            fontWeight: FontWeight.w700,
            color: Colors.white.withValues(alpha: 0.92),
          ),
        ),
        SizedBox(height: tablet ? 8 : 6),
        field,
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    final size = MediaQuery.sizeOf(context);
    final tablet = size.shortestSide >= 600;
    final fieldStyle = TextStyle(
      fontSize: tablet ? 18 : 16,
      fontWeight: FontWeight.w600,
      color: AppTheme.textMain,
      height: 1.25,
    );

    return GestureDetector(
      onTap: () => FocusScope.of(context).unfocus(),
      child: Scaffold(
        resizeToAvoidBottomInset: !tablet,
        body: Stack(
          fit: StackFit.expand,
          children: [
            const DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topRight,
                  end: Alignment.bottomLeft,
                  colors: [
                    Color(0xFF071018),
                    Color(0xFF0B3A6E),
                    Color(0xFF0E7490),
                    Color(0xFF0B1220),
                  ],
                  stops: [0, 0.38, 0.72, 1],
                ),
              ),
            ),
            Positioned(
              top: -size.height * 0.12,
              left: -80,
              child: _glow(size.width * 0.55, const Color(0xFF38BDF8)),
            ),
            Positioned(
              bottom: -80,
              right: -40,
              child: _glow(size.width * 0.5, const Color(0xFF2563EB)),
            ),
            Opacity(
              opacity: 0.07,
              child: Center(
                child: Image.asset(
                  'assets/branding/logo.png',
                  width: tablet ? 420 : 260,
                  fit: BoxFit.contain,
                ),
              ),
            ),
            SafeArea(
              child: tablet
                  ? Align(
                      alignment: Alignment.topCenter,
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.fromLTRB(32, 20, 32, 12),
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 520),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              _brandBlock(tablet: true),
                              const SizedBox(height: 16),
                              _glassCard(
                                tablet: true,
                                session: s,
                                fieldStyle: fieldStyle,
                              ),
                            ],
                          ),
                        ),
                      ),
                    )
                  : Center(
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 20,
                          vertical: 16,
                        ),
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 420),
                          child: Column(
                            children: [
                              _brandBlock(tablet: false),
                              const SizedBox(height: 20),
                              _glassCard(
                                tablet: false,
                                session: s,
                                fieldStyle: fieldStyle,
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _glow(double size, Color color) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color.withValues(alpha: 0.22),
        ),
      ),
    );
  }

  Widget _brandBlock({required bool tablet}) {
    return Column(
      children: [
        Container(
          width: tablet ? 88 : 64,
          height: tablet ? 88 : 64,
          padding: EdgeInsets.all(tablet ? 12 : 8),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(tablet ? 22 : 16),
            border: Border.all(color: Colors.white.withValues(alpha: 0.22)),
          ),
          child: Image.asset('assets/branding/logo.png', fit: BoxFit.contain),
        ),
        SizedBox(height: tablet ? 16 : 12),
        Text(
          'Hypex',
          style: TextStyle(
            fontSize: tablet ? 34 : 24,
            fontWeight: FontWeight.w800,
            color: Colors.white,
            letterSpacing: 0.4,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          'نظام المبيعات والمندوبين',
          style: TextStyle(
            fontSize: tablet ? 16 : 13,
            color: Colors.white.withValues(alpha: 0.82),
          ),
        ),
      ],
    );
  }

  Widget _glassCard({
    required bool tablet,
    required SessionController session,
    required TextStyle fieldStyle,
  }) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(tablet ? 28 : 20),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          padding: EdgeInsets.fromLTRB(
            tablet ? 28 : 20,
            tablet ? 26 : 20,
            tablet ? 28 : 20,
            tablet ? 22 : 18,
          ),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: tablet ? 0.16 : 0.14),
            borderRadius: BorderRadius.circular(tablet ? 28 : 20),
            border: Border.all(color: Colors.white.withValues(alpha: 0.28)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.18),
                blurRadius: 28,
                offset: const Offset(0, 12),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'تسجيل الدخول',
                style: TextStyle(
                  fontSize: tablet ? 26 : 20,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'أدخل اسم المستخدم وكلمة السر',
                style: TextStyle(
                  fontSize: tablet ? 15 : 13,
                  color: Colors.white.withValues(alpha: 0.78),
                ),
              ),
              SizedBox(height: tablet ? 22 : 18),
              _loginField(
                tablet: tablet,
                label: 'اسم المستخدم',
                field: TextField(
                  controller: _user,
                  focusNode: _userFocus,
                  textInputAction: TextInputAction.next,
                  keyboardType: TextInputType.emailAddress,
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.left,
                  autocorrect: false,
                  enableSuggestions: false,
                  autofillHints: const [AutofillHints.username],
                  style: fieldStyle,
                  onSubmitted: (_) => _passFocus.requestFocus(),
                  inputFormatters: [
                    FilteringTextInputFormatter.deny(RegExp(r'\s')),
                  ],
                  decoration: _fieldDecoration(
                    tablet: tablet,
                    hint: 'اكتب اسم المستخدم هنا',
                    prefixIcon: Icon(
                      Icons.person_outline_rounded,
                      size: tablet ? 24 : 20,
                      color: AppTheme.primary,
                    ),
                  ),
                ),
              ),
              SizedBox(height: tablet ? 18 : 14),
              _loginField(
                tablet: tablet,
                label: 'كلمة السر',
                field: TextField(
                  controller: _pass,
                  focusNode: _passFocus,
                  obscureText: _obscure,
                  textInputAction: TextInputAction.done,
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.left,
                  autocorrect: false,
                  enableSuggestions: false,
                  autofillHints: const [AutofillHints.password],
                  style: fieldStyle,
                  onSubmitted: (_) => _submit(),
                  decoration: _fieldDecoration(
                    tablet: tablet,
                    hint: 'اكتب كلمة السر هنا',
                    prefixIcon: Icon(
                      Icons.lock_outline_rounded,
                      size: tablet ? 24 : 20,
                      color: AppTheme.primary,
                    ),
                    suffixIcon: IconButton(
                      tooltip: _obscure ? 'إظهار' : 'إخفاء',
                      icon: Icon(
                        _obscure
                            ? Icons.visibility_outlined
                            : Icons.visibility_off_outlined,
                        size: tablet ? 24 : 20,
                        color: AppTheme.textSoft,
                      ),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                ),
              ),
              SizedBox(height: tablet ? 8 : 4),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                dense: !tablet,
                value: _remember,
                activeColor: const Color(0xFF38BDF8),
                checkColor: const Color(0xFF0B1220),
                onChanged: (v) => setState(() => _remember = v ?? false),
                title: Text(
                  'تذكّرني على هذا الجهاز',
                  style: TextStyle(
                    fontSize: tablet ? 15 : 13,
                    fontWeight: FontWeight.w600,
                    color: Colors.white.withValues(alpha: 0.9),
                  ),
                ),
              ),
              SizedBox(height: tablet ? 10 : 8),
              SizedBox(
                height: tablet ? 56 : 48,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(tablet ? 16 : 12),
                    gradient: const LinearGradient(
                      colors: [Color(0xFF38BDF8), Color(0xFF2563EB)],
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFF2563EB).withValues(alpha: 0.35),
                        blurRadius: 16,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: Material(
                    color: Colors.transparent,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(tablet ? 16 : 12),
                      onTap: session.busy ? null : _submit,
                      child: Center(
                        child: session.busy
                            ? const SizedBox(
                                width: 24,
                                height: 24,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2.4,
                                  color: Colors.white,
                                ),
                              )
                            : Text(
                                'دخول',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: tablet ? 18 : 16,
                                  fontWeight: FontWeight.w800,
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
    );
  }
}
