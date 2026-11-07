import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/utils/currency_helper.dart';
import 'package:evcharg/feature/Booking/UI/screen/payment_method_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

import '../../model/bookingdetailsMOdel.dart';

class BookingDetails extends StatefulWidget {
  final Data? bookingData;

  const BookingDetails({super.key, this.bookingData});

  @override
  State<BookingDetails> createState() => _BookingDetailsState();
}

class _BookingDetailsState extends State<BookingDetails> {
  Data? get booking => widget.bookingData;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── Top green header with blur ──
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
                            text: 'Booking Details',
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

          // ── Content ──
          Expanded(
            child: SingleChildScrollView(
              padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ── Vehicle Info Card ──
                  Container(
                    width: 358.w,
                    padding: EdgeInsets.all(12.w),
                    decoration: BoxDecoration(
                      color: R.color.white,
                      borderRadius: BorderRadius.circular(12.r),
                      border: Border.all(color: R.color.lightGray, width: 1),
                    ),
                    child: Row(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(8.r),
                          child: (booking?.vehicleImage != null &&
                                  booking!.vehicleImage!.isNotEmpty)
                              ? Image.network(
                                  booking!.vehicleImage!,
                                  width: 52.w,
                                  height: 52.w,
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, __, ___) => Image.asset(
                                    Assets.carScreen,
                                    width: 52.w,
                                    height: 52.w,
                                    fit: BoxFit.cover,
                                  ),
                                )
                              : Image.asset(
                                  Assets.carScreen,
                                  width: 52.w,
                                  height: 52.w,
                                  fit: BoxFit.cover,
                                ),
                        ),
                        SizedBox(width: 12.w),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            WText(
                              text: booking?.vehicleName ?? 'Vehicle',
                              color: R.color.darkNavy,
                              fontSize: 14.sp,
                              fontWeight: FontWeight.w700,
                            ),
                            SizedBox(height: 4.h),
                            WText(
                              text: booking?.vehiclePlate ?? '',
                              color: R.color.neutralGray,
                              fontSize: 12.sp,
                              fontWeight: FontWeight.w400,
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  SizedBox(height: 16.h),

                  // ── Station Card ──
                  Container(
                    width: 358.w,
                    padding: EdgeInsets.all(16.w),
                    decoration: BoxDecoration(
                      color: R.color.white,
                      borderRadius: BorderRadius.circular(12.r),
                      border: Border.all(color: R.color.lightGray, width: 1),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // ── Image + Name/Address row ──
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.center,
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(8.r),
                              child: (booking?.stationImage != null &&
                                      booking!.stationImage!.isNotEmpty)
                                  ? Image.network(
                                      booking!.stationImage!,
                                      width: 52.w,
                                      height: 52.w,
                                      fit: BoxFit.cover,
                                      errorBuilder: (_, __, ___) =>
                                          Image.asset(
                                        Assets.charging,
                                        width: 52.w,
                                        height: 52.w,
                                        fit: BoxFit.cover,
                                      ),
                                    )
                                  : Image.asset(
                                      Assets.charging,
                                      width: 52.w,
                                      height: 52.w,
                                      fit: BoxFit.cover,
                                    ),
                            ),
                            SizedBox(width: 12.w),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  WText(
                                    text: booking?.stationName ?? 'Charging Station',
                                    color: R.color.darkNavy,
                                    fontSize: 13.sp,
                                    fontWeight: FontWeight.w700,
                                  ),
                                  SizedBox(height: 4.h),
                                  WText(
                                    text: booking?.address ?? '',
                                    color: R.color.neutralGray,
                                    fontSize: 11.sp,
                                    fontWeight: FontWeight.w400,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        SizedBox(height: 16.h),
                        Divider(color: R.color.lightGray, height: 1.h, thickness: 1),
                        SizedBox(height: 16.h),

                        // ── Type | Energy | Slot row with dividers ──
                        IntrinsicHeight(
                          child: Row(
                            children: [
                              Expanded(
                                  child: _infoChip(
                                      booking?.connectorType ?? 'Type A',
                                      'Connector')),
                              VerticalDivider(
                                  color: R.color.lightGray,
                                  width: 1.w,
                                  thickness: 1),
                              Expanded(
                                  child: _infoChip(
                                      booking?.energyKwh ?? '110 kw/h',
                                      'Energy')),
                              VerticalDivider(
                                  color: R.color.lightGray,
                                  width: 1.w,
                                  thickness: 1),
                              Expanded(
                                  child: _infoChip(
                                      booking?.chargingSlot ?? 'Slot A',
                                      'Charging Slot')),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  SizedBox(height: 16.h),

                  // ── Booking Info Card ──
                  Container(
                    width: 358.w,
                    padding: EdgeInsets.all(16.w),
                    decoration: BoxDecoration(
                      color: R.color.white,
                      borderRadius: BorderRadius.circular(12.r),
                      border: Border.all(color: R.color.lightGray, width: 1),
                    ),
                    child: Column(
                      children: [
                        _detailRow('Booking Date:',
                            booking?.bookingDate ?? ''),
                        SizedBox(height: 12.h),
                        _detailRow('Charging Duration:',
                            booking?.chargingDuration ?? ''),
                        SizedBox(height: 12.h),
                        _detailRow('Charging Session Timing:',
                            booking?.chargingSessionTiming ?? ''),
                      ],
                    ),
                  ),
                  SizedBox(height: 16.h),

                  // ── Amount Card ──
                  Container(
                    width: 358.w,
                    padding: EdgeInsets.all(16.w),
                    decoration: BoxDecoration(
                      color: R.color.white,
                      borderRadius: BorderRadius.circular(12.r),
                      border: Border.all(color: R.color.lightGray, width: 1),
                    ),
                    child: Column(
                      children: [
                        _detailRow('Amount Estimation:',
                            '${getCurrencySymbol(booking?.currencySymbol)}${booking?.amountEstimation ?? 0}'),
                        SizedBox(height: 12.h),
                        _detailRow(
                            'Tax:', '${getCurrencySymbol(booking?.currencySymbol)}${booking?.tax ?? 0}'),
                        SizedBox(height: 12.h),
                        Divider(
                            color: R.color.lightGray,
                            height: 1.h,
                            thickness: 1),
                        SizedBox(height: 12.h),
                        _detailRow('Total Amount:',
                            '${getCurrencySymbol(booking?.currencySymbol)}${booking?.totalAmount ?? 0}',
                            isBold: true),
                      ],
                    ),
                  ),
                  SizedBox(height: 24.h),
                ],
              ),
            ),
          ),

          // ── Proceed to Pay Button ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: WButton(
              label: 'Proceed to Pay',
              height: 52.h,
              radius: 12,
              buttonColor: R.color.mintGreen,
              textColor: R.color.white,
              fontSize: 16.sp,
              fontWeight: FontWeight.w600,
              decorationType: DecorationType.solid,
              onPressed: () {
                Get.to(() => PaymentMethodScreen(bookingData: booking));
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _infoChip(String value, String label) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.center,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        WText(
          text: value,
          color: R.color.darkNavy,
          fontSize: 13.sp,
          fontWeight: FontWeight.w700,
        ),
        SizedBox(height: 2.h),
        WText(
          text: label,
          color: R.color.neutralGray,
          fontSize: 10.sp,
          fontWeight: FontWeight.w400,
        ),
      ],
    );
  }

  Widget _detailRow(String label, String value, {bool isBold = false}) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WText(
          text: label,
          color: R.color.neutralGray,
          fontSize: 12.sp,
          fontWeight: FontWeight.w400,
        ),
        const Spacer(),
        WText(
          text: value,
          color: R.color.darkNavy,
          fontSize: isBold ? 14.sp : 12.sp,
          fontWeight: isBold ? FontWeight.w700 : FontWeight.w600,
        ),
      ],
    );
  }
}
