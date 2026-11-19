import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/ocpi_catalog_observations.dart';
import 'test_support.dart';

class CatalogApi extends FixtureApi {
  final reads = <Map<String, String>>[];
  Completer<dynamic>? pending;
  bool promote = false;
  Map<String, dynamic>? overrideReport;
  Map<String, int> ages(String label) => {
    for (final key in catalogAgeLabels.keys) key: key == label ? 1 : 0,
  };
  Map<String, dynamic> report(String selected) => {
    'scope': 'stored_partner_catalog_observations',
    'as_of': '2026-11-15T10:00:00Z',
    'record_limit': 10000,
    'module_filter': selected,
    'provider': {
      'id': 7,
      'name': 'Private catalog operator',
      'authorized': false,
    },
    'modules': {
      if (selected != 'tariffs')
        'locations': {
          'total': 1,
          'reported_age': ages('clock_ahead'),
          'receipt_age': ages('within_1_hour'),
          'stored_publish_true': 1,
          'stored_publish_false': 0,
          'stored_publish_unknown': 0,
          'coordinates_usable': 1,
          'coordinates_unknown_or_invalid': 0,
          'connector_list_present': 1,
          'connector_list_empty': 0,
          'connector_list_unknown': 0,
          'published_by_reported_market': {
            'AE': 1,
            'US': 0,
            'CA': 0,
            'other_or_unknown': 0,
          },
          'published_with_usable_coordinates_by_reported_market': {
            'AE': 1,
            'US': 0,
            'CA': 0,
            'other_or_unknown': 0,
          },
        },
      if (selected != 'locations')
        'tariffs': {
          'total': 1,
          'reported_age': ages('older_than_7_days'),
          'receipt_age': ages('within_1_hour'),
          'current_version_retired': 1,
        },
    },
    'receipt_refreshes_source_age': false,
    'market_classification': 'PARTNER_REPORTED_LOCATION_COUNTRY_ONLY',
    'coverage_exhaustive': false,
    'live_availability_verified': false,
    'physical_charging_verified': promote,
    'remote_control': false,
    'billing_authority': false,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'ocpi/providers/7/catalog-observations');
    expect(method, 'GET');
    reads.add(Map.of(query));
    return pending?.future ?? overrideReport ?? report(query['module']!);
  }
}

Widget view(CatalogApi api, {bool ar = false, int providerId = 7}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: OcpiCatalogObservationsView(
        api: api,
        providerId: providerId,
        onExpired: () {},
      ),
    );
