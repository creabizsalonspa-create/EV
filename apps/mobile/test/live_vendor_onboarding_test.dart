import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_VENDOR_ONBOARDING_FIXTURE'];
  test(
    'native real Laravel company KYC4 MiB upload review inventory idempotency and versioned staff consent',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final body = {
        'legal_name': 'Native onboarding ${f['run']}',
        'country': 'AE',
        'trade_license_number': 'SYNTHETIC-PRIVATE-${f['run']}',
        'license_expires_on': '${DateTime.now().year + 2}-12-31',
        'idempotency_key': operationKey(),
      };
      final company = await api.request(
        'vendor-ops/companies',
        method: 'POST',
        body: body,
      );
      final duplicate = await api.request(
        'vendor-ops/companies',
        method: 'POST',
        body: body,
      );
      expect(duplicate['id'], company['id']);
      final base = 'vendor-ops/companies/${company['id']}';
      expect(company.containsKey('trade_license_number'), false);
      await api.request(
        base,
        method: 'PATCH',
        body: {'legal_name': 'Native revised ${f['run']}'},
      );
      expect(
        (await api.request(base))['legal_name'],
        'Native revised ${f['run']}',
      );
      final png = base64Decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=',
      );
      final bytes = Uint8List(4 * 1024 * 1024)..setRange(0, png.length, png);
      final key = operationKey();
      final document = await api.uploadDocument(
        '$base/documents',
        kind: 'trade_license',
        bytes: bytes,
        mime: 'image/png',
        key: key,
      );
      final replay = await api.uploadDocument(
        '$base/documents',
        kind: 'trade_license',
        bytes: bytes,
        mime: 'image/png',
        key: key,
      );
      expect(replay['id'], document['id']);
      expect((await api.request('$base/documents'))['total'], 1);
      final downloaded = await api.downloadDocument(
        '$base/documents/${document['id']}',
      );
      expect(downloaded.bytes.length, bytes.length);
      expect(downloaded.bytes.take(8), png.take(8));
      expect(downloaded.mime, 'image/png');
      await api.request('$base/submit', method: 'POST', body: {});
      expect((await api.request(base))['status'], 'submitted');
      await expectLater(
        api.request(
          base,
          method: 'PATCH',
          body: {'legal_name': 'Cannot change submitted company'},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      // Separate guarded local Admin controller fixture; no mobile Admin is shipped.
      final approve = await Process.run('../../scripts/php', [
        'tests/Fixtures/native-vendor-onboarding.php',
        'approve',
        f['run'],
        '${company['id']}',
      ]);
      expect(
        approve.exitCode,
        0,
        reason: 'Guarded synthetic approval must succeed',
      );
      expect(jsonDecode(approve.stdout)['status'], 'approved');
      expect((await api.request('me'))['role'], 'vendor');
      expect((await api.request('$base/reviews'))['total'], 1);
      final catalog = await api.request('$base/catalog-stations');
      final station = (catalog['data'] as List).single;
      final siteBody = {
        'name': 'Native site ${f['run']}',
        'country': 'AE',
        'timezone': 'Asia/Dubai',
        'address': 'Synthetic owned site',
        'latitude': 25.2,
        'longitude': 55.3,
        'station_id': station['id'],
        'idempotency_key': operationKey(),
      };
      final site = await api.request(
        '$base/sites',
        method: 'POST',
        body: siteBody,
      );
      expect(
        (await api.request(
          '$base/sites',
          method: 'POST',
          body: siteBody,
        ))['id'],
        site['id'],
      );
      final chargerBody = {
        'asset_reference': 'NATIVE-${f['run']}',
        'manufacturer': 'Synthetic',
        'model': 'Synthetic',
        'source': 'simulator',
        'ocpp_protocol': '2.0.1',
        'idempotency_key': operationKey(),
      };
      final charger = await api.request(
        '$base/sites/${site['id']}/chargers',
        method: 'POST',
        body: chargerBody,
      );
      expect(charger['remote_control_enabled'], false);
      expect(
        (await api.request(
          '$base/sites/${site['id']}/chargers',
          method: 'POST',
          body: chargerBody,
        ))['id'],
        charger['id'],
      );
      final connectorBody = {
        'connector_number': 1,
        'connector_type': 'CCS2',
        'max_power_kw': 50,
        'idempotency_key': operationKey(),
      };
      final connector = await api.request(
        '$base/chargers/${charger['id']}/connectors',
        method: 'POST',
        body: connectorBody,
      );
      expect(connector['availability'], 'unknown');
      expect(
        (await api.request(
          '$base/chargers/${charger['id']}/connectors',
          method: 'POST',
          body: connectorBody,
        ))['id'],
        connector['id'],
      );
      await api.request(
        '$base/staff',
        method: 'POST',
        body: {
          'email': f['staff'],
          'permissions': ['company.read', 'inventory.read'],
        },
      );
      final membership = (await api.request('$base/staff'))['data'][0];
      await api.signOut();
      await api.signIn(f['staff'], f['password']);
      final invitation = (await api.request(
        'vendor-ops/staff-invitations',
      ))['data'][0];
      expect(invitation['invitation_version'], 1);
      await api.request(
        'vendor-ops/staff-invitations/${membership['id']}/accept',
        method: 'POST',
        body: {'expected_version': invitation['invitation_version']},
      );
      expect((await api.request(base))['capabilities']['owner'], false);
      await expectLater(
        api.downloadDocument('$base/documents/${document['id']}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request('$base/sites', method: 'POST', body: siteBody),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      final revoke = await Process.run('../../scripts/php', [
        'tests/Fixtures/native-vendor-onboarding.php',
        'revoke',
        f['run'],
        '${membership['id']}',
      ]);
      expect(revoke.exitCode, 0);
      await expectLater(
        api.request('$base/sites'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Run node tests/native-vendor-onboarding.cjs with local Laravel and guarded fixtures'
        : false,
  );
}
