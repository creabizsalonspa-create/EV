import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_source_registration.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';
import 'vendor_meter_auth_expiration_test.dart' show BufferedTransportApi;

Map<String, dynamic> snapshot({
  String state = 'registered',
  bool matches = true,
}) => {
  'registration_id': state == 'not_prepared' ? null : 1,
  'binding_id': state == 'not_prepared' ? null : 2,
  'profile_id': state == 'not_prepared' ? null : 3,
  'state': state,
  'source_station_id': state == 'registered' ? 401 : null,
  'verified_at': state == 'registered' ? '2026-11-21T10:15:30.123456Z' : null,
  'upstream_registration_verified': state == 'registered',
  'evidence': state == 'registered'
      ? 'authenticated_source_record_created_and_read_back'
      : 'no_verified_registration',
  'device_commands_sent': 0,
  'charging_capability_granted': false,
  'charger_hardware_verified': false,
  'automatic_retry_allowed': false,
  'source_snapshot_only': true,
  'live_connection_verified': false,
  'current_inventory_matches_registration': matches,
  'can_prepare': false,
  'can_register': false,
};

class RegistrationApi extends FixtureApi {
  Map<String, dynamic> value = snapshot();
  Completer<dynamic>? pending;
  Object? failure;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    expect(method, 'GET');
    expect(body, isNull);
    expect(query, isEmpty);
    if (failure != null) throw failure!;
    return pending == null
        ? Map<String, dynamic>.from(value)
        : await pending!.future;
  }
}

Widget view(
  EvApi api, {
  bool ar = false,
  VoidCallback? expired,
  int company = 1,
  int charger = 4,
}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: ChargerSourceRegistrationView(
    api: api,
    companyId: company,
    chargerId: charger,
    onExpired: expired ?? () {},
  ),
);
void main() {
  test(
    'typed snapshots preserve historical registration without granting device authority',
    () {
      for (final state in [
        'not_prepared',
        'prepared',
        'registering',
        'registered',
        'requires_inspection',
      ]) {
        expect(
          ChargerSourceRegistrationSnapshot.parse(snapshot(state: state)).state,
          state,
        );
      }
      expect(
        ChargerSourceRegistrationSnapshot.parse(
          snapshot(matches: false),
        ).inventoryMatches,
        isFalse,
      );
      for (final entry in {
        'charging_capability_granted': true,
        'charger_hardware_verified': true,
        'live_connection_verified': true,
        'automatic_retry_allowed': true,
        'device_commands_sent': 1,
        'can_prepare': true,
        'can_register': true,
        'upstream_registration_verified': false,
        'evidence': 'PHYSICAL',
        'verified_at': '2026-11-21 10:15:30',
        'source_station_id': -1,
        'registration_id': 9007199254740992,
        'state': 'live',
      }.entries) {
        expect(
          () => ChargerSourceRegistrationSnapshot.parse({
            ...snapshot(),
            entry.key: entry.value,
          }),
          throwsFormatException,
          reason: entry.key,
        );
      }
    },
  );
  testWidgets(
    'registration is explicit read-only snapshot and refresh replaces old status',
    (t) async {
      final api = RegistrationApi();
      await t.pumpWidget(view(api));
      await t.pumpAndSettle();
      expect(api.calls, [
        'GET vendor-ops/companies/1/chargers/4/source-registration',
      ]);
      expect(find.text('Source record created and read back'), findsOneWidget);
      expect(find.textContaining('does not prove a live'), findsOneWidget);
      api.value = snapshot(state: 'requires_inspection');
      await t.tap(find.byKey(const Key('source-registration-refresh')));
      await t.pumpAndSettle();
      expect(
        find.textContaining('Requires authorized inspection'),
        findsOneWidget,
      );
      expect(api.calls.length, 2);
      expect(find.text('Source record created and read back'), findsNothing);
    },
  );
  testWidgets(
    'historical source receipt displays current-mapping review warning',
    (t) async {
      await t.pumpWidget(
        view(RegistrationApi()..value = snapshot(matches: false)),
      );
      await t.pumpAndSettle();
      expect(find.textContaining('no longer matches'), findsOneWidget);
    },
  );
  testWidgets(
    'background clears status and held response; resume never replays',
    (t) async {
      final api = RegistrationApi()..pending = Completer<dynamic>();
      await t.pumpWidget(view(api));
      await t.pump();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pump();
      api.pending!.complete(snapshot());
      await t.pump();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(find.text('Source record created and read back'), findsNothing);
      expect(api.calls.length, 1);
    },
  );
  testWidgets(
    'newer session transition rejects old success without expiry callback',
    (t) async {
      final api = RegistrationApi()..pending = Completer<dynamic>();
      var expired = 0;
      await t.pumpWidget(view(api, expired: () => expired++));
      await t.pump();
      await api.forgetSession();
      api.pending!.complete(snapshot());
      await t.pump();
      expect(expired, 0);
      expect(find.text('Source record created and read back'), findsNothing);
    },
  );
  testWidgets('real401 expires only owned current registration read', (
    t,
  ) async {
    final api = BufferedTransportApi(401);
    addTearDown(api.close);
    var expired = 0;
    await t.pumpWidget(view(api, expired: () => expired++));
    await t.runAsync(api.deliver);
    await t.pump();
    expect(expired, 1);
  });
  testWidgets(
    'failed refresh removes stale source proof and never renders raw errors',
    (t) async {
      final api = RegistrationApi();
      await t.pumpWidget(view(api));
      await t.pumpAndSettle();
      api.failure = const ApiException(503, 'PRIVATE-credential');
      await t.tap(find.byKey(const Key('source-registration-refresh')));
      await t.pumpAndSettle();
      expect(find.text('Source record created and read back'), findsNothing);
      expect(find.textContaining('PRIVATE-credential'), findsNothing);
      expect(find.textContaining('Status is unavailable'), findsOneWidget);
    },
  );
  testWidgets('Arabic narrow layout keeps evidence and UTC source text', (
    t,
  ) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    addTearDown(t.view.resetDevicePixelRatio);
    await t.pumpWidget(view(RegistrationApi(), ar: true));
    await t.pumpAndSettle();
    expect(find.text('التسجيل لدى المصدر'), findsOneWidget);
    expect(t.takeException(), isNull);
  });
}
