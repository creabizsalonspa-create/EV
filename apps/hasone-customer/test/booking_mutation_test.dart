import 'dart:async';
import 'package:dio/dio.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:flutter_test/flutter_test.dart';

class MutationNetwork extends NetworkClient {
  final Future<NetworkResponse> Function() answer;
  final calls = <String>[];
  dynamic sentBody;
  MutationNetwork(this.answer)
    : super(
        baseUrl: 'https://example.test',
        onUnAuthorize: () {},
        commonHeaders: () => {},
      );
  @override
  Future<NetworkResponse> putRequest(
    String url, {
    dynamic body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) {
    calls.add(url);
    sentBody = body;
    return answer();
  }
}

class BookingReader extends AllBookingController {
  int reads = 0;
  bool failRead = false;
  BookingReader(NetworkClient client) : super(client: client);
  @override
  Future<void> fetchBookings() async {
    reads++;
    if (failRead) throw StateError('read unavailable');
  }
}

void main() {
  test(
    'paid/source denial is explained and authority is reloaded once',
    () async {
      const reason =
          'Paid, arrived or authorized bookings require operator review.';
      final net = MutationNetwork(
        () async => NetworkResponse(
          isSuccess: false,
          statusCode: 400,
          responseData: {'status': 'fail', 'message': reason},
        ),
      );
      final controller = BookingReader(net);
      expect(await controller.cancelBooking('booking'), false);
      expect(controller.bookingMutationMessage.value, reason);
      expect(controller.reads, 1);
      expect(net.calls, ['/api/v1/Bookings/booking/cancel']);
      expect(controller.bookings, isEmpty);
    },
  );
  test(
    'HTTP success with a failed application result cannot confirm a mutation',
    () async {
      final net = MutationNetwork(
        () async => NetworkResponse(
          isSuccess: true,
          statusCode: 200,
          responseData: {'status': 'fail'},
        ),
      );
      final controller = BookingReader(net);
      expect(await controller.cancelBooking('booking'), false);
      expect(controller.reads, 1);
      expect(net.calls.length, 1);
    },
  );
  test(
    'uncertain write and failed authority read never trigger automatic replay',
    () async {
      final net = MutationNetwork(
        () async => throw StateError('lost acknowledgement'),
      );
      final controller = BookingReader(net)..failRead = true;
      expect(await controller.cancelBooking('booking'), false);
      expect(controller.bookingMutationMessage.value, contains('unconfirmed'));
      expect(controller.reads, 1);
      expect(net.calls.length, 1);
    },
  );
  test(
    'reschedule preserves exact selected fields and resets progress',
    () async {
      final pending = Completer<NetworkResponse>();
      final net = MutationNetwork(() => pending.future);
      final controller = BookingReader(net);
      final result = controller.rescheduleBooking(
        bookingId: 'booking',
        date: '2026-11-09',
        time: '12:00 PM',
        slotStart: '12:00 PM',
        slotEnd: '01:00 PM',
      );
      expect(controller.rescheduleLoading.value, true);
      expect(net.sentBody, {
        'date': '2026-11-09',
        'time': '12:00 PM',
        'slotStart': '12:00 PM',
        'slotEnd': '01:00 PM',
      });
      pending.complete(
        NetworkResponse(
          isSuccess: true,
          statusCode: 200,
          responseData: {'status': 'Success'},
        ),
      );
      expect(await result, true);
      expect(controller.rescheduleLoading.value, false);
      expect(controller.bookingMutationMessage.value, isEmpty);
      expect(controller.reads, 1);
    },
  );
}
