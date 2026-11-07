import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import 'package:google_maps_flutter_android/google_maps_flutter_android.dart';
import 'package:google_maps_flutter_platform_interface/google_maps_flutter_platform_interface.dart';

import 'controller_binder_screen.dart';
import 'feature/Auth/UI/screen/splash_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final GoogleMapsFlutterPlatform mapsImpl = GoogleMapsFlutterPlatform.instance;
  if (mapsImpl is GoogleMapsFlutterAndroid) {
    mapsImpl.useAndroidViewSurface = true;
    initializeMapRenderer();
  }

  runApp(const MyApp());
}

void initializeMapRenderer() {
  final GoogleMapsFlutterPlatform mapsImpl = GoogleMapsFlutterPlatform.instance;
  if (mapsImpl is GoogleMapsFlutterAndroid) {
    mapsImpl.initializeWithRenderer(AndroidMapRenderer.latest).catchError((_) {
      // Renderer already initialized — safe to ignore on hot-restart
      return AndroidMapRenderer.latest;
    });
  }
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ScreenUtilInit(
      designSize: const Size(390, 844),
      minTextAdapt: true,
      splitScreenMode: true,
      builder: (context, child) {
        return GetMaterialApp(
          debugShowCheckedModeBanner: false,
          initialBinding: ControllerBinding(),
          builder: (context, child) => Column(
            children: [
              const SafeArea(
                bottom: false,
                child: Material(
                  color: Color(0xFFFFF3CD),
                  child: Padding(
                    padding: EdgeInsets.all(6),
                    child: Text(
                      'Hasone development • Payments and remote controls unavailable',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 12, color: Colors.black87),
                    ),
                  ),
                ),
              ),
              Expanded(child: child ?? const SizedBox.shrink()),
            ],
          ),
          home: const SplashScreen(),
        );
      },
    );
  }
}
