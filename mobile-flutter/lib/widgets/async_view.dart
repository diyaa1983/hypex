import 'package:flutter/material.dart';

import '../core/theme.dart';
import 'ui_kit.dart';

/// عرض حالة تحميل / خطأ / محتوى بشكل موحّد.
class AsyncView extends StatelessWidget {
  const AsyncView({
    super.key,
    required this.loading,
    required this.error,
    required this.onRetry,
    required this.child,
    this.skeleton = true,
  });

  final bool loading;
  final String? error;
  final VoidCallback onRetry;
  final Widget child;
  final bool skeleton;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return skeleton
          ? const SkeletonList()
          : const Center(child: CircularProgressIndicator());
    }
    if (error != null) {
      return ListView(
        padding: const EdgeInsets.fromLTRB(24, 60, 24, 24),
        children: [
          const MiniIcon(
            Icons.wifi_tethering_error_rounded,
            color: AppTheme.danger,
            size: 62,
            iconSize: 30,
            radius: 20,
          ),
          const SizedBox(height: 16),
          const Text(
            'تعذّر تحميل البيانات',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 8),
          Text(
            error!,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 13.5, color: AppTheme.textSoft),
          ),
          const SizedBox(height: 20),
          Center(
            child: SizedBox(
              width: 190,
              child: FilledButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: const Text('إعادة المحاولة'),
              ),
            ),
          ),
        ],
      );
    }
    return child;
  }
}

/// رسالة فراغ.
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.message,
    this.icon,
    this.actionLabel,
    this.onAction,
  });

  final String message;
  final IconData? icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            MiniIcon(
              icon ?? Icons.inbox_rounded,
              color: AppTheme.textSoft,
              size: 64,
              iconSize: 30,
              radius: 22,
            ),
            const SizedBox(height: 14),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: AppTheme.textSoft,
                fontSize: 14.5,
                fontWeight: FontWeight.w600,
              ),
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: 16),
              SizedBox(
                width: 200,
                child: FilledButton.icon(
                  onPressed: onAction,
                  icon: const Icon(Icons.add_rounded, size: 18),
                  label: Text(actionLabel!),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

void showSnack(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Icon(
              error ? Icons.error_outline_rounded : Icons.check_circle_rounded,
              color: Colors.white,
              size: 19,
            ),
            const SizedBox(width: 10),
            Expanded(child: Text(message)),
          ],
        ),
        backgroundColor: error ? AppTheme.danger : AppTheme.success,
        behavior: SnackBarBehavior.floating,
        duration: Duration(seconds: error ? 5 : 3),
      ),
    );
}
