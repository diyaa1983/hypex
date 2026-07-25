import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:provider/provider.dart';

import 'app.dart';
import 'core/api_client.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'services/location_tracking_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(AppTheme.overlayLight);
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  // قناة التواصل بين isolate الخدمة والواجهة.
  FlutterForegroundTask.initCommunicationPort();
  LocationTrackingService.init(
    intervalSec: await LocationTrackingService.intervalSec,
  );

  final api = await ApiClient.create();
  final session = SessionController(api);
  await session.boot();

  // إعادة تشغيل التتبّع تلقائياً إن كان مفعّلاً من قبل.
  if (session.authenticated) {
    await LocationTrackingService.resumeIfEnabled();
  }

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
