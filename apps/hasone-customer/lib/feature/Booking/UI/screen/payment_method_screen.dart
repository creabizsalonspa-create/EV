import 'dart:ui';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/app_settings/app_settings_service.dart';
import 'package:evcharg/core/service/payment/payment_service.dart';
import 'package:evcharg/core/service/utils/currency_helper.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/controller/booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/booking_confirm_screen.dart';
import 'package:evcharg/feature/Booking/UI/screen/sslcommerz_webview_screen.dart';
import 'package:evcharg/feature/Booking/model/bookingdetailsMOdel.dart';
import 'package:evcharg/feature/Booking/model/bookingpaymentmodel.dart' as bpm;
import 'package:evcharg/feature/Booking/model/get_payment_method_model.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class PaymentMethodScreen extends StatefulWidget {
  final Data? bookingData;

  // ── Extension payment fields ──
  final bool isExtensionPayment;
  final String? sessionId;
  final int? extendMins;
  final int? extensionCost;
  final String? extensionLabel;
  final void Function(int newTimeRemainingMs)? onExtensionSuccess;

  const PaymentMethodScreen({
    super.key,
    this.bookingData,
    this.isExtensionPayment = false,
    this.sessionId,
    this.extendMins,
    this.extensionCost,
    this.extensionLabel,
    this.onExtensionSuccess,
  });

  @override
  State<PaymentMethodScreen> createState() => _PaymentMethodScreenState();
}

class _PaymentMethodScreenState extends State<PaymentMethodScreen> {
  Data? get booking => widget.bookingData;

  // API data
  PaymentMethodData? _pmData;
  bool _loadingData = true;

  // Selection state
  String _selectedGatewayId = ''; // "sslcommerz" | "stripe" | "card"
  int _selectedCardIndex = -1;

  bool _paymentProcessing = false;

  @override
  void initState() {
    super.initState();
    _loadPaymentMethodData();
  }

  Future<void> _loadPaymentMethodData() async {
    final bookingId = booking?.bookingId ?? '';
    if (bookingId.isEmpty) {
      setState(() => _loadingData = false);
      return;
    }

    final ctrl = Get.isRegistered<BookingController>()
        ? Get.find<BookingController>()
        : Get.put(BookingController());

    final data = await ctrl.fetchPaymentMethod(bookingId);
    if (!mounted) return;

    setState(() {
      _pmData = data;
      _loadingData = false;

      // Update Stripe key if provided
      if (data?.stripePublishableKey != null && data!.stripePublishableKey!.isNotEmpty) {
        PaymentService.updateStripeKey(data.stripePublishableKey!);
      }

      // Use gateways directly — backend already returns only enabled ones
      final gateways = data?.availableGateways ?? [];

      // Auto-select first available gateway
      if (gateways.isNotEmpty) {
        _selectedGatewayId = gateways.first.id ?? '';
      }

      // Auto-select default card
      if (data?.cards != null) {
        for (int i = 0; i < data!.cards!.length; i++) {
          if (data.cards![i].isDefault == true) {
            _selectedCardIndex = i;
            break;
          }
        }
        if (_selectedCardIndex < 0 && data.cards!.isNotEmpty) {
          _selectedCardIndex = 0;
        }
      }
    });
  }

