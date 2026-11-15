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
    return pending?.future ?? report(query['module']!);
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
}
