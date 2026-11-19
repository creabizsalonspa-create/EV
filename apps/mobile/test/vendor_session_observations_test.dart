import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_session_observations.dart';
import 'package:creabiz_ev/vendor_inventory.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';

Map<String, dynamic> record(int id) => {
  'id': id,
  'station_id': 7,
  'station_name': 'Owned station $id',
  'recorded_status': 'stopped',
  'energy_wh': 2500,
  'energy_state': 'recorded',
  'record_created_at': '2026-11-19T06:00:00.000000Z',
  'record_updated_at': '2026-11-19T06:01:00Z',
  'stopped_at': null,
  'protocol': null,
  'evidence': 'SIMULATED',
  'physical_charging_verified': false,
  'live_availability_verified': false,
};
Map<String, dynamic> page({int company = 4, int number = 1, String? status}) =>
    {
      'scope': 'company_recorded_charging_sessions',
      'company_id': company,
      'status_filter': status,
      'inventory_station_scope': 'current_owned_unique_site_links',
      'customer_identity_fields_included': false,
      'financial_fields_included': false,
      'control_actions_exposed': false,
      'physical_charging_verified': false,
      'live_availability_verified': false,
      'data': [record(number == 1 ? 27 : 1)],
      'per_page': 25,
      'current_page': number,
      'last_page': 2,
      'total': 26,
      'as_of': '2026-11-19T06:02:00Z',
    };

class OperationsApi extends FixtureApi {
  int epoch = 0;
  bool fail = false;
  Completer<dynamic>? pending;
  final reads = <Map<String, String>>[];
  @override
  bool Function() sessionFence() {
    final captured = epoch;
    return () => epoch == captured;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(body, isNull);
    expect(path, contains('vendor-ops/companies/'));
    reads.add(Map.of(query));
    if (pending != null) return pending!.future;
    if (fail) throw const ApiException(503, 'PRIVATE ERROR');
    return page(
      company: int.parse(path.split('/')[2]),
      number: int.parse(query['page']!),
      status: query['status'],
    );
  }
}

class DirectoryOperationsApi extends OperationsApi {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'vendor-ops/companies')
      return {
        'data': [
          {
            'id': 4,
            'legal_name': 'Restricted company',
            'status': 'approved',
            'capabilities': {
              'charging_read': true,
              'company_read': false,
              'inventory_read': false,
              'inventory_write': false,
            },
          },
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    return super.request(path, method: method, body: body, query: query);
  }
}

