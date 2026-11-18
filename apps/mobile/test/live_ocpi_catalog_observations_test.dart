import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/ocpi_catalog_observations.dart';

void main() {
  final input = Platform.environment['EV_NATIVE_CATALOG_OBSERVATIONS'];
  test(
    'actual Laravel catalog response passes canonical native report validation; customer remains denied',
    () async {
      final f =
          jsonDecode(await File(input!).readAsString()) as Map<String, dynamic>;
      final base = Uri.parse(f['base'] as String);
      expect(base.host, '127.0.0.1');
      expect(base.port, 8000);
      expect(f['fixture_only'], true);
      final owner = EvApi(base), customer = EvApi(base);
      addTearDown(owner.close);
      addTearDown(customer.close);
      await owner.signIn(f['owner_email'] as String, f['password'] as String);
      final provider = f['provider_id'] as int;
      final path = 'ocpi/providers/$provider/catalog-observations';
      for (final module in ['all', 'locations', 'tariffs']) {
        final result = await owner.request(path, query: {'module': module});
        expect(
          catalogObservationReportValid(result, providerId: provider),
          isTrue,
          reason:
              'Real $module response must not be rejected by the native client',
        );
        expect(result['module_filter'], module);
        if (module != 'tariffs') {
          expect(
            result['modules']['locations']['published_by_reported_market']['AE'],
            1,
          );
          expect(
            result['modules']['locations']['published_with_usable_coordinates_by_reported_market']['AE'],
            1,
          );
          expect(
            result['modules']['locations']['published_by_reported_market']['US'],
            0,
          );
          expect(
            result['modules']['locations']['reported_age']['older_than_7_days'],
            1,
          );
          expect(
            result['modules']['locations']['receipt_age']['within_1_hour'],
            1,
          );
        }
        expect(result['coverage_exhaustive'], false);
        expect(result['remote_control'], false);
        expect(result['billing_authority'], false);
      }
      await customer.signIn(
        f['customer_email'] as String,
        f['password'] as String,
      );
      await expectLater(
        customer.request(path),
        throwsA(
          isA<ApiException>().having(
            (e) => e.status,
            'private provider report',
            403,
          ),
        ),
      );
      await owner.signOut();
      await customer.signOut();
    },
    skip: input == null
        ? 'Explicit disposable local catalog fixture required. No live partner requests.'
        : false,
  );
}
