import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  test('rejects remote plaintext and credential-bearing origins', () {
    expect(() => EvApi(Uri.parse('http://example.test')), throwsArgumentError);
    expect(
      () => EvApi(Uri.parse('https://name:secret@example.test')),
      throwsArgumentError,
    );
  });
  test('csrf, cookies and session rotation use the actual protocol', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var csrfCalls = 0;
    final subscription = server.listen((req) async {
      req.response.headers.contentType = ContentType.json;
      if (req.uri.path == '/api/csrf') {
        csrfCalls++;
        req.response.cookies.add(Cookie('session', 'fixture-$csrfCalls'));
        req.response.write(jsonEncode({'token': 'csrf-$csrfCalls'}));
      } else if (req.uri.path == '/api/login') {
        expect(req.headers.value('X-CSRF-TOKEN'), 'csrf-1');
        expect(req.cookies.single.value, 'fixture-1');
        final body = jsonDecode(await utf8.decoder.bind(req).join());
        expect(body['email'], 'synthetic@example.test');
        req.response.write(
          jsonEncode({'id': 1, 'name': 'Fixture', 'role': 'customer'}),
        );
      } else {
        req.response.statusCode = 401;
        req.response.write(jsonEncode({'message': 'Unauthenticated.'}));
      }
      await req.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
    try {
      final user = await api.signIn(
        'synthetic@example.test',
        'synthetic-password',
      );
      expect(user.role, 'customer');
      expect(csrfCalls, 2);
      await expectLater(
        api.request('me'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
      );
    } finally {
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });
}