Widget panel(
  OperationsApi api, {
  String lang = 'en',
  int company = 4,
  VoidCallback? expired,
}) => MaterialApp(
  locale: Locale(lang),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: VendorSessionObservationsView(
    api: api,
    companyId: company,
    onExpired: expired ?? () {},
  ),
);
void main() {
  test(
    'immutable projection does not retain unknown private response fields',
    () {
      final raw = page();
      (raw['data'][0] as Map)['customer_email'] = 'PRIVATE';
      final parsed = VendorSessionObservationPage.parse(
        raw,
        company: 4,
        page: 1,
      );
      raw['data'][0]['station_name'] = 'changed';
      expect(parsed.items.single.stationName, 'Owned station 27');
      expect(() => parsed.items.clear(), throwsUnsupportedError);
    },
  );
  for (final change in <void Function(Map<String, dynamic>)>[
    (v) => v['company_id'] = 9,
    (v) => v['physical_charging_verified'] = true,
    (v) => v['control_actions_exposed'] = true,
    (v) => v['total'] = -1,
    (v) => v['last_page'] = 3,
    (v) => v['status_filter'] = 'charging',
    (v) => v['as_of'] = '2026-02-30T00:00:00Z',
    (v) => v['data'][0]['energy_wh'] = -1,
    (v) => v['data'][0]['energy_state'] = 'unavailable',
    (v) => v['data'][0]['protocol'] = '2.0.1',
    (v) => v['data'][0]['record_updated_at'] = 'not-a-time',
    (v) => v['data'] = [record(1), record(1)],
  ]) {
    test(
      'rejects incoherent/private or acceptance-promoting observation ${change.hashCode}',
      () {
        final raw = page();
        change(raw);
        expect(
          () => VendorSessionObservationPage.parse(raw, company: 4, page: 1),
          throwsFormatException,
        );
      },
    );
  }
  for (final lang in ['en', 'ar'])
    testWidgets(
      '$lang small-screen read-only observations paginate without mutation',
      (tester) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        final api = OperationsApi();
        await tester.pumpWidget(panel(api, lang: lang));
        await tester.pumpAndSettle();
        expect(find.text('Owned station 27'), findsOneWidget);
        expect(
          Directionality.of(tester.element(find.text('Owned station 27'))),
          lang == 'ar' ? TextDirection.rtl : TextDirection.ltr,
        );
        final next = find.widgetWithText(
          OutlinedButton,
          lang == 'ar' ? 'التالي' : 'Next',
        );
        await tester.ensureVisible(next);
        await tester.tap(next);
        await tester.pumpAndSettle();
        expect(api.reads.last, {'page': '2'});
        expect(find.text('Owned station 1'), findsOneWidget);
        expect(find.textContaining('PRIVATE'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  testWidgets(
    'failed page removes old rows and explicit retry repeats only failed page',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = OperationsApi();
      await tester.pumpWidget(panel(api));
      await tester.pumpAndSettle();
      api.fail = true;
      final next = find.widgetWithText(OutlinedButton, 'Next');
      await tester.ensureVisible(next);
      await tester.tap(next);
      await tester.pumpAndSettle();
      expect(find.text('Owned station 27'), findsNothing);
      expect(find.textContaining('PRIVATE ERROR'), findsNothing);
      api.fail = false;
      final retry = find.widgetWithText(OutlinedButton, 'Retry this page');
      await tester.ensureVisible(retry);
      await tester.tap(retry);
      await tester.pumpAndSettle();
      expect(api.reads.last, {'page': '2'});
      expect(find.text('Owned station 1'), findsOneWidget);
    },
  );
  testWidgets(
    'hidden mount and background erase records and require explicit foreground read',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      final api = OperationsApi();
      await tester.pumpWidget(panel(api));
      await tester.pumpAndSettle();
      expect(api.reads, isEmpty);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.reads, isEmpty);
      await tester.tap(find.widgetWithText(OutlinedButton, 'Read first page'));
      await tester.pumpAndSettle();
      expect(api.reads.length, 1);
      final old = tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Read first page'),
          )
          .onPressed!;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.text('Owned station 27'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      old();
      await tester.pumpAndSettle();
      expect(api.reads.length, 1);
    },
  );
  for (final lateFailure in [false, true])
    testWidgets(
      'old actor ${lateFailure ? '401' : 'success'} cannot populate or expire new actor',
      (tester) async {
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        final api = OperationsApi()..pending = Completer<dynamic>();
        var expired = 0;
        await tester.pumpWidget(panel(api, expired: () => expired++));
        await tester.pump();
        api.epoch++;
        lateFailure
            ? api.pending!.completeError(const ApiException(401, 'PRIVATE'))
            : api.pending!.complete(page());
        await tester.pumpAndSettle();
        expect(expired, 0);
        expect(find.text('Owned station 27'), findsNothing);
        expect(api.reads.length, 1);
      },
    );
  testWidgets(
    'captured filter callback and read callbacks cannot cross account fence',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = OperationsApi();
      await tester.pumpWidget(panel(api));
      await tester.pumpAndSettle();
      final callback = tester
          .widget<DropdownButtonFormField<String>>(
            find.byType(DropdownButtonFormField<String>),
          )
          .onChanged!;
      api.epoch++;
      callback('charging');
      await tester.pumpAndSettle();
      expect(api.reads.length, 1);
    },
  );
  testWidgets(
    'new company replaces pending old company and rejects its late reply',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = OperationsApi()..pending = Completer<dynamic>();
      final pending = api.pending!;
      await tester.pumpWidget(panel(api));
      await tester.pump();
      api.pending = null;
      await tester.pumpWidget(panel(api, company: 5));
      await tester.pumpAndSettle();
      pending.complete(page());
      await tester.pumpAndSettle();
      expect(api.reads.length, 2);
      expect(find.text('Owned station 27'), findsOneWidget);
    },
  );
  testWidgets(
    'operations-only customer staff reaches session view without KYC or inventory grant',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = DirectoryOperationsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: VendorInventoryView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      final button = find.widgetWithText(
        TextButton,
        'Recorded charging sessions',
      );
      expect(button, findsOneWidget);
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pumpAndSettle();
      expect(find.byType(VendorSessionObservationsView), findsOneWidget);
      expect(find.text('Owned station 27'), findsOneWidget);
      expect(api.reads.length, 1);
    },
  );
  testWidgets(
    'current 401 expires only current view and single flight blocks duplicate read',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = OperationsApi();
      var expired = 0;
      await tester.pumpWidget(panel(api, expired: () => expired++));
      await tester.pumpAndSettle();
      final callback = tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Read first page'),
          )
          .onPressed!;
      api.pending = Completer<dynamic>();
      callback();
      callback();
      expect(api.reads.length, 2);
      api.pending!.completeError(const ApiException(401, 'PRIVATE'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Owned station 27'), findsNothing);
    },
  );
}
