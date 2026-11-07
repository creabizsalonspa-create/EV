import 'package:evcharg/core/service/app_settings/app_settings_service.dart';
import 'package:evcharg/feature/Booking/UI/screen/booking_screen.dart';
import 'package:evcharg/feature/Home/UI/screen/home_screen.dart';
import 'package:evcharg/feature/bottom_sheet_Navigation.dart';
import 'package:evcharg/feature/map/ui/screen/map_screen.dart';
import 'package:evcharg/feature/profile/UI/screen/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';


class MainScreen extends StatefulWidget {
  const MainScreen({super.key});

  static void switchToMapTab(BuildContext context) {
    final state = context.findAncestorStateOfType<_MainScreenState>();
    state?._switchTo(1);
  }

  @override
  State<MainScreen> createState() => _MainScreenState();
}

class _MainScreenState extends State<MainScreen> {
  int _currentIndex = 0;

  @override
  void initState() {
    super.initState();
    // Refresh app settings (currency, gateways) silently on MainScreen entry
    _refreshSettings();
  }

  void _refreshSettings() {
    if (Get.isRegistered<AppSettingsService>()) {
      Get.find<AppSettingsService>().refreshIfNeeded();
    }
  }

  void _switchTo(int index) {
    setState(() => _currentIndex = index);
    // Silently refresh settings on every tab switch so currency stays in sync
    _refreshSettings();
  }

  Widget _buildBody() {
    switch (_currentIndex) {
      case 0:
        return const HomeScreen();
      case 1:
        return const MapScreen();
      case 3:
        return const BookingScreen();
      case 4:
        return const ProfileScreen();
      default:
        return const HomeScreen();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: _buildBody(),
      bottomNavigationBar: BottomSheetNavigation(
        currentIndex: _currentIndex,
        onTap: (i) {
          if (i == 2) return;
          _switchTo(i);
        },
      ),
    );
  }
}

