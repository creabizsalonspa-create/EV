import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/rider_access.dart';
import 'test_support.dart';

class PublicationApi extends FixtureApi {
  int writes = 0, reads = 0;
  bool active = true, inactiveToken = false;
  String grantState = 'approved';
  @override
  bool Function() sessionFence() =>
      () => active;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'POST') {
      writes++;
      expect(
        path,
        inactiveToken
            ? 'vendor-ops/companies/7/rider-tokens/42/retire-source'
            : 'vendor-ops/companies/7/rider-tokens/42/provision',
      );
      throw const ApiException(503, 'RAW PRIVATE SOURCE ERROR');
    }
    reads++;
    return {
      'data': [
        {
          'id': 9,
          'token_id': 42,
          'label': 'Synthetic card',
          'state': grantState,
          'token_type': 'ISO14443',
          'suffix': '7777',
          'provisioning_state': 'requires_inspection',
          'revoked_at': null,
          'token_revoked_at': inactiveToken ? '2026-11-15T00:00:00Z' : null,
          'token_expires_at': '2099-01-01T00:00:00Z',
        },
      ],
      'source_adapter_enabled': true,
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

Widget view(PublicationApi api, {bool ar = false, int company = 7}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: RiderAccessView(
        api: api,
        onExpired: () {},
        records: RiderRecords.operatorRequests,
        companyId: company,
      ),
    );
void main() {
  for (final ar in [false, true])
    testWidgets(
      'RFID publication requires confirmation and unknown writes never auto-repeat ($ar)',
      (tester) async {
        final api = PublicationApi();
        await tester.pumpWidget(view(api, ar: ar));
        await tester.pumpAndSettle();
        final publish = find.text(
          ar
              ? 'إعداد تفويض مصدر الاختبار'
              : 'Provision test-source authorization',
        );
        await tester.scrollUntilVisible(publish, 150);
        await tester.tap(publish);
        await tester.pumpAndSettle();
        expect(api.writes, 0);
        await tester.tap(find.text(ar ? 'إلغاء' : 'Cancel'));
        await tester.pumpAndSettle();
        expect(api.writes, 0);
        await tester.ensureVisible(publish);
        await tester.tap(publish);
        await tester.pumpAndSettle();
        await tester.tap(find.text(ar ? 'تأكيد' : 'Confirm'));
        await tester.pumpAndSettle();
        expect(api.writes, 1);
        expect(find.textContaining('RAW PRIVATE'), findsNothing);
        final refresh = find.text(ar ? 'تحديث' : 'Refresh');
        await tester.ensureVisible(refresh);
        await tester.tap(refresh);
        await tester.pumpAndSettle();
        expect(api.writes, 1);
        expect(api.reads, 2);
      },
    );
  testWidgets('RFID confirmation cannot cross a company target change', (
    tester,
  ) async {
    final api = PublicationApi();
    await tester.pumpWidget(view(api));
    await tester.pumpAndSettle();
    final publish = find.text('Provision test-source authorization');
    await tester.scrollUntilVisible(publish, 150);
    await tester.tap(publish);
    await tester.pumpAndSettle();
    await tester.pumpWidget(view(api, company: 8));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.writes, 0);
  });
  for (final ar in [false, true]) {
    testWidgets(
      'inactive source retirement is explicit and never auto-retries ($ar)',
      (tester) async {
        final api = PublicationApi()..inactiveToken = true;
        await tester.pumpWidget(view(api, ar: ar));
        await tester.pumpAndSettle();
        final retire = find.text(
          ar
              ? 'إنهاء تسجيل الرمز غير النشط بمصدر الاختبار'
              : 'Retire inactive test-source registration',
        );
        await tester.scrollUntilVisible(retire, 150);
        await tester.tap(retire);
        await tester.pumpAndSettle();
        expect(api.writes, 0);
        await tester.tap(find.text(ar ? 'إلغاء' : 'Cancel'));
        await tester.pumpAndSettle();
        expect(api.writes, 0);
        await tester.ensureVisible(retire);
        await tester.tap(retire);
        await tester.pumpAndSettle();
        await tester.tap(find.text(ar ? 'تأكيد' : 'Confirm'));
        await tester.pumpAndSettle();
        expect(api.writes, 1);
        expect(find.textContaining('RAW PRIVATE'), findsNothing);
        final refresh = find.text(ar ? 'تحديث' : 'Refresh');
        await tester.ensureVisible(refresh);
        await tester.tap(refresh);
        await tester.pumpAndSettle();
        expect(api.writes, 1);
      },
    );
  }
  testWidgets(
    'one withdrawn grant never offers global retirement of an active token',
    (tester) async {
      final api = PublicationApi()..grantState = 'revoked';
      await tester.pumpWidget(view(api));
      await tester.pumpAndSettle();
      expect(
        find.text('Retire inactive test-source registration'),
        findsNothing,
      );
      expect(api.writes, 0);
    },
  );
}
