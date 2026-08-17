import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/session.dart';
import '../core/theme.dart';
import 'app_confirm_dialog.dart';

/// هيكل موحّد للشاشات المتفرعة مع خروج واضح وتباعد متسق.
class MobileScaffold extends StatelessWidget {
  const MobileScaffold({
    super.key,
    required this.title,
    required this.body,
    this.actions = const [],
    this.showBack = true,
    this.showLogout = true,
    this.padding,
    this.floatingActionButton,
    this.bottomNavigationBar,
    this.backgroundColor,
  });

  final Widget title;
  final Widget body;
  final List<Widget> actions;
  final bool showBack;
  final bool showLogout;
  final EdgeInsetsGeometry? padding;
  final Widget? floatingActionButton;
  final Widget? bottomNavigationBar;
  final Color? backgroundColor;

  static Future<void> confirmLogout(BuildContext context) async {
    final confirmed = await showAppConfirmDialog(
      context,
      title: 'تسجيل الخروج',
      message: 'هل تريد تسجيل الخروج من التطبيق؟',
      confirmLabel: 'خروج',
      destructive: true,
    );
    if (confirmed != true || !context.mounted) return;
    await context.read<SessionController>().logout();
    if (context.mounted) context.go('/login');
  }

  void _goBack(BuildContext context) {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: backgroundColor ?? AppTheme.bg,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        leadingWidth: showBack ? 64 : 0,
        leading: showBack
            ? IconButton(
                tooltip: 'رجوع',
                onPressed: () => _goBack(context),
                iconSize: 28,
                padding: const EdgeInsets.all(10),
                style: IconButton.styleFrom(
                  foregroundColor: Colors.white,
                  minimumSize: const Size(52, 52),
                  tapTargetSize: MaterialTapTargetSize.padded,
                ),
                icon: const Icon(Icons.arrow_back_rounded, size: 30),
              )
            : null,
        title: title,
        actions: [
          ...actions,
          if (showLogout)
            TextButton.icon(
              onPressed: () => confirmLogout(context),
              icon: const Icon(Icons.logout_rounded, size: 26),
              label: const Text(
                'خروج',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
              ),
              style: TextButton.styleFrom(
                foregroundColor: Colors.white,
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                minimumSize: const Size(72, 48),
              ),
            ),
        ],
      ),
      body: padding == null ? body : Padding(padding: padding!, child: body),
      floatingActionButton: floatingActionButton,
      bottomNavigationBar: bottomNavigationBar,
    );
  }
}

/// بطاقة ترويسة للمستندات مع فاصل قسم خفيف، ملائمة للواجهات RTL.
class DocumentHeaderCard extends StatelessWidget {
  const DocumentHeaderCard({
    super.key,
    required this.child,
    this.title,
    this.padding = const EdgeInsets.all(14),
  });

  final Widget child;
  final String? title;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (title != null) ...[
            Text(
              title!,
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
            ),
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 10),
              child: Divider(height: 1),
            ),
          ],
          child,
        ],
      ),
    );
  }
}

class DocumentSectionDivider extends StatelessWidget {
  const DocumentSectionDivider(this.label, {super.key});
  final String label;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 14, bottom: 8),
        child: Row(
          children: [
            Text(
              label,
              style: const TextStyle(
                color: AppTheme.textSoft,
                fontWeight: FontWeight.w800,
                fontSize: 13,
              ),
            ),
            const SizedBox(width: 10),
            const Expanded(child: Divider(height: 1)),
          ],
        ),
      );
}
