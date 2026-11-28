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
  'stopped_at': '2026-11-19T06:00:00Z',
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

Map<String, dynamic> meterPage({
  int session = 27,
  int number = 1,
  int cut = 27,
  bool linked = true,
}) => {
  'scope': 'company_csms_meter_history',
  'company_id': 4,
  'session_id': session,
  'through_id': linked ? cut : 0,
  'source_linked': linked,
  'protocol': linked ? '2.0.1' : null,
  'coverage': 'RECORDED_APPLIED_CSMS_EVENTS',
  'history_complete': false,
  'billing_authority': false,
  'physical_energy_verified': false,
  'commands_sent': false,
  'network_requests': 0,
  'evidence': linked ? 'SIMULATED_CSMS' : 'NO_RECORDED_CSMS_LINK',
  'as_of': '2026-11-19T10:00:00Z',
  'observations': {
    'data': linked
        ? [
            {
              'id': number == 1 ? cut : 1,
              'sequence': number == 1 ? 26 : 0,
              'kind': number == 1 ? 'ended' : 'started',
              'cumulative_meter_wh': 1002500,
              'source_occurred_at': '2026-11-19T09:59:00.123456Z',
              'recorded_at': '2026-11-19T09:59:01Z',
            },
          ]
        : [],
    'current_page': number,
    'last_page': linked ? 2 : 1,
    'total': linked ? 26 : 0,
    'per_page': 25,
  },
};

class MeterOperationsApi extends OperationsApi {
  bool newer = false, unlinked = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (!path.endsWith('/meter-history'))
      return super.request(path, method: method, body: body, query: query);
    expect(method, 'GET');
    expect(body, isNull);
    reads.add(Map.of(query));
    if (pending != null) return pending!.future;
    if (this.fail) throw const ApiException(503, 'PRIVATE');
    return meterPage(
      session: int.parse(path.split('/')[4]),
      number: int.parse(query['page']!),
      cut: query.containsKey('through')
          ? int.parse(query['through']!)
          : (newer ? 28 : 27),
      linked: !unlinked,
    );
  }
}

Widget meters(
  MeterOperationsApi api, {
  String lang = 'en',
  int session = 27,
  VoidCallback? expired,
}) => MaterialApp(
  locale: Locale(lang),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: VendorSessionObservationsView(
    api: api,
    companyId: 4,
    sessionId: session,
    onExpired: expired ?? () {},
  ),
);
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

