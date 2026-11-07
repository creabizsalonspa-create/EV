import 'dart:async';

import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/charging_screen.dart';
import 'package:evcharg/feature/Booking/model/charging_starting_model.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';

Data verified({String session = 's', String booking = 'b'}) => Data.fromJson({
  'sessionId': session,
  'bookingId': booking,
  'source': 'ocpp',
  'protocol': 'ocpp1.6',
  'sourceVerified': true,
  'meterVerified': true,
  'energyWh': 5000,
  'kwhUsed': 5,
  'chargingStatus': 'Charging',
  'paymentStatus': 'not_collected',
});

class RecoveringStatus extends AllBookingController {
  final pending = <Completer<Data?>>[];
  @override
  Future<void> fetchBookings() async {}
  @override
  Future<Data?> fetchChargingStatus(String sessionId) {
    final next = Completer<Data?>();
    pending.add(next);
    return next.future;
  }
}

Future<void> show(
  WidgetTester tester,
  RecoveringStatus controller, {
  String? Function()? identity,
}) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  Get.reset();
  Get.testMode = true;
  addTearDown(() => close(tester));
  Get.put<AllBookingController>(controller);
  await tester.pumpWidget(
    ScreenUtilInit(
      designSize: const Size(390, 844),
      builder: (_, child) => GetMaterialApp(
        home: ChargingScreen(
          bookingId: 'b',
          sessionId: 's',
          statusReadTimeout: const Duration(seconds: 2),
          accountIdentity: identity,
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump();
}

Future<void> close(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  Get.reset();
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
}

void main() {
  testWidgets(
    'timeout clears telemetry, manual read recovers and late first reply is ignored',
    (tester) async {
      final c = RecoveringStatus();
      await show(tester, c);
      expect(c.pending.length, 1);
      await tester.pump(const Duration(seconds: 3));
      expect(
        find.textContaining('Charging may still be active'),
        findsOneWidget,
      );
      await tester.ensureVisible(find.text('Refresh status'));
      await tester.tap(find.text('Refresh status'));
      await tester.pump();
      await tester.pump();
      expect(c.pending.length, 2);
      c.pending[1].complete(verified());
      await tester.pump();
      await tester.pump();
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsOneWidget);
      c.pending[0].complete(verified(session: 'other'));
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsOneWidget);
      expect(c.pending.length, 2);
      expect(tester.takeException(), isNull);
      await close(tester);
    },
  );

  testWidgets(
    'resuming starts a new read without waiting for old in-flight request',
    (tester) async {
      final c = RecoveringStatus();
      await show(tester, c);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump();
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      await tester.pump();
      expect(c.pending.length, 2);
      c.pending[0].complete(verified());
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsNothing);
      c.pending[1].complete(verified());
      await tester.pump();
      await tester.pump();
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsOneWidget);
      await close(tester);
    },
  );

  testWidgets(
    'account change discards pending source proof and cannot reread old session',
    (tester) async {
      var owner = 'first';
      final c = RecoveringStatus();
      await show(tester, c, identity: () => owner);
      owner = 'second';
      c.pending[0].complete(verified());
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsNothing);
      expect(find.textContaining('Sign-in changed'), findsOneWidget);
      await tester.pump(const Duration(seconds: 11));
      expect(c.pending.length, 1);
      expect(find.textContaining('Energy Used:'), findsNothing);
      await close(tester);
    },
  );

  testWidgets(
    'source proof for a different session or booking cannot populate status',
    (tester) async {
      final c = RecoveringStatus();
      await show(tester, c);
      c.pending[0].complete(verified(session: 'other'));
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsNothing);
      expect(find.textContaining('Ask the operator'), findsOneWidget);
      await tester.ensureVisible(find.text('Refresh status'));
      await tester.tap(find.text('Refresh status'));
      await tester.pump();
      await tester.pump();
      c.pending[1].complete(verified(booking: 'other'));
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsNothing);
      expect(find.textContaining('Energy Used:'), findsNothing);
      await close(tester);
    },
  );

  testWidgets('failed refresh clears prior progress and can only read again', (
    tester,
  ) async {
    final c = RecoveringStatus();
    await show(tester, c);
    c.pending[0].complete(verified());
    await tester.pump();
    await tester.pump();
    expect(find.textContaining('Energy Used:'), findsOneWidget);
    await tester.ensureVisible(find.text('Refresh status'));
    await tester.tap(find.text('Refresh status'));
    await tester.pump();
    await tester.pump();
    c.pending[1].completeError(StateError('offline'));
    await tester.pump();
    await tester.pump();
    expect(find.textContaining('Energy Used:'), findsNothing);
    expect(find.textContaining('Retry only checks status'), findsOneWidget);
    expect(c.pending.length, 2);
    await close(tester);
  });
  testWidgets(
    'reusing the screen for a new booking rejects the previous in-flight source',
    (tester) async {
      final c = RecoveringStatus();
      var session = 's', booking = 'b';
      late StateSetter change;
      Get.reset();
      Get.testMode = true;
      Get.put<AllBookingController>(c);
      addTearDown(() => close(tester));
      await tester.pumpWidget(
        ScreenUtilInit(
          designSize: const Size(390, 844),
          builder: (_, child) => GetMaterialApp(
            home: StatefulBuilder(
              builder: (_, setState) {
                change = setState;
                return ChargingScreen(bookingId: booking, sessionId: session);
              },
            ),
          ),
        ),
      );
      await tester.pump();
      expect(c.pending.length, 1);
      change(() {
        session = 'new-session';
        booking = 'new-booking';
      });
      await tester.pump();
      expect(c.pending.length, 2);
      c.pending[0].complete(verified());
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsNothing);
      c.pending[1].complete(verified(session: session, booking: booking));
      await tester.pump();
      await tester.pump();
      expect(find.text('Charging'), findsOneWidget);
    },
  );
  testWidgets(
    'power loss is shown as ended needing review and never as completed success',
    (tester) async {
      final c = RecoveringStatus();
      await show(tester, c);
      c.pending[0].complete(
        Data.fromJson({
          'sessionId': 's',
          'bookingId': 'b',
          'source': 'ocpp',
          'protocol': 'ocpp2.0.1',
          'sourceVerified': true,
          'meterVerified': true,
          'energyWh': 5000,
          'kwhUsed': 5,
          'chargingStatus': 'Completed',
          'readOnly': true,
          'sourceEnd': {'reason': 'PowerLoss', 'reviewRequired': true},
        }),
      );
      await tester.pump();
      await tester.pump();
      expect(find.text('Ended at source'), findsOneWidget);
      expect(find.text('Completed'), findsNothing);
      expect(find.text('Source reason: power loss'), findsOneWidget);
      expect(find.textContaining('before starting again'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
