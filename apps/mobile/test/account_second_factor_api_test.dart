import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

// Keep real loopback HTTP outside widget binding's deliberate HTTP override.
void main() {
  test('native login sends a factor only on the explicit factor entry path', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final bodies = <Map<String, dynamic>>[];
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path.endsWith('/login')) {
        bodies.add(Map<String, dynamic>.from(jsonDecode(await utf8.decoder.bind(request).join()) as Map));
        request.response.write(jsonEncode({'id': 1, 'name': 'Customer', 'role': 'customer', 'email_verified_at': null}));
      } else { request.response.write(jsonEncode({'token': 'synthetic-csrf'})); }
      await request.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
    try {
      await api.signInWithFactor('customer@example.invalid', 'current-password', '012345');
      expect(bodies.single['second_factor_code'], '012345');
      await api.signIn('customer@example.invalid', 'current-password');
      expect(bodies.last.containsKey('second_factor_code'), isFalse);
    } finally { api.close(); await subscription.cancel(); await server.close(force: true); }
  });
}
