import 'dart:async';
import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_logs.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';
import 'charger_logs_test.dart' show ExportPicker;
import 'vendor_meter_auth_expiration_test.dart' show BufferedTransportApi;
import 'package:file_picker/file_picker.dart';

Map<String, dynamic> record({String evidence = 'AUTHENTICATED_SOURCE'}) => {
  'id': 1,
  'protocol': '2.0.1',
  'evidence': evidence,
  'physical_device_verified': false,
  'can_download': true,
  'dispatch_state': 'transport_submitted',
  'expired': false,
  'cancelled_at': null,
  'device_status': 'Uploaded',
  'expires_at': '2026-11-21T12:15:00Z',
  'file_received_at': '2026-11-21T12:01:00Z',
  'file_expires_at': '2026-11-22T12:01:00Z',
  'accepted_at': '2026-11-21T12:00:01Z',
  'file_size': 3,
  'file_sha256': sha256.convert([1, 2, 3]).toString(),
};

class AuthLogsApi extends FixtureApi {
  bool manage = false, requestable = false;
  String evidence = 'AUTHENTICATED_SOURCE';
  Map<String, dynamic>? badRow;
  Completer<dynamic>? pending;
  Completer<PrivateDocument>? bytes;
  final methods = <String>[];
  int downloads = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    methods.add('$method $path');
    expect(method, 'GET');
    if (pending != null) return await pending!.future;
    return {
      'can_request': requestable,
      'can_manage': manage,
      'maximum_file_bytes': 524288,
      'upload_lifetime_seconds': 900,
      'file_retention_seconds': 86400,
      'evidence': evidence,
      'charging_capacity_changed': false,
      'current_page': 1,
      'last_page': 1,
      'data': [badRow ?? record()],
    };
  }

  @override
  Future<PrivateDocument> downloadDiagnosticLog(String path) async {
    downloads++;
    return bytes == null
        ? PrivateDocument(
            Uint8List.fromList([1, 2, 3]),
            'application/octet-stream',
          )
        : await bytes!.future;
  }
}

Widget view(EvApi api, {bool ar = false, VoidCallback? expired}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: ChargerLogsView(
    api: api,
    companyId: 2,
    chargerId: 3,
    onExpired: expired ?? () {},
  ),
);
void main() {
  testWidgets(
    'authenticated source history is read-only unless canonical controls explicitly allow it',
    (t) async {
      final api = AuthLogsApi();
      await t.pumpWidget(view(api));
      await t.pumpAndSettle();
      expect(
        find.text('Authenticated source record; hardware unverified'),
        findsOneWidget,
      );
      expect(find.textContaining('Private simulator logs.'), findsNothing);
      expect(find.text('Request diagnostic file'), findsNothing);
      expect(find.text('Inspect diagnostic source'), findsNothing);
      expect(find.text('Revoke upload and remove file'), findsNothing);
      expect(api.methods, [
        'GET vendor-ops/companies/2/chargers/3/diagnostic-logs',
      ]);
    },
  );
  testWidgets(
    'historical simulator rows retain their original evidence in an authenticated source list',
    (t) async {
      await t.pumpWidget(
        view(AuthLogsApi()..badRow = record(evidence: 'SIMULATED_SOURCE')),
      );
      await t.pumpAndSettle();
      expect(find.text('Simulated source record'), findsOneWidget);
      expect(
        find.text('Authenticated source record; hardware unverified'),
        findsNothing,
      );
    },
  );
  for (final change in [
    {'evidence': 'PHYSICAL_HARDWARE'},
    {'physical_device_verified': true},
    {'protocol': '3.0'},
  ]) {
    testWidgets(
      'unsupported source record fails closed: ${change.keys.single}',
      (t) async {
        await t.pumpWidget(
          view(AuthLogsApi()..badRow = {...record(), ...change}),
        );
        await t.pumpAndSettle();
        expect(find.text('Save private diagnostic file'), findsNothing);
        expect(find.text('Request diagnostic file'), findsNothing);
      },
    );
  }
  testWidgets('source bytes export only after exact private integrity checks', (
    t,
  ) async {
    final picker = ExportPicker();
    FilePicker.platform = picker;
    await t.pumpWidget(view(AuthLogsApi()));
    await t.pumpAndSettle();
    await t.ensureVisible(find.text('Save private diagnostic file'));
    await t.tap(find.text('Save private diagnostic file'));
    await t.pumpAndSettle();
    expect(picker.saves, 1);
    expect(picker.saved, [1, 2, 3]);
  });
  testWidgets(
    'a retained old-row export callback cannot read a newer source page',
    (t) async {
      final api = AuthLogsApi(), picker = ExportPicker();
      FilePicker.platform = picker;
      await t.pumpWidget(view(api));
      await t.pumpAndSettle();
      await t.ensureVisible(find.text('Save private diagnostic file'));
      final old = t
          .widget<TextButton>(
            find.widgetWithText(TextButton, 'Save private diagnostic file'),
          )
          .onPressed!;
      api.badRow = {...record(), 'id': 2};
      await t.ensureVisible(find.text('Refresh diagnostic history'));
      await t.tap(find.text('Refresh diagnostic history'));
      await t.pumpAndSettle();
      old();
      await t.pump();
      expect(api.downloads, 0);
      expect(picker.saves, 0);
    },
  );
  testWidgets(
    'a new credential transition cannot export an older held source file',
    (t) async {
      final api = AuthLogsApi()..bytes = Completer<PrivateDocument>(),
          picker = ExportPicker();
      FilePicker.platform = picker;
      await t.pumpWidget(view(api));
      await t.pumpAndSettle();
      await t.ensureVisible(find.text('Save private diagnostic file'));
      await t.tap(find.text('Save private diagnostic file'));
      await t.pump();
      await api.forgetSession();
      api.bytes!.complete(
        PrivateDocument(
          Uint8List.fromList([1, 2, 3]),
          'application/octet-stream',
        ),
      );
      await t.pump();
      expect(picker.saves, 0);
      expect(find.text('Save private diagnostic file'), findsNothing);
    },
  );
  testWidgets(
    'background clears source records and never automatically reloads on resume',
    (t) async {
      final api = AuthLogsApi();
      await t.pumpWidget(view(api));
      await t.pumpAndSettle();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pump();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(
        find.text('Authenticated source record; hardware unverified'),
        findsNothing,
      );
      expect(api.methods, hasLength(1));
    },
  );
  testWidgets(
    'actual API401 expires current diagnostic view after credential epoch invalidation',
    (t) async {
      final api = BufferedTransportApi(401);
      addTearDown(api.close);
      var expired = 0;
      await t.pumpWidget(view(api, expired: () => expired++));
      await t.runAsync(api.deliver);
      await t.pump();
      expect(expired, 1);
    },
  );
  testWidgets(
    'Arabic390px describes authenticated evidence without hardware acceptance',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(view(AuthLogsApi(), ar: true));
      await t.pumpAndSettle();
      expect(find.text('سجل مصدر مصادق؛ الأجهزة غير متحققة'), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );
}
