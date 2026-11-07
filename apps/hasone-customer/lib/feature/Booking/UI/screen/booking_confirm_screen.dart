import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/utils/currency_helper.dart';
import 'package:evcharg/feature/Booking/UI/screen/navigate_station_screen.dart';
import 'package:evcharg/feature/Booking/model/bookingpaymentmodel.dart' as payment;
import 'package:evcharg/feature/main_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class BookingConfirmScreen extends StatefulWidget {
  final payment.Data? paymentData;

  const BookingConfirmScreen({super.key, this.paymentData});

  @override
  State<BookingConfirmScreen> createState() => _BookingConfirmScreenState();
}

class _BookingConfirmScreenState extends State<BookingConfirmScreen> {
  payment.Data? get data => widget.paymentData;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Stack(
        children: [
          // ── Top green blur header ──
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Stack(
              children: [
                // Base green layer
                Container(
                  width: double.infinity,
                  height: 150.h,
                  color: const Color(0xFF7CFFBC),
                ),
                // Blur overlay
                Positioned.fill(
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 150, sigmaY: 150),
                    child: Container(color: Colors.transparent),
                  ),
                ),
              ],
            ),
          ),

          // ── Main content ──
          SafeArea(
            child: Column(
              children: [
                Expanded(
                  child: SingleChildScrollView(
                    child: Column(
                      children: [
                        SizedBox(height: 120.h),

                        // ── Success Image ──
                        Image.asset(
                          Assets.success_payment,
                          width: 294.w,
                          height: 247.h,
                          fit: BoxFit.contain,
                        ),
                        SizedBox(height: 24.h),

                        // ── Booking Successfully ──
                        WText(
                          text: data?.title ?? 'Booking Successfully',
                          color: R.color.darkNavy,
                          fontSize: 22.sp,
                          fontWeight: FontWeight.w700,
                        ),
                        SizedBox(height: 8.h),
                        WText(
                          text: data?.subtitle ?? 'Your Charging Spot has been Confirmed',
                          color: R.color.neutralGray,
                          fontSize: 13.sp,
                          fontWeight: FontWeight.w400,
                        ),
                        SizedBox(height: 30.h),

                        // ── Details rows ──
                        Padding(
                          padding: EdgeInsets.symmetric(horizontal: 24.w),
                          child: Column(
                            children: [
                              _detailRow('Date:', data?.date ?? ''),
                              SizedBox(height: 12.h),
                              _detailRow('Location:', data?.location ?? ''),
                              SizedBox(height: 12.h),
                              _detailRow('Time:', data?.time ?? ''),
                              SizedBox(height: 12.h),
                              _detailRow('Station:', data?.stationName ?? ''),
                              if (data?.totalAmount != null) ...[
                                SizedBox(height: 12.h),
                                _detailRow('Total Amount:', '${getCurrencySymbol(data?.currencySymbol)}${data!.totalAmount}'),
                              ],
                            ],
                          ),
                        ),
                        SizedBox(height: 40.h),
                      ],
                    ),
                  ),
                ),

                // ── Buttons ──
                Padding(
                  padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 8.h),
                  child: Column(
                    children: [
                      WButton(
                        label: 'Go to Station',
                        onPressed: () => Get.to(() => NavigateStationScreen(bookingId: data?.bookingId ?? '')),
                      ),
                      SizedBox(height: 12.h),
                      WButton(
                        label: 'Back to Home',
                        buttonColor: R.color.mintGreen,
                        textColor: R.color.darkNavy,
                        decorationType: DecorationType.stroke,
                        onPressed: () => Get.offAll(() => const MainScreen()),
                      ),
                      SizedBox(height: 16.h),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _detailRow(String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WText(
          text: label,
          color: R.color.neutralGray,
          fontSize: 13.sp,
          fontWeight: FontWeight.w400,
        ),
        SizedBox(width: 8.w),
        Expanded(
          child: WText(
            text: value,
            color: R.color.darkNavy,
            fontSize: 13.sp,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }
}
