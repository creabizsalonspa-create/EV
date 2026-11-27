import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/corporate_cycle_review.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/corporate_billing.dart';
import 'corporate_billing_test.dart' show CorporateApi, corporateApp;

Map<String, dynamic> reviewSource() => {
  'agreement_id': 6, 'period': '2026-01',
  'review_at': '2026-02-15T00:00:00+00:00', 'billing_day': 15,
  'currency': 'KWD', 'minor_units': 3, 'claim_count': 1,
  'claims': [{'claim_id': 7, 'invoice_id': 9, 'amount_minor': 1250, 'held': false}],
  'count_is_lower_bound': false, 'total_minor': 1250,
  'remaining_credit_minor': 100000, 'blocked_count': 0,
  'state': 'ready_for_review', 'read_only': true,
  'statement_issued': false, 'real_payment_processed': false,
  'can_issue_reviewed_batch': false,
};

CorporateCycleReview parse(dynamic raw) => CorporateCycleReview.parse(
  raw, agreement: 6, currency: 'KWD', precision: 3);

class CycleApi extends CorporateApi {
  final changes = StreamController<int>.broadcast(sync: true);
  int epoch = 0;
  int reviewReads = 0;
  Completer<dynamic>? delayed;
  bool malformed = false, canIssue = false, forgeIssue = false;
  bool expireRead = false, expireIssue = false;
  Never expire() {
    revoke();
    final invalidated = epoch;
    throw ApiException(401, 'Expired', expirationIsCurrent: () => epoch == invalidated);
  }
  @override
  Stream<int> get sessionEvents => changes.stream;
  @override
  bool Function() sessionFence() {
    final initial = epoch;
    return () => initial == epoch;
  }
  void revoke() {
    epoch++;
    changes.add(epoch);
  }
  @override
  void close() {
    changes.close();
    super.close();
  }
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/cycle-review')) {
      expect(method, 'GET');
      expect(body, isNull);
      reviewReads++;
      if (delayed != null) return delayed!.future;
      if (expireRead) expire();
      return reviewSource()
        ..['agreement_id'] = malformed ? 8 : 6
        ..['can_issue_reviewed_batch'] = canIssue;
    }
    if (path.endsWith('/cycle-review/issue')) {
      expect(method, 'POST');
      posts++;
      bodies.add({...body!});
      if (expireIssue) expire();
      return {
        'statement_id': 12, 'agreement_id': 6,
        'period': body['period'], 'claim_ids': body['claim_ids'],
        'total_minor': forgeIssue ? 1251 : body['total_minor'],
        'currency': 'KWD', 'minor_units': 3,
        'statement_issued': true, 'real_payment_processed': false,
        'evidence': 'TEST_REVIEWED_STATEMENT', 'replayed': posts > 1,
      };
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Widget reviewApp(CycleApi api, {bool ar = false, VoidCallback? onExpired}) => corporateApp(
  CorporateCycleReviewView(api: api, onExpired: onExpired ?? () {}, agreementId: 6,
    fleetId: 4, currency: 'KWD', precision: 3), ar: ar);

void main() {
  test('exact review is immutable and refuses inconsistent claim sets', () {
    final raw = reviewSource();
    final result = parse(raw);
    raw['claims'][0]['amount_minor'] = 1;
    expect(result.money(result.total!), 'KWD 1.250');
    expect(result.claims.single.amount, 1250);
    expect(() => result.claims.clear(), throwsUnsupportedError);
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r['agreement_id'] = 8,
      (r) => r['statement_issued'] = true,
      (r) => r['real_payment_processed'] = true,
      (r) => r['total_minor'] = 1251,
      (r) => r['total_minor'] = 1250.0,
      (r) => r['remaining_credit_minor'] = 1000,
      (r) => r['claim_count'] = 2,
      (r) => r['billing_day'] = 29,
      (r) => r['claims'][0]['amount_minor'] = 9007199254740992,
      (r) => r['claims'][0]['held'] = true,
      (r) => r['minor_units'] = 2,
    ]) {
      final invalid = reviewSource();
      change(invalid);
      expect(() => parse(invalid), throwsFormatException);
    }
    final capped = reviewSource()
      ..['claims'] = []
      ..['claim_count'] = 101
      ..['count_is_lower_bound'] = true
      ..['total_minor'] = null
      ..['state'] = 'selection_required';
    expect(parse(capped).lowerBound, true);
    expect(parse(capped).total, isNull);
  });

  testWidgets('current manager can open a read-only monthly review from company agreement', (t) async {
    final api = CycleApi();
    addTearDown(api.close);
    await t.pumpWidget(corporateApp(CorporateBillingView(api: api, onExpired: () {})));
    await t.pumpAndSettle();
    await t.tap(find.text('Synthetic organization'));
    await t.pumpAndSettle();
    await t.ensureVisible(find.text('Monthly billing review'));
    await t.tap(find.text('Monthly billing review'));
    await t.pumpAndSettle();
    expect(api.reviewReads, 1);
    expect(find.text('Proposed total: KWD 1.250'), findsOneWidget);
    expect(api.posts, 0);
    expect(find.byType(FilledButton), findsNothing);
    expect(find.text('Issue reviewed TEST statement'), findsNothing);
  });

  testWidgets('reviewed TEST issuance needs explicit consent and exact acknowledgement', (t) async {
    final api = CycleApi()..canIssue = true..forgeIssue = true;
    addTearDown(api.close);
    await t.pumpWidget(reviewApp(api));
    await t.pumpAndSettle();
    expect(api.posts, 0);
    final button = find.text('Issue reviewed TEST statement');
    await t.ensureVisible(button);
    await t.tap(button);
    await t.pumpAndSettle();
    expect(api.posts, 0);
    await t.tap(find.text('Cancel'));
    await t.pumpAndSettle();
    expect(api.posts, 0);
    await t.tap(button);
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm TEST issue'));
    await t.pumpAndSettle();
    expect(api.posts, 1);
    expect(api.bodies.single, {
      'period': '2026-01', 'claim_ids': [7], 'total_minor': 1250,
      'consent': true,
    });
    expect(find.textContaining('Statement not confirmed.'), findsOneWidget);
    expect(find.textContaining('TEST statement #12 confirmed'), findsNothing);
    api.forgeIssue = false;
    await t.ensureVisible(button);
    await t.tap(button);
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm TEST issue'));
    await t.pumpAndSettle();
    expect(api.posts, 2);
    expect(api.bodies[1], api.bodies[0]);
    expect(find.text('TEST statement #12 confirmed. No payment collected.'), findsOneWidget);
    expect(button, findsNothing);
  });

  testWidgets('revoked session cannot confirm an old reviewed TEST batch', (t) async {
    final api = CycleApi()..canIssue = true;
    addTearDown(api.close);
    await t.pumpWidget(reviewApp(api));
    await t.pumpAndSettle();
    await t.ensureVisible(find.text('Issue reviewed TEST statement'));
    await t.tap(find.text('Issue reviewed TEST statement'));
    await t.pumpAndSettle();
    api.revoke();
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm TEST issue'));
    await t.pumpAndSettle();
    expect(api.posts, 0);
    expect(find.text('Issue reviewed TEST statement'), findsNothing);
  });

  testWidgets('stale manager eligibility does not dispatch private review', (t) async {
    final api = CycleApi()..manager = false;
    addTearDown(api.close);
    await t.pumpWidget(reviewApp(api));
    await t.pumpAndSettle();
    expect(api.reviewReads, 0);
    expect(find.text('Private review unavailable.'), findsOneWidget);
    expect(find.text('Proposed total: KWD 1.250'), findsNothing);
  });

  testWidgets('session change drops a delayed private review result', (t) async {
    final api = CycleApi()..delayed = Completer<dynamic>();
    addTearDown(api.close);
    await t.pumpWidget(reviewApp(api));
    await t.pump();
    expect(api.reviewReads, 1);
    api.revoke();
    api.delayed!.complete(reviewSource());
    await t.pumpAndSettle();
    expect(find.text('Proposed total: KWD 1.250'), findsNothing);
    expect(find.text('Refresh cycle review'), findsNothing);
    expect(api.posts, 0);
  });

  testWidgets('current read expiry clears private review and requests sign-in', (t) async {
    final api = CycleApi()..expireRead = true;
    addTearDown(api.close);
    var expirations = 0;
    await t.pumpWidget(reviewApp(api, onExpired: () { expirations++; }));
    await t.pumpAndSettle();
    expect(expirations, 1);
    expect(find.text('Proposed total: KWD 1.250'), findsNothing);
    expect(api.posts, 0);
  });

  testWidgets('current issue expiry requests sign-in without confirming a statement', (t) async {
    final api = CycleApi()..canIssue = true..expireIssue = true;
    addTearDown(api.close);
    var expirations = 0;
    await t.pumpWidget(reviewApp(api, onExpired: () { expirations++; }));
    await t.pumpAndSettle();
    await t.ensureVisible(find.text('Issue reviewed TEST statement'));
    await t.tap(find.text('Issue reviewed TEST statement'));
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm TEST issue'));
    await t.pumpAndSettle();
    expect(api.posts, 1);
    expect(expirations, 1);
    expect(find.textContaining('TEST statement #'), findsNothing);
    expect(find.text('Issue reviewed TEST statement'), findsNothing);
  });

  testWidgets('superseded expiry cannot sign out another generation', (t) async {
    final api = CycleApi()..delayed = Completer<dynamic>();
    addTearDown(api.close);
    var expirations = 0;
    await t.pumpWidget(reviewApp(api, onExpired: () { expirations++; }));
    await t.pump();
    expect(api.reviewReads, 1);
    api.revoke();
    final expiredGeneration = api.epoch;
    api.revoke();
    api.delayed!.completeError(ApiException(401, 'Old expiry',
      expirationIsCurrent: () => api.epoch == expiredGeneration));
    await t.pumpAndSettle();
    expect(expirations, 0);
    expect(find.text('Proposed total: KWD 1.250'), findsNothing);
  });

  testWidgets('Arabic review clears on background and fails closed on forged refresh', (t) async {
    final api = CycleApi();
    addTearDown(api.close);
    await t.pumpWidget(reviewApp(api, ar: true));
    await t.pumpAndSettle();
    expect(find.text('الإجمالي المقترح: KWD 1.250'), findsOneWidget);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pump();
    expect(find.text('الإجمالي المقترح: KWD 1.250'), findsNothing);
    expect(api.reviewReads, 1);
    api.malformed = true;
    await t.tap(find.text('تحديث مراجعة الدورة'));
    await t.pumpAndSettle();
    expect(api.reviewReads, 2);
    expect(find.text('المراجعة الخاصة غير متاحة.'), findsOneWidget);
    expect(find.text('الإجمالي المقترح: KWD 1.250'), findsNothing);
    expect(t.takeException(), isNull);
  });
}
