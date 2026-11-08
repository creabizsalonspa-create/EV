import 'dart:async';
import 'package:dio/dio.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Booking/model/reservation_chargers.dart';
import 'package:evcharg/feature/Booking/UI/controller/booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/reservation_charger_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

const station = '507f1f77bcf86cd799439011';
Map<String, dynamic> catalog() => {
  'stationId': station,
  'readOnly': true,
  'reservationScope': 'station',
  'independentCapacity': false,
  'hardwareAvailability': 'unverified',
  'choices': [
    {'chargerId': 'a', 'protocol': 'ocpp1.6'},
    {'chargerId': 'b', 'protocol': 'ocpp2.0.1'},
  ],
};

class ChoiceNetwork extends NetworkClient {
  Map<String, dynamic>? sent;
  final String receipt;
  int writes = 0;
  ChoiceNetwork(this.receipt)
    : super(
        baseUrl: 'https://example.test',
        commonHeaders: () => {},
        onUnAuthorize: () {},
      );
  @override
  Future<NetworkResponse> postRequest(
    String url, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    String? bearerToken,
    Map<String, String>? headers,
  }) async {
    sent = body;
    writes++;
    return NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {
        'status': 'Success',
        'data': {
          'bookingId': '507f1f77bcf86cd799439022',
          'reservedChargerId': receipt,
          'isPaid': false,
        },
      },
    );
  }
}

Future<dynamic> book(BookingController c, String id) => c.createBooking(
  stationId: station,
  date: '2033-03-03',
  slotStart: '10:00 AM',
  slotEnd: '11:00 AM',
  reservedChargerId: id,
);

void main() {
  test(
    'catalog requires explicit station-wide and unknown live availability contract',
    () {
      expect(
        ReservationChargers.fromJson(
          catalog(),
          station,
        ).choices.map((c) => c.id),
        ['a', 'b'],
      );
      for (final patch in [
        {'stationId': 'other'},
        {'readOnly': false},
        {'independentCapacity': true},
        {'hardwareAvailability': 'available'},
        {'reservationScope': 'connector'},
        {
          'choices': [
            {'chargerId': 'a', 'protocol': 'ocpp1.6'},
            {'chargerId': 'a', 'protocol': 'ocpp1.6'},
          ],
        },
        {
          'choices': [
            {'chargerId': 'a', 'protocol': 'unknown'},
          ],
        },
      ]) {
        expect(
          () => ReservationChargers.fromJson({...catalog(), ...patch}, station),
          throwsFormatException,
        );
      }
    },
  );
  test(
    'selected charger is transmitted and accepted only when the receipt matches',
    () async {
      final good = ChoiceNetwork('b');
      final c = BookingController(client: good);
      expect((await book(c, 'b'))!.reservedChargerId, 'b');
      expect(good.sent!['reservedChargerId'], 'b');
      final bad = ChoiceNetwork('a');
      final other = BookingController(client: bad);
      expect(await book(other, 'b'), isNull);
      expect(other.bookingData.value, isNull);
      expect(other.bookingError.value, contains('unconfirmed'));
      expect(bad.writes, 1);
    },
  );
  test('invalid charger identity never reaches booking transport', () async {
    final net = ChoiceNetwork('b');
    final c = BookingController(client: net);
    expect(await book(c, 'bad/id'), isNull);
    expect(net.writes, 0);
  });
  testWidgets(
    'multi-charger picker requires explicit selection and never labels live availability',
    (tester) async {
      final changes = <String?>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ReservationChargerPicker(
              stationId: station,
              onChanged: changes.add,
              load: () async =>
                  ReservationChargers.fromJson(catalog(), station),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(changes.last, isNull);
      expect(
        find.text('Live connector availability is unverified.'),
        findsOneWidget,
      );
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('b').last);
      await tester.pumpAndSettle();
      expect(changes.last, 'b');
      await tester.pumpWidget(const SizedBox());
    },
  );
  testWidgets(
    'background discards catalog, resume accepts only the new request',
    (tester) async {
      final pending = <Completer<ReservationChargers>>[];
      final changes = <String?>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ReservationChargerPicker(
              stationId: station,
              onChanged: changes.add,
              load: () {
                final p = Completer<ReservationChargers>();
                pending.add(p);
                return p.future;
              },
            ),
          ),
        ),
      );
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(pending.length, 2);
      pending[0].complete(
        const ReservationChargers(station, [
          ReservationCharger('a', 'ocpp1.6'),
        ]),
      );
      await tester.pump();
      await tester.pump();
      expect(changes.last, isNull);
      pending[1].complete(
        const ReservationChargers(station, [
          ReservationCharger('b', 'ocpp2.0.1'),
        ]),
      );
      await tester.pump();
      await tester.pump();
      expect(changes.last, 'b');
      await tester.pumpWidget(const SizedBox());
    },
  );
  testWidgets('new sign-in cannot reuse a pending approved-charger catalog', (
    tester,
  ) async {
    var owner = 'first';
    final pending = Completer<ReservationChargers>();
    final changes = <String?>[];
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ReservationChargerPicker(
            stationId: station,
            onChanged: changes.add,
            identity: () => owner,
            load: () => pending.future,
          ),
        ),
      ),
    );
    await tester.pump();
    owner = 'second';
    pending.complete(
      const ReservationChargers(station, [ReservationCharger('a', 'ocpp1.6')]),
    );
    await tester.pump();
    await tester.pump();
    expect(changes.last, isNull);
    expect(
      find.textContaining('Approved chargers unavailable'),
      findsOneWidget,
    );
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets(
    'failed read retries only charger catalog and preserves no previous selection',
    (tester) async {
      var calls = 0;
      final changes = <String?>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ReservationChargerPicker(
              stationId: station,
              onChanged: changes.add,
              load: () async {
                calls++;
                if (calls == 1) throw StateError('offline');
                return const ReservationChargers(station, [
                  ReservationCharger('b', 'ocpp2.0.1'),
                ]);
              },
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(changes.last, isNull);
      await tester.tap(find.text('Retry charger list'));
      await tester.pump();
      await tester.pump();
      expect(calls, 2);
      expect(changes.last, 'b');
      await tester.pumpWidget(const SizedBox());
    },
  );
}
