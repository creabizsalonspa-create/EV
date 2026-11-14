import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_agreements.dart';
import 'test_support.dart';

class AgreementApi extends FixtureApi {
  bool consent = false, loseReply = false, active = true;
  int posts = 0;
  Completer<dynamic>? delayed;
  final body = {
    'id': 'version-1',
    'country': 'AE',
    'revision': 1,
    'sha256': 'reviewed-hash',
    'title_en': 'Reviewed terms',
    'title_ar': 'الشروط المراجعة',
    'body_en': 'PRIVATE COMPANY TERMS',
    'body_ar': 'نص شروط الشركة',
  };
  @override
  bool Function() sessionFence() =>
      () => active;
  Map<String, dynamic> view() => {
    'version': body,
    'can_accept': !consent,
    'acceptance': consent
        ? {'id': 'receipt-1', 'created_at': '2026-11-14'}
        : null,
    'history': {'data': [], 'current_page': 1, 'last_page': 1},
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'POST') {
      posts++;
      expect(path, 'vendor-ops/companies/1/agreement/accept');
      expect(body, {
        'version_id': 'version-1',
        'sha256': 'reviewed-hash',
        'accepted': true,
      });
      consent = true;
      if (loseReply) throw const ApiException(503, 'PRIVATE LOST REPLY');
      return {'id': 'receipt-1'};
    }
    return delayed == null ? view() : delayed!.future;
  }
}

Widget screen(AgreementApi api, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: VendorAgreementsView(api: api, companyId: 1, onExpired: () {}),
);

void main() {
  for (final ar in [false, true]) {
    testWidgets(
      'explicit owner review sends exactly the displayed version/hash ($ar)',
      (tester) async {
        final api = AgreementApi();
        await tester.pumpWidget(screen(api, ar: ar));
        await tester.pumpAndSettle();
        final label = ar
            ? 'الموافقة على الاتفاقية المراجعة'
            : 'Accept reviewed agreement';
        expect(
          tester
              .widget<FilledButton>(find.widgetWithText(FilledButton, label))
              .onPressed,
          isNull,
        );
        await tester.tap(find.byType(CheckboxListTile));
        await tester.pump();
        await tester.ensureVisible(find.text(label));
        await tester.tap(find.text(label));
        await tester.pumpAndSettle();
        expect(api.posts, 1);
        expect(find.textContaining('receipt-1'), findsOneWidget);
      },
    );
  }
  testWidgets(
    'unknown acceptance does not replay; explicit read recovers the recorded receipt',
    (tester) async {
      final api = AgreementApi()..loseReply = true;
      await tester.pumpWidget(screen(api));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      await tester.ensureVisible(find.text('Accept reviewed agreement'));
      await tester.tap(find.text('Accept reviewed agreement'));
      await tester.pumpAndSettle();
      expect(api.posts, 1);
      expect(find.textContaining('PRIVATE LOST REPLY'), findsNothing);
      expect(find.byType(CheckboxListTile), findsNothing);
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.textContaining('receipt-1'), findsOneWidget);
      expect(api.posts, 1);
    },
  );
  testWidgets(
    'background invalidates a pending read and clears consent without automatic refresh',
    (tester) async {
      final api = AgreementApi()..delayed = Completer<dynamic>();
      await tester.pumpWidget(screen(api));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      api.delayed!.complete(api.view());
      await tester.pumpAndSettle();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('PRIVATE COMPANY TERMS'), findsNothing);
      expect(find.byType(CheckboxListTile), findsNothing);
      expect(api.posts, 0);
    },
  );
  testWidgets(
    'retained owner screen cannot submit after the authenticated account changes',
    (tester) async {
      final api = AgreementApi();
      await tester.pumpWidget(screen(api));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      api.active = false;
      await tester.ensureVisible(find.text('Accept reviewed agreement'));
      await tester.tap(find.text('Accept reviewed agreement'));
      await tester.pumpAndSettle();
      expect(api.posts, 0);
    },
  );
}
