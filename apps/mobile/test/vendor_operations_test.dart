import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_inventory.dart';
import 'package:creabiz_ev/vendor_company.dart';
import 'package:creabiz_ev/vendor_forms.dart';
import 'package:creabiz_ev/vendor_operations_strings.dart';
import 'test_support.dart';

final png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=',
);

class VendorApi extends FixtureApi {
  bool lost = false, denied = false, owner = true;
  String status = 'approved';
  int uploads = 0, posts = 0;
  final writes = <Map<String, dynamic>>[];
  Map<String, dynamic> get company => {
    'id': 1,
    'legal_name': 'Canonical operator',
    'country': 'AE',
    'status': status,
    'license_expires_on': '2030-12-31',
    'submission_readiness': {
      'editable': ['draft', 'changes_requested', 'rejected'].contains(status),
      'license_valid': true,
      'trade_license_present': uploads > 0,
    },
    'capabilities': {
      'owner': owner,
      'company_read': true,
      'private_evidence': owner,
      'inventory_read': true,
      'inventory_write': owner,
      'inventory_operational': status == 'approved',
    },
  };
  Map<String, dynamic> page(List<dynamic> items) => {
    'data': items,
    'current_page': 1,
    'last_page': 1,
    'total': items.length,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    if (denied) throw const ApiException(401, 'private server diagnostic');
    if (method != 'GET') {
      posts++;
      if (path.endsWith('/submit')) status = 'submitted';
      writes.add({'path': path, 'method': method, ...?body});
      if (lost) throw const ApiException(0, 'private server diagnostic');
      return {'id': 9, ...?body};
    }
    if (path == 'regions')
      return [
        {'country': 'AE', 'timezone': 'Asia/Dubai'},
      ];
    if (path == 'vendor-ops/companies') return page([company]);
    if (path == 'vendor-ops/companies/1') return company;
    if (path.endsWith('catalog-stations'))
      return page([
        {
          'id': 10,
          'name': 'Owned canonical station',
          'country': 'AE',
          'timezone': 'Asia/Dubai',
          'latitude': 25.2,
          'longitude': 55.3,
        },
      ]);
    if (path.endsWith('/documents'))
      return page(
        uploads == 0
            ? []
            : [
                {
                  'id': 5,
                  'kind': 'trade_license',
                  'mime_type': 'image/png',
                  'size_bytes': png.length,
                  'created_at': '2026-10-28',
                },
              ],
      );
    if (path == 'vendor-ops/staff-invitations')
      return page([
        {
          'id': 7,
          'company': {'legal_name': 'Canonical operator'},
          'status': 'invited',
          'permissions': ['inventory.read'],
          'invitation_version': 3,
        },
      ]);
    return page([]);
  }

  @override
  Future<dynamic> uploadDocument(
    String path, {
    required String kind,
    required Uint8List bytes,
    required String mime,
    required String key,
  }) async {
    uploads++;
    writes.add({'path': path, 'kind': kind, 'key': key});
    if (lost) throw const ApiException(0, 'private server diagnostic');
    return {'id': 5};
  }

  @override
  Future<PrivateDocument> downloadDocument(String path) async =>
      PrivateDocument(png, 'image/png');
}

