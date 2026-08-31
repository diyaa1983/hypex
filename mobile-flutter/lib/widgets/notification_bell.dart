import 'package:flutter/material.dart';

/// جرس الإشعارات — الواجهة جاهزة؛ إعدادات وأنواع التنبيه تُضاف لاحقاً.
class NotificationBellButton extends StatelessWidget {
  const NotificationBellButton({super.key});

  static Future<void> openInbox(BuildContext context) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        final h = MediaQuery.sizeOf(ctx).height;
        return SafeArea(
          child: SizedBox(
            height: h * 0.55,
            child: Column(
              children: [
                const SizedBox(height: 10),
                Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: const Color(0xFFD0D7E2),
                    borderRadius: BorderRadius.circular(99),
                  ),
                ),
                const Padding(
                  padding: EdgeInsets.fromLTRB(16, 14, 16, 8),
                  child: Row(
                    children: [
                      Icon(Icons.notifications_rounded, color: Color(0xFF0B63CE)),
                      SizedBox(width: 8),
                      Text(
                        'الإشعارات',
                        style: TextStyle(
                          fontWeight: FontWeight.w900,
                          fontSize: 17,
                        ),
                      ),
                    ],
                  ),
                ),
                const Divider(height: 1),
                const Expanded(
                  child: Center(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.notifications_none_rounded,
                            size: 48,
                            color: Color(0xFF9AA8BC),
                          ),
                          SizedBox(height: 12),
                          Text(
                            'لا توجد إشعارات حالياً',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontWeight: FontWeight.w800,
                              fontSize: 15,
                            ),
                          ),
                          SizedBox(height: 6),
                          Text(
                            'سيتم تفعيل إعدادات الإشعارات لاحقاً.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: Color(0xFF6B7A90),
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: 'الإشعارات',
      onPressed: () => openInbox(context),
      padding: const EdgeInsets.symmetric(horizontal: 6),
      visualDensity: VisualDensity.compact,
      icon: const Icon(
        Icons.notifications_rounded,
        color: Colors.white,
        size: 26,
      ),
    );
  }
}
