import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';

/// إشعارات نظام أندرويد (شريط الحالة) — تعمل والتطبيق في الخلفية.
class LocalAlertService {
  LocalAlertService._();

  static final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  static bool _ready = false;

  static const _channelId = 'hypex_inbox';
  static const _channelName = 'إشعارات المندوب';
  static const _channelDesc = 'اعتماد موقع العميل والتنبيهات';

  static Future<void> init() async {
    if (_ready) return;
    const android = AndroidInitializationSettings('@mipmap/ic_launcher');
    await _plugin.initialize(
      const InitializationSettings(android: android),
      onDidReceiveNotificationResponse: (resp) {
        FlutterForegroundTask.launchApp('/');
      },
    );
    final androidPlugin = _plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    await androidPlugin?.createNotificationChannel(
      const AndroidNotificationChannel(
        _channelId,
        _channelName,
        description: _channelDesc,
        importance: Importance.high,
        playSound: true,
        enableVibration: true,
      ),
    );
    await androidPlugin?.requestNotificationsPermission();
    _ready = true;
  }

  static Future<void> showInbox({
    required int id,
    required String title,
    required String body,
  }) async {
    try {
      await init();
      final text = body.trim().isEmpty ? title : body;
      await _plugin.show(
        id <= 0 ? DateTime.now().millisecondsSinceEpoch.remainder(100000) : id,
        title,
        text,
        NotificationDetails(
          android: AndroidNotificationDetails(
            _channelId,
            _channelName,
            channelDescription: _channelDesc,
            importance: Importance.high,
            priority: Priority.high,
            icon: '@mipmap/ic_launcher',
            styleInformation: BigTextStyleInformation(text),
            category: AndroidNotificationCategory.message,
            autoCancel: true,
          ),
        ),
      );
    } catch (_) {}
  }

  static Future<void> cancelInbox() async {
    try {
      await _plugin.cancelAll();
    } catch (_) {}
  }
}
