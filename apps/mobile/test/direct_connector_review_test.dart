import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/direct_connector_review.dart';
import 'package:creabiz_ev/api.dart';
import 'test_support.dart';

class ReviewApi extends FixtureApi {
  int reads = 0, writes = 0;
  bool lost = false, expireRead = false;
  Map<String, dynamic>? stored;
  Completer<dynamic>? delayed;

  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'vendor-ops/companies/1/chargers/2/direct-connector-reviews');
    if (method == 'GET') {
      reads++;
      expect(query, {'connector_id': '3'});
      if (delayed != null) return delayed!.future;
      if (expireRead) {
        await forgetSession();
        throw ApiException(401, 'PRIVATE RESPONSE', expirationIsCurrent: sessionFence());
      }
      return {'current_page': 1, 'last_page': 1,
        'total': stored == null ? 0 : 1, 'data': [if (stored != null) stored]};
    }
    expect(method, 'POST');
    writes++;
    expect(body!['connector_id'], 3);
    stored = {
      'id': 7, 'connector_id': 3,
      'source_evse_number': body['source_evse_number'],
      'source_connector_number': body['source_connector_number'],
      'current_registration_matches': true, 'review_state': 'current',
      'source_observed_at': '2026-11-28 12:00:00.000000',
      'reviewed_at': '2026-11-28 12:01:00.000000',
      'evidence': 'DIRECT_CONNECTOR_REVIEW_ONLY',
      'charging_mapping_created': false, 'physical_availability_verified': false,
      'remote_control_authorized': false,
    };
    if (lost) throw const ApiException(503, 'PRIVATE RESPONSE');
    return stored;
  }
}

Widget view(ReviewApi api, {String protocol = '1.6', bool owner = true, VoidCallback? onExpired}) =>
    MaterialApp(home: DirectConnectorReviewView(
      api: api, companyId: 1, chargerId: 2, connectorId: 3,
      protocol: protocol, canReview: owner, onExpired: onExpired ?? () {},
    ));

