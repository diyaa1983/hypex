import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// يُخفي شريط الحالة والتنقّل، ويتوقف عن الوضع الغامر أثناء ظهور الكيبورد حتى لا يُغلق.
class FullscreenBinder extends StatefulWidget {
  const FullscreenBinder({super.key, required this.child});

  final Widget child;

  static Future<void> apply() {
    return SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
  }

  static Future<void> allowKeyboard() {
    return SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
  }

  @override
  State<FullscreenBinder> createState() => _FullscreenBinderState();
}

class _FullscreenBinderState extends State<FullscreenBinder>
    with WidgetsBindingObserver {
  bool _imeOpen = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    FullscreenBinder.apply();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && !_imeOpen) {
      FullscreenBinder.apply();
    }
  }

  @override
  void didChangeMetrics() {
    final views = WidgetsBinding.instance.platformDispatcher.views;
    if (views.isEmpty) return;
    final ime = views.first.viewInsets.bottom > 0;
    if (ime == _imeOpen) return;
    _imeOpen = ime;
    if (ime) {
      FullscreenBinder.allowKeyboard();
    } else {
      FullscreenBinder.apply();
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
