import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/payment_cases.dart';
import 'package:creabiz_ev/vendor_company.dart';
import 'test_support.dart';
import 'vendor_finance_test.dart' show VendorOperationStore;

class CaseApi extends FixtureApi {
  bool lost = false,
      deny = false,
      tampered = false,
      closed = false,
      unpaid = false;
  int posts = 0, uploads = 0, downloads = 0;
  final keys = <String>[];
  final bodies = <Map<String, dynamic>>[];
  final bytes = Uint8List.fromList(
    '%PDF-1.4\nSynthetic private file'.codeUnits,
  );
  Map<String, dynamic> get evidence => {
    'id': 8,
    'case_id': 7,
    'uploaded_by': 1,
    'kind': 'service_record',
    'mime_type': 'application/pdf',
    'size_bytes': bytes.length,
    'sha256': sha256.convert(bytes).toString(),
    'created_at': '2026-10-28',
    'evidence_status': 'PRIVATE_REVIEW_ONLY',
    'provider_submitted': false,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    if (deny) throw const ApiException(401, 'private-case-diagnostic');
    if (path == 'me') return {'id': 1, 'role': 'customer'};
    if (path == 'finance/invoices/3')
      return {
        'invoice': {'id': 3, 'user_id': 1, 'minor_units': 3, 'currency': 'KWD'},
        'real_payment_processed': false,
        'net_collected_minor': unpaid ? 0 : 2000,
      };
    if (method == 'POST') {
      posts++;
      bodies.add({...body!});
      if (lost) throw const ApiException(0, 'private-case-diagnostic');
      return {
        'id': 7,
        'user_id': 1,
        ...body,
        'evidence': 'MOCK_PROVIDER',
        'real_money_moved': false,
        'status': 'open',
      };
    }
    return {
      'data': path.endsWith('evidence')
          ? (uploads > 0 ? [evidence] : [])
          : [
              {
                'id': 7,
                'invoice_id': 3,
                'reason': 'Literal <script> dispute',
                'status': 'open',
                'evidence': 'MOCK_PROVIDER',
                'real_money_moved': false,
              },
            ],
      'can_upload': !closed,
      'current_page': int.parse(query['page'] ?? '1'),
      'last_page': 2,
      'total': 26,
    };
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
    keys.add(key);
    expect(caseId, 7);
    expect(kind, 'service_record');
    expect(bytes, this.bytes);
    if (lost) throw const ApiException(0, 'private-case-diagnostic');
    return evidence;
  }

  @override
  Future<PrivateDocument> downloadDocument(String path) async {
    downloads++;
    return PrivateDocument(
      tampered ? Uint8List.fromList('%PDF-TAMPERED'.codeUnits) : bytes,
      'application/pdf',
    );
  }
}

Widget app(Widget view, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: view,
);
Future<void> click(WidgetTester t, String label) async {
  await t.scrollUntilVisible(
    find.text(label),
    180,
    scrollable: find.byType(Scrollable).first,
  );
  await Scrollable.ensureVisible(t.element(find.text(label)), alignment: 0.5);
  await t.pumpAndSettle();
  expect(find.text(label).hitTestable(), findsOneWidget);
  await t.tap(find.text(label));
  await t.pumpAndSettle();
}

Future<void> consent(WidgetTester t) async {
  await t.ensureVisible(find.byType(CheckboxListTile));
  await t.tap(find.byType(CheckboxListTile));
  await t.pumpAndSettle();
}