  /// Get enabled gateways — the API already returns only enabled gateways,
  /// so we use them directly. If availableGateways from API is empty,
  /// fall back to AppSettings availableGateways.
  List<AvailableGateway> get _enabledGateways {
    final gateways = _pmData?.availableGateways ?? [];
    if (gateways.isNotEmpty) return gateways;

    // Fallback: build from AppSettings availableGateways array
    final appSettings = Get.isRegistered<AppSettingsService>()
        ? Get.find<AppSettingsService>()
        : null;
    if (appSettings != null && appSettings.availableGateways.isNotEmpty) {
      return appSettings.availableGateways.map((gw) => AvailableGateway(
        id: gw['id']?.toString(),
        name: gw['name']?.toString(),
        description: gw['description']?.toString(),
        minAmount: (gw['minAmount'] as num?)?.toDouble(),
      )).toList();
    }
    return gateways;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── Top green header with blur ──
          _buildHeader(),

          // ── Body ──
          Expanded(
            child: _loadingData
                ? Center(child: CircularProgressIndicator(color: R.color.mintGreen))
                : SingleChildScrollView(
                    padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 20.h),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // ── Details card ──
                        _buildDetailsCard(),

                        SizedBox(height: 24.h),

                        // ── Payment Gateway selection ──
                        WText(
                          text: 'Select Payment Method',
                          color: R.color.darkNavy,
                          fontSize: 16.sp,
                          fontWeight: FontWeight.w700,
                        ),
                        SizedBox(height: 12.h),

                        _buildGatewayList(),

                        // ── Show saved cards when "card" gateway selected ──
                        if (_selectedGatewayId == 'card') ...[
                          SizedBox(height: 20.h),
                          _buildSavedCardsList(),
                        ],

                        SizedBox(height: 24.h),
                      ],
                    ),
                  ),
          ),

          // ── Confirm Payment Button ──
          if (_enabledGateways.isNotEmpty)
            Padding(
              padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
              child: WButton(
                label: _paymentProcessing ? 'Processing...' : 'Confirm Payment',
                height: 52.h,
                radius: 12,
                buttonColor: R.color.mintGreen,
                textColor: Colors.white,
                fontSize: 16.sp,
                fontWeight: FontWeight.w600,
                decorationType: DecorationType.solid,
                onPressed: _paymentProcessing ? null : _onConfirmPayment,
              ),
            ),
        ],
      ),
    );
  }

  // ══════════════════════════════════════════════════════════════════════════
  // HEADER
  // ══════════════════════════════════════════════════════════════════════════

  Widget _buildHeader() {
    return Stack(
      children: [
        Container(
          width: double.infinity,
          height: 150.h,
          color: const Color(0xFF7CFFBC),
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
                  child: Icon(Icons.arrow_back, color: R.color.darkNavy, size: 22.sp),
                ),
                Expanded(
                  child: Center(
                    child: WText(
                      text: 'Payment Method',
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
    );
  }

  // ══════════════════════════════════════════════════════════════════════════
  // DETAILS CARD
  // ══════════════════════════════════════════════════════════════════════════

  Widget _buildDetailsCard() {
    return Container(
      width: 358.w,
      padding: EdgeInsets.all(16.w),
      decoration: BoxDecoration(
        color: const Color(0xFFEBFBF2),
        borderRadius: BorderRadius.circular(12.r),
        border: Border.all(color: const Color(0xFFE5E7EB), width: 1),
      ),
      child: widget.isExtensionPayment
          ? _buildExtensionDetails()
          : _buildBookingDetails(),
    );
  }

  Widget _buildBookingDetails() {
    final date = _pmData?.date ?? booking?.bookingDate ?? '';
    final time = _pmData?.time ?? booking?.chargingSessionTiming ?? '';
    final duration = _pmData?.chargingDuration ?? booking?.chargingDuration ?? '';
    final amount = _pmData?.amountEstimation ?? booking?.amountEstimation ?? 0;
    final tax = _pmData?.tax ?? booking?.tax ?? 0;
    final total = _pmData?.totalAmount ?? booking?.totalAmount ?? 0;
    final cs = getCurrencySymbol(_pmData?.currencySymbol ?? booking?.currencySymbol);

    return Column(
      children: [
        Container(
          width: 326.w,
          padding: EdgeInsets.all(16.w),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12.r),
            border: Border.all(color: const Color(0xFFE5E7EB), width: 1),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _infoRow('Booking Date:', date),
              SizedBox(height: 10.h),
              _infoRow('Charging Duration:', duration),
              SizedBox(height: 10.h),
              _infoRow('Time:', time),
            ],
          ),
        ),
        SizedBox(height: 12.h),
        Divider(color: const Color(0xFFE5E7EB), height: 1.h, thickness: 1),
        SizedBox(height: 12.h),
        Container(
          width: 326.w,
          padding: EdgeInsets.all(16.w),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12.r),
            border: Border.all(color: const Color(0xFFE5E7EB), width: 1),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _amountRow('Amount Estimation:', '$cs$amount'),
              SizedBox(height: 10.h),
              _amountRow('Tax:', '$cs$tax'),
              SizedBox(height: 10.h),
              Divider(color: const Color(0xFFE5E7EB), height: 1.h, thickness: 1),
              SizedBox(height: 10.h),
              _amountRow('Total Amount:', '$cs$total', isBold: true),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildExtensionDetails() {
    final cs = getCurrencySymbol(_pmData?.currencySymbol);
    return Container(
      width: 326.w,
      padding: EdgeInsets.all(16.w),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12.r),
        border: Border.all(color: const Color(0xFFE5E7EB), width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          WText(
            text: 'Extend Charging Session',
            color: R.color.darkNavy,
            fontSize: 16.sp,
            fontWeight: FontWeight.w700,
          ),
          SizedBox(height: 12.h),
          _infoRow('Extension:', widget.extensionLabel ?? '${widget.extendMins ?? 0} Min'),
          SizedBox(height: 10.h),
          Divider(color: const Color(0xFFE5E7EB), height: 1.h, thickness: 1),
          SizedBox(height: 10.h),
          _amountRow('Estimated Cost:', '$cs${widget.extensionCost ?? 0}', isBold: true),
        ],
      ),
    );
  }

  // ══════════════════════════════════════════════════════════════════════════
  // GATEWAY LIST
  // ══════════════════════════════════════════════════════════════════════════

  Widget _buildGatewayList() {
    final gateways = _enabledGateways;

    if (gateways.isEmpty) {
      return Container(
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
      );
    }

    return Column(
      children: gateways.map((gw) {
        final isSelected = _selectedGatewayId == gw.id;
        return Padding(
          padding: EdgeInsets.only(bottom: 10.h),
          child: GestureDetector(
            onTap: () => setState(() {
              _selectedGatewayId = gw.id ?? '';
            }),
            child: Container(
              width: double.infinity,
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
                  _radioIndicator(isSelected),
                  SizedBox(width: 12.w),
                  Icon(
                    _gatewayIcon(gw.id ?? ''),
                    color: isSelected ? R.color.mintGreen : R.color.neutralGray,
                    size: 22.sp,
                  ),
                  SizedBox(width: 10.w),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        WText(
                          text: gw.name ?? '',
                          color: R.color.darkNavy,
                          fontSize: 14.sp,
                          fontWeight: FontWeight.w600,
                        ),
                        if (gw.description != null && gw.description!.isNotEmpty) ...[
                          SizedBox(height: 2.h),
                          WText(
                            text: gw.description!,
                            color: R.color.neutralGray,
                            fontSize: 11.sp,
                            fontWeight: FontWeight.w400,
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      }).toList(),
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

  // ══════════════════════════════════════════════════════════════════════════
  // SAVED CARDS LIST
  // ══════════════════════════════════════════════════════════════════════════

  Widget _buildSavedCardsList() {
    final cards = _pmData?.cards ?? [];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WText(
          text: 'Select Card',
          color: R.color.darkNavy,
          fontSize: 14.sp,
          fontWeight: FontWeight.w600,
        ),
        SizedBox(height: 10.h),

        if (cards.isEmpty)
          Container(
            width: double.infinity,
            padding: EdgeInsets.symmetric(vertical: 20.h),
            child: Center(
              child: WText(
                text: 'No saved cards available.',
                color: R.color.neutralGray,
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
              ),
            ),
          )
        else
          ...List.generate(cards.length, (index) {
            final card = cards[index];
            final isSelected = _selectedCardIndex == index;
            return Padding(
              padding: EdgeInsets.only(bottom: 10.h),
              child: GestureDetector(
                onTap: () => setState(() => _selectedCardIndex = index),
                child: Container(
                  width: double.infinity,
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
                      _radioIndicator(isSelected),
                      SizedBox(width: 12.w),
                      Icon(Icons.credit_card, color: R.color.neutralGray, size: 20.sp),
                      SizedBox(width: 8.w),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          WText(
                            text: card.cardType ?? 'Card',
                            color: R.color.darkNavy,
                            fontSize: 14.sp,
                            fontWeight: FontWeight.w500,
                          ),
                          if (card.cardHolder != null && card.cardHolder!.isNotEmpty)
                            WText(
                              text: card.cardHolder!,
                              color: R.color.neutralGray,
                              fontSize: 11.sp,
                              fontWeight: FontWeight.w400,
                            ),
                        ],
                      ),
                      const Spacer(),
                      WText(
                        text: '**** ${card.last4 ?? '****'}',
                        color: R.color.neutralGray,
                        fontSize: 13.sp,
                        fontWeight: FontWeight.w400,
                      ),
                    ],
                  ),
                ),
              ),
            );
          }),
      ],
    );
  }

  // ══════════════════════════════════════════════════════════════════════════
  // HELPER: Enrich SSLCommerz result
  // ══════════════════════════════════════════════════════════════════════════

  void _enrichSSLCommerzResult(bpm.Data result) {
    result.title ??= 'Booking Successfully';
    result.subtitle ??= 'Your Charging Spot has been Confirmed';
    result.date ??= _pmData?.date ?? booking?.bookingDate ?? '';
    result.time ??= _pmData?.time ?? booking?.slotStart ?? '';
    result.location ??= booking?.address ?? '';
    result.stationName ??= booking?.stationName ?? '';
    result.totalAmount ??= _pmData?.totalAmount ?? booking?.totalAmount;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // CONFIRM PAYMENT
  // ══════════════════════════════════════════════════════════════════════════

  Future<void> _onConfirmPayment() async {
    if (_selectedGatewayId.isEmpty) {
      Get.snackbar('Error', 'Please select a payment method',
          snackPosition: SnackPosition.BOTTOM);
      return;
    }

    // If saved card selected, must pick a card
    if (_selectedGatewayId == 'card') {
      final cards = _pmData?.cards ?? [];
      if (cards.isEmpty || _selectedCardIndex < 0) {
        Get.snackbar('Error', 'Please select a saved card',
            snackPosition: SnackPosition.BOTTOM);
        return;
      }
    }

    setState(() => _paymentProcessing = true);

    if (widget.isExtensionPayment) {
      await _processExtensionPayment();
    } else {
      await _processBookingPayment();
    }

    if (mounted) setState(() => _paymentProcessing = false);
  }

  Future<void> _processBookingPayment() async {
    final bookingId = booking?.bookingId ?? _pmData?.bookingId ?? '';
    if (bookingId.isEmpty) {
      Get.snackbar('Error', 'Booking ID not found', snackPosition: SnackPosition.BOTTOM);
      return;
    }

    final ctrl = Get.isRegistered<BookingController>()
        ? Get.find<BookingController>()
        : Get.put(BookingController());
    final totalAmount = _pmData?.totalAmount ?? booking?.totalAmount ?? 0;

    if (_selectedGatewayId == 'stripe') {
      // ── Stripe Flow ──
      String? savedCardId;
      if (_selectedCardIndex >= 0 && (_pmData?.cards?.isNotEmpty ?? false)) {
        savedCardId = _pmData!.cards![_selectedCardIndex].cardId;
      }

      // Step 1: Create PaymentIntent
      final result = await ctrl.initiateStripePayment(
        bookingId: bookingId,
        amount: totalAmount.toDouble(),
        savedCardId: savedCardId,
      );

      if (result == null || result.clientSecret == null || result.clientSecret!.isEmpty) {
        Get.snackbar('Error', 'Failed to initiate Stripe payment.',
            snackPosition: SnackPosition.BOTTOM,
            backgroundColor: Colors.red.shade100,
            colorText: Colors.red.shade900);
        return;
      }

      // Update Stripe key if returned from create-intent
      if (result.publishableKey != null && result.publishableKey!.isNotEmpty) {
        await PaymentService.updateStripeKey(result.publishableKey!);
      }

      // Step 2: Confirm with Stripe SDK — pass publishableKey to ensure it matches
      final paymentIntentId = await PaymentService.confirmStripePayment(
        result.clientSecret!,
        publishableKey: result.publishableKey,
      );
      if (paymentIntentId == null) return;

      // Step 3: Verify with backend via POST /api/v1/payment/stripe/verify
      final verifyResult = await ctrl.verifyStripePayment(
        bookingId: bookingId,
        stripePaymentIntentId: paymentIntentId,
      );

      if (verifyResult != null) {
        Get.off(() => BookingConfirmScreen(paymentData: verifyResult));
      } else {
        Get.off(() => BookingConfirmScreen(paymentData: result));
      }
    } else if (_selectedGatewayId == 'sslcommerz') {
      // ── SSLCommerz Flow ──
      final result = await ctrl.initiateSSLCommerzPayment(
        bookingId: bookingId,
        amount: totalAmount.toDouble(),
      );

      if (result == null) {
        Get.snackbar('Error', 'Failed to initiate SSLCommerz payment.',
            snackPosition: SnackPosition.BOTTOM,
            backgroundColor: Colors.red.shade100,
            colorText: Colors.red.shade900);
        return;
      }

      if (result.gatewayUrl != null && result.gatewayUrl!.isNotEmpty) {
        final success = await Get.to<bool>(() => SSLCommerzWebViewScreen(
          gatewayUrl: result.gatewayUrl!,
          bookingId: bookingId,
          onSuccess: () {},
        ));

        if (success == true) {
          _enrichSSLCommerzResult(result);
          Get.off(() => BookingConfirmScreen(paymentData: result));
        }
      } else {
        Get.snackbar('Error', 'No payment gateway URL received.',
            snackPosition: SnackPosition.BOTTOM,
            backgroundColor: Colors.red.shade100,
            colorText: Colors.red.shade900);
      }
    } else if (_selectedGatewayId == 'card') {
      // ── Saved Card Flow ──
      final cardId = _pmData?.cards?[_selectedCardIndex].cardId;
      final result = await ctrl.confirmPayment(
        bookingId: bookingId,
        paymentMethod: 'card',
        cardId: cardId,
      );

      if (result != null) {
        Get.off(() => BookingConfirmScreen(paymentData: result));
      } else {
        Get.snackbar('Error', 'Payment failed. Please try again.',
            snackPosition: SnackPosition.BOTTOM,
            backgroundColor: Colors.red.shade100,
            colorText: Colors.red.shade900);
      }
    }
  }

  Future<void> _processExtensionPayment() async {
    final sessionId = widget.sessionId;
    final extendMins = widget.extendMins;
    if (sessionId == null || sessionId.isEmpty || extendMins == null) {
      Get.snackbar('Error', 'Session info not found', snackPosition: SnackPosition.BOTTOM);
      return;
    }

    final allBookingCtrl = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());

    final extendResult = await allBookingCtrl.extendSession(sessionId, extendMins);
    if (extendResult == null) {
      Get.snackbar('Error', 'Failed to extend session.',
          snackPosition: SnackPosition.BOTTOM,
          backgroundColor: Colors.red.shade100,
          colorText: Colors.red.shade900);
      return;
    }

    String? cardId;
    if (_selectedGatewayId == 'card' && _selectedCardIndex >= 0) {
      cardId = _pmData?.cards?[_selectedCardIndex].cardId;
    }

    if (_selectedGatewayId == 'stripe') {
      final payResult = await allBookingCtrl.paySession(sessionId, paymentMethod: 'stripe');

      if (payResult == null || payResult.clientSecret == null || payResult.clientSecret!.isEmpty) {
        Get.snackbar('Error', 'Failed to initiate Stripe payment.',
            snackPosition: SnackPosition.BOTTOM, backgroundColor: Colors.red.shade100, colorText: Colors.red.shade900);
        return;
      }

      if (payResult.publishableKey != null && payResult.publishableKey!.isNotEmpty) {
        await PaymentService.updateStripeKey(payResult.publishableKey!);
      }

      final paymentIntentId = await PaymentService.confirmStripePayment(
        payResult.clientSecret!,
        publishableKey: payResult.publishableKey,
      );
      if (paymentIntentId == null) return;

      await allBookingCtrl.paySession(sessionId, paymentMethod: 'stripe', stripePaymentIntentId: paymentIntentId);
      _onExtensionPaymentSuccess(extendResult, extendMins);
    } else if (_selectedGatewayId == 'sslcommerz') {
      final payResult = await allBookingCtrl.paySession(sessionId, paymentMethod: 'sslcommerz');

      if (payResult == null) {
        Get.snackbar('Error', 'Failed to initiate SSLCommerz payment.',
            snackPosition: SnackPosition.BOTTOM, backgroundColor: Colors.red.shade100, colorText: Colors.red.shade900);
        return;
      }

      if (payResult.gatewayUrl != null && payResult.gatewayUrl!.isNotEmpty) {
        final success = await Get.to<bool>(() => SSLCommerzWebViewScreen(
          gatewayUrl: payResult.gatewayUrl!, sessionId: sessionId, onSuccess: () {},
        ));
        if (success == true) _onExtensionPaymentSuccess(extendResult, extendMins);
      } else {
        Get.snackbar('Error', 'No payment gateway URL received.',
            snackPosition: SnackPosition.BOTTOM, backgroundColor: Colors.red.shade100, colorText: Colors.red.shade900);
      }
    } else {
      final payResult = await allBookingCtrl.paySession(sessionId, paymentMethod: _selectedGatewayId, cardId: cardId);
      if (payResult != null) {
        _onExtensionPaymentSuccess(extendResult, extendMins);
      } else {
        Get.snackbar('Error', 'Extension payment failed.',
            snackPosition: SnackPosition.BOTTOM, backgroundColor: Colors.red.shade100, colorText: Colors.red.shade900);
      }
    }
  }

  void _onExtensionPaymentSuccess(dynamic extendResult, int extendMins) {
    if (extendResult.newTimeRemainingMs != null) {
      widget.onExtensionSuccess?.call(extendResult.newTimeRemainingMs!);
    }
    Get.back();
    Get.snackbar('Success', 'Session extended by $extendMins minutes.',
      snackPosition: SnackPosition.BOTTOM, backgroundColor: Colors.green.shade100,
      colorText: Colors.green.shade900, margin: EdgeInsets.all(16.w));
  }

  // ══════════════════════════════════════════════════════════════════════════
  // HELPERS
  // ══════════════════════════════════════════════════════════════════════════

  Widget _radioIndicator(bool isSelected) {
    return Container(
      width: 20.w, height: 20.w,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: isSelected ? R.color.mintGreen : const Color(0xFFE5E7EB), width: 2),
      ),
      child: isSelected
          ? Center(child: Container(width: 10.w, height: 10.w, decoration: BoxDecoration(color: R.color.mintGreen, shape: BoxShape.circle)))
          : null,
    );
  }

  Widget _infoRow(String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WText(text: label, color: R.color.neutralGray, fontSize: 12.sp, fontWeight: FontWeight.w400),
        const Spacer(),
        WText(text: value, color: R.color.darkNavy, fontSize: 12.sp, fontWeight: FontWeight.w600),
      ],
    );
  }

  Widget _amountRow(String label, String value, {bool isBold = false}) {
    return Row(
      children: [
        WText(text: label, color: R.color.neutralGray, fontSize: 12.sp, fontWeight: FontWeight.w400),
        const Spacer(),
        WText(
          text: value,
          color: isBold ? R.color.darkNavy : R.color.neutralGray,
          fontSize: isBold ? 14.sp : 12.sp,
          fontWeight: isBold ? FontWeight.w700 : FontWeight.w400,
        ),
      ],
    );
  }
}

