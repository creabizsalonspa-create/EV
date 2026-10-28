import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_documents.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/refund_requests.dart';
import 'package:creabiz_ev/loyalty_actions.dart';
import 'test_support.dart';

class MemoryOperations implements FinancialOperationStore {
  final values = <String, String>{};
  bool fail = false;
  @override
  Future<Map<String, dynamic>?> read(String scope) async =>
      values[scope] == null
      ? null
      : Map<String, dynamic>.from(jsonDecode(values[scope]!) as Map);
  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    if (fail) throw StateError('secure write failed');
    values[scope] = jsonEncode(value);
  }

  @override
  Future<void> remove(String scope) async {
    values.remove(scope);
  }
}

class FinancialApi extends FixtureApi {
  bool lost = false, deny = false;
  String actor = 'customer';
  int posts = 0;
  final bodies = <Map<String, dynamic>>[];
  Completer<dynamic>? pending;
  final bytes = Uint8List.fromList(utf8.encode('%PDF-synthetic'));
  Map<String, dynamic> get document => {
    'id': 9,
    'source_id': 3,
    'source_kind': 'charging_invoice',
    'number': 'TEST-PRIVATE-9',
    'locale': 'ar',
    'pdf_sha256': sha256.convert(bytes).toString(),
    'legal_status': 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED',
    'external_provider_verified': false,
    'private_path': 'secret-storage-path',
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (deny) throw const ApiException(401, 'secret-provider-field');
    if (pending != null) return pending!.future;
    if (path == 'me') return {'id': 1, 'role': actor};
    if (method == 'POST') {
      posts++;
      bodies.add({...body!});
      if (lost) throw const ApiException(0, 'secret-provider-field');
      if (path == 'financial/documents') return document;
      if (path.endsWith('/review'))
        return {
          'id': 8,
          'decision_note': body['note'],
          'status': body['decision'] == 'approve' ? 'approved' : 'rejected',
        };
      return {
        'id': 8,
        'invoice_id': 3,
        'amount_minor': body['amount_minor'],
        'reason': body['reason'],
        'request_key': body['idempotency_key'],
        'status': 'pending',
      };
    }
    return {
      'data': path == 'financial/documents'
          ? [document]
          : [
              {
                'id': 8,
                'invoice_id': 3,
                'amount_minor': 125,
                'reason': 'Literal <script> reason',
                'status': 'pending',
              },
            ],
      'current_page': int.parse(query['page'] ?? '1'),
      'last_page': 2,
      'total': 26,
    };
  }
}

class LoyaltyMutationApi extends FinancialApi {
  bool forged = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (!path.startsWith('commercial/loyalty/'))
      return super.request(path, method: method, body: body, query: query);
    expect(method, 'POST');
    posts++;
    bodies.add({...body!});
    if (lost) throw const ApiException(0, 'secret-provider-field');
    return {
      'account': {
        'unit': 'NON_MONETARY_POINTS',
        'cash_value': forged,
        'transferable': false,
        'evidence': 'TEST_PROMOTIONAL_ECONOMICS',
      },
      'redemption': {'id': 5, 'reward_id': 7, 'status': 'pending'},
    };
  }
}

Widget wrap(Widget child, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: child,
);
Future<void> confirm(WidgetTester t, String button) async {
  await t.ensureVisible(find.text(button).last);
  await t.tap(find.text(button).last);
  await t.pumpAndSettle();
  await t.tap(find.widgetWithText(FilledButton, 'Confirm').last);
  await t.pumpAndSettle();
}