void main() {
  testWidgets(
    'an invoice with no remaining captured funds offers no new dispute submission',
    (t) async {
      final api = CaseApi()..unpaid = true;
      await t.pumpWidget(
        app(
          CreatePaymentCaseView(
            api: api,
            onExpired: () {},
            invoiceId: 3,
            store: VendorOperationStore(),
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text(caseEn['noFunds']!), findsOneWidget);
      expect(
        t.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull,
      );
      expect(api.posts, 0);
    },
  );

  testWidgets(
    'resolved case retains private review but offers no new upload form',
    (t) async {
      final api = CaseApi()
        ..uploads = 1
        ..closed = true;
      await t.pumpWidget(
        app(
          CaseEvidenceView(
            api: api,
            onExpired: () {},
            caseId: 7,
            store: VendorOperationStore(),
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('View verified private file'), findsOneWidget);
      expect(find.byType(CheckboxListTile), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
      expect(api.uploads, 1);
    },
  );

  testWidgets(
    'unknown customer case creation freezes exact Arabic amount reason and identity through restart',
    (t) async {
      final api = CaseApi()..lost = true, store = VendorOperationStore();
      Widget screen() => app(
        CreatePaymentCaseView(
          api: api,
          onExpired: () {},
          invoiceId: 3,
          store: store,
        ),
      );
      await t.pumpWidget(screen());
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await t.enterText(find.byType(TextField).first, '١٫٢٥٠');
      await t.enterText(
        find.byType(TextField).last,
        'Synthetic customer dispute',
      );
      await consent(t);
      await click(t, 'Confirm saved request');
      expect(api.bodies.single['amount_minor'], 1250);
      expect(store.values.length, 1);
      final first = api.bodies.single;
      await t.pumpWidget(const SizedBox());
      await t.pumpWidget(screen());
      await t.pumpAndSettle();
      expect(api.posts, 1);
      api.lost = false;
      await consent(t);
      await click(t, 'Confirm saved request');
      expect(api.bodies.last, first);
      expect(store.values, isEmpty);
      expect(find.textContaining('private-case-diagnostic'), findsNothing);
    },
  );
  testWidgets(
    'private upload requires explicit file and consent then retains same fingerprint and key after lost acknowledgement',
    (t) async {
      final api = CaseApi()..lost = true, store = VendorOperationStore();
      Widget screen() => app(
        CaseEvidenceView(
          api: api,
          onExpired: () {},
          caseId: 7,
          store: store,
          picker: () async => VendorEvidence(api.bytes, 'application/pdf'),
        ),
      );
      await t.pumpWidget(screen());
      await t.pumpAndSettle();
      expect(api.uploads, 0);
      await click(t, 'Choose PDF, PNG or JPEG up to4 MiB');
      await consent(t);
      await click(t, 'Upload private evidence');
      expect(api.uploads, 1);
      expect(store.values.length, 1);
      await t.pumpWidget(const SizedBox());
      await t.pumpWidget(screen());
      await t.pumpAndSettle();
      expect(api.uploads, 1);
      expect(
        find.textContaining('select the same original file'),
        findsOneWidget,
      );
      await click(t, 'Choose PDF, PNG or JPEG up to4 MiB');
      await consent(t);
      api.lost = false;
      await click(t, 'Upload private evidence');
      expect(api.keys.last, api.keys.first);
      expect(store.values, isEmpty);
      expect(find.textContaining('private-case-diagnostic'), findsNothing);
    },
  );
  testWidgets(
    'different reselected bytes cannot replace the original pending evidence',
    (t) async {
      final api = CaseApi()..lost = true, store = VendorOperationStore();
      Widget screen(bool changed) => app(
        CaseEvidenceView(
          api: api,
          onExpired: () {},
          caseId: 7,
          store: store,
          picker: () async => VendorEvidence(
            changed
                ? Uint8List.fromList('%PDF-1.4\nDifferent'.codeUnits)
                : api.bytes,
            'application/pdf',
          ),
        ),
      );
      await t.pumpWidget(screen(false));
      await t.pumpAndSettle();
      await click(t, 'Choose PDF, PNG or JPEG up to4 MiB');
      await consent(t);
      await click(t, 'Upload private evidence');
      await t.pumpWidget(const SizedBox());
      await t.pumpWidget(screen(true));
      await t.pumpAndSettle();
      await click(t, 'Choose PDF, PNG or JPEG up to4 MiB');
      expect(api.uploads, 1);
      expect(store.values.length, 1);
      expect(
        t.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull,
      );
    },
  );
  testWidgets('tampered private evidence is rejected before any viewer opens', (
    t,
  ) async {
    final api = CaseApi()
      ..uploads = 1
      ..tampered = true;
    await t.pumpWidget(
      app(
        CaseEvidenceView(
          api: api,
          onExpired: () {},
          caseId: 7,
          store: VendorOperationStore(),
        ),
      ),
    );
    await t.pumpAndSettle();
    await click(t, 'View verified private file');
    expect(find.byType(VendorDocumentPreview), findsNothing);
    expect(t.takeException(), isNull);
  });
  testWidgets(
    'case history is paginated literal text and never submits a refund or provider evidence',
    (t) async {
      final api = CaseApi();
      await t.pumpWidget(app(PaymentCasesView(api: api, onExpired: () {})));
      await t.pumpAndSettle();
      expect(find.text('Literal <script> dispute'), findsOneWidget);
      await click(t, 'Next');
      expect(api.calls.where((p) => p.startsWith('POST')), isEmpty);
      expect(api.posts, 0);
      expect(api.uploads, 0);
    },
  );
  testWidgets(
    'Arabic evidence consent fits390px and expiry clears private rows',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      final api = CaseApi()..uploads = 1;
      var expired = 0;
      await t.pumpWidget(
        app(
          CaseEvidenceView(
            api: api,
            onExpired: () => expired++,
            caseId: 7,
            store: VendorOperationStore(),
          ),
          ar: true,
        ),
      );
      await t.pumpAndSettle();
      expect(
        Directionality.of(t.element(find.byType(CheckboxListTile))),
        TextDirection.rtl,
      );
      expect(t.takeException(), isNull);
      api.deny = true;
      await click(t, 'تحديث');
      expect(expired, 1);
      expect(find.textContaining('private-case-diagnostic'), findsNothing);
      expect(find.text('عرض الملف الخاص المتحقق منه'), findsNothing);
    },
  );
  test(
    'case interface translations have parity',
    () => expect(caseEn.keys.toSet(), caseAr.keys.toSet()),
  );
}
