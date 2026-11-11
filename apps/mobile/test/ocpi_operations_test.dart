import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/ocpi_operations.dart';
import 'test_support.dart';

class PartnerApi extends FixtureApi {
  int mutations = 0, reads = 0;
  bool revoked = false, deny = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') {
      mutations++;
      revoked = true;
      if (deny) throw const ApiException(403, 'PRIVATE PARTNER TOKEN');
      throw const ApiException(503, 'PRIVATE PARTNER TOKEN');
    }
    if (path == 'ocpi/providers') {
      reads++;
      return {
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'data': [
          {
            'id': 7,
            'name': 'Authorized partner',
            'authorized': !revoked,
            'version': revoked ? null : '2.2.1',
            'readable_modules': revoked ? [] : ['locations'],
            'commands_enabled': false,
            'billing_eligible': false,
          },
        ],
      };
    }
    expect(path, 'ocpi/providers/7/records/locations');
    return {
      'current_page': 1,
      'last_page': 1,
      'total': 1,
      'data': [
        {
          'country_code': 'AE',
          'party_id': 'SYN',
          'external_id': '1',
          'payload': {'token': 'PRIVATE PARTNER TOKEN'},
          'normalized': {
            'name': 'Partner station',
            'address': 'Synthetic address',
            'availability': 'unknown',
          },
        },
      ],
    };
  }
}

class CdrApi extends PartnerApi {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/records/cdrs'))
      return {
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'data': [
          {
            'external_id': 'cdr-1',
            'payload': {'token': 'PRIVATE PARTNER TOKEN'},
            'normalized': {'energy_kwh': '12.34'},
            'reconciliation': {
              'status': 'requires_review',
              'issues': ['energy_mismatch'],
              'credit_review': {
                'reference_state': 'matched',
                'financial_authority': false,
                'automatic_refund': false,
              },
              'billing_eligible': false,
            },
          },
        ],
      };
    return super.request(path, method: method, body: body, query: query);
  }
}

class ProvisionApi extends PartnerApi {
  Map<String, dynamic>? created;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'ocpi/connection-options')
      return {
        'data': [
          {'key': 'approved', 'configuration_valid': true, 'assigned': false},
          {'key': 'invalid', 'configuration_valid': false, 'assigned': false},
          {'key': 'assigned', 'configuration_valid': true, 'assigned': true},
        ],
      };
    if (path == 'ocpi/owner-options')
      return {
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'data': [
          {'id': 9, 'name': 'Synthetic vendor'},
        ],
      };
    if (path == 'ocpi/providers' && method == 'POST') {
      created = body;
      mutations++;
      throw const ApiException(503, 'PRIVATE TOKEN');
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

void main() {
  testWidgets(
    'CDR source discrepancies are visible without billing authority or private payloads',
    (tester) async {
      final api = CdrApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiOperationsView(api: api, admin: false, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Inspect Charge detail records'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Inspect Charge detail records'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Session and CDR energy differ'));
      await tester.pumpAndSettle();
      expect(
        find.text('Partner reconciliation requires review'),
        findsOneWidget,
      );
      expect(
        find.text('Source comparison does not authorize billing'),
        findsOneWidget,
      );
      expect(find.text('Session and CDR energy differ'), findsOneWidget);
      expect(
        find.text('Credit CDR original reference matched'),
        findsOneWidget,
      );
      expect(
        find.text(
          'A source reference does not approve a refund or change the original invoice.',
        ),
        findsOneWidget,
      );
      expect(find.textContaining('PRIVATE PARTNER TOKEN'), findsNothing);
      expect(api.mutations, 0);
    },
  );
  testWidgets(
    'Admin provisions only approved server connections, initially unauthorized, with no blind retry',
    (tester) async {
      final api = ProvisionApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiOperationsView(api: api, admin: true, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Add configured partner'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'Partner name'),
        'Synthetic native partner',
      );
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      expect(find.text('invalid'), findsNothing);
      expect(find.text('assigned'), findsNothing);
      await tester.tap(find.text('approved').last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byType(DropdownButtonFormField<int>));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<int>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Synthetic vendor · #9').last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Create unauthorized partner'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create unauthorized partner'));
      await tester.pumpAndSettle();
      expect(api.created, {
        'owner_id': 9,
        'connection_key': 'approved',
        'name': 'Synthetic native partner',
        'authorized': false,
      });
      expect(api.mutations, 1);
      final submit = tester.widget<FilledButton>(find.byType(FilledButton));
      expect(submit.onPressed, isNull);
      expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
    },
  );

  testWidgets(
    'uncertain partner mutation is never replayed and refresh reconciles revocation',
    (tester) async {
      final api = PartnerApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiOperationsView(api: api, admin: false, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Revoke partner'), findsNothing);
      final sync = find.text('Synchronize Locations');
      await tester.ensureVisible(sync);
      await tester.pumpAndSettle();
      await tester.tap(sync);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.mutations, 1);
      expect(find.textContaining('Outcome uncertain.'), findsOneWidget);
      expect(find.textContaining('PRIVATE PARTNER TOKEN'), findsNothing);
      final disabled = tester.widget<TextButton>(
        find.ancestor(
          of: find.text('Synchronize Locations'),
          matching: find.byType(TextButton),
        ),
      );
      expect(disabled.onPressed, isNull);
      await tester.scrollUntilVisible(find.text('Refresh partners'), -250);
      await tester.tap(find.text('Refresh partners'));
      await tester.pumpAndSettle();
      expect(find.text('Operator authorization revoked'), findsOneWidget);
      expect(find.text('Synchronize Locations'), findsNothing);
      expect(api.mutations, 1);
    },
  );

  testWidgets(
    'record inspection displays allowlisted data without raw credentials or commands',
    (tester) async {
      final api = PartnerApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiOperationsView(api: api, admin: false, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Inspect Locations'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Inspect Locations'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Partner station'));
      await tester.pumpAndSettle();
      expect(find.text('Partner station'), findsOneWidget);
      expect(find.textContaining('PRIVATE PARTNER TOKEN'), findsNothing);
      expect(find.text('Start charging'), findsNothing);
      expect(api.mutations, 0);
    },
  );

  testWidgets('server permission rejection clears partner details', (
    tester,
  ) async {
    final api = PartnerApi()..deny = true;
    await tester.pumpWidget(
      MaterialApp(
        home: OcpiOperationsView(api: api, admin: true, onExpired: () {}),
      ),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Revoke partner'));
    await tester.tap(find.text('Revoke partner'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(find.text('Authorized partner'), findsNothing);
    expect(find.textContaining('PRIVATE PARTNER TOKEN'), findsNothing);
    expect(api.mutations, 1);
  });
}
