import 'package:evcharg/feature/Booking/UI/controller/booking_controller.dart';
import 'package:evcharg/core/utils/reservation_date.dart';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Booking/history/source_history.dart';
import 'package:flutter_test/flutter_test.dart';

/// Opt-in real disposable API check. The harness supplies synthetic, short-lived
/// JWTs in the child process environment; none are logged or persisted here.
void main() {
  final url = Platform.environment['AGENT2_HISTORY_API_URL'];
  test(
    'actual native booking preserves complete calendar identity and duplicate denial',
    () async {
      final station = Platform.environment['AGENT2_NEW_BOOKING_STATION']!;
      final uri = Uri.parse(url!);
      if (uri.scheme != 'http' || uri.host != '127.0.0.1') {
        throw StateError('Disposable loopback API required');
      }
      final dio = Dio(BaseOptions(baseUrl: url, validateStatus: (_) => true));
      final client = NetworkClient(
        dio: dio,
        onUnAuthorize: () {},
        commonHeaders: () => {
          'Authorization': Platform.environment['AGENT2_HISTORY_TOKEN']!,
        },
      );
      final controller = BookingController(client: client);
      final day = reservationDay(DateTime.utc(2032, 4, 1));
      try {
        final booking = await controller.createBooking(
          stationId: station,
          date: day,
          slotStart: '12:00 PM',
          slotEnd: '01:00 PM',
          energyKwh: 'Not measured',
        );
        expect(booking, isNotNull, reason: controller.bookingError.value);
        expect(booking!.bookingDate, day);
        expect(booking.isPaid, false);
        expect(
          await controller.createBooking(
            stationId: station,
            date: day,
            slotStart: '12:00 PM',
            slotEnd: '01:00 PM',
          ),
          isNull,
        );
        expect(controller.bookingError.value, isNotEmpty);
      } finally {
        dio.close(force: true);
      }
    },
    skip:
        url == null ||
            Platform.environment['AGENT2_NEW_BOOKING_STATION'] == null
        ? 'Requires the isolated Express booking fixture'
        : false,
  );
  test(
    'actual private Express history is consumed by the Flutter API model',
    () async {
      final uri = Uri.parse(url!);
      if (uri.scheme != 'http' ||
          uri.host != '127.0.0.1' ||
          uri.path.isNotEmpty ||
          uri.hasQuery ||
          uri.hasFragment ||
          uri.userInfo.isNotEmpty) {
        throw StateError('Disposable loopback API required');
      }
      final token = Platform.environment['AGENT2_HISTORY_TOKEN']!;
      final foreignToken =
          Platform.environment['AGENT2_HISTORY_FOREIGN_TOKEN']!;
      final expected = Platform.environment['AGENT2_HISTORY_EXPECTED_SESSION']!;
      var authorization = token;
      final dio = Dio(BaseOptions(baseUrl: url, validateStatus: (_) => true));
      final client = NetworkClient(
        dio: dio,
        onUnAuthorize: () {},
        commonHeaders: () => {'Authorization': authorization},
      );
      try {
        final api = SourceHistoryApi(client);
        final seen = <String>{};
        String? cursor;
        SourceHistoryEntry? matched;
        final scopedProtocols = <String>{};
        do {
          final page = await api.load(cursor);
          for (final entry in page.entries) {
            expect(seen.add(entry.sessionId), true);
            if (entry.sessionId == expected) matched = entry;
            if (entry.resource != null) {
              scopedProtocols.add(entry.resource!.protocol);
              expect(entry.resource!.connectorId, 2);
              expect(
                entry.resource!.evseId,
                entry.resource!.protocol == 'ocpp2.0.1' ? 3 : null,
              );
            }
          }
          cursor = page.nextCursor;
          expect(seen.length, lessThan(500));
        } while (cursor != null);
        if (Platform.environment['AGENT2_EXPECT_RESOURCE_HISTORY'] == '1') {
          expect(scopedProtocols, {'ocpp1.6', 'ocpp2.0.1'});
        }
        expect(matched, isNotNull);
        expect(matched!.energyWh, 7000);
        expect(matched.state, 'ended');
        expect(matched.totalFils, 735);
        authorization = foreignToken;
        final foreign = await api.load(null);
        expect(
          foreign.entries.any((entry) => seen.contains(entry.sessionId)),
          false,
        );
        authorization = '';
        await expectLater(api.load(null), throwsFormatException);
      } finally {
        dio.close(force: true);
      }
    },
    skip: url == null
        ? 'Requires the isolated Express/Mongo/OCPP harness'
        : false,
  );
}
