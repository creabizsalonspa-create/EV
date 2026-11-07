import 'package:evcharg/feature/Booking/model/charging_starting_model.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/charging_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';

Map<String, dynamic> progressContract() => {
  'sessionId': 's',
  'bookingId': 'b',
  'source': 'ocpp',
  'protocol': 'ocpp1.6',
  'sourceVerified': true,
  'meterVerified': true,
  'energyWh': 5000,
  'kwhUsed': 5,
  'chargingStatus': 'Charging',
  'readOnly': true,
  'currency': 'AED',
  'billingMode': 'calculation_only_no_collection',
  'paymentStatus': 'not_collected',
  'billingStatus': 'provisional_unpaid',
  'provisionalCostVerified': true,
  'provisionalSubtotalFils': 500,
  'provisionalVatFils': 25,
  'provisionalTotalFils': 525,
  'meterReceivedAt': DateTime.fromMillisecondsSinceEpoch(
    DateTime.now().millisecondsSinceEpoch,
    isUtc: true,
  ).toIso8601String(),
  'meterReceiptAgeSeconds': 0,
  'meterFreshness': 'recent',
};

class ProgressStatus extends AllBookingController {
  @override
  Future<void> fetchBookings() async {}
  @override
  Future<Data?> fetchChargingStatus(String sessionId) async =>
      Data.fromJson(progressContract());
}

void main() {
  test(
    'active and final calculated amounts are distinct and preserve explicit zero',
    () {
      final active = Data.fromJson(progressContract());
      expect(active.provisionalCostFils, 525);
      expect(active.finalCostFils, isNull);
      final zero = Data.fromJson({
        ...progressContract(),
        'provisionalSubtotalFils': 0,
        'provisionalVatFils': 0,
        'provisionalTotalFils': 0,
      });
      expect(zero.provisionalCostFils, 0);
      final ended = Data.fromJson({
        ...progressContract(),
        'chargingStatus': 'Completed',
        'billingStatus': 'calculated_unpaid',
        'subtotalFils': 500,
        'vatFils': 25,
        'totalFils': 525,
      });
      expect(ended.provisionalCostFils, isNull);
      expect(ended.finalCostFils, 525);
    },
  );
  test(
    'tampered cost, currency, collection or proof flags never become an amount',
    () {
      for (final change in [
        {'currency': 'USD'},
        {'paymentStatus': 'paid'},
        {'readOnly': false},
        {'sourceVerified': 'true'},
        {'provisionalTotalFils': 526},
        {'provisionalTotalFils': -1},
        {'provisionalSubtotalFils': '500'},
        {'billingStatus': 'calculated_unpaid'},
        {'provisionalCostVerified': false},
      ]) {
        expect(
          Data.fromJson({...progressContract(), ...change}).provisionalCostFils,
          isNull,
        );
      }
      final legacy = Data.fromJson({
        'totalAmount': 999,
        'chargingStatus': 'Charging',
      });
      expect(legacy.provisionalCostFils, isNull);
      expect(legacy.finalCostFils, isNull);
    },
  );
  test(
    'receipt timing supports stale readings and rejects missing/future/coerced values',
    () {
      final stale = Data.fromJson({
        ...progressContract(),
        'meterReceivedAt': DateTime.now()
            .toUtc()
            .subtract(const Duration(seconds: 121))
            .toIso8601String(),
        'meterReceiptAgeSeconds': 121,
        'meterFreshness': 'stale',
      });
      expect(stale.meterFreshness, 'stale');
      for (final change in [
        {'meterReceivedAt': null},
        {'meterReceiptAgeSeconds': '0'},
        {'meterFreshness': 'stale'},
        {'meterReceivedAt': '2026-02-31T00:00:00Z'},
        {
          'meterReceivedAt': DateTime.now()
              .toUtc()
              .add(const Duration(days: 1))
              .toIso8601String(),
        },
      ]) {
        expect(
          Data.fromJson({...progressContract(), ...change}).meterReceivedAt,
          isNull,
        );
      }
    },
  );
  test(
    'source end is independent from successful completion and financial collection',
    () {
      for (final reason in [
        'PowerLoss',
        'EmergencyStop',
        'GroundFault',
        'OvercurrentFault',
        'PowerQuality',
        'Timeout',
        'Reboot',
        'Other',
      ]) {
        final value = Data.fromJson({
          ...progressContract(),
          'chargingStatus': 'Completed',
          'sourceEnd': {'reason': reason, 'reviewRequired': true},
          'billingStatus': 'calculated_unpaid',
          'subtotalFils': 500,
          'vatFils': 25,
          'totalFils': 525,
        });
        expect(value.sourceEndNeedsReview, true);
        expect(value.sourceEndReason, reason);
        expect(value.finalCostFils, 525);
        expect(value.paymentStatus, 'not_collected');
      }
      final normal = Data.fromJson({
        ...progressContract(),
        'chargingStatus': 'Completed',
        'sourceEnd': {'reason': 'EVDisconnected', 'reviewRequired': false},
      });
      expect(normal.sourceEndReason, 'EVDisconnected');
      expect(normal.sourceEndNeedsReview, false);
      for (final end in [
        null,
        {'reason': 'PowerLoss', 'reviewRequired': false},
        {'reason': '<script>', 'reviewRequired': true},
        {'reason': 'Remote', 'reviewRequired': 'false'},
      ]) {
        final value = Data.fromJson({
          ...progressContract(),
          'chargingStatus': 'Completed',
          'sourceEnd': end,
        });
        expect(value.sourceEndReason, isNull);
        expect(value.sourceEndNeedsReview, true);
      }
      final active = Data.fromJson({
        ...progressContract(),
        'sourceEnd': {'reason': 'PowerLoss', 'reviewRequired': true},
      });
      expect(active.sourceEndReason, isNull);
      expect(active.sourceEndNeedsReview, false);
    },
  );
  testWidgets(
    'calculated progress stays unpaid, fits narrow screen and clears in background',
    (tester) async {
      tester.view.physicalSize = const Size(320, 740);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      Get.testMode = true;
      Get.put<AllBookingController>(ProgressStatus());
      await tester.pumpWidget(
        ScreenUtilInit(
          designSize: const Size(390, 844),
          builder: (_, child) => const GetMaterialApp(
            home: ChargingScreen(bookingId: 'b', sessionId: 's'),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(
        find.text('Calculated so far: AED 5.25 (not collected)'),
        findsOneWidget,
      );
      expect(find.textContaining('meter received 0s ago'), findsOneWidget);
      expect(tester.takeException(), isNull);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.textContaining('Calculated so far:'), findsNothing);
      await tester.pumpWidget(const SizedBox());
      Get.reset();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    },
  );
}
