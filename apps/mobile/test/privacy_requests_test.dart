import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/privacy_requests.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Widget API contract fixture. HTTP transport and ownership are validated by
/// api_test.dart and PrivacyRequestTest.php respectively.
class _PrivacyApi extends EvApi {
  _PrivacyApi() : super(Uri.parse('https://example.invalid'));
  final rows = <Map<String, dynamic>>[];
  final received = <Map<String, dynamic>>[];
  Completer<void>? holdGet;
  bool failReadbackOnce = false;

  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'privacy-requests' && method == 'GET') {
      if (holdGet != null) await holdGet!.future;
      if (rows.isNotEmpty && failReadbackOnce) {
        failReadbackOnce = false;
        throw const ApiException(503, 'readback unavailable');
      }
      return {
        'data': rows, 'current_page': 1, 'last_page': 1,
        'total': rows.length,
      };
    }
    if (path == 'privacy-requests' && method == 'POST') {
      final payload = Map<String, dynamic>.from(body!);
      received.add(payload);
      final prior = rows.where((r) =>
        r['idempotency_key'] == payload['idempotency_key']).toList();
      if (prior.isNotEmpty) return prior.first;
      final row = <String, dynamic>{
        'id': rows.length + 1, 'status': 'submitted',
        'resolution': null, 'decision_note': null,
        'request_type': payload['request_type'], 'reason': payload['reason'],
        'idempotency_key': payload['idempotency_key'],
      };
      rows.add(row);
      return row;
    }
    throw ApiException(404, 'Unexpected test endpoint: $method $path');
  }
}

void main() {
  testWidgets('native privacy request submits and stays usable afterwards',
      (tester) async {
    final api = _PrivacyApi();
    try {
      await tester.pumpWidget(MaterialApp(
        home: PrivacyRequestsView(api: api, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('No privacy requests yet.'), findsOneWidget);
      await tester.tap(find.byKey(const Key('privacy-confirm')));
      await tester.ensureVisible(find.byKey(const Key('privacy-submit')));
      await tester.tap(find.byKey(const Key('privacy-submit')));
      await tester.pumpAndSettle();
      expect(api.received, hasLength(1));
      expect(api.received.single['request_type'], 'data_access');
      expect(
        (api.received.single['idempotency_key'] as String).length,
        greaterThanOrEqualTo(12),
      );
      expect(find.text('Request recorded for review.'), findsOneWidget);
      expect(find.text('Submitted'), findsOneWidget);
      expect(
        tester.widget<FilledButton>(find.byKey(const Key('privacy-submit'))).onPressed,
        isNotNull,
      );
    } finally {
      api.close();
    }
  });
  testWidgets('post-commit privacy readback failure retains original request identity',
      (tester) async {
    final api = _PrivacyApi()..failReadbackOnce = true;
    try {
      await tester.pumpWidget(MaterialApp(home: PrivacyRequestsView(
        api: api, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('privacy-confirm')));
      await tester.ensureVisible(find.byKey(const Key('privacy-submit')));
      await tester.tap(find.byKey(const Key('privacy-submit')));
      await tester.pumpAndSettle();
      expect(api.rows, hasLength(1));
      expect(api.received, hasLength(1));
      expect(find.byKey(const Key('privacy-error')), findsOneWidget);
      expect(find.text('Request recorded for review.'), findsNothing);
      await tester.tap(find.byKey(const Key('privacy-submit')));
      await tester.pumpAndSettle();
      expect(api.received, hasLength(2));
      expect(api.rows, hasLength(1), reason: 'Retry must not duplicate the request');
      expect(api.received[1]['idempotency_key'], api.received[0]['idempotency_key']);
      expect(find.text('Request recorded for review.'), findsOneWidget);
    } finally {api.close();}
  });

  testWidgets('reused privacy view cannot retain previous account reasons',
      (tester) async {
    final first = _PrivacyApi()..holdGet = Completer<void>();
    final second = _PrivacyApi();
    second.rows.add({
      'id': 44, 'status': 'submitted', 'request_type': 'data_access',
      'reason': 'Second account reason', 'resolution': null, 'decision_note': null,
    });
    first.rows.add({
      'id': 12, 'status': 'submitted', 'request_type': 'data_access',
      'reason': 'First account private reason', 'resolution': null, 'decision_note': null,
    });
    try {
      await tester.pumpWidget(MaterialApp(home: PrivacyRequestsView(
        api: first, onExpired: () {},
      )));
      await tester.pump();
      await tester.pumpWidget(MaterialApp(home: PrivacyRequestsView(
        api: second, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(find.text('Second account reason'), findsOneWidget);
      expect(find.text('First account private reason'), findsNothing);
      first.holdGet!.complete();
      await tester.pumpAndSettle();
      expect(find.text('Second account reason'), findsOneWidget);
      expect(find.text('First account private reason'), findsNothing);
    } finally {
      if (!(first.holdGet?.isCompleted ?? true)) first.holdGet!.complete();
      first.close();second.close();
    }
  });

}
