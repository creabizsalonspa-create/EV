import 'dart:ui';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Notification/UI/controller/notification_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class NotificationScreen extends StatefulWidget {
  const NotificationScreen({super.key});

  @override
  State<NotificationScreen> createState() => _NotificationScreenState();
}

class _NotificationScreenState extends State<NotificationScreen> {
  late final NotificationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<NotificationController>()
        ? Get.find<NotificationController>()
        : Get.put(NotificationController());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _controller.fetchNotificationSettings();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: SingleChildScrollView(
        child: Column(
          children: [
            // ── Top green header with blur ──
            Stack(
              children: [
                Container(width: double.infinity, height: 150.h, color: R.color.mintGreenAccent),
                Positioned.fill(
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 150, sigmaY: 150),
                    child: Container(color: Colors.transparent),
                  ),
                ),
                Container(
                  width: double.infinity,
                  height: 150.h,
                  padding: EdgeInsets.symmetric(horizontal: 16.w),
                  child: SafeArea(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        GestureDetector(
                          onTap: () => Get.back(),
                          child: Icon(Icons.arrow_back_ios_new, color: R.color.darkNavy, size: 20.sp),
                        ),
                        Expanded(
                          child: Center(
                            child: WText(
                              text: 'Notification',
                              color: R.color.darkNavy,
                              fontSize: 18.sp,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                        SizedBox(width: 22.w),
                      ],
                    ),
                  ),
                ),
              ],
            ),

            // ── Settings list ──
            Obx(() {
              if (_controller.settingsLoading.value) {
                return Padding(
                  padding: EdgeInsets.only(top: 40.h),
                  child: const Center(child: CircularProgressIndicator()),
                );
              }
              final s = _controller.settings.value;

              final items = [
                {
                  'icon': Icons.bolt,
                  'title': 'Charging Status Alerts',
                  'subtitle': 'Real-time charging updates',
                  'key': 'chargingStatusAlerts',
                  'value': s?.chargingStatusAlerts ?? false,
                },
                {
                  'icon': Icons.battery_alert_outlined,
                  'title': 'Low Battery Alerts',
                  'subtitle': 'Battery level warnings',
                  'key': 'lowBatteryAlerts',
                  'value': s?.lowBatteryAlerts ?? false,
                },
                {
                  'icon': Icons.calendar_today_outlined,
                  'title': 'Booking Updates',
                  'subtitle': 'Reservation status updates',
                  'key': 'bookingUpdates',
                  'value': s?.bookingUpdates ?? false,
                },
                {
                  'icon': Icons.ev_station_outlined,
                  'title': 'Station Updates',
                  'subtitle': 'Availability and changes',
                  'key': 'stationUpdates',
                  'value': s?.stationUpdates ?? false,
                },
                {
                  'icon': Icons.payment_outlined,
                  'title': 'Payment & Session',
                  'subtitle': 'Payment and usage summary',
                  'key': 'paymentAndSession',
                  'value': s?.paymentAndSession ?? false,
                },
              ];

              return Padding(
                padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
                child: Column(
                  children: List.generate(items.length, (index) {
                    final n = items[index];
                    final bool enabled = n['value'] as bool;
                    final String key = n['key'] as String;
                    return Container(
                      width: 358.w,
                      margin: EdgeInsets.only(
                          bottom: index < items.length - 1 ? 12.h : 0),
                      padding: EdgeInsets.symmetric(
                          horizontal: 16.w, vertical: 12.h),
                      decoration: BoxDecoration(
                        color: R.color.white,
                        borderRadius: BorderRadius.circular(12.r),
                        border: Border.all(color: R.color.lightGray, width: 1),
                      ),
                      child: Row(
                        children: [
                          // ── Icon ──
                          Container(
                            width: 40.w,
                            height: 40.w,
                            decoration: BoxDecoration(
                              color: R.color.mintGreen,
                              borderRadius: BorderRadius.circular(10.r),
                            ),
                            child: Icon(n['icon'] as IconData,
                                size: 20.sp, color: R.color.white),
                          ),
                          SizedBox(width: 12.w),

                          // ── Title + Subtitle ──
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                WText(
                                  text: n['title'] as String,
                                  color: R.color.darkNavy,
                                  fontSize: 14.sp,
                                  fontWeight: FontWeight.w600,
                                ),
                                SizedBox(height: 2.h),
                                WText(
                                  text: n['subtitle'] as String,
                                  color: R.color.neutralGray,
                                  fontSize: 11.sp,
                                  fontWeight: FontWeight.w400,
                                ),
                              ],
                            ),
                          ),

                          // ── Toggle switch ──
                          _controller.savingKey.value == key
                              ? SizedBox(
                                  width: 24.w,
                                  height: 24.w,
                                  child: const CircularProgressIndicator(strokeWidth: 2),
                                )
                              : Switch(
                                  value: enabled,
                                  onChanged: _controller.savingKey.value.isNotEmpty
                                      ? null // disable all while one is saving
                                      : (val) => _controller.toggleSetting(key, val),
                                  activeThumbColor: R.color.white,
                                  activeTrackColor: R.color.mintGreen,
                                  inactiveThumbColor: R.color.white,
                                  inactiveTrackColor: R.color.lightGray,
                                ),
                        ],
                      ),
                    );
                  }),
                ),
              );
            }),
          ],
        ),
      ),
    );
  }
}
