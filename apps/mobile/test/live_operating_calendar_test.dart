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
      for (final api in clients.values) {
        await api.signOut();
      }
    },
    skip: fixture == null
        ? 'Requires explicit private loopback Laravel calendar fixture; no hardware or money.'
        : false,
  );
}