Widget host(Widget child, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: child,
);
void main() {
  test(
    'vendor strings, date validation, Arabic numeric input and evidence signatures are explicit',
    () {
      expect(vendorOpsEn.keys.toSet(), vendorOpsAr.keys.toSet());
      expect(vendorDate('2030-02-31'), false);
      expect(vendorDate('2032-02-29'), true);
      expect(vendorDigits('−٢٥٫٢'), '-25.2');
      expect(vendorEvidenceMime(png), 'image/png');
      expect(vendorEvidenceMime(utf8.encode('<html>')), isNull);
    },
  );
  testWidgets(
    'create company validates date and sends one stable scoped operation',
    (t) async {
      final api = VendorApi();
      await t.pumpWidget(host(VendorInventoryView(api: api, onExpired: () {})));
      await t.pumpAndSettle();
      await t.tap(find.text('Create company'));
      await t.pumpAndSettle();
      await t.enterText(
        find.widgetWithText(TextFormField, 'Legal company name'),
        'New canonical operator',
      );
      await t.enterText(
        find.widgetWithText(TextFormField, 'Trade license number'),
        'SYNTHETIC',
      );
      await t.enterText(
        find.widgetWithText(TextFormField, 'License expiry (YYYY-MM-DD)'),
        '2030-02-31',
      );
      await t.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'Save'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.text('Save'));
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await t.enterText(
        find.widgetWithText(TextFormField, 'License expiry (YYYY-MM-DD)'),
        '2030-12-31',
      );
      await t.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'Save'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.text('Save'));
      await t.pumpAndSettle();
      expect(api.posts, 1);
      expect(api.writes.single['country'], 'AE');
      expect(
        api.writes.single['idempotency_key'],
        matches(RegExp(r'^[a-f0-9-]{36}$')),
      );
    },
  );
  testWidgets(
    'lost site creation requires record review and never repeats the write',
    (t) async {
      final api = VendorApi()..lost = true;
      await t.pumpWidget(
        host(
          VendorEditView(
            api: api,
            onExpired: () {},
            kind: 'site',
            company: api.company,
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.enterText(find.widgetWithText(TextFormField, 'Name'), 'Site');
      await t.enterText(
        find.widgetWithText(TextFormField, 'Address'),
        'Address',
      );
      await t.enterText(find.widgetWithText(TextFormField, 'Latitude'), '٢٥٫٢');
      await t.enterText(
        find.widgetWithText(TextFormField, 'Longitude'),
        '٥٥٫٣',
      );
      await t.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'Save'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.text('Save'));
      await t.pumpAndSettle();
      expect(api.posts, 1);
      expect(api.writes.single['latitude'], 25.2);
      await t.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'Save'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(
        t
            .widget<FilledButton>(find.widgetWithText(FilledButton, 'Save'))
            .onPressed,
        isNull,
      );
      await t.scrollUntilVisible(
        find.text(vendorOpsEn['uncertain']!),
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text(vendorOpsEn['uncertain']!), findsOneWidget);
      expect(find.textContaining('private server'), findsNothing);
    },
  );
  testWidgets(
    'approved inventory site form selects only canonical owned station and uses its coordinates',
    (t) async {
      final api = VendorApi();
      await t.pumpWidget(
        host(
          VendorEditView(
            api: api,
            onExpired: () {},
            kind: 'site',
            company: api.company,
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.scrollUntilVisible(
        find.text('Link an owned catalog station (optional)'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.text('Link an owned catalog station (optional)'));
      await t.pumpAndSettle();
      await t.tap(find.text('Owned canonical station'));
      await t.pumpAndSettle();
      await t.scrollUntilVisible(
        find.widgetWithText(TextFormField, 'Name'),
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.enterText(
        find.widgetWithText(TextFormField, 'Name'),
        'Linked site',
      );
      await t.enterText(
        find.widgetWithText(TextFormField, 'Address'),
        'Selected site',
      );
      await t.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'Save'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.text('Save'));
      await t.pumpAndSettle();
      expect(api.writes.single['station_id'], 10);
      expect(api.writes.single['longitude'], 55.3);
    },
  );
  testWidgets(
    'KYC upload requires explicit consent and private image preview hides on background',
    (t) async {
      final api = VendorApi()..status = 'draft';
      await t.pumpWidget(
        host(
          VendorRecordsView(
            api: api,
            onExpired: () {},
            company: api.company,
            kind: 'documents',
            pick: () async => VendorEvidence(png, 'image/png'),
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.tap(find.widgetWithText(FilledButton, 'Upload private evidence'));
      await t.pumpAndSettle();
      expect(
        t
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Upload private evidence'),
            )
            .onPressed,
        isNull,
      );
      await t.tap(find.text('Choose PDF, PNG or JPEG'));
      await t.pumpAndSettle();
      await t.tap(find.byType(Checkbox));
      await t.pumpAndSettle();
      await t.tap(find.widgetWithText(FilledButton, 'Upload private evidence'));
      await t.pumpAndSettle();
      expect(api.uploads, 1);
      expect(api.writes.single['key'], matches(RegExp(r'^[a-f0-9-]{36}$')));
      await t.tap(find.text('View private document'));
      await t.pumpAndSettle();
      expect(find.byType(Image), findsOneWidget);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pumpAndSettle();
      expect(find.byType(Image), findsNothing);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(find.byType(Image), findsNothing);
    },
  );
  testWidgets(
    'staff invitation acceptance sends the displayed scope version and no grant calculation',
    (t) async {
      final api = VendorApi();
      await t.pumpWidget(
        host(
          VendorRecordsView(api: api, onExpired: () {}, kind: 'invitations'),
        ),
      );
      await t.pumpAndSettle();
      await t.tap(find.text('Accept invitation'));
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await t.tap(find.widgetWithText(FilledButton, 'Confirm'));
      await t.pumpAndSettle();
      expect(api.writes.single['expected_version'], 3);
      expect(api.writes.single.containsKey('permissions'), false);
    },
  );
  testWidgets(
    'staff profile lacks private evidence/actions and expired sessions clear company rows in RTL',
    (t) async {
      final api = VendorApi()..owner = false;
      int expired = 0;
      await t.pumpWidget(
        host(
          VendorCompanyView(api: api, onExpired: () => expired++, company: 1),
          ar: true,
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('مستندات التحقق الخاصة'), findsNothing);
      expect(
        Directionality.of(t.element(find.text('Canonical operator'))),
        TextDirection.rtl,
      );
      api.denied = true;
      await t.tap(find.text('تحديث'));
      await t.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Canonical operator'), findsNothing);
    },
  );
  testWidgets(
    'submission readiness comes from the server and locks refresh after internal review submission',
    (t) async {
      final api = VendorApi()..status = 'draft';
      await t.pumpWidget(
        host(VendorCompanyView(api: api, company: 1, onExpired: () {})),
      );
      await t.pumpAndSettle();
      final submit = find.widgetWithText(
        FilledButton,
        'Submit for internal review',
      );
      await t.scrollUntilVisible(
        submit,
        250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(t.widget<FilledButton>(submit).onPressed, isNull);
      api.uploads = 1;
      await t.scrollUntilVisible(
        find.text('Refresh'),
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.text('Refresh'));
      await t.pumpAndSettle();
      await t.scrollUntilVisible(
        submit,
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(submit);
      await t.pumpAndSettle();
      await t.tap(find.widgetWithText(FilledButton, 'Confirm'));
      await t.pumpAndSettle();
      expect(api.posts, 1);
      expect(find.text('Edit company details'), findsNothing);
      expect(find.text('Submit for internal review'), findsNothing);
    },
  );
}
