import 'package:flutter/material.dart';

import '../core/theme.dart';

/// توضيح ظهور طلبات الشراء المعتمدة في كشف الحساب.
class OrderStatementWorkflowNote extends StatelessWidget {
  const OrderStatementWorkflowNote({super.key, this.compact = false});

  final bool compact;

  static const String fullText =
      'كشف الحساب يعرض بيانات Oracle مع طلبات الشراء المعتمدة. '
      'يظهر الطلب في الكشف بعد اعتماد الإدارة، '
      'ويختفي إذا تم فك الاعتماد. '
      'الترحيل إلى Oracle اختياري.';

  static const String compactText =
      'الطلب يظهر في الكشف بعد الاعتماد ويختفي عند فك الاعتماد.';

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(compact ? 10 : 12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.info_outline_rounded,
            size: compact ? 18 : 20,
            color: AppTheme.primary,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              compact ? compactText : fullText,
              style: TextStyle(
                fontSize: compact ? 11.5 : 12,
                fontWeight: FontWeight.w600,
                color: AppTheme.textSoft,
                height: 1.45,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