void main() {
  test(
    'a stale pending screen retains its original request key after another screen confirms',
    () async {
      final store = MemoryOperations();
      final first = FinancialOperation(store, 'stale-owner-invoice');
      final second = FinancialOperation(store, 'stale-owner-invoice');
      final original = await first.prepare({'amount_minor': 1});
      await second.restore();
      await first.confirmed();
      final retry = await second.prepare({'amount_minor': 1});
      expect(retry['idempotency_key'], original['idempotency_key']);
      final restarted = FinancialOperation(store, 'stale-owner-invoice');
      await restarted.restore();
      expect(restarted.pending?['key'], original['idempotency_key']);
    },
  );
  test(
    'stale retry secure write failure blocks dispatch and preserves its identity',
    () async {
      final store = MemoryOperations();
      final first = FinancialOperation(store, 'stale-storage-failure');
      final stale = FinancialOperation(store, 'stale-storage-failure');
      final original = await first.prepare({'amount_minor': 1});
      await stale.restore();
      await first.confirmed();
      store.fail = true;
      await expectLater(stale.prepare({'amount_minor': 1}), throwsStateError);
      expect(stale.pending!['key'], original['idempotency_key']);
      store.fail = false;
      expect(
        (await stale.prepare({'amount_minor': 1}))['idempotency_key'],
        original['idempotency_key'],
      );
    },
  );
  test(
    'persisting a stale retry never overwrites another newer durable intent',
    () async {
      final store = MemoryOperations();
      final first = FinancialOperation(store, 'stale-newer-intent');
      final stale = FinancialOperation(store, 'stale-newer-intent');
      final original = await first.prepare({'amount_minor': 1});
      await stale.restore();
      await first.confirmed();
      final newer = FinancialOperation(store, 'stale-newer-intent');
      final next = await newer.prepare({'amount_minor': 2});
      expect(
        (await stale.prepare({'amount_minor': 1}))['idempotency_key'],
        original['idempotency_key'],
      );
      final restarted = FinancialOperation(store, 'stale-newer-intent');
      await restarted.restore();
      expect(restarted.pending!['key'], next['idempotency_key']);
      await stale.confirmed();
      expect(
        (await store.read('stale-newer-intent'))!['key'],
        next['idempotency_key'],
      );
    },
  );
  test(
    'nested financial selections are frozen before asynchronous storage',
    () async {
      final store = MemoryOperations();
      final op = FinancialOperation(store, 'nested-owner-agreement');
      final ids = [7];
      final future = op.prepare({'claim_ids': ids});
      ids.add(8);
      final payload = await future;
      expect(payload['claim_ids'], [7]);
      expect(
        () => (payload['claim_ids'] as List).add(9),
        throwsUnsupportedError,
      );
    },
  );

  testWidgets(
    'reward request has no automatic POST and resumes the same durable request after restart',
    (t) async {
      final api = LoyaltyMutationApi()..lost = true;
      final store = MemoryOperations();
      Widget view() => wrap(
        LoyaltyActionView(
          api: api,
          onExpired: () {},
          action: 'redeem',
          reward: 7,
          store: store,
        ),
      );
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await confirm(t, 'Confirm');
      expect(api.posts, 1);
      await t.pumpWidget(const MaterialApp(home: Text('closed')));
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      expect(api.posts, 1);
      api.lost = false;
      await confirm(t, 'Confirm');
      expect(api.bodies[0], api.bodies[1]);
      expect(store.values, isEmpty);
      expect(find.textContaining('Server confirmed'), findsOneWidget);
    },
  );
  testWidgets(
    'forged monetary reward receipt cannot clear the durable operation',
    (t) async {
      final api = LoyaltyMutationApi()..forged = true;
      final store = MemoryOperations();
      await t.pumpWidget(
        wrap(
          LoyaltyActionView(
            api: api,
            onExpired: () {},
            action: 'redeem',
            reward: 7,
            store: store,
          ),
        ),
      );
      await t.pumpAndSettle();
      await confirm(t, 'Confirm');
      expect(store.values.length, 1);
      expect(find.textContaining('Server confirmed'), findsNothing);
    },
  );
  testWidgets(
    'invoice points claim accepts exact Arabic invoice identifiers without computing points',
    (t) async {
      final api = LoyaltyMutationApi();
      await t.pumpWidget(
        wrap(
          LoyaltyActionView(
            api: api,
            onExpired: () {},
            action: 'earn',
            store: MemoryOperations(),
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField), '٧');
      await confirm(t, 'Confirm');
      expect(api.bodies.single['invoice_id'], 7);
      expect(api.bodies.single.containsKey('points'), false);
    },
  );

  test(
    'concurrent screens share one persisted identity and late receipts cannot erase a newer request',
    () async {
      final store = MemoryOperations();
      final first = FinancialOperation(store, 'concurrent-owner-invoice');
      final second = FinancialOperation(store, 'concurrent-owner-invoice');
      final bodies = await Future.wait([
        first.prepare({'amount_minor': 1}),
        second.prepare({'amount_minor': 1}),
      ]);
      expect(bodies[0], bodies[1]);
      await first.confirmed();
      final third = FinancialOperation(store, 'concurrent-owner-invoice');
      final next = await third.prepare({'amount_minor': 2});
      expect(next['idempotency_key'], isNot(bodies[0]['idempotency_key']));
      await second.confirmed();
      expect(
        (await store.read('concurrent-owner-invoice'))!['key'],
        next['idempotency_key'],
      );
    },
  );

  test(
    'operation identity survives recreation and rejects changed payload, owner scopes remain separate',
    () async {
      final store = MemoryOperations();
      final one = FinancialOperation(store, 'https://one:1:refund:3');
      await one.restore();
      final body = await one.prepare({
        'amount_minor': 125,
        'reason': 'Synthetic reason',
      });
      final restored = FinancialOperation(store, 'https://one:1:refund:3');
      await restored.restore();
      expect(
        await restored.prepare({
          'amount_minor': 125,
          'reason': 'Synthetic reason',
        }),
        body,
      );
      await expectLater(
        restored.prepare({'amount_minor': 126, 'reason': 'Synthetic reason'}),
        throwsA(isA<ApiException>()),
      );
      final other = FinancialOperation(store, 'https://one:2:refund:3');
      await other.restore();
      expect(other.pending, isNull);
      await restored.confirmed();
      expect(store.values, isEmpty);
    },
  );
  test(
    'secure write failure cannot produce a dispatchable operation identity',
    () async {
      final op = FinancialOperation(MemoryOperations()..fail = true, 'owner:1');
      await expectLater(op.prepare({'amount_minor': 1}), throwsStateError);
      expect(op.pending, isNull);
    },
  );
  test(
    'financial PDF rejects corruption, wrong MIME and forged legal/provider evidence',
    () {
      final api = FinancialApi();
      final doc = FinancialDocumentRecord.fromJson(api.document);
      doc.verify(PrivateDocument(api.bytes, 'application/pdf'));
      expect(
        () => doc.verify(
          PrivateDocument(Uint8List.fromList([1, 2]), 'application/pdf'),
        ),
        throwsA(isA<ApiException>()),
      );
      expect(
        () => FinancialDocumentRecord.fromJson({
          ...api.document,
          'external_provider_verified': true,
        }),
        throwsFormatException,
      );
    },
  );
  testWidgets(
    'document pages render allowlisted private metadata and paginate without generating on refresh',
    (t) async {
      final api = FinancialApi();
      await t.pumpWidget(
        wrap(FinancialDocumentsView(api: api, onExpired: () {})),
      );
      await t.pumpAndSettle();
      expect(find.text('TEST-PRIVATE-9'), findsOneWidget);
      expect(find.textContaining('secret-storage-path'), findsNothing);
      await t.ensureVisible(find.text('Next'));
      await t.tap(find.text('Next'));
      await t.pumpAndSettle();
      expect(find.text('2 / 2'), findsOneWidget);
      expect(api.posts, 0);
    },
  );
  testWidgets(
    'lost refund acknowledgement persists frozen exact Arabic input and explicit retry uses the same key',
    (t) async {
      final api = FinancialApi()..lost = true;
      final store = MemoryOperations();
      Widget view() => wrap(
        RefundRequestView(
          api: api,
          onExpired: () {},
          invoiceId: 3,
          digits: 2,
          currency: 'AED',
          store: store,
        ),
      );
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField).at(0), '١٫٢٥');
      await t.enterText(
        find.byType(TextField).at(1),
        'Synthetic refund reason',
      );
      await confirm(t, 'Submit request');
      expect(api.posts, 1);
      expect(api.bodies.single['amount_minor'], 125);
      expect(store.values.length, 1);
      await t.pumpWidget(const MaterialApp(home: Text('closed')));
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      expect(api.posts, 1);
      expect(t.widget<TextField>(find.byType(TextField).first).readOnly, true);
      api.lost = false;
      await confirm(t, 'Resume the same request');
      expect(api.posts, 2);
      expect(api.bodies[1], api.bodies[0]);
      expect(store.values, isEmpty);
      expect(find.textContaining('Request confirmed.'), findsOneWidget);
    },
  );
  testWidgets(
    'storage failure blocks refund POST and raw diagnostics are hidden',
    (t) async {
      final api = FinancialApi();
      await t.pumpWidget(
        wrap(
          RefundRequestView(
            api: api,
            onExpired: () {},
            invoiceId: 3,
            digits: 2,
            currency: 'AED',
            store: MemoryOperations()..fail = true,
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField).first, '1.25');
      await t.enterText(find.byType(TextField).last, 'Synthetic reason');
      await confirm(t, 'Submit request');
      expect(api.posts, 0);
      expect(find.textContaining('secret-provider-field'), findsNothing);
    },
  );
  testWidgets(
    'vendor decision restores same immutable intent after uncertain transport',
    (t) async {
      final api = FinancialApi()
        ..actor = 'vendor'
        ..lost = true;
      final store = MemoryOperations();
      Widget view() => wrap(
        RefundDecisionView(
          api: api,
          onExpired: () {},
          requestId: 8,
          store: store,
        ),
      );
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField), 'Synthetic rejection');
      await confirm(t, 'Confirm');
      expect(api.posts, 1);
      await t.pumpWidget(const MaterialApp(home: Text('closed')));
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      api.lost = false;
      await confirm(t, 'Resume the same request');
      expect(api.bodies[1], api.bodies[0]);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'Arabic refund history fits390px and status is not treated as paid money',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(
        wrap(
          RefundRequestsView(
            api: FinancialApi(),
            onExpired: () {},
            role: 'customer',
          ),
          ar: true,
        ),
      );
      await t.pumpAndSettle();
      expect(find.textContaining('بانتظار المراجعة'), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );
  testWidgets('disposed financial reads cannot expire the next identity', (
    t,
  ) async {
    final api = FinancialApi()..pending = Completer<dynamic>();
    int expired = 0;
    await t.pumpWidget(
      wrap(FinancialDocumentsView(api: api, onExpired: () => expired++)),
    );
    await t.pump();
    await t.pumpWidget(const MaterialApp(home: Text('new identity')));
    api.pending!.completeError(
      const ApiException(401, 'secret-provider-field'),
    );
    await t.pumpAndSettle();
    expect(expired, 0);
  });
}
