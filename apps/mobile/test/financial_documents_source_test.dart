import 'dart:async';
import 'dart:typed_data';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_documents.dart';
import 'package:creabiz_ev/vendor_company.dart' show VendorDocumentPreview;
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

final testPdf = Uint8List.fromList('%PDF-1.4\n%%EOF'.codeUnits);

Map<String, dynamic> financialRecord({
  String kind = 'charging_invoice',
  int source = 7,
  String language = 'en',
  String? digest,
}) => {
  'id': 41,
  'source_id': source,
  'source_kind': kind,
  'number': 'CB-TDOC-AE-P-2026-000000041',
  'locale': language,
  'pdf_sha256': digest ?? sha256.convert(testPdf).toString(),
  'legal_status': 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED',
  'external_provider_verified': false,
};

class DocumentApi extends FixtureApi {
  int stamp = 0, requests = 0, posts = 0, downloads = 0;
  bool forgedSource = false;
  Completer<dynamic>? listHeld, postHeld;
  Completer<PrivateDocument>? pdfHeld;
  @override
  bool Function() sessionFence() {
    final now = stamp;
    return () => now == stamp;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requests++;
    calls.add('$method $path');
    if (path != 'financial/documents') {
      throw StateError('Unexpected financial source request');
    }
    if (method == 'POST') {
      posts++;
      return postHeld?.future ?? financialRecord(
        kind: body!['source_kind'] as String,
        source: body['source_id'] as int,
        language: body['locale'] as String,
      );
    }
    if (method != 'GET') throw StateError('Unexpected financial mutation');
    if (listHeld != null) return listHeld!.future;
    return {
      'data': [financialRecord(
        kind: forgedSource ? 'vendor_settlement' : 'charging_invoice',
      )],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }

  @override
  Future<PrivateDocument> downloadDocument(String path) async {
    downloads++;
    calls.add('GET $path');
    return pdfHeld?.future ?? PrivateDocument(testPdf, 'application/pdf');
  }
}

Widget app(DocumentApi api, {String? kind, int? source}) => MaterialApp(
  home: FinancialDocumentsView(
    api: api, onExpired: () {}, sourceKind: kind, sourceId: source,
  ),
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  test('private PDF record validates TEST evidence and literal original checksum', () {
    final record = FinancialDocumentRecord.fromJson(financialRecord());
    record.verify(PrivateDocument(testPdf, 'application/pdf'));
    expect(() => record.verify(PrivateDocument(
      Uint8List.fromList('%PDF-spoof'.codeUnits), 'application/pdf',
    )), throwsA(isA<ApiException>()));
    expect(() => FinancialDocumentRecord.fromJson({
      ...financialRecord(), 'legal_status': 'LEGALLY_APPROVED',
    }), throwsFormatException);
    for (final forged in [
      {...financialRecord(), 'id': 9007199254740992},
      {...financialRecord(), 'source_id': 9007199254740992},
      {...financialRecord(), 'number': '=HYPERLINK("token")'},
      {...financialRecord(), 'number': 'CB-TDOC-US-V0-2026-000000041'},
      {...financialRecord(), 'external_provider_verified': true},
    ]) {
      expect(
        () => FinancialDocumentRecord.fromJson(forged),
        throwsFormatException,
      );
    }
  });

  testWidgets('private source listing validates exact requested kind and source',
      (tester) async {
    final api = DocumentApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(api, kind: 'charging_invoice', source: 7));
    await tester.pumpAndSettle();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsOneWidget);
    expect(api.requests, 1);
    api.forgedSource = true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsNothing);
    expect(find.byType(OutlinedButton), findsWidgets);
    expect(api.posts, 0);
  });

  testWidgets('already rendered private PDF list vanishes immediately on sign-out event',
      (tester) async {
    final api = DocumentApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsOneWidget);
    expect(find.text('View private PDF'), findsOneWidget);
    final reads = api.requests;
    // No request is pending, and the API role is not changed by a widget
    // rebuild. The signal must repaint without a synthetic UI trigger.
    await api.forgetSession();
    await tester.pump();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsNothing);
    expect(find.text('View private PDF'), findsNothing);
    expect(find.text(financialEn['privateCleared']!), findsOneWidget);
    expect(api.requests, reads);
  });

  testWidgets('old-account list reply cannot display another customer document',
      (tester) async {
    final api = DocumentApi()..listHeld = Completer<dynamic>();
    addTearDown(api.close);
    await tester.pumpWidget(app(api));
    await tester.pump();
    expect(api.requests, 1);
    api.stamp++;
    api.listHeld!.complete({
      'data': [financialRecord()], 'current_page': 1, 'last_page': 1, 'total': 1,
    });
    await tester.pumpAndSettle();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsNothing);
    expect(find.text(financialEn['privateCleared']!), findsOneWidget);
  });

  testWidgets('initially inactive financial documents issue no private request',
      (tester) async {
    final api = DocumentApi();
    addTearDown(api.close);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(api.requests, 0);
    expect(find.text(financialEn['privateCleared']!), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.requests, 0);
  });

  testWidgets('financial documents vanish on background without auto-reload',
      (tester) async {
    final api = DocumentApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsOneWidget);
    final reads = api.requests;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsNothing);
    expect(find.text(financialEn['privateCleared']!), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.requests, reads);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.requests, reads + 1);
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsOneWidget);
  });

  testWidgets('revoked session while private PDF streams never opens viewer',
      (tester) async {
    final api = DocumentApi()..pdfHeld = Completer<PrivateDocument>();
    addTearDown(api.close);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('View private PDF'),
      180, scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('View private PDF'));
    await tester.pump();
    expect(api.downloads, 1);
    api.stamp++;
    api.pdfHeld!.complete(PrivateDocument(testPdf, 'application/pdf'));
    await tester.pumpAndSettle();
    expect(find.byType(VendorDocumentPreview), findsNothing);
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsNothing);
    expect(find.text(financialEn['privateCleared']!), findsOneWidget);
  });

  testWidgets('uncertain document creation is not auto-retried after background',
      (tester) async {
    final api = DocumentApi()..postHeld = Completer<dynamic>();
    addTearDown(api.close);
    await tester.pumpWidget(app(api, kind: 'charging_invoice', source: 7));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Prepare document').first);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    final buttons = find.widgetWithText(FilledButton, 'Prepare document');
    await tester.ensureVisible(buttons);
    await tester.tap(buttons);
    await tester.pump();
    expect(api.posts, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    api.postHeld!.complete(financialRecord());
    await tester.pumpAndSettle();
    expect(find.text('CB-TDOC-AE-P-2026-000000041'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.posts, 1);
    expect(find.text('Private financial documents'), findsOneWidget);
  });
}
