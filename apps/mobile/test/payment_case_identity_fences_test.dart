import 'dart:async';
import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/payment_cases.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/vendor_company.dart';
import 'test_support.dart';

class SessionCaseStore implements FinancialOperationStore {
  final entries = <String, Map<String, dynamic>>{};
  int reads = 0, writes = 0;

  @override
  Future<Map<String, dynamic>?> read(String scope) async {
    reads++;
    return entries[scope];
  }

  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    writes++;
    entries[scope] = value;
  }

  @override
  Future<void> remove(String scope) async {
    entries.remove(scope);
  }
}

class FencedCaseApi extends FixtureApi {
  int identity = 0, posts = 0, reads = 0, downloads = 0, uploads = 0;
  Completer<dynamic>? delayedInvoice, delayedEvidence, delayedPost;
  Completer<PrivateDocument>? delayedDocument;
  final bytes = Uint8List.fromList('%PDF-1.4\nFenced fixture'.codeUnits);

  @override
  bool Function() sessionFence() {
    final epoch = identity;
    return () => epoch == identity;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') return {'id': 1, 'role': 'customer'};
    if (path == 'finance/invoices/3') {
      final waiting = delayedInvoice;
      delayedInvoice = null;
      if (waiting != null) return waiting.future;
      return {
        'invoice': {
          'id': 3,
          'user_id': 1,
          'minor_units': 2,
          'currency': 'AED',
        },
        'real_payment_processed': false,
        'net_collected_minor': 300,
      };
    }
    if (method == 'POST' && path == 'commercial/cases') {
      posts++;
      final waiting = delayedPost;
      delayedPost = null;
      if (waiting != null) return waiting.future;
      return {
        ...body!,
        'id': 7,
        'user_id': 1,
        'status': 'open',
        'real_money_moved': false,
        'evidence': 'MOCK_PROVIDER',
      };
    }
    if (path == 'commercial/cases/7/evidence') {
      reads++;
      final waiting = delayedEvidence;
      delayedEvidence = null;
      if (waiting != null) return waiting.future;
      return records;
    }
    throw StateError('Unexpected financial fixture request: $method $path');
  }

  Map<String, dynamic> get records => {
    'data': [
      {
        'id': 5,
        'case_id': 7,
        'kind': 'service_record',
        'created_at': '2026-11-23',
        'mime_type': 'application/pdf',
        'size_bytes': bytes.length,
        'sha256': sha256.convert(bytes).toString(),
        'evidence_status': 'PRIVATE_REVIEW_ONLY',
        'provider_submitted': false,
      },
    ],
    'current_page': 1,
    'last_page': 1,
    'total': 1,
    'can_upload': true,
  };

  @override
  Future<PrivateDocument> downloadDocument(String path) async {
    downloads++;
    final waiting = delayedDocument;
    delayedDocument = null;
    if (waiting != null) return waiting.future;
    return PrivateDocument(bytes, 'application/pdf');
  }

  @override
  Future<dynamic> uploadCaseEvidence(
    int caseId, {
    required String kind,
    required Uint8List bytes,
    required String mime,
    required String key,
  }) async {
    uploads++;
    return records['data']!.first;
  }
}

Widget caseScreen(FencedCaseApi api, SessionCaseStore store) =>
    MaterialApp(home: CreatePaymentCaseView(
      api: api,
      onExpired: () {},
      invoiceId: 3,
      store: store,
    ));

Widget evidenceScreen(FencedCaseApi api, SessionCaseStore store, {
  Future<VendorEvidence?> Function()? picker,
}) => MaterialApp(home: CaseEvidenceView(
  api: api,
  onExpired: () {},
  caseId: 7,
  store: store,
  picker: picker,
));

void main() {
  testWidgets('stale invoice cannot initialize a new identity payment case', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedInvoice = waiting;
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pump();
    api.identity++;
    waiting.complete({
      'invoice': {
        'id': 3, 'user_id': 1, 'minor_units': 2, 'currency': 'AED',
      },
      'real_payment_processed': false,
      'net_collected_minor': 300,
    });
    await tester.pumpAndSettle();
    expect(find.byType(CheckboxListTile), findsNothing);
    expect(store.reads, 0);
    expect(api.posts, 0);
  });

  testWidgets('late case receipt cannot confirm old identity request', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedPost = waiting;
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '1.25');
    await tester.enterText(find.byType(TextField).last, 'Disputed captured amount');
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Confirm saved request'));
    await tester.tap(find.text('Confirm saved request'));
    await tester.pump();
    expect(api.posts, 1);
    expect(store.writes, 1);
    api.identity++;
    waiting.complete({
      'id': 7, 'invoice_id': 3, 'user_id': 1, 'kind': 'dispute',
      'amount_minor': 125, 'reason': 'Disputed captured amount',
      'status': 'open', 'real_money_moved': false, 'evidence': 'MOCK_PROVIDER',
    });
    await tester.pump();
    expect(find.text('Server confirmed. Refresh authoritative history. No provider submission or real refund is claimed.'), findsNothing);
    expect(store.entries.length, 1);
  });

  testWidgets('delayed private evidence cannot reveal former account', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedEvidence = waiting;
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pump();
    api.identity++;
    waiting.complete(api.records);
    await tester.pumpAndSettle();
    expect(find.text('View verified private file'), findsNothing);
    expect(find.byType(FilledButton), findsNothing);
    expect(store.reads, 0);
  });

  testWidgets('selected private file is discarded after account switch', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<VendorEvidence?>();
    await tester.pumpWidget(evidenceScreen(api, store, picker: () => waiting.future));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Choose PDF, PNG or JPEG up to4 MiB'));
    await tester.pump();
    api.identity++;
    waiting.complete(VendorEvidence(api.bytes, 'application/pdf'));
    await tester.pumpAndSettle();
    expect(find.textContaining('application/pdf ·'), findsNothing);
    expect(find.text('Upload private evidence'), findsNothing);
    expect(api.uploads, 0);
  });

  testWidgets('old account downloaded private PDF never opens after switch', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<PrivateDocument>();
    api.delayedDocument = waiting;
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('View verified private file'));
    await tester.pump();
    expect(api.downloads, 1);
    api.identity++;
    waiting.complete(PrivateDocument(api.bytes, 'application/pdf'));
    await tester.pumpAndSettle();
    expect(find.byType(VendorDocumentPreview), findsNothing);
    expect(find.text('View verified private file'), findsNothing);
  });

  testWidgets('inactive state forgets private evidence until explicit refresh', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    addTearDown(() => tester.binding
        .handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pumpAndSettle();
    expect(find.text('View verified private file'), findsOneWidget);
    expect(api.reads, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.text('View verified private file'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads, 1);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.reads, 2);
    expect(find.text('View verified private file'), findsOneWidget);
  });
}
