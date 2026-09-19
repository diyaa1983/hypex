import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../offline/offline_controller.dart';

/// أيقونة واي فاي بجانب الخروج: أخضر عند الاتصال، أحمر مع X عند الانقطاع.
class ServerStatusDot extends StatelessWidget {
  const ServerStatusDot({super.key});

  @override
  Widget build(BuildContext context) {
    final connected = context.select<OfflineController, bool>(
      (off) => off.serverConnected,
    );
    return IconButton(
      tooltip: connected
          ? 'متصل بالسيرفر'
          : 'لا يوجد اتصال بالسيرفر — اضغط لإعادة المحاولة',
      onPressed: () => context.read<OfflineController>().retryConnection(),
      padding: const EdgeInsets.symmetric(horizontal: 6),
      visualDensity: VisualDensity.compact,
      icon: connected ? const _WifiOn() : const _WifiOffX(),
    );
  }
}

class _WifiOn extends StatelessWidget {
  const _WifiOn();

  @override
  Widget build(BuildContext context) {
    return const Icon(
      Icons.wifi_rounded,
      color: Color(0xFF22C55E),
      size: 28,
    );
  }
}

class _WifiOffX extends StatelessWidget {
  const _WifiOffX();

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 28,
      height: 28,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          const Icon(
            Icons.wifi_rounded,
            color: Color(0xFFE0453C),
            size: 28,
          ),
          Positioned(
            right: -1,
            bottom: -1,
            child: Container(
              width: 13,
              height: 13,
              decoration: BoxDecoration(
                color: const Color(0xFFE0453C),
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 1.4),
              ),
              child: const Icon(
                Icons.close_rounded,
                size: 9,
                color: Colors.white,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
