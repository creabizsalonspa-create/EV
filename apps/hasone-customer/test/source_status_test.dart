import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/feature/Booking/model/charging_starting_model.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/charging_screen.dart';

Map<String, dynamic> contract() => {
  'source': 'ocpp',
  'protocol': 'ocpp1.6',
  'sourceVerified': true,
  'meterVerified': true,
  'chargingStatus': 'Completed',
  'energyWh': 5000,
  'kwhUsed': 5,
  'batteryPercent': null,
  'timeRemaining': null,
  'paymentStatus': 'not_collected',
  'canStop': true,
  'canExtend': true,
};

class PendingStatus extends AllBookingController {
  final response = Completer<Data?>();
  @override
  Future<Data?> fetchChargingStatus(String sessionId) => response.future;
}

void main() {
  test('legacy estimates cannot become source telemetry', () {
    final value = Data.fromJson({
      'chargingStatus': 'Charging',
      'batteryPercent': 99,
      'kwhUsed': 100,
      'timeRemaining': '00:10:00',
      'canStop': true,
      'canExtend': true,
    });
    expect(value.hasSourceProof, false);
    expect(value.kwhUsed, null);
    expect(value.batteryPercent, null);
    expect(value.timeRemaining, null);
    expect(value.canStop, false);
  });
  test(
    'verified zero and completed meters are available, unpaid and read only',
    () {
      for (final protocol in ['ocpp1.6', 'ocpp2.0.1']) {
        final value = Data.fromJson({...contract(), 'protocol': protocol});
        expect(value.hasVerifiedMeter, true);
        expect(value.kwhUsed, 5);
        expect(value.paymentStatus, 'not_collected');
        expect(value.batteryPercent, null);
        expect(value.canStop, false);
        expect(value.canExtend, false);
      }
      final zero = Data.fromJson({...contract(), 'energyWh': 0, 'kwhUsed': 0});
      expect(zero.hasVerifiedMeter, true);
    },
  );
  test('mismatched, negative and unverified meters remain unavailable', () {
    for (final fields in [
      {'energyWh': 1},
      {'kwhUsed': -1},
      {'kwhUsed': double.infinity},
      {'meterVerified': false},
      {'sourceVerified': 'true'},
      {'protocol': 'unknown'},
      {'source': 'estimate'},
    ]) {
      expect(Data.fromJson({...contract(), ...fields}).hasVerifiedMeter, false);
    }
  });
  testWidgets(
    'initial estimates and late background responses cannot show charging',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      Get.testMode = true;
      final controller = PendingStatus();
      Get.put<AllBookingController>(controller);
      await tester.pumpWidget(
        ScreenUtilInit(
          designSize: const Size(390, 844),
          builder: (_, child) => const GetMaterialApp(
            home: ChargingScreen(
              bookingId: 'b',
              sessionId: 's',
              initialBatteryPercent: 99,
              initialTimeRemainingMs: 60000,
            ),
          ),
        ),
      );
      await tester.pump();
      expect(find.text('Source status unverified'), findsOneWidget);
      expect(find.text('99%'), findsNothing);
      final stop = tester.widget<WButton>(
        find.byWidgetPredicate(
          (w) => w is WButton && w.label == 'Stop Charging',
        ),
      );
      expect(stop.onPressed, null);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      controller.response.complete(Data.fromJson(contract()));
      await tester.pump();
      expect(find.text('Completed'), findsNothing);
      expect(find.textContaining('Energy Used:'), findsNothing);
      await tester.pumpWidget(const SizedBox());
      Get.reset();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    },
  );
}
