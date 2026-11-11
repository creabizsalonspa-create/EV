import 'dart:async';
import 'package:dio/dio.dart';
import 'package:evcharg/core/utils/reservation_date.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Booking/UI/controller/booking_controller.dart';
import 'package:flutter_test/flutter_test.dart';

class BookingNetwork extends NetworkClient {
  final Future<NetworkResponse> Function() answer;
  int calls = 0;
  Map<String, dynamic>? sent;
  BookingNetwork(this.answer)
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
  }) {
    calls++;
    sent = body;
    return answer();
  }
}

Future<dynamic> create(BookingController controller, String day) =>
    controller.createBooking(
      stationId: 'station',
      date: day,
      slotStart: '12:00 PM',
      slotEnd: '01:00 PM',
    );

void main() {
  test(
    'selected calendar day retains year independently of display labels',
    () {
      expect(reservationDay(DateTime(2026, 11, 8, 0, 1)), '2026-11-08');
      expect(reservationDay(DateTime.utc(2027, 11, 8, 23, 59)), '2027-11-08');
      expect(isReservationDay('2028-02-29'), true);
      for (final day in [
        '08 Nov, Sun',
        '2026-02-30',
        '2026-02-29',
        '2026-1-01',
      ]) {
        expect(isReservationDay(day), false);
      }
    },
  );
  test('invalid date never reaches the booking mutation transport', () async {
    final net = BookingNetwork(() async => throw StateError('must not send'));
    final controller = BookingController(client: net);
    expect(await create(controller, '08 Nov, Sun'), isNull);
    expect(net.calls, 0);
    expect(controller.bookingError.value, contains('year'));
    expect(controller.loading.value, false);
  });
  test(
    'calendar denial is retained without inventing a booking receipt',
    () async {
      const message =
          'Station calendar has overlapping slots. Operator review is required.';
      final net = BookingNetwork(
        () async => NetworkResponse(
          isSuccess: false,
          statusCode: 400,
          responseData: {'status': 'fail', 'message': message},
        ),
      );
      final controller = BookingController(client: net);
      expect(await create(controller, '2026-11-08'), isNull);
      expect(net.sent!['date'], '2026-11-08');
      expect(controller.bookingError.value, message);
      expect(controller.bookingData.value, isNull);
      expect(net.calls, 1);
      expect(controller.loading.value, false);
    },
  );
  test(
    'pending and uncertain creation cannot trigger a second automatic write',
    () async {
      final pending = Completer<NetworkResponse>();
      final net = BookingNetwork(() => pending.future);
      final controller = BookingController(client: net);
      final first = create(controller, '2026-11-08');
      expect(await create(controller, '2026-11-09'), isNull);
      expect(net.calls, 1);
      pending.completeError(StateError('lost response'));
      expect(await first, isNull);
      expect(controller.bookingError.value, contains('unconfirmed'));
      expect(controller.loading.value, false);
      expect(net.calls, 1);
    },
  );
}
