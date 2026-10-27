import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

// Explicitly opt in against the disposable local development service only.
// This is a native-client protocol smoke test, not physical-device acceptance.
void main() {
  const origin = String.fromEnvironment('EV_NATIVE_SMOKE_URL');
  test(
    'native client exercises actual Laravel roles, bookings and privacy',
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
          'reservations/${reservation['id']}',
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
