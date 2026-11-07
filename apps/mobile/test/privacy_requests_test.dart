import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/privacy_requests.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('native privacy request opens, submits and remains usable after success', (tester) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final rows = <Map<String, dynamic>>[];
    final received = <Map<String, dynamic>>[];
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path == '/api/privacy-requests' && request.method == 'GET') {
        request.response.write(jsonEncode({
          'data': rows, 'current_page': 1, 'last_page': 1, 'total': rows.length,
        }));
      } else if (request.uri.path == '/api/privacy-requests' && request.method == 'POST') {
        final json = jsonDecode(await utf8.decoder.bind(request).join()) as Map<String, dynamic>;
        received.add(json);
        final row = <String, dynamic>{
          'id': 1, 'status': 'submitted', 'resolution': null, 'decision_note': null,
          'request_type': json['request_type'], 'reason': json['reason'],
        };
        rows.add(row);
        request.response.statusCode = 201;
        request.response.write(jsonEncode(row));
      } else {
        request.response.statusCode = 404;
        request.response.write('{}');
      }
      await request.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:' + server.port.toString()));
    try {
      await tester.pumpWidget(MaterialApp(home: PrivacyRequestsView(api: api, onExpired: () {})));
      await tester.pumpAndSettle();
      expect(find.text('No privacy requests yet.'), findsOneWidget);
      await tester.tap(find.byKey(const Key('privacy-confirm')));
      await tester.ensureVisible(find.byKey(const Key('privacy-submit')));
      await tester.tap(find.byKey(const Key('privacy-submit')));
      await tester.pumpAndSettle();
      expect(received, hasLength(1));
      expect(received.single['request_type'], 'data_access');
      expect((received.single['idempotency_key'] as String).length, greaterThanOrEqualTo(12));
      expect(find.text('Request recorded for review.'), findsOneWidget);
      expect(find.text('Submitted'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byKey(const Key('privacy-submit'))).onPressed, isNotNull);
    } finally {
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });
}
