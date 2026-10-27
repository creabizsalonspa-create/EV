import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

// Explicitly opt in against the disposable local development service only.
// This is a native-client protocol smoke test, not physical-device acceptance.
void main() {
  const origin = String.fromEnvironment('EV_NATIVE_SMOKE_URL');
  test(
    'native client exercises Laravel fleets, meters, bookings and privacy',
    () async {
      final base = Uri.parse(origin);
      expect(
        ['127.0.0.1', 'localhost'].contains(base.host),
        isTrue,
        reason: 'Synthetic smoke records must remain on loopback.',
      );
      final customer = EvApi(base),
          vendor = EvApi(base),
          outsider = EvApi(base);
      final run = DateTime.now().microsecondsSinceEpoch;
      final password = 'synthetic-$run-aA9!';
      try {
        final driver = await customer.signIn(
          'driver-$run@example.test',
          password,
          name: 'Native smoke driver',
        );
        expect(driver.role, 'customer');
        final vendorEmail = 'vendor-$run@example.test';
        await vendor.signIn(vendorEmail, password, name: 'Native smoke vendor');
        final promoted = await Process.run('../../scripts/php', [
          'artisan',
          'platform:role',
          vendorEmail,
          'vendor',
        ]);
        expect(
          promoted.exitCode,
          0,
          reason: 'Local operator role provisioning failed.',
        );
        expect((await vendor.signIn(vendorEmail, password)).role, 'vendor');
        final station = await vendor.request(
          'stations',
          method: 'POST',
          body: {
            'name': 'Native smoke station $run',
            'country': 'AE',
            'currency': 'AED',
            'timezone': 'Asia/Dubai',
            'latitude': 25.2,
            'longitude': 55.3,
            'source': 'simulator',
            'connector_type': 'CCS2',
            'power_kw': 50,
          },
        );
        // Enough uniquely named records to prove actual server pagination, not local slicing.
        for (var i = 0; i < 25; i++) {
          await vendor.request(
            'stations',
            method: 'POST',
            body: {
              'name': 'Native smoke station $run page $i',
              'country': 'AE',
              'currency': 'AED',
              'timezone': 'Asia/Dubai',
              'latitude': 25.2,
              'longitude': 55.3,
              'source': 'simulator',
              'connector_type': i.isOdd ? 'CCS2' : 'Type2',
              'power_kw': i.isOdd ? 150 : 22,
            },
          );
        }
        final query = CatalogFilter(
          search: 'Native smoke station $run',
          country: 'ae',
        );
        final firstPage = await customer.catalog(filter: query);
        final secondPage = await customer.catalog(filter: query, page: 2);
        expect(firstPage.total, 26);
        expect(firstPage.items.length, 25);
        expect(secondPage.items.length, 1);
        expect(secondPage.current, 2);
        expect(
          firstPage.items.map((v) => v.id).contains(secondPage.items.single.id),
          isFalse,
        );
        final filtered = await customer.catalog(
          filter: CatalogFilter(
            search: 'Native smoke station $run',
            country: 'AE',
            connector: 'CCS2',
            minimumPower: 100,
          ),
        );
        expect(filtered.total, 12);
        expect(
          filtered.items.every(
            (v) => v.connector == 'CCS2' && v.powerKw >= 100,
          ),
          isTrue,
        );
        final day = DateTime.now().toUtc().add(const Duration(days: 1));
        final slot = DateTime.utc(day.year, day.month, day.day, 12);
        final booking = {
          'station_id': station['id'],
          'starts_at': slot.toIso8601String().replaceFirst('.000Z', 'Z'),
          'idempotency_key': 'native-smoke-$run',
        };
        final reservation = await customer.request(
          'reservations',
          method: 'POST',
          body: booking,
        );
        expect(reservation['status'], 'confirmed');
        expect(
          (await customer.request(
            'reservations',
            method: 'POST',
            body: booking,
          ))['id'],
          reservation['id'],
        );
        final vendorBookings = await vendor.request('reservations');
        expect(
          (vendorBookings['data'] as List).any(
            (r) => r['id'] == reservation['id'],
          ),
          isTrue,
        );
        final vehicle = await customer.request(
          'vehicles',
          method: 'POST',
          body: {'name': 'Native smoke EV', 'connector_type': 'CCS2'},
        );
        final ticket = await customer.request(
          'support',
          method: 'POST',
          body: {
            'subject': 'Native smoke $run',
            'body': 'Synthetic client protocol check',
          },
        );
        await outsider.signIn(
          'outsider-$run@example.test',
          password,
          name: 'Native smoke outsider',
        );
        expect((await outsider.request('vehicles'))['data'], isEmpty);
        expect((await outsider.request('reservations'))['data'], isEmpty);
        expect((await outsider.request('support'))['data'], isEmpty);
        expect(vehicle['id'], isNotNull);
        expect(ticket['id'], isNotNull);
        await customer.request(
          'support/${ticket['id']}/messages',
          method: 'POST',
          body: {'body': 'Native public follow-up'},
        );
        final thread = await customer.request('support/${ticket['id']}');
        expect(thread['messages']['data'].length, 2);
        await expectLater(
          outsider.request('support/${ticket['id']}'),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
        );
        final fleet = await customer.request(
          'fleets',
          method: 'POST',
          body: {'name': 'Native fleet $run', 'country': 'AE'},
        );
        final center = await customer.request(
          'fleets/${fleet['id']}/cost-centers',
          method: 'POST',
          body: {'code': 'OPS', 'name': 'Operations'},
        );
        await customer.request(
          'fleets/${fleet['id']}/invitations',
          method: 'POST',
          body: {'email': 'outsider-$run@example.test'},
        );
        final invitation = (await outsider.request(
          'fleet-invitations',
        ))['data'].single;
        await expectLater(
          outsider.request('fleets/${fleet['id']}'),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
        );
        await outsider.request(
          'fleet-invitations/${invitation['id']}/accept',
          method: 'POST',
        );
        final driverVehicle = await outsider.request(
          'vehicles',
          method: 'POST',
          body: {'name': 'Fleet driver EV', 'connector_type': 'CCS2'},
        );
        await outsider.request(
          'fleets/${fleet['id']}/vehicles',
          method: 'POST',
          body: {
            'vehicle_id': driverVehicle['id'],
            'cost_center_id': center['id'],
          },
        );
        final driverFleet = await outsider.request('fleets/${fleet['id']}');
        expect(driverFleet['members'], isEmpty);
        expect(
          driverFleet['vehicles'].single['vehicle_id'],
          driverVehicle['id'],
        );
        await expectLater(
          outsider.request(
            'fleets/${fleet['id']}/cost-centers',
            method: 'POST',
            body: {'code': 'DENIED', 'name': 'Not owner'},
          ),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
        );
        await customer.request(
          'fleets/${fleet['id']}/members/${invitation['id']}',
          method: 'DELETE',
        );
        expect(
          (await customer.request('fleets/${fleet['id']}'))['vehicles'],
          isEmpty,
        );
        await expectLater(
          outsider.request('fleets/${fleet['id']}'),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
        );
        // Move only this synthetic booking into its active slot via local fixture setup.
        // No clock overrides, production endpoint changes or physical charger commands.
        final fixture = await Process.run('../../scripts/php', [
          '-r',
          "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); \$now = Carbon\\CarbonImmutable::now('UTC'); App\\Models\\Reservation::whereKey(${reservation['id']})->update(['starts_at' => \$now->startOfHour()->addMinutes(intdiv(\$now->minute, 30) * 30)->format('Y-m-d H:i:s')]);",
        ]);
        expect(fixture.exitCode, 0, reason: fixture.stderr.toString());
        final session = await customer.request(
          'reservations/${reservation['id']}/simulate-start',
          method: 'POST',
        );
        expect(
          (await vendor.request(
            'simulations',
          ))['data'].any((v) => v['id'] == session['id']),
          isTrue,
        );
        await expectLater(
          customer.request(
            'simulations/${session['id']}/meter',
            method: 'PUT',
            body: {'energy_wh': 1500},
          ),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
        );
        await vendor.request(
          'simulations/${session['id']}/meter',
          method: 'PUT',
          body: {'energy_wh': 1500},
        );
        await expectLater(
          vendor.request(
            'simulations/${session['id']}/meter',
            method: 'PUT',
            body: {'energy_wh': 1000},
          ),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
        );
        final stopped = await customer.request(
          'simulations/${session['id']}/stop',
          method: 'POST',
        );
        expect(stopped['total_minor'], 150);
        final issued = await customer.request(
          'finance/sessions/${session['id']}/invoice',
          method: 'POST',
        );
        final invoice = issued['invoice'];
        expect(invoice['evidence'], 'SIMULATED');
        final history = await customer.request(
          'finance/invoices',
          query: {
            'q': invoice['number'] as String,
            'currency': 'AED',
            'page': '1',
          },
        );
        expect(history['data'].length, 1);
        expect(history['data'][0]['id'], invoice['id']);
        final receipt = await customer.request(
          'finance/invoices/${invoice['id']}',
        );
        expect(receipt['payment_provider'], 'SIMULATOR_ONLY');
        expect(receipt['real_payment_processed'], false);
        expect(receipt['invoice']['total_minor'], 150);
        expect(receipt['payments'], isEmpty);
        await expectLater(
          outsider.request('finance/invoices/${invoice['id']}'),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
        );

        await expectLater(
          vendor.request(
            'simulations/${session['id']}/meter',
            method: 'PUT',
            body: {'energy_wh': 2000},
          ),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
        );
        // Operator-only test fixture for an actual support notification; no admin mobile UI.
        final adminRole = await Process.run('../../scripts/php', [
          'artisan',
          'platform:role',
          'outsider-$run@example.test',
          'admin',
        ]);
        expect(adminRole.exitCode, 0);
        await outsider.request(
          'support/${ticket['id']}/messages',
          method: 'POST',
          body: {'body': 'Native smoke support reply', 'internal': false},
        );
        final inbox = await customer.request('notifications');
        final notification = (inbox['data'] as List).singleWhere(
          (n) => n['support_ticket_id'] == ticket['id'],
        );
        expect(notification['read_at'], isNull);
        await customer.request(
          'notifications/${notification['id']}/read',
          method: 'POST',
        );
        final readInbox = await customer.request('notifications');
        expect(
          (readInbox['data'] as List).singleWhere(
            (n) => n['id'] == notification['id'],
          )['read_at'],
          isNotNull,
        );
        final cancellable = await customer.request(
          'reservations',
          method: 'POST',
          body: {
            ...booking,
            'starts_at': slot
                .add(const Duration(minutes: 30))
                .toIso8601String()
                .replaceFirst('.000Z', 'Z'),
            'idempotency_key': 'cancel-$run',
          },
        );
        await customer.request(
          'reservations/${cancellable['id']}',
          method: 'DELETE',
        );
        expect(
          (await customer.request('reservations'))['data'].first['status'],
          'cancelled',
        );
        await customer.signOut();
        await expectLater(
          customer.request('me'),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
        );
      } finally {
        customer.close();
        vendor.close();
        outsider.close();
      }
    },
    skip: origin.isEmpty
        ? 'Set EV_NATIVE_SMOKE_URL to the disposable local Laravel service.'
        : false,
  );
}
