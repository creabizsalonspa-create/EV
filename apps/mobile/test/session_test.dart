import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/session_vault.dart';
import 'fixtures/tls.dart';

class MemoryVault implements SessionVault {
  final entries = <String, String>{};
  bool failDelete = false;
  int writes = 0;
  @override
  Future<String?> read(String origin) async => entries[origin];
  @override
  Future<void> write(String origin, String value) async {
    entries[origin] = value;
    writes++;
  }

  @override
  Future<void> delete(String origin) async {
    if (failDelete) throw StateError('locked');
    entries.remove(origin);
  }
}

void main() {
  test('plaintext origins never persist credentials', () async {
    final vault = MemoryVault();
    final api = EvApi(Uri.parse('http://127.0.0.1:8000'), vault: vault);
    expect(api.canRemember, isFalse);
    expect(await api.rememberSession(), isFalse);
    expect(await api.restoreSession(), isNull);
    expect(vault.writes, 0);
    api.close();
  });
  test(
    'secure HTTP-only cookies restore through real TLS and fresh me/CSRF; logout, origin and expiry are enforced',
    () async {
      final context = SecurityContext()
        ..useCertificateChainBytes(utf8.encode(fixtureCertificate))
        ..usePrivateKeyBytes(utf8.encode(fixturePrivateKey));
      final server = await HttpServer.bindSecure(
        InternetAddress.loopbackIPv4,
        0,
        context,
      );
      var revoked = false;
      var meCalls = 0;
      final subscription = server.listen((req) async {
        req.response.headers.contentType = ContentType.json;
        final path = req.uri.path;
        if (path == '/api/logout') {
          revoked = true;
          req.response.statusCode = 204;
        } else if (path == '/api/me') {
          meCalls++;
          if (revoked ||
              !req.cookies.any(
                (c) => c.name == 'session' && c.value == 'synthetic-credential',
              )) {
            req.response.statusCode = 401;
            req.response.write(jsonEncode({'message': 'expired'}));
          } else {
            req.response.write(
              jsonEncode({
                'id': 1,
                'name': 'Fresh server account',
                'role': 'vendor',
              }),
            );
          }
        } else if (path == '/api/csrf') {
          req.response.cookies.add(
            Cookie('session', 'synthetic-credential')
              ..secure = true
              ..httpOnly = true
              ..expires = DateTime.now().toUtc().add(
                const Duration(minutes: 30),
              )
              ..path = '/',
          );
          req.response.cookies.add(
            Cookie('XSRF-TOKEN', 'csrf-cookie-never-persist')..secure = true,
          );
          req.response.write(
            jsonEncode({'token': 'fresh-token-never-persist'}),
          );
        } else {
          expect(
            req.headers.value('X-CSRF-TOKEN'),
            'fresh-token-never-persist',
          );
          req.response.write(
            jsonEncode({'id': 1, 'name': 'Fixture', 'role': 'customer'}),
          );
        }
        await req.response.close();
      });
      final base = Uri.parse('https://127.0.0.1:${server.port}'),
          vault = MemoryVault();
      HttpClient trusted() => HttpClient(
        context: SecurityContext()
          ..setTrustedCertificatesBytes(utf8.encode(fixtureCertificate)),
      );
      final first = EvApi(base, http: trusted(), vault: vault);
      final second = EvApi(base, http: trusted(), vault: vault);
      final untrusted = EvApi(base);
      try {
        await expectLater(
          untrusted.request('csrf'),
          throwsA(isA<HandshakeException>()),
        );
        await first.signIn('synthetic@example.test', 'password-never-persist');
        expect(await first.rememberSession(), isTrue);
        final saved = vault.entries[base.origin]!;
        expect(saved.contains('password-never-persist'), isFalse);
        expect(saved.contains('fresh-token-never-persist'), isFalse);
        expect(saved.contains('XSRF-TOKEN'), isFalse);
        expect((await second.restoreSession())!.name, 'Fresh server account');
        expect(meCalls, 1);
        expect(second.remembered, isTrue);
        vault.failDelete = true;
        await expectLater(
          second.signOut(),
          throwsA(isA<SessionStorageException>()),
        );
        expect(second.remembered, isTrue);
        expect(revoked, isFalse);
        vault.failDelete = false;
        await second.signOut();
        expect(vault.entries, isEmpty);
        expect(revoked, isTrue);
        vault.entries[base.origin] = saved;
        expect(await second.restoreSession(), isNull);
        expect(vault.entries, isEmpty);
        final other = jsonDecode(saved) as Map<String, dynamic>;
        other['origin'] = 'https://different.example';
        vault.entries[base.origin] = jsonEncode(other);
        final before = meCalls;
        expect(await second.restoreSession(), isNull);
        expect(meCalls, before);
        other['origin'] = base.origin;
        other['expires'] = DateTime.now()
            .subtract(const Duration(days: 1))
            .toIso8601String();
        vault.entries[base.origin] = jsonEncode(other);
        expect(await second.restoreSession(), isNull);
        expect(vault.entries, isEmpty);
      } finally {
        first.close();
        second.close();
        untrusted.close();
        await subscription.cancel();
        await server.close(force: true);
      }
    },
  );
}
