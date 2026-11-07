import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/scanning_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class ChargingStationScreen extends StatefulWidget {
  final String bookingId;

  const ChargingStationScreen({super.key, required this.bookingId});

  @override
  State<ChargingStationScreen> createState() => _ChargingStationScreenState();
}

class _ChargingStationScreenState extends State<ChargingStationScreen> {
  late final AllBookingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());

    // Fetch latest location data to populate screen
    _controller.fetchLatestLocation(widget.bookingId);
    // Fetch charging station data to get sessionId for scanning
    _controller.fetchChargingStation(widget.bookingId);
  }

  // Get booking data from the booking list by bookingId
  dynamic get _booking {
    try {
      return _controller.bookings.firstWhereOrNull(
        (b) => (b.bookingId ?? b.sId) == widget.bookingId,
      );
    } catch (_) {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── Top green blur header ──
          Stack(
            children: [
              Container(
                width: double.infinity,
                height: 150.h,
                color: R.color.mintGreenAccent,
              ),
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
                        child: Icon(Icons.arrow_back_ios_new, color: R.color.darkNavy, size: 22.sp),
                      ),
                      Expanded(
                        child: Center(
                          child: WText(
                            text: 'Charging Station',
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

          Expanded(
            child: Obx(() {
              final latest = _controller.latestLocationData.value;
              final nav = _controller.navigationData.value;
              final booking = _booking;

              final stationName = latest?.stationName ?? nav?.stationName ?? booking?.stationName ?? 'Power Station';
              final stationImage = latest?.stationImage ?? nav?.stationImage ?? '';
              final address = booking?.address ?? '';
              final chargingSlot = booking?.chargingSlot ?? 'Slot A';
              final connectorType = booking?.connectorType ?? 'Type A';
              final chargingDuration = booking?.chargingDuration ?? '60 Min';
              final sessionTiming = booking?.chargingSessionTiming ?? booking?.time ?? '';

              if (_controller.latestLocationLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }

              return SingleChildScrollView(
                child: Column(
                  children: [
                    // ── Welcome text ──
                    SizedBox(height: 24.h),
                    WText(
                      text: 'Welcome to Your Charger',
                      color: R.color.darkNavy,
                      fontSize: 20.sp,
                      fontWeight: FontWeight.w700,
                    ),
                    SizedBox(height: 6.h),
                    WText(
                      text: 'Connect your vehicle to begin charging.',
                      color: R.color.neutralGray,
                      fontSize: 13.sp,
                      fontWeight: FontWeight.w400,
                    ),
                    SizedBox(height: 16.h),

                    // ── Charging station image ──
                    Image.asset(
                      Assets.charging_station,
                      width: 358.w,
                      height: 270.h,
                      fit: BoxFit.contain,
                    ),

                    SizedBox(height: 20.h),

                    // ── Info card ──
                    Padding(
                      padding: EdgeInsets.symmetric(horizontal: 16.w),
                      child: Container(
                        width: 358.w,
                        padding: EdgeInsets.all(16.w),
                        decoration: BoxDecoration(
                          color: R.color.white,
                          borderRadius: BorderRadius.circular(12.r),
                          border: Border.all(color: R.color.lightGray, width: 1),
                        ),
                        child: Column(
                          children: [
                            // ── Power Station + Slot ──
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Row(
                                  children: [
                                    ClipRRect(
                                      borderRadius: BorderRadius.circular(8.r),
                                      child: (stationImage.isNotEmpty && stationImage != 'string')
                                          ? Image.network(
                                              stationImage,
                                              width: 40.w,
                                              height: 40.w,
                                              fit: BoxFit.cover,
                                              errorBuilder: (_, __, ___) => Image.asset(
                                                Assets.charging,
                                                width: 40.w,
                                                height: 40.w,
                                                fit: BoxFit.cover,
                                              ),
                                            )
                                          : Image.asset(
                                              Assets.charging,
                                              width: 40.w,
                                              height: 40.w,
                                              fit: BoxFit.cover,
                                            ),
                                    ),
                                    SizedBox(width: 10.w),
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        SizedBox(
                                          width: 140.w,
                                          child: WText(
                                            text: stationName,
                                            color: R.color.darkNavy,
                                            fontSize: 13.sp,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                        SizedBox(height: 2.h),
                                        SizedBox(
                                          width: 140.w,
                                          child: WText(
                                            text: address,
                                            color: R.color.neutralGray,
                                            fontSize: 11.sp,
                                            fontWeight: FontWeight.w400,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    WText(
                                      text: chargingSlot,
                                      color: R.color.darkNavy,
                                      fontSize: 13.sp,
                                      fontWeight: FontWeight.w700,
                                    ),
                                    SizedBox(height: 2.h),
                                    WText(
                                      text: 'Charging Slot',
                                      color: R.color.neutralGray,
                                      fontSize: 11.sp,
                                      fontWeight: FontWeight.w400,
                                    ),
                                  ],
                                ),
                              ],
                            ),
                            SizedBox(height: 16.h),
                            Divider(color: R.color.lightGray, height: 1.h, thickness: 1),
                            SizedBox(height: 16.h),

                            // ── Type | Duration | Session Time ──
                            IntrinsicHeight(
                              child: Row(
                                children: [
                                  Expanded(child: _statCol(connectorType, 'Connector')),
                                  VerticalDivider(color: R.color.lightGray, width: 1.w, thickness: 1),
                                  Expanded(child: _statCol(chargingDuration, 'Duration')),
                                  VerticalDivider(color: R.color.lightGray, width: 1.w, thickness: 1),
                                  Expanded(child: _statCol(sessionTiming, 'Session Time')),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    SizedBox(height: 24.h),
                  ],
                ),
              );
            }),
          ),

          // ── Buttons ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: Column(
              children: [
                WButton(
                  label: 'Scan Code to Start Charging',
                  height: 52.h,
                  radius: 12,
                  buttonColor: R.color.mintGreen,
                  textColor: R.color.white,
                  fontSize: 15.sp,
                  fontWeight: FontWeight.w600,
                  decorationType: DecorationType.solid,
                  onPressed: () {
                    final sessionId = _controller.chargingStationData.value?.sessionId ?? '';
                    if (sessionId.isEmpty) {
                      final error = _controller.chargingSessionError.value;
                      Get.snackbar(
                        'Not Available',
                        error ?? 'Charging session not ready yet. Please wait.',
                        snackPosition: SnackPosition.BOTTOM,
                        backgroundColor: Colors.orange.shade100,
                        colorText: Colors.orange.shade900,
                        margin: const EdgeInsets.all(16),
                      );
                      // Retry fetching
                      _controller.fetchChargingStation(widget.bookingId);
                      return;
                    }
                    Get.to(() => ScanningScreen(
                      bookingId: widget.bookingId,
                      sessionId: sessionId,
                    ));
                  },
                ),
                SizedBox(height: 12.h),
                // WButton(
                //   label: 'Extend Session',
                //   height: 52.h,
                //   radius: 12,
                //   buttonColor: R.color.mintGreen,
                //   textColor: R.color.mintGreen,
                //   fontSize: 15.sp,
                //   fontWeight: FontWeight.w600,
                //   decorationType: DecorationType.stroke,
                //   onPressed: () {},
                // ),
                // SizedBox(height: 16.h),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _statCol(String value, String label) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        WText(text: value, color: R.color.darkNavy, fontSize: 13.sp, fontWeight: FontWeight.w700),
        SizedBox(height: 2.h),
        WText(text: label, color: R.color.neutralGray, fontSize: 10.sp, fontWeight: FontWeight.w400),
      ],
    );
  }
}

