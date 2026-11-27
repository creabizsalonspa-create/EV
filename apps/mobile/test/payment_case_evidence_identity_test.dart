import 'dart:async';
import 'dart:convert';
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
  int identity = 0, reads = 0, downloads = 0, uploads = 0;
  final events = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => events.stream;
  void revoke() { identity++; events.add(identity); }
  Completer<dynamic>? delayedEvidence, delayedUpload;
  Completer<PrivateDocument>? delayedDocument;
  final Uint8List bytes;
  final String mime;
  FencedCaseApi({Uint8List? imageBytes})
      : bytes = imageBytes ?? Uint8List.fromList('%PDF-1.4\nFenced fixture'.codeUnits),
        mime = imageBytes == null ? 'application/pdf' : 'image/png';

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
        'mime_type': mime,
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
    return PrivateDocument(bytes, mime);
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
    final pending = delayedUpload;
    delayedUpload = null;
    if (pending != null) return pending.future;
    return records['data']!.first;
  }
}

Widget evidenceScreen(FencedCaseApi api, SessionCaseStore store, {
  Future<VendorEvidence?> Function()? picker,
}) => MaterialApp(home: CaseEvidenceView(
  api: api,
  onExpired: () {},
  caseId: 7,
  store: store,
  picker: picker,
));

Future<void> tapVisible(WidgetTester tester, String label) async {
  await tester.scrollUntilVisible(
    find.text(label),
    180,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.ensureVisible(find.text(label));
  await tester.pump();
  await tester.tap(find.text(label));
  await tester.pump();
}

void main() {
  testWidgets('delayed private evidence cannot reveal former account', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedEvidence = waiting;
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pump();
    api.revoke();
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
    await tapVisible(tester, 'Choose PDF, PNG or JPEG up to4 MiB');
    api.revoke();
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
    await tapVisible(tester, 'View verified private file');
    expect(api.downloads, 1);
    api.revoke();
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
  testWidgets('system document picker temporarily inactivates without losing verified selection', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<VendorEvidence?>();
    addTearDown(() => tester.binding
        .handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(evidenceScreen(api, store, picker: () => waiting.future));
    await tester.pumpAndSettle();
    expect(api.reads, 1);
    await tapVisible(tester, 'Choose PDF, PNG or JPEG up to4 MiB');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.text('View verified private file'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    waiting.complete(VendorEvidence(api.bytes, 'application/pdf'));
    await tester.pumpAndSettle();
    expect(find.textContaining('application/pdf ·'), findsOneWidget);
    expect(api.reads, 1);
    expect(store.writes, 0);
  });

  testWidgets('system picker is discarded on true background transition', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<VendorEvidence?>();
    addTearDown(() => tester.binding
        .handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(evidenceScreen(api, store, picker: () => waiting.future));
    await tester.pumpAndSettle();
    await tapVisible(tester, 'Choose PDF, PNG or JPEG up to4 MiB');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    waiting.complete(VendorEvidence(api.bytes, 'application/pdf'));
    await tester.pumpAndSettle();
    expect(find.textContaining('application/pdf ·'), findsNothing);
    expect(find.text('View verified private file'), findsNothing);
    expect(api.uploads, 0);
    expect(store.writes, 0);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('View verified private file'), findsOneWidget);
  });

  testWidgets('late uploaded evidence receipt cannot confirm or expose another identity', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedUpload = waiting;
    await tester.pumpWidget(evidenceScreen(
      api, store,
      picker: () async => VendorEvidence(api.bytes, 'application/pdf'),
    ));
    await tester.pumpAndSettle();
    await tapVisible(tester, 'Choose PDF, PNG or JPEG up to4 MiB');
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pump();
    await tapVisible(tester, 'Upload private evidence');
    await tester.pump(const Duration(milliseconds: 100));
    expect(api.uploads, 1);
    expect(store.writes, 1);
    api.revoke();
    waiting.complete(api.records['data']!.first);
    await tester.pumpAndSettle();
    expect(store.entries.length, 1,
        reason: 'Old owner intent must not be acknowledged by the next account');
    expect(find.text('Upload private evidence'), findsNothing);
    expect(find.text('View verified private file'), findsNothing);
  });

  testWidgets('idle evidence route redacts immediately on session revocation', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pumpAndSettle();
    final action = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'View verified private file'),
    ).onPressed!;
    api.revoke();
    action();
    await tester.pumpAndSettle();
    expect(find.text('View verified private file'), findsNothing);
    expect(find.byType(VendorDocumentPreview), findsNothing);
    expect(api.downloads, 0);
  });

  testWidgets('stale evidence callback cannot download after background refresh', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    addTearDown(() => tester.binding
        .handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pumpAndSettle();
    final action = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'View verified private file'),
    ).onPressed!;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    action();
    await tester.pumpAndSettle();
    expect(api.downloads, 0);
    expect(api.reads, 2);
    expect(find.byType(VendorDocumentPreview), findsNothing);
  });

  testWidgets('open private viewer retires when underlying case target changes', (
    tester,
  ) async {
    final api = FencedCaseApi(imageBytes: base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=',
    ));
    final store = SessionCaseStore();
    final target = ValueNotifier<int>(7);
    await tester.pumpWidget(MaterialApp(
      home: ValueListenableBuilder<int>(
        valueListenable: target,
        builder: (_, id, _) => CaseEvidenceView(
          api: api, onExpired: () {}, caseId: id, store: store,
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tapVisible(tester, 'View verified private file');
    await tester.pumpAndSettle();
    expect(find.byType(VendorDocumentPreview), findsOneWidget);
    expect(api.downloads, 1);
    target.value = 9;
    await tester.pumpAndSettle();
    expect(find.byType(VendorDocumentPreview), findsNothing);
    expect(find.byType(InteractiveViewer), findsNothing);
    expect(api.downloads, 1);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
    target.dispose();
  });

  testWidgets('malformed stored evidence metadata cannot enable a file upload', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final scope = '${api.base.origin}:1:case-evidence:7';
    store.entries[scope] = {
      'key': 'retained-fixture-identity',
      'body': {
        'kind': 'service_record',
        'mime_type': 'application/pdf',
        'size_bytes': -1,
        'sha256': sha256.convert(api.bytes).toString(),
      },
    };
    await tester.pumpWidget(evidenceScreen(api, store));
    await tester.pumpAndSettle();
    expect(find.text('Upload private evidence'), findsNothing);
    expect(find.text('View verified private file'), findsNothing);
    expect(store.entries.containsKey(scope), true);
    expect(api.uploads, 0);
    expect(store.writes, 0);
  });
}
