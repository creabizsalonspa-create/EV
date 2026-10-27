import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_admission.dart';
import 'test_support.dart';

class AdmissionApi extends FixtureApi {
  int version = 1, writes = 0;
  bool denied = false, loseAck = false, revoked = false, canManage = true;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, startsWith('vendor-ops/companies/1/chargers/2/admission'));
    if (denied) throw const ApiException(401, 'private diagnostic');
    final profile = {
      'identity': 'ev-test-widget',
      'protocol': '2.0.1',
      'credential_version': version,
      'enabled': !revoked,
    };
    if (method == 'GET') return {'profile': profile, 'can_manage': canManage};
    writes++;
    if (method == 'DELETE') {
      revoked = true;
      return {...profile, 'enabled': false};
    }
    expect(body, {'expected_version': version, 'days': 30});
    version++;
    if (loseAck) throw const ApiException(0, 'private diagnostic');
    return {
      'profile': {...profile, 'credential_version': version},
      'secret': 'a' * 64,
    };
  }
}

Widget app(AdmissionApi api, {bool ar = false, VoidCallback? expired}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: ChargerAdmissionView(
        api: api,
        company: const {'id': 1},
        charger: const {'id': 2},
        onExpired: expired ?? () {},
      ),
    );
void main() {
  test('admission translation keys match', () {
    expect(admissionEn.keys.toSet(), admissionAr.keys.toSet());
  });
  testWidgets(
    'one-time secret requires confirmation and is removed after30 seconds',
    (t) async {
      final api = AdmissionApi();
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      await t.tap(find.text('Issue / rotate credential'));
      await t.pumpAndSettle();
      expect(api.writes, 0);
      await t.tap(find.text('Confirm'));
      await t.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.byKey(const Key('charger-one-time-secret')), findsOneWidget);
      await t.pump(const Duration(seconds: 31));
      expect(find.byKey(const Key('charger-one-time-secret')), findsNothing);
    },
  );
  testWidgets(
    'lost acknowledgement clears stale version and never repeats rotation',
    (t) async {
      final api = AdmissionApi()..loseAck = true;
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      await t.tap(find.text('Issue / rotate credential'));
      await t.pumpAndSettle();
      await t.tap(find.text('Confirm'));
      await t.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Issue / rotate credential'), findsNothing);
      expect(find.textContaining('private diagnostic'), findsNothing);
      await t.tap(find.text('Refresh admission'));
      await t.pumpAndSettle();
      expect(find.text('Credential version: 2'), findsOneWidget);
      expect(api.writes, 1);
    },
  );
  testWidgets(
    'read-only staff have no credential actions and Arabic fits390px',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      final api = AdmissionApi()..canManage = false;
      await t.pumpWidget(app(api, ar: true));
      await t.pumpAndSettle();
      expect(find.text(admissionAr['rotate']!), findsNothing);
      expect(
        Directionality.of(t.element(find.text(admissionAr['notice']!))),
        TextDirection.rtl,
      );
      expect(t.takeException(), isNull);
    },
  );
  testWidgets('expired scope delegates cleanup and shows no secret', (t) async {
    int expired = 0;
    await t.pumpWidget(
      app(
        AdmissionApi()..denied = true,
        expired: () {
          expired++;
        },
      ),
    );
    await t.pumpAndSettle();
    expect(expired, 1);
    expect(find.byKey(const Key('charger-one-time-secret')), findsNothing);
  });
}