class StaffStopApi extends OperationsApi {
  bool lostStop = false;
  final stops = <Map<String, dynamic>>[];
  final sessions = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => sessions.stream;
  void revoke() {
    epoch++;
    sessions.add(epoch);
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'POST') {
      expect(path, 'vendor-ops/companies/4/charging-sessions/27/stop');
      stops.add(body!);
      if (lostStop) throw const ApiException(409, 'PRIVATE stop diagnostic');
      return {
        'scope': 'company_simulator_session_stop',
        'company_id': 4,
        'session_id': 27,
        'command_id': 99,
        'command_status': 'pending',
        'session_status': 'stopping',
        'evidence': 'SIMULATED_CSMS',
        'physical_charging_verified': false,
      };
    }
    reads.add(query);
    return {
      ...page(),
      'staff_stop_grant': {'id': 42, 'version': 7},
      'data': [
        {
          ...record(27),
          'recorded_status': 'charging',
          'stopped_at': null,
          'protocol': '2.0.1',
          'evidence': 'SIMULATED_CSMS',
          'can_request_stop': true,
        },
      ],
    };
  }
}

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
        final context = tester.element(find.text('Owned station 27'));
        final localizations = MaterialLocalizations.of(context);
        for (final minute in [0, 1, 2]) {
          final localizedClock = localizations.formatTimeOfDay(
            TimeOfDay(hour: 6, minute: minute),
            alwaysUse24HourFormat: MediaQuery.alwaysUse24HourFormatOf(context),
          );
          expect(
            find.textContaining(' · $localizedClock · \u2068UTC\u2069'),
            findsOneWidget,
          );
        }
        expect(find.textContaining('2026-11-19T06:'), findsNothing);
        expect(
          Directionality.of(context),
          lang == 'ar' ? TextDirection.rtl : TextDirection.ltr,
        );
        final next = find.widgetWithText(
          OutlinedButton,
          lang == 'ar' ? 'التالي' : 'Next',
        );
        expect(next.hitTestable(), findsOneWidget);
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
      expect(next.hitTestable(), findsOneWidget);
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
  for (final lang in ['en', 'ar']) {
    testWidgets(
      '$lang explicit simulator stop matches the reviewed current staff grant',
      (tester) async {
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        final api = StaffStopApi()..lostStop = lang == 'ar';
        addTearDown(api.sessions.close);
        await tester.pumpWidget(panel(api, lang: lang));
        await tester.pumpAndSettle();
        final action = find.widgetWithText(
          OutlinedButton,
          lang == 'ar' ? 'طلب إيقاف جلسة محاكاة' : 'Request a simulator stop',
        );
        await tester.scrollUntilVisible(action, 200);
        await tester.tap(action);
        await tester.pumpAndSettle();
        expect(api.stops, isEmpty);
        await tester.tap(
          find.widgetWithText(
            FilledButton,
            lang == 'ar' ? 'إرسال الطلب' : 'Send request',
          ),
        );
        await tester.pumpAndSettle();
        expect(api.stops, [
          {'grant_id': 42, 'grant_version': 7, 'consent': true},
        ]);
        expect(api.reads, hasLength(1));
        expect(action, findsNothing);
        expect(
          find.textContaining(
            lang == 'ar' ? 'تعذر تأكيد نتيجة' : 'request recorded',
          ),
          findsOneWidget,
        );
        expect(find.textContaining('PRIVATE'), findsNothing);
      },
    );
  }

  testWidgets('revoked staff cannot confirm a retained simulator stop', (
    tester,
  ) async {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    final api = StaffStopApi();
    addTearDown(api.sessions.close);
    await tester.pumpWidget(panel(api));
    await tester.pumpAndSettle();
    final action = find.widgetWithText(
      OutlinedButton,
      'Request a simulator stop',
    );
    await tester.scrollUntilVisible(action, 200);
    await tester.tap(action);
    await tester.pumpAndSettle();
    api.revoke();
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Send request'));
    await tester.pumpAndSettle();
    expect(api.stops, isEmpty);
    expect(find.text('Owned station 27'), findsNothing);
  });
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
  for (final authStatus in [401, 409, 419])
    testWidgets(
      'current $authStatus expires only current view and single flight blocks duplicate read',
      (tester) async {
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
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
        api.pending!.completeError(ApiException(authStatus, 'PRIVATE'));
        await tester.pumpAndSettle();
        expect(expired, 1);
        expect(find.text('Owned station 27'), findsNothing);
      },
    );
  test(
    'meter projection is immutable and rejects unsafe counters or acceptance claims',
    () {
      final value = meterPage();
      final parsed = VendorMeterObservationPage.parse(
        value,
        company: 4,
        session: 27,
        page: 1,
      );
      value['observations']['data'][0]['cumulative_meter_wh'] = 0;
      expect(parsed.items.single.counterWh, 1002500);
      expect(() => parsed.items.clear(), throwsUnsupportedError);
      for (final mutate in <void Function(Map<String, dynamic>)>[
        (v) => v['company_id'] = 5,
        (v) => v['session_id'] = 28,
        (v) => v['through_id'] = 1,
        (v) => v['billing_authority'] = true,
        (v) => v['history_complete'] = true,
        (v) => v['physical_energy_verified'] = true,
        (v) => v['commands_sent'] = true,
        (v) => v['protocol'] = null,
        (v) => v['observations']['data'][0]['cumulative_meter_wh'] =
            9007199254740992,
        (v) => v['observations']['data'][0]['source_occurred_at'] =
            '2026-02-30T00:00:00Z',
        (v) => v['source_linked'] = false,
      ]) {
        final raw = meterPage();
        mutate(raw);
        expect(
          () => VendorMeterObservationPage.parse(
            raw,
            company: 4,
            session: 27,
            page: 1,
          ),
          throwsFormatException,
        );
      }
      expect(
        () => VendorMeterObservationPage.parse(
          meterPage(),
          company: 4,
          session: 27,
          page: 1,
          through: 28,
        ),
        throwsFormatException,
      );
    },
  );
  for (final lang in ['en', 'ar'])
    testWidgets(
      '$lang meter view holds snapshot across pages and explicitly refreshes',
      (tester) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        final api = MeterOperationsApi();
        await tester.pumpWidget(meters(api, lang: lang));
        await tester.pumpAndSettle();
        expect(find.textContaining('1002500 Wh'), findsOneWidget);
        expect(
          tester.widget<Text>(find.textContaining('1002500 Wh')).textDirection,
          TextDirection.ltr,
        );
        expect(
          find.textContaining(
            lang == 'ar' ? 'ليست طاقة مستهلكة' : 'not consumed or billed',
          ),
          findsOneWidget,
        );
        api.newer = true;
        final next = find.widgetWithText(
          OutlinedButton,
          lang == 'ar' ? 'التالي' : 'Next',
        );
        await tester.ensureVisible(next);
        await tester.tap(next);
        await tester.pumpAndSettle();
        expect(api.reads.last, {'page': '2', 'through': '27'});
        final refresh = find.widgetWithText(
          OutlinedButton,
          lang == 'ar' ? 'تحديث لقطة السجل' : 'Refresh recorded snapshot',
        );
        await tester.ensureVisible(refresh);
        await tester.tap(refresh);
        await tester.pumpAndSettle();
        expect(api.reads.last, {'page': '1'});
        expect(tester.takeException(), isNull);
      },
    );
  testWidgets(
    'meter navigation wipes hidden session records and detached row actions stay inert',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = MeterOperationsApi();
      await tester.pumpWidget(panel(api));
      await tester.pumpAndSettle();
      final row = find.byKey(const ValueKey('meter-history-27'));
      final old = tester.widget<OutlinedButton>(row).onPressed!;
      await tester.ensureVisible(row);
      await tester.tap(row);
      await tester.pumpAndSettle();
      expect(find.textContaining('1002500 Wh'), findsOneWidget);
      expect(find.text('Owned station 27', skipOffstage: false), findsNothing);
      old();
      await tester.pumpAndSettle();
      expect(api.reads.length, 2);
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('Owned station 27'), findsNothing);
    },
  );
  testWidgets(
    'failed history page clears counters and retries exact snapshot cursor',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = MeterOperationsApi();
      await tester.pumpWidget(meters(api));
      await tester.pumpAndSettle();
      api.fail = true;
      final next = find.widgetWithText(OutlinedButton, 'Next');
      await tester.ensureVisible(next);
      await tester.pumpAndSettle();
      await tester.tap(next);
      await tester.pumpAndSettle();
      expect(find.textContaining('1002500 Wh'), findsNothing);
      api.fail = false;
      final retry = find.widgetWithText(OutlinedButton, 'Retry this page');
      await tester.ensureVisible(retry);
      await tester.tap(retry);
      await tester.pumpAndSettle();
      expect(api.reads.last, {'page': '2', 'through': '27'});
    },
  );
  testWidgets(
    'unlinked history is explicit and never fabricated from current session total',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = MeterOperationsApi()..unlinked = true;
      await tester.pumpWidget(meters(api));
      await tester.pumpAndSettle();
      expect(find.textContaining('No recorded CSMS link'), findsOneWidget);
      expect(find.textContaining('1002500'), findsNothing);
    },
  );
  testWidgets(
    'inactive meter mount and late credential conflict do not read or expire newer actor',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      final api = MeterOperationsApi();
      var expired = 0;
      await tester.pumpWidget(meters(api, expired: () => expired++));
      await tester.pumpAndSettle();
      expect(api.reads, isEmpty);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.reads, isEmpty);
      api.pending = Completer<dynamic>();
      await tester.tap(
        find.widgetWithText(OutlinedButton, 'Refresh recorded snapshot'),
      );
      await tester.pump();
      api.epoch++;
      api.pending!.completeError(const ApiException(409, 'PRIVATE'));
      await tester.pumpAndSettle();
      expect(expired, 0);
      expect(find.textContaining('1002500'), findsNothing);
    },
  );
  testWidgets('new session target rejects late old timeline', (tester) async {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    final api = MeterOperationsApi()..pending = Completer<dynamic>();
    final old = api.pending!;
    await tester.pumpWidget(meters(api, session: 27));
    await tester.pump();
    api.pending = null;
    await tester.pumpWidget(meters(api, session: 28));
    await tester.pumpAndSettle();
    old.complete(meterPage());
    await tester.pumpAndSettle();
    expect(api.reads.length, 2);
    expect(find.text('#28'), findsOneWidget);
  });
}
