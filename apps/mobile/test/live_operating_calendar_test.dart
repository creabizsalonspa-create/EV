import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/strings.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final fixture = Platform.environment['EV_NATIVE_OPERATING_CALENDAR_FIXTURE'];
  test(
    'native calendar API parity preserves tenant policy, history and cancellation',
    () async {
      final f =
          jsonDecode(await File(fixture!).readAsString())
              as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base'] as String);
      expect(['127.0.0.1', 'localhost'], contains(base.host));
      final clients = <String, EvApi>{};
      for (final role in ['vendor', 'customer', 'other']) {
        final api = EvApi(base);
        addTearDown(api.close);
        await api.signIn(
          f['accounts'][role]['email'] as String,
          f['accounts'][role]['password'] as String,
        );
        clients[role] = api;
      }
      final vendor = clients['vendor']!,
          customer = clients['customer']!,
          other = clients['other']!;
      final path = 'stations/${f['station_id']}/information';
      final published = Map<String, dynamic>.from(
        await vendor.request(path) as Map,
      );
      expect(published['enforce_operating_hours'], true);
      expect(published['closed_dates'], [f['holiday']]);
      final body = <String, dynamic>{
        'station_id': f['station_id'],
        'starts_at': f['start'],
        'idempotency_key': 'native-closed-${f['run']}',
      };
      await expectLater(
        customer.request('reservations', method: 'POST', body: body),
        throwsA(
          isA<ApiException>().having(
            (e) => errorKey(e),
            'calendar reason',
            'calendarClosed',
          ),
        ),
      );
      final open = await vendor.request(
        path,
        method: 'PUT',
        body: {...published, 'closed_dates': []},
      );
      expect(open['enforce_operating_hours'], true);
      final acceptedBody = {
        ...body,
        'idempotency_key': 'native-open-${f['run']}',
      };
      final receipt = await customer.request(
        'reservations',
        method: 'POST',
        body: acceptedBody,
      );
      expect(
        receipt['operating_calendar_snapshot']['revision'],
        open['revision'],
      );
      expect(
        receipt['operating_calendar_snapshot']['physical_availability_verified'],
        false,
      );
      await vendor.request(
        path,
        method: 'PUT',
        body: {
          ...Map<String, dynamic>.from(open as Map),
          'closed_dates': [f['holiday']],
        },
      );
      await expectLater(
        other.request(
          path,
          method: 'PUT',
          body: {...published, 'enforce_operating_hours': false},
        ),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'foreign editor', 404),
        ),
      );
      await expectLater(
        other.request('reservations/${receipt['id']}', method: 'DELETE'),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'foreign booking', 404),
        ),
      );
      final repeated = await customer.request(
        'reservations',
        method: 'POST',
        body: acceptedBody,
      );
      expect(repeated['id'], receipt['id']);
      expect(
        repeated['operating_calendar_snapshot'],
        receipt['operating_calendar_snapshot'],
      );
      final cancelled = await customer.request(
        'reservations/${receipt['id']}',
        method: 'DELETE',
      );
      expect(cancelled['status'], 'cancelled');
      // Verify the remaining 3.4.6 native HTTP acceptance against the same
      // real, private loopback station. Notices inform; blocks deny new bookings.
      final current = Map<String, dynamic>.from(
        await vendor.request(path) as Map,
      );
      const english = 'Native fixture: use rear access';
      const arabic = 'استخدم المدخل الخلفي';
      final start = DateTime.parse(f['start'] as String).toUtc();
      final end = start.add(const Duration(hours: 1));
      final expiry = end.add(const Duration(days: 1))
          .toIso8601String().substring(0, 10);
      final notice = await vendor.request(path, method: 'PUT', body: {
        ...current,
        'closed_dates': <String>[],
        'notice_en': english,
        'notice_ar': arabic,
        'notice_until': expiry,
      });
      expect(notice['notice_active'], true);
      final publicApi = EvApi(base);
      addTearDown(publicApi.close);
      final publicNotice = await publicApi.request(path);
      expect(publicNotice['notice_active'], true);
      expect(publicNotice['notice_en'], english);
      expect(publicNotice['notice_ar'], arabic);
      final blockPath = 'stations/${f['station_id']}/availability-blocks';
      final blockBody = <String, dynamic>{
        'idempotency_key': operationKey(),
        'starts_at': f['start'],
        'ends_at': end.toIso8601String().replaceFirst('.000Z', 'Z'),
        'reason_en': 'Native fixture closure',
        'reason_ar': 'إغلاق مؤقت',
      };
      await expectLater(
        other.request(blockPath, method: 'POST', body: blockBody),
        throwsA(isA<ApiException>().having((e) => e.status, 'foreign publish', 404)),
      );
      final block = await vendor.request(blockPath, method: 'POST', body: blockBody);
      expect(block['station_id'], f['station_id']);
      expect(block['revision'], 1);
      expect(block['scope'], 'STATION_WIDE_OPERATOR_POLICY');
      expect(block['physical_availability_verified'], false);
      final retry = await vendor.request(blockPath, method: 'POST', body: blockBody);
      expect(retry, block);
      final publicBlocks = await publicApi.request(blockPath);
      expect(publicBlocks['data'], [block]);
      expect((publicBlocks['data'] as List).single, isNot(contains('created_by')));
      expect((publicBlocks['data'] as List).single, isNot(contains('idempotency_key')));
      final newBooking = {
        ...body,
        'idempotency_key': 'native-blocked-${f['run']}',
      };
      await expectLater(
        customer.request('reservations', method: 'POST', body: newBooking),
        throwsA(isA<ApiException>()
            .having((e) => e.status, 'blocked booking', 409)
            .having((e) => e.message, 'operator policy', contains('operator availability block'))),
      );
      final cancelPath = '$blockPath/${block['id']}/cancel';
      await expectLater(
        other.request(cancelPath, method: 'POST', body: {'revision': 1}),
        throwsA(isA<ApiException>().having((e) => e.status, 'foreign cancel', 404)),
      );
      final cancelledBlock = await vendor.request(cancelPath, method: 'POST', body: {'revision': 1});
      expect(cancelledBlock['cancelled'], true);
      expect(cancelledBlock['revision'], 2);
      expect((await publicApi.request(blockPath))['data'], isEmpty);
      // The notice is still active. Cancelling the block permits a new booking.
      expect((await publicApi.request(path))['notice_active'], true);
      final afterBlock = await customer.request('reservations', method: 'POST', body: {
        ...newBooking,
        'idempotency_key': 'native-unblocked-${f['run']}',
      });
      expect(afterBlock['operating_calendar_snapshot']['physical_availability_verified'], false);
      await customer.request('reservations/${afterBlock['id']}', method: 'DELETE');
      final cleared = await vendor.request(path, method: 'PUT', body: {
        ...Map<String, dynamic>.from(notice as Map),
        'notice_en': null,
        'notice_ar': null,
        'notice_until': null,
      });
      expect(cleared['notice_active'], false);
      expect((await publicApi.request(path))['notice_active'], false);
      for (final api in clients.values) {
        await api.signOut();
      }
    },
    skip: fixture == null
        ? 'Requires explicit private loopback Laravel calendar fixture; no hardware or money.'
        : false,
  );
}