void main() {
  for (final protocol in ['1.6', '2.0.1']) {
    testWidgets('explicit $protocol review recovers lost response without repeat write',
        (tester) async {
      final api = ReviewApi()..lost = true;
      await tester.pumpWidget(view(api, protocol: protocol));
      expect(api.reads, 0);
      expect(find.text('Record connector identity review'), findsNothing);
      await tester.tap(find.text('Read stored connector review'));
      await tester.pumpAndSettle();
      if (protocol == '2.0.1') {
        await tester.enterText(find.byType(TextField).first, '1');
      }
      await tester.enterText(find.byType(TextField).last, '2');
      final submit = find.text('Record connector identity review');
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Review outcome uncertain; read stored review before another submission.'), findsOneWidget);
      expect(find.text('Record connector identity review'), findsNothing);
      expect(find.textContaining('PRIVATE RESPONSE'), findsNothing);
      await tester.tap(find.text('Read stored connector review'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.reads, 2);
      expect(find.text('Stored connector review matches current registration'), findsOneWidget);
      expect(find.text(protocol == '1.6' ? '#7 · 0/2' : '#7 · 1/2'), findsOneWidget);
    });
  }

  for (final protocol in ['1.6', '2.0.1']) {
    testWidgets('stale $protocol review can only renew the same target', (tester) async {
      final api = ReviewApi();
      api.stored = {
        'id': 6, 'connector_id': 3,
        'source_evse_number': protocol == '1.6' ? 0 : 1,
        'source_connector_number': 2, 'current_registration_matches': false, 'review_state': 'renewal_required',
        'source_observed_at': '2026-11-28 12:00:00.000000',
        'reviewed_at': '2026-11-28 12:01:00.000000',
        'evidence': 'DIRECT_CONNECTOR_REVIEW_ONLY',
        'charging_mapping_created': false, 'physical_availability_verified': false,
        'remote_control_authorized': false,
      };
      await tester.pumpWidget(view(api, protocol: protocol));
      expect(api.reads, 0);
      await tester.tap(find.text('Read stored connector review'));
      await tester.pumpAndSettle();
      final fields = tester.widgetList<TextField>(find.byType(TextField)).toList();
      expect(fields.length, 2);
      expect(fields.every((field) => field.readOnly), true);
      expect(fields.first.controller!.text, protocol == '1.6' ? '0' : '1');
      expect(fields.last.controller!.text, '2');
      final submit = find.text('Re-review same connector identity');
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.stored!['source_connector_number'], 2);
      expect(find.text('Stored connector review matches current registration'), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
    });
  }

  for (final state in ['inventory_changed', 'operator_review_required', 'unknown_state']) {
    testWidgets('blocked $state receipt cannot offer renewal', (tester) async {
      final api = ReviewApi()..stored = {
        'id': 6, 'connector_id': 3,
        'source_evse_number': 0, 'source_connector_number': 2,
        'current_registration_matches': false, 'review_state': state,
        'source_observed_at': '2026-11-28 12:00:00.000000',
        'reviewed_at': '2026-11-28 12:01:00.000000',
        'evidence': 'DIRECT_CONNECTOR_REVIEW_ONLY',
        'charging_mapping_created': false, 'physical_availability_verified': false,
        'remote_control_authorized': false,
      };
      await tester.pumpWidget(view(api));
      await tester.tap(find.text('Read stored connector review'));
      await tester.pumpAndSettle();
      expect(find.byType(TextField), findsNothing);
      expect(find.text('Re-review same connector identity'), findsNothing);
      expect(find.text(state == 'inventory_changed'
        ? 'Connector inventory changed; operator investigation required'
        : state == 'operator_review_required'
          ? 'Connector review requires operator investigation'
          : 'Connector review unavailable. Read again explicitly.'), findsOneWidget);
      expect(api.writes, 0);
    });
  }

  testWidgets('session change immediately retires displayed private review', (tester) async {
    final api = ReviewApi()..stored = {
      'id': 7, 'connector_id': 3,
      'source_evse_number': 0, 'source_connector_number': 2,
      'current_registration_matches': true, 'review_state': 'current',
      'source_observed_at': '2026-11-28 12:00:00.000000',
      'reviewed_at': '2026-11-28 12:01:00.000000',
      'evidence': 'DIRECT_CONNECTOR_REVIEW_ONLY',
      'charging_mapping_created': false, 'physical_availability_verified': false,
      'remote_control_authorized': false,
    };
    await tester.pumpWidget(view(api));
    await tester.tap(find.text('Read stored connector review'));
    await tester.pumpAndSettle();
    expect(find.text('#7 · 0/2'), findsOneWidget);
    await api.forgetSession();
    await tester.pumpAndSettle();
    expect(find.text('#7 · 0/2'), findsNothing);
    expect(find.text('Read stored connector review'), findsNothing);
    await tester.pumpWidget(view(api, owner: false));
    await tester.pumpAndSettle();
    expect(find.text('Read stored connector review'), findsNothing);
    expect(api.reads, 1);
    expect(api.writes, 0);
  });

  testWidgets('expiration retires route and still notifies parent once', (tester) async {
    final api = ReviewApi()..expireRead = true;
    var expired = 0;
    await tester.pumpWidget(view(api, onExpired: () => expired++));
    await tester.tap(find.text('Read stored connector review'));
    await tester.pumpAndSettle();
    expect(expired, 1);
    expect(find.text('Read stored connector review'), findsNothing);
    expect(find.textContaining('PRIVATE RESPONSE'), findsNothing);
    expect(api.writes, 0);
  });

  testWidgets('late response cannot reappear after credential transition', (tester) async {
    final api = ReviewApi()..delayed = Completer<dynamic>();
    await tester.pumpWidget(view(api));
    await tester.tap(find.text('Read stored connector review'));
    await tester.pump();
    await api.forgetSession();
    api.delayed!.complete({'current_page': 1, 'last_page': 1, 'total': 0, 'data': []});
    await tester.pumpAndSettle();
    expect(find.text('Read stored connector review'), findsNothing);
    expect(find.text('No connector review recorded'), findsNothing);
    expect(api.writes, 0);
  });

  testWidgets('non-owner read is explicit and cannot write; background discards late receipt',
      (tester) async {
    final api = ReviewApi();
    await tester.pumpWidget(view(api, owner: false));
    await tester.tap(find.text('Read stored connector review'));
    await tester.pumpAndSettle();
    expect(find.text('No connector review recorded'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    api.delayed = Completer<dynamic>();
    await tester.tap(find.text('Read stored connector review'));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    api.delayed!.complete({'current_page': 1, 'last_page': 1, 'total': 0, 'data': []});
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.text('No connector review recorded'), findsNothing);
    expect(api.writes, 0);
  });
}
