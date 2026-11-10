import 'dart:async';
import 'package:evcharg/app/assets.dart';
import 'package:evcharg/core/service/app_settings/app_settings_service.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';
import 'package:evcharg/feature/main_screen.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'onboarding_screen.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _checkSession();
  }

  Future<void> _checkSession() async {
    // Fetch app settings (public API — no auth required)
    // Must await so Stripe publishable key is set from API before navigating
    if (Get.isRegistered<AppSettingsService>()) {
      await Get.find<AppSettingsService>().fetch();
    }

    // Keep splash visible for at least 2.5s
    await Future.delayed(const Duration(milliseconds: 2500));

    final auth = Get.put(AuthController());
    final hasToken = await auth.initSession();

    if (hasToken) {
      // Token exists → skip login, go straight to main app
      Get.offAll(() => const MainScreen());
    } else {
      // No token → show onboarding
      Get.off(() => const OnboardingScreen());
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SizedBox.expand(
        child: Image.asset(
          Assets.splash,
          fit: BoxFit.cover,
        ),
      ),
    );
  }
}
