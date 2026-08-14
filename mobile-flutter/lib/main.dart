import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:provider/provider.dart';

import 'app.dart';
import 'core/api_client.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'services/location_presence_service.dart';
import 'services/location_tracking_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(AppTheme.overlayLight);
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
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
  LocationPresenceService.onSessionConflict = (msg) {
    session.handleDeviceConflict(msg);
  };
  await session.boot();

  runApp(
    MultiProvider(
      providers: [
        Provider<ApiClient>.value(value: api),
        ChangeNotifierProvider<SessionController>.value(value: session),
      ],
      child: const NammaApp(),
    ),
  );
}
