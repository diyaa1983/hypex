import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:provider/provider.dart';

import 'app.dart';
import 'core/api_client.dart';
import 'core/inbox_controller.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'offline/offline_controller.dart';
import 'services/location_presence_service.dart';
import 'services/location_tracking_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  PaintingBinding.instance.imageCache.maximumSize = 80;
  PaintingBinding.instance.imageCache.maximumSizeBytes = 40 << 20;
  SystemChrome.setSystemUIOverlayStyle(AppTheme.overlayLight);
  await SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
  await SystemChrome.setPreferredOrientations(const [
    DeviceOrientation.landscapeLeft,
    DeviceOrientation.landscapeRight,
  ]);

  // قناة التواصل بين isolate الخدمة والواجهة.
  FlutterForegroundTask.initCommunicationPort();
  LocationTrackingService.init(
    intervalSec: await LocationTrackingService.intervalSec,
  );

  final api = await ApiClient.create();
  final session = SessionController(api);
  final offline = OfflineController(api);
  offline.csrfProvider = () async {
    if (session.csrf.isNotEmpty) return session.csrf;
    return session.ensureCsrf();
  };
  offline.csrfRefresh = () async {
    try {
      await session.refreshMe();
    } catch (_) {}
    return session.csrf;
  };
  LocationPresenceService.onSessionConflict = (msg) {
    session.handleDeviceConflict(msg);
  };
  await session.boot();
  await offline.start();
  final inbox = InboxController(api, session);
  inbox.start();

  runApp(
    MultiProvider(
      providers: [
        Provider<ApiClient>.value(value: api),
        ChangeNotifierProvider<SessionController>.value(value: session),
        ChangeNotifierProvider<OfflineController>.value(value: offline),
        ChangeNotifierProvider<InboxController>.value(value: inbox),
      ],
      child: const NammaApp(),
    ),
  );
}
