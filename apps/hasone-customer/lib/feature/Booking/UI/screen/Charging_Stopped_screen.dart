import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/app_settings/app_settings_service.dart';
import 'package:evcharg/core/service/payment/payment_service.dart';
import 'package:evcharg/core/service/utils/currency_helper.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/sslcommerz_webview_screen.dart';
import 'package:evcharg/feature/main_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class ChargingStoppedScreen extends StatefulWidget {
  final String sessionId;
  final String popupTitle;
  final String subTitle;
  final double energyDelivered;
  final double costPerKwh;
  final double extendSessionCharge;
  final double totalAmount;
  final bool needsPayment;
  final String? currencySymbol;

  const ChargingStoppedScreen({
    super.key,
    required this.sessionId,
    this.popupTitle = 'Charging Stopped',
    this.subTitle = 'Your booking is confirmed!',
    this.energyDelivered = 0,
    this.costPerKwh = 0,
    this.extendSessionCharge = 0,
    this.totalAmount = 0,
    this.needsPayment = false,
    this.currencySymbol,
  });

  @override
  State<ChargingStoppedScreen> createState() => _ChargingStoppedScreenState();
}

class _ChargingStoppedScreenState extends State<ChargingStoppedScreen> {
  late final AllBookingController _controller;
  bool _isPaying = false;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());
  }

  /// Called when user taps "Proceed to Pay" (needsPayment == true)
  /// Uses AppSettings to get available gateways, shows bottom sheet, processes payment.
  void _handlePayment() async {
    // Get available gateways from AppSettings
    final appSettings = Get.isRegistered<AppSettingsService>()
        ? Get.find<AppSettingsService>()
        : null;

    // Build gateway list from availableGateways array (already filtered by backend)
    final List<Map<String, String>> methods = [];

    if (appSettings != null && appSettings.availableGateways.isNotEmpty) {
      // Use the availableGateways array from AppSettings — backend only sends enabled ones
      for (final gw in appSettings.availableGateways) {
        methods.add({
          'id': gw['id']?.toString() ?? '',
          'name': gw['name']?.toString() ?? '',
          'desc': gw['description']?.toString() ?? '',
        });
      }
    } else if (appSettings != null) {
      // Fallback: use individual boolean flags
      if (appSettings.sslcommerzEnabled.value) {
        methods.add({'id': 'sslcommerz', 'name': 'SSLCommerz', 'desc': 'Pay via bKash, Nagad, Cards, Mobile Banking'});
      }
      if (appSettings.stripeEnabled.value) {
        methods.add({'id': 'stripe', 'name': 'Stripe', 'desc': 'Pay via International Cards'});
      }
      if (appSettings.savedCardEnabled.value) {
        methods.add({'id': 'card', 'name': 'Saved Card', 'desc': 'Pay using a saved payment card'});
      }
    }

    if (!mounted) return;

    String selectedMethod = methods.isNotEmpty ? methods.first['id']! : '';
    bool isProcessing = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          return Container(
            width: 390.w,
            padding: EdgeInsets.only(top: 32.h, right: 16.w, bottom: 32.h, left: 16.w),
            decoration: BoxDecoration(
              color: R.color.white,
              borderRadius: BorderRadius.only(
                topLeft: Radius.circular(32.r),
                topRight: Radius.circular(32.r),
              ),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Center(
                  child: Container(
                    width: 40.w, height: 4.h,
                    margin: EdgeInsets.only(bottom: 16.h),
                    decoration: BoxDecoration(color: R.color.lightGray, borderRadius: BorderRadius.circular(2.r)),
                  ),
                ),
                WText(text: 'Select Payment Method', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
                SizedBox(height: 16.h),

                // ── Empty state when all gateways disabled ──
                if (methods.isEmpty)
                  Container(
                    width: double.infinity,
                    padding: EdgeInsets.symmetric(vertical: 40.h),
                    child: Column(
                      children: [
                        Icon(Icons.payment_outlined, size: 48.sp, color: R.color.neutralGray),
                        SizedBox(height: 12.h),
                        WText(
                          text: 'No payment methods available',
                          color: R.color.neutralGray,
                          fontSize: 14.sp,
                          fontWeight: FontWeight.w500,
                          textAlign: TextAlign.center,
                        ),
                        SizedBox(height: 6.h),
                        WText(
                          text: 'Please contact support for assistance.',
                          color: R.color.neutralGray,
                          fontSize: 12.sp,
                          fontWeight: FontWeight.w400,
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  )
                else
                  ...methods.map((m) {
                  final isSelected = selectedMethod == m['id'];
                  return GestureDetector(
                    onTap: () => setSheet(() => selectedMethod = m['id']!),
                    child: Container(
                      width: double.infinity,
                      margin: EdgeInsets.only(bottom: 10.h),
                      padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 14.h),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(12.r),
                        border: Border.all(
                          color: isSelected ? R.color.mintGreen : const Color(0xFFE5E7EB),
                          width: 1.5,
                        ),
                      ),
                      child: Row(
                        children: [
                          Container(
                            width: 20.w, height: 20.w,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              border: Border.all(color: isSelected ? R.color.mintGreen : const Color(0xFFE5E7EB), width: 2),
                            ),
                            child: isSelected
                                ? Center(child: Container(width: 10.w, height: 10.w, decoration: BoxDecoration(color: R.color.mintGreen, shape: BoxShape.circle)))
                                : null,
                          ),
                          SizedBox(width: 12.w),
                          Icon(_gatewayIcon(m['id']!), color: isSelected ? R.color.mintGreen : R.color.neutralGray, size: 22.sp),
                          SizedBox(width: 10.w),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                WText(text: m['name']!, color: R.color.darkNavy, fontSize: 14.sp, fontWeight: FontWeight.w600),
                                SizedBox(height: 2.h),
                                WText(text: m['desc']!, color: R.color.neutralGray, fontSize: 11.sp, fontWeight: FontWeight.w400),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                }),

                SizedBox(height: 16.h),

                if (methods.isEmpty)
                  const SizedBox.shrink()
                else if (isProcessing)
                  Center(child: CircularProgressIndicator(color: R.color.mintGreen))
                else
                  WButton(
                    label: 'Confirm Payment',
                    height: 52.h,
                    radius: 12,
                    buttonColor: R.color.mintGreen,
                    textColor: R.color.white,
                    fontSize: 15.sp,
                    fontWeight: FontWeight.w600,
                    decorationType: DecorationType.solid,
                    onPressed: () async {
                      setSheet(() => isProcessing = true);

                      if (selectedMethod == 'stripe') {
                        final result = await _controller.paySession(
                          widget.sessionId,
                          paymentMethod: 'stripe',
                        );

                        if (result == null || result.clientSecret == null || result.clientSecret!.isEmpty) {
                          setSheet(() => isProcessing = false);
                          Get.snackbar('Error', 'Failed to initiate Stripe payment.',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.red.shade100,
                            colorText: Colors.red.shade900,
                            margin: EdgeInsets.all(16.w));
                          return;
                        }

                        if (result.publishableKey != null && result.publishableKey!.isNotEmpty) {
                          await PaymentService.updateStripeKey(result.publishableKey!);
                        }

                        final paymentIntentId = await PaymentService.confirmStripePayment(
                          result.clientSecret!,
                          publishableKey: result.publishableKey,
                        );
                        if (paymentIntentId == null) {
                          setSheet(() => isProcessing = false);
                          return;
                        }

                        await _controller.paySession(
                          widget.sessionId,
                          paymentMethod: 'stripe',
                          stripePaymentIntentId: paymentIntentId,
                        );

                        if (!mounted) return;
                        setSheet(() => isProcessing = false);
                        Navigator.pop(ctx);
                        Get.snackbar('Success', 'Payment completed successfully!',
                          snackPosition: SnackPosition.BOTTOM,
                          backgroundColor: Colors.green.shade100,
                          colorText: Colors.green.shade900,
                          margin: EdgeInsets.all(16.w));
                        _showReviewSheet();

                      } else if (selectedMethod == 'sslcommerz') {
                        final result = await _controller.paySession(
                          widget.sessionId,
                          paymentMethod: 'sslcommerz',
                        );

                        if (result == null) {
                          setSheet(() => isProcessing = false);
                          Get.snackbar('Error', 'Failed to initiate SSLCommerz payment.',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.red.shade100,
                            colorText: Colors.red.shade900,
                            margin: EdgeInsets.all(16.w));
                          return;
                        }

                        setSheet(() => isProcessing = false);
                        Navigator.pop(ctx);

                        if (result.gatewayUrl != null && result.gatewayUrl!.isNotEmpty) {
                          final success = await Get.to<bool>(() => SSLCommerzWebViewScreen(
                            gatewayUrl: result.gatewayUrl!,
                            sessionId: widget.sessionId,
                            onSuccess: () {},
                          ));
                          if (success == true) {
                            _showReviewSheet();
                          }
                        } else {
                          Get.snackbar('Error', 'No payment gateway URL received.',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.red.shade100,
                            colorText: Colors.red.shade900,
                            margin: EdgeInsets.all(16.w));
                        }

                      } else {
                        final result = await _controller.paySession(
                          widget.sessionId,
                          paymentMethod: selectedMethod,
                        );

                        if (!mounted) return;
                        setSheet(() => isProcessing = false);

                        if (result != null) {
                          Navigator.pop(ctx);
                          Get.snackbar('Success', 'Payment completed successfully!',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.green.shade100,
                            colorText: Colors.green.shade900,
                            margin: EdgeInsets.all(16.w));
                          _showReviewSheet();
                        } else {
                          Get.snackbar('Error', 'Payment failed. Please try again.',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.red.shade100,
                            colorText: Colors.red.shade900,
                            margin: EdgeInsets.all(16.w));
                        }
                      }
                    },
                  ),
              ],
            ),
          );
        },
      ),
    );
  }

  IconData _gatewayIcon(String id) {
    switch (id) {
      case 'sslcommerz':
        return Icons.account_balance;
      case 'stripe':
        return Icons.payment;
      case 'card':
        return Icons.credit_card;
      default:
        return Icons.payment;
    }
  }

  // ── Give Review bottom sheet ──
  void _showReviewSheet() {
    int stars = 4;
    final descController = TextEditingController();
    bool isSubmitting = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
        child: StatefulBuilder(
          builder: (ctx, setSheet) => Container(
            width: 390.w,
            padding: EdgeInsets.only(
                top: 32.h, right: 16.w, bottom: 32.h, left: 16.w),
            decoration: BoxDecoration(
              color: R.color.white,
              borderRadius: BorderRadius.only(
                topLeft: Radius.circular(32.r),
                topRight: Radius.circular(32.r),
              ),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // ── Drag handle ──
                Center(
                  child: Container(
                    width: 40.w,
                    height: 4.h,
                    margin: EdgeInsets.only(bottom: 16.h),
                    decoration: BoxDecoration(
                      color: R.color.lightGray,
                      borderRadius: BorderRadius.circular(2.r),
                    ),
                  ),
                ),

                WText(
                    text: 'Give Review',
                    color: R.color.darkNavy,
                    fontSize: 18.sp,
                    fontWeight: FontWeight.w700),
                SizedBox(height: 8.h),
                Divider(color: R.color.lightGrayShade),
                SizedBox(height: 8.h),

                // ── Share experience ──
                Center(
                  child: Column(
                    children: [
                      WText(
                          text: 'Share your experience with us',
                          color: R.color.darkNavy,
                          fontSize: 14.sp,
                          fontWeight: FontWeight.w600,
                          textAlign: TextAlign.center),
                      SizedBox(height: 4.h),
                      WText(
                          text:
                              'How was your charging session at this station?',
                          color: R.color.neutralGray,
                          fontSize: 12.sp,
                          fontWeight: FontWeight.w400,
                          textAlign: TextAlign.center),
                      SizedBox(height: 16.h),

                      // ── Stars ──
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: List.generate(
                            5,
                            (i) => GestureDetector(
                                  onTap: () => setSheet(() => stars = i + 1),
                                  child: Padding(
                                    padding:
                                        EdgeInsets.symmetric(horizontal: 4.w),
                                    child: Icon(
                                      i < stars
                                          ? Icons.star_rounded
                                          : Icons.star_outline_rounded,
                                      color: const Color(0xFFFBBC05),
                                      size: 36.sp,
                                    ),
                                  ),
                                )),
                      ),
                    ],
                  ),
                ),
                SizedBox(height: 10.h),

                // ── Description label ──
                WText(
                    text: 'Description',
                    color: R.color.darkNavy,
                    fontSize: 13.sp,
                    fontWeight: FontWeight.w600),
                SizedBox(height: 8.h),

                // ── Text field ──
                Container(
                  height: 72.h,
                  decoration: BoxDecoration(
                    color: R.color.white,
                    borderRadius: BorderRadius.circular(8.r),
                    border: Border.all(color: R.color.lightGray),
                  ),
                  child: TextField(
                    controller: descController,
                    maxLines: 3,
                    style:
                        TextStyle(fontSize: 12.sp, color: R.color.darkNavy),
                    decoration: InputDecoration(
                      hintText: 'Tell us more about your experience......',
                      hintStyle: TextStyle(
                          fontSize: 12.sp, color: R.color.neutralGray),
                      border: InputBorder.none,
                      contentPadding: EdgeInsets.symmetric(
                          horizontal: 12.w, vertical: 10.h),
                    ),
                  ),
                ),
                SizedBox(height: 20.h),

                // ── Submit Review button ──
                WButton(
                  label: isSubmitting ? 'Submitting...' : 'Submit Review',
                  height: 52.h,
                  radius: 12,
                  buttonColor: R.color.mintGreen,
                  textColor: R.color.white,
                  fontSize: 15.sp,
                  fontWeight: FontWeight.w600,
                  decorationType: DecorationType.solid,
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          setSheet(() => isSubmitting = true);

                          final success = await _controller.submitReview(
                            sessionId: widget.sessionId,
                            rating: stars,
                            description: descController.text.trim(),
                          );

                          setSheet(() => isSubmitting = false);

                          if (success) {
                            Navigator.pop(ctx);
                            Get.snackbar(
                              'Thank you!',
                              'Your review has been submitted.',
                              snackPosition: SnackPosition.BOTTOM,
                              backgroundColor: Colors.green.shade100,
                              colorText: Colors.green.shade900,
                              margin: EdgeInsets.all(16.w),
                            );
                            Get.offAll(() => const MainScreen());
                          } else {
                            Get.snackbar(
                              'Error',
                              'Failed to submit review. Please try again.',
                              snackPosition: SnackPosition.BOTTOM,
                              backgroundColor: Colors.red.shade100,
                              colorText: Colors.red.shade900,
                              margin: EdgeInsets.all(16.w),
                            );
                          }
                        },
                ),
                SizedBox(height: 12.h),

                // ── Go to Home button ──
                WButton(
                  label: 'Go to Home',
                  height: 52.h,
                  radius: 12,
                  buttonColor: R.color.mintGreen,
                  textColor: R.color.mintGreen,
                  fontSize: 15.sp,
                  fontWeight: FontWeight.w600,
                  decorationType: DecorationType.stroke,
                  onPressed: () {
                    Navigator.pop(ctx);
                    Get.offAll(() => const MainScreen());
                  },
                ),
              ],
            ),
          ),
        ),
      ),
    );
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
                  color: R.color.mintGreenAccent),
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
                      SizedBox(width: 22.w),
                      Expanded(
                        child: Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              WText(
                                  text: widget.popupTitle,
                                  color: R.color.darkNavy,
                                  fontSize: 18.sp,
                                  fontWeight: FontWeight.w700),
                              SizedBox(height: 4.h),
                              WText(
                                  text: widget.subTitle,
                                  color: R.color.neutralGray,
                                  fontSize: 13.sp,
                                  fontWeight: FontWeight.w400),
                            ],
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
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  // ── Station charging image ──
                  Image.asset(
                    Assets.stationcharging,
                    width: 390.w,
                    height: 340.h,
                    fit: BoxFit.cover,
                  ),
                  SizedBox(height: 30),

                  // ── Summary card ──
                  Padding(
                    padding: EdgeInsets.symmetric(horizontal: 16.w),
                    child: Column(
                      children: [
                        Container(
                          width: 358.w,
                          padding: EdgeInsets.all(16.w),
                          decoration: BoxDecoration(
                            color: R.color.white,
                            borderRadius: BorderRadius.circular(12.r),
                            border: Border.all(
                                color: R.color.lightGray, width: 1),
                          ),
                          child: Column(
                            children: [
                              _summaryRow('Energy Delivered',
                                  '${widget.energyDelivered} kwh'),
                              // SizedBox(height: 12.h),
                              // _summaryRow('Cost/kwh',
                              //     '\$${widget.costPerKwh}'),
                              SizedBox(height: 12.h),
                              _summaryRow('Extend Session Charge',
                                  '${getCurrencySymbol(widget.currencySymbol)}${widget.extendSessionCharge}'),
                              SizedBox(height: 12.h),
                              // Divider(
                              //     color: R.color.lightGray,
                              //     height: 1.h,
                              //     thickness: 1),
                              // SizedBox(height: 12.h),
                              // _summaryRow('Total Amount:',
                              //     '\$${widget.totalAmount}',
                              //     isBold: true),
                            ],
                          ),
                        ),
                        SizedBox(height: 24.h),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),

          // ── Bottom button ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: _isPaying
                ? Center(
                    child:
                        CircularProgressIndicator(color: R.color.mintGreen))
                : WButton(
                    label: widget.needsPayment
              // label: widget.extendSessionCharge > 0
                        ? 'Proceed to Pay'
                        : 'Confirm',
                    height: 52.h,
                    radius: 12,
                    buttonColor: R.color.mintGreen,
                    textColor: R.color.white,
                    fontSize: 15.sp,
                    fontWeight: FontWeight.w600,
                    onPressed: widget.needsPayment
                    // onPressed: widget.extendSessionCharge > 0
                        ? _handlePayment
                        : _showReviewSheet,
                  ),
          ),
        ],
      ),
    );
  }

  Widget _summaryRow(String label, String value, {bool isBold = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        WText(
            text: label,
            color: R.color.neutralGray,
            fontSize: 13.sp,
            fontWeight: FontWeight.w400),
        WText(
          text: value,
          color: R.color.darkNavy,
          fontSize: isBold ? 14.sp : 13.sp,
          fontWeight: isBold ? FontWeight.w700 : FontWeight.w500,
        ),
      ],
    );
  }
}

