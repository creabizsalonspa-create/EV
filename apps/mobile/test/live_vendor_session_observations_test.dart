// Opt-in private local HTTP integration for read-only observations and queued simulator stops.
import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_session_observations.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_STAFF_OBSERVATIONS_FIXTURE'];
  test(
    'actual Laravel native consent and private recorded-session pages',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], isTrue);
      final base = Uri.parse(f['base']);
      expect(['127.0.0.1', 'localhost'], contains(base.host));
      expect(base.scheme, 'http');
      final api = EvApi(base);
      addTearDown(api.close);
      final account = await api.signIn(f['staff_email'], f['password']);
      expect(account.role, 'customer');
      final company = f['company_id'] as int,
          endpoint = 'vendor-ops/companies/$company/charging-sessions';
      await expectLater(
        api.request(endpoint),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'before consent', 404),
        ),
      );
      await expectLater(
        api.request(
          'vendor-ops/staff-invitations/${f['membership_id']}/accept',
          method: 'POST',
          body: {'expected_version': 1, 'consent': true},
        ),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'stale consent', 409),
        ),
      );
      await api.request(
        'vendor-ops/staff-invitations/${f['membership_id']}/accept',
        method: 'POST',
        body: {'expected_version': 2, 'consent': true},
      );
      final raw = await api.request(endpoint, query: {'page': '1'});
      expect(jsonEncode(raw), isNot(contains('PRIVATE')));
      expect(jsonEncode(raw), isNot(contains('total_minor')));
      final first = VendorSessionObservationPage.parse(
        raw,
        company: company,
        page: 1,
      );
      expect(first.total, 27);
      expect(first.items.length, 25);
      expect(first.items.first.evidence, 'SIMULATED_CSMS');
      expect(first.items.first.energyWh, 2500);
      final second = VendorSessionObservationPage.parse(
        await api.request(endpoint, query: {'page': '2'}),
        company: company,
        page: 2,
      );
      expect(second.items.length, 2);
      expect(second.items.first.id, lessThan(first.items.last.id));
      final empty = VendorSessionObservationPage.parse(
        await api.request(endpoint, query: {'page': '1', 'status': 'charging'}),
        company: company,
        page: 1,
        status: 'charging',
      );
      expect(empty.items, isEmpty);
      final meterSession = f['meter_session_id'] as int;
      final historyEndpoint = '$endpoint/$meterSession/meter-history';
      final meterRaw = await api.request(historyEndpoint, query: {'page': '1'});
      expect(jsonEncode(meterRaw), isNot(contains('PRIVATE')));
      final meters = VendorMeterObservationPage.parse(
        meterRaw,
        company: company,
        session: meterSession,
        page: 1,
      );
      expect(meters.sourceLinked, isTrue);
      expect(meters.protocol, '2.0.1');
      expect(meters.total, 27);
      expect(meters.items.length, 25);
      expect(meters.items.first.counterWh, 1002500);
      final meterTail = VendorMeterObservationPage.parse(
        await api.request(
          historyEndpoint,
          query: {'page': '2', 'through': '${meters.through}'},
        ),
        company: company,
        session: meterSession,
        page: 2,
        through: meters.through,
      );
      expect(meterTail.items.length, 2);
      expect(meterTail.items.last.counterWh, 1000000);
      await expectLater(
        api.request('vendor-ops/companies/$company'),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'no KYC permission', 404),
        ),
      );
      final companies = await api.request('vendor-ops/companies');
      final row = (companies['data'] as List).singleWhere(
        (c) => c['id'] == company,
      );
      expect(row['capabilities']['charging_read'], true);
      expect(row['capabilities']['inventory_read'], false);
      expect(row['capabilities']['company_read'], false);
      await api.signOut();
      await expectLater(
        api.request(historyEndpoint),
        throwsA(
          isA<ApiException>()
              .having((e) => e.status, 'current unauthenticated read', 401)
              .having((e) => e.endsCurrentSession, 'owned invalidation', true),
        ),
      );
    },
    skip:
        path == null ||
            Platform.environment['EV_NATIVE_STAFF_STOP_PROTOCOL'] != null
        ? 'Requires owned read-only local fixture: node tests/native-vendor-session-observations.cjs'
        : false,
  );

  test(
    'actual Laravel Flutter staff grant queues one scoped synthetic stop and replays its receipt',
    () async {
      final fixture =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(fixture['fixture_only'], isTrue);
      final base = Uri.parse(fixture['base']);
      expect(['127.0.0.1', 'localhost'], contains(base.host));
      expect(base.scheme, 'http');
      final api = EvApi(base);
      addTearDown(api.close);
      final account = await api.signIn(
        fixture['staff_email'],
        fixture['password'],
      );
      expect(account.role, 'customer');
      final company = fixture['company_id'] as int;
      final session = fixture['stop_session_id'] as int;
      final member = fixture['membership_id'] as int;
      final endpoint = 'vendor-ops/companies/$company/charging-sessions';
      await expectLater(
        api.request(endpoint),
        throwsA(
          isA<ApiException>().having(
            (error) => error.status,
            'unaccepted grant',
            404,
          ),
        ),
      );
      await api.request(
        'vendor-ops/staff-invitations/$member/accept',
        method: 'POST',
        body: {'expected_version': 2, 'consent': true},
      );
      final first = VendorSessionObservationPage.parse(
        await api.request(endpoint),
        company: company,
        page: 1,
      );
      expect(first.stopGrant, (id: member, version: 2));
      final row = first.items.singleWhere((item) => item.id == session);
      expect(row.canRequestStop, true);
      expect(
        row.protocol,
        Platform.environment['EV_NATIVE_STAFF_STOP_PROTOCOL'],
      );
      expect(row.state, 'charging');
      final response = await api.request(
        '$endpoint/$session/stop',
        method: 'POST',
        body: {'grant_id': member, 'grant_version': 2, 'consent': true},
      );
      expect(response['scope'], 'company_simulator_session_stop');
      expect(response['company_id'], company);
      expect(response['session_id'], session);
      expect(response['evidence'], 'SIMULATED_CSMS');
      expect(response['physical_charging_verified'], false);
      expect(response['command_status'], 'pending');
      expect(response['session_status'], 'stopping');
      final next = VendorSessionObservationPage.parse(
        await api.request(endpoint),
        company: company,
        page: 1,
      );
      expect(
        next.items.singleWhere((item) => item.id == session).canRequestStop,
        false,
      );
      final replay = await api.request(
        '$endpoint/$session/stop',
        method: 'POST',
        body: {'grant_id': member, 'grant_version': 2, 'consent': true},
      );
      expect(replay['command_id'], response['command_id']);
    },
    skip:
        path == null ||
            Platform.environment['EV_NATIVE_STAFF_STOP_PROTOCOL'] == null
        ? 'Requires the owned simulator-stop fixture via EV_NATIVE_STAFF_STOP_PROTOCOL'
        : false,
  );
}
