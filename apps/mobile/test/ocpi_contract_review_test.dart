import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/ocpi_contract_review.dart';
import 'test_support.dart';

class ReviewApi extends FixtureApi {
  bool approved = false, forged = false, failWrite = false;
  int revision = 0;
  final writes = <Map<String,dynamic>>[];
  Completer<dynamic>? pending;
  Map<String,dynamic> get evidence => {
    'external_partner_acceptance_verified': false,
    'contract_legally_executed_verified': false,
    'live_roaming_enabled': forged,
    'payment_authority': false,
    'operational_enforcement_available': false,
    'administrative_authorization_review_required': true,
  };
  Map<String,dynamic> get status => {
    ...evidence, 'state': approved ? 'internally_reviewed' : 'missing',
    'revision': revision, 'review_valid_for_current_owner': approved,
    'valid_until': approved ? '2027-11-28' : null,
    'source': 'INTERNAL_MANUAL_DOCUMENT_DIGEST_ONLY', 'automatic_partner_requests': 0,
  };
  @override
  Future<dynamic> request(String path, {String method = 'GET',
      Map<String,dynamic>? body, Map<String,String> query = const {}}) async {
    calls.add('$method $path');
    if (method == 'POST') {
      writes.add(Map<String,dynamic>.from(body!));
      if (failWrite) throw const ApiException(503, 'PRIVATE SERVER DETAIL');
      approved = true; revision++;
      return status;
    }
    if (path.endsWith('/history')) {
      if (pending != null) return pending!.future;
      return {...evidence, 'scope': 'private_ocpi_contract_review_history',
        'current_revision': revision, 'history': {
          'current_page': 1, 'last_page': 1, 'total': 1, 'data': [{
            'revision': 1, 'decision': 'review', 'valid_until': '2027-11-28',
            'recorded_at': '2026-11-28T10:00:00Z', 'reference_id': 'PRIVATE-CASE-001',
            'evidence_sha256': 'a' * 64, 'reason': 'PRIVATE ADMIN AUDIT NOTE',
          }]
        }};
    }
    return status;
  }
}

Widget screen(ReviewApi api, {bool admin = true, bool authorized = false}) =>
    MaterialApp(home: OcpiContractReviewView(api: api, providerId: 7,
      admin: admin, providerAuthorized: authorized, onExpired: () {}));

Future<void> fill(WidgetTester tester) async {
  for (final item in [
    ['Internal case reference','SYNTHETIC-CASE-01'],
    ['Reviewed document SHA-256 digest','a' * 64],
    ['Validity date YYYY-MM-DD (UTC)','2027-11-28'],
    ['Internal review reason','Synthetic internal evidence only.'],
  ]) {
    final field = find.widgetWithText(TextField,item[0]);
    await tester.scrollUntilVisible(field, 150, scrollable: find.byType(Scrollable).first);
    await tester.ensureVisible(field);
    await tester.enterText(field,item[1]);
  }
  final button = find.text('Record internal review');
  tester.testTextInput.hide();
  await tester.pumpAndSettle();
  await tester.scrollUntilVisible(button, 150, scrollable: find.byType(Scrollable).first);
  await tester.ensureVisible(button);
  await tester.tap(button);
  await tester.pumpAndSettle();
  await tester.tap(find.text('Confirm'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('admin records an exact revision and explicit internal review', (tester) async {
    final api = ReviewApi();
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await fill(tester);
    expect(api.writes, hasLength(1));
    expect(api.writes.single['decision'], 'review');
    expect(api.writes.single['expected_revision'], 0);
    expect(api.writes.single['reference_id'], 'SYNTHETIC-CASE-01');
    expect(api.writes.single['evidence_sha256'], 'a' * 64);
    expect(api.writes.single['idempotency_key'], matches(RegExp(r'^[a-f0-9-]{36}$')));
    expect(api.revision, 1);
    await tester.pumpWidget(const SizedBox());
    api.close();
  });

  testWidgets('uncertain mutation clears draft and requires explicit refresh', (tester) async {
    final api = ReviewApi()..failWrite = true;
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await fill(tester);
    expect(api.writes, hasLength(1));
    expect(find.byType(TextField), findsNothing);
    expect(find.textContaining('PRIVATE SERVER DETAIL'), findsNothing);
    await tester.ensureVisible(find.text('Refresh review'));
    await tester.tap(find.text('Refresh review'));
    await tester.pumpAndSettle();
    expect(api.writes, hasLength(1));
    await tester.pumpWidget(const SizedBox());
    api.close();
  });

  testWidgets('vendor history projects no administrator-only detail', (tester) async {
    final api = ReviewApi()..approved = true..revision = 1;
    await tester.pumpWidget(screen(api, admin:false));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Read review history'));
    await tester.pumpAndSettle();
    expect(find.textContaining('PRIVATE'), findsNothing);
    expect(find.textContaining('#1'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    expect(api.writes, isEmpty);
    await tester.pumpWidget(const SizedBox());
    api.close();
  });

  testWidgets('forged live authority is rejected', (tester) async {
    final api = ReviewApi()..forged = true;
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.text('Internal review unavailable. No approval is assumed.'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    expect(api.writes,isEmpty);
    await tester.pumpWidget(const SizedBox());
    api.close();
  });

  testWidgets('late audit cannot restore private history after backgrounding', (tester) async {
    final api = ReviewApi()..approved = true..revision = 1;
    final reply = await api.request('ocpi/providers/7/contract-review/history');
    api.pending = Completer<dynamic>();
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Read review history'));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    api.pending!.complete(reply);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.textContaining('PRIVATE ADMIN AUDIT NOTE'), findsNothing);
    expect(find.byType(TextField), findsNothing);
    await tester.pumpWidget(const SizedBox());
    api.close();
  });
}
