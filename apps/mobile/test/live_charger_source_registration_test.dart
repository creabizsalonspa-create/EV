// Opt-in authenticated local CSMS metadata observation; no charger commands.
import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_source_registration.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_SOURCE_REGISTRATION_FIXTURE'];
  test(
    'actual Laravel owner projects both OCPP retained source snapshots without native controls',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], isTrue);
      if (f['synthetic_retained_receipt'] == true) {
        expect(f['source_authentication_performed'], isFalse);
        expect(f['source_writes_performed'], 0);
        expect(f['expected_inventory_matches'], isFalse);
      }
      final base = Uri.parse(f['base']);
      expect(base.scheme, 'http');
      expect(['localhost', '127.0.0.1'], contains(base.host));
      final api = EvApi(base);
      addTearDown(api.close);
      final account = await api.signIn(f['owner'], f['password']);
      expect(account.role, 'vendor');
      final company = f['company_id'];
      expect(company, isA<int>());
      final protocols = <String>{};
      for (final row in f['chargers'] as List) {
        expect(row['id'], isA<int>());
        protocols.add(row['protocol']);
        final raw = await api.request(
          'vendor-ops/companies/$company/chargers/${row['id']}/source-registration',
        );
        final projected = ChargerSourceRegistrationSnapshot.parse(raw);
        expect(projected.state, 'registered');
        expect(
          projected.inventoryMatches,
          f['expected_inventory_matches'] ?? true,
        );
        expect(projected.verifiedAt, isNotNull);
        expect(raw['can_prepare'], isFalse);
        expect(raw['can_register'], isFalse);
        expect(raw['device_commands_sent'], 0);
        expect(raw['charging_capability_granted'], isFalse);
        expect(raw['live_connection_verified'], isFalse);
        expect(raw['charger_hardware_verified'], isFalse);
      }
      expect(protocols, {'1.6', '2.0.1'});
      f.clear();
    },
    skip: path == null
        ? 'Requires owned local authenticated CSMS metadata fixture from Agent1 source-registration acceptance.'
        : false,
  );
}