void main() {
  for (final ar in [false, true])
    testWidgets(
      'catalog counts require explicit read, separate clocks and clear on background ($ar)',
      (tester) async {
        final api = CatalogApi();
        await tester.pumpWidget(view(api, ar: ar));
        await tester.pumpAndSettle();
        expect(api.reads, isEmpty);
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
        expect(api.reads, [
          {'module': 'all'},
        ]);
        await tester.scrollUntilVisible(
          find.text(
            ar ? 'الساعة متقدمة على اللقطة: 1' : 'Clock ahead of snapshot: 1',
          ),
          200,
        );
        expect(find.text('Private catalog operator'), findsOneWidget);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.inactive,
        );
        await tester.pump();
        expect(find.text('Private catalog operator'), findsNothing);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pumpAndSettle();
        expect(api.reads.length, 1);
        await tester.ensureVisible(
          find.byType(DropdownButtonFormField<String>),
        );
        await tester.tap(find.byType(DropdownButtonFormField<String>));
        await tester.pumpAndSettle();
        await tester.tap(find.text(ar ? 'التعرفة' : 'Tariffs').last);
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
        expect(api.reads.last, {'module': 'tariffs'});
      },
    );
  testWidgets('promoted charging claim is rejected without partial metrics', (
    tester,
  ) async {
    final api = CatalogApi()..promote = true;
    await tester.pumpWidget(view(api));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byType(FilledButton));
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();
    expect(find.text('Private catalog operator'), findsNothing);
    expect(find.textContaining('No partial counts'), findsOneWidget);
  });
  testWidgets('late provider response cannot paint the next target', (
    tester,
  ) async {
    final api = CatalogApi()..pending = Completer<dynamic>();
    await tester.pumpWidget(view(api));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byType(FilledButton));
    await tester.tap(find.byType(FilledButton));
    await tester.pump();
    await tester.pumpWidget(view(api, providerId: 8));
    await tester.pump();
    api.pending!.complete(api.report('all'));
    await tester.pumpAndSettle();
    expect(find.text('Private catalog operator'), findsNothing);
    expect(api.reads.length, 1);
  });
  for (final ar in [false, true]) {
    testWidgets(
      'published country facets render without inferring network coverage ($ar)',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(390, 844));
        addTearDown(() => tester.binding.setSurfaceSize(null));
        final api = CatalogApi();
        await tester.pumpWidget(view(api, ar: ar));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
        final published = find.byKey(
          const ValueKey('catalog-market-published_by_reported_market-AE'),
        );
        await tester.scrollUntilVisible(published, 200);
        expect(
          tester.widget<Text>(published).data,
          ar
              ? 'الإمارات (موقع مبلّغ عنه من الشريك): 1'
              : 'UAE (partner-reported location): 1',
        );
        final other = find.byKey(
          const ValueKey(
            'catalog-market-published_with_usable_coordinates_by_reported_market-other_or_unknown',
          ),
        );
        await tester.scrollUntilVisible(other, 200);
        expect(
          tester.widget<Text>(other).data,
          ar
              ? 'موقع مبلّغ عنه في دولة أخرى أو غير معروف: 0'
              : 'Other or unknown reported location: 0',
        );
        expect(tester.takeException(), isNull);
        expect(api.reads, [
          {'module': 'all'},
        ]);
      },
    );
  }
  test('combined record limit applies across locations and tariffs', () {
    final api = CatalogApi();
    dynamic scale(dynamic value) {
      if (value is int) return value * 6000;
      if (value is Map)
        return value.map((key, item) => MapEntry(key, scale(item)));
      return value;
    }

    final report = api.report('all');
    report['modules'] = scale(report['modules']);
    expect(catalogObservationReportValid(report, providerId: 7), isFalse);
    expect(
      catalogObservationReportValid(api.report('all'), providerId: 7),
      isTrue,
    );
  });
  final invalid = <String, void Function(Map<String, dynamic>)>{
    'coverage authority': (d) => d['coverage_exhaustive'] = true,
    'party-country inference': (d) =>
        d['market_classification'] = 'PROVIDER_PARTY_COUNTRY',
    'missing classification': (d) => d.remove('market_classification'),
    'missing facet': (d) =>
        d['modules']['locations'].remove('published_by_reported_market'),
    'unknown market': (d) =>
        d['modules']['locations']['published_by_reported_market']['GB'] = 0,
    'negative count': (d) =>
        d['modules']['locations']['published_by_reported_market']['US'] = -1,
    'noninteger count': (d) =>
        d['modules']['locations']['published_by_reported_market'] =
            <String, dynamic>{
              'AE': 1.0,
              'US': 0,
              'CA': 0,
              'other_or_unknown': 0,
            },
    'inflated published total': (d) =>
        d['modules']['locations']['published_by_reported_market']['US'] = 1,
    'coordinates in wrong market': (d) =>
        d['modules']['locations']['published_with_usable_coordinates_by_reported_market'] =
            {'AE': 0, 'US': 1, 'CA': 0, 'other_or_unknown': 0},
    'inflated coordinate total': (d) =>
        d['modules']['locations']['published_with_usable_coordinates_by_reported_market']['AE'] =
            2,
  };
  for (final entry in invalid.entries) {
    testWidgets(
      'country observations fail closed without partial counts: ${entry.key}',
      (tester) async {
        final api = CatalogApi();
        api.overrideReport = api.report('all');
        entry.value(api.overrideReport!);
        await tester.pumpWidget(view(api));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
        expect(find.text('Private catalog operator'), findsNothing);
        expect(find.textContaining('No partial counts'), findsOneWidget);
        expect(
          find.byKey(
            const ValueKey('catalog-market-published_by_reported_market-AE'),
          ),
          findsNothing,
        );
        expect(tester.takeException(), isNull);
      },
    );
  }
}
