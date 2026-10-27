import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/session_vault.dart';
import 'fixtures/tls.dart';

class MemoryVault implements SessionVault {
  final entries = <String, String>{};
  bool failDelete = false;
  bool failWrite = false;
  int writes = 0;
  Future<void>? deleteWait;
  @override
  Future<String?> read(String origin) async => entries[origin];
  @override
  Future<void> write(String origin, String value) async {
    entries[origin] = value;
    writes++;
    if (failWrite) throw StateError('write failed after platform update');
  }

  @override
  Future<void> delete(String origin) async {
    if (deleteWait != null) await deleteWait;
    if (failDelete) throw StateError('locked');
    entries.remove(origin);
  }
}

// Observe the actual TLS response stream subscription to order logout exactly
// after header validation and before body completion, without timing sleeps.
class ObservedClient implements HttpClient {
  final HttpClient delegate;
  final Completer<void> bodyListening;
  ObservedClient(this.delegate, this.bodyListening);
  @override
  Future<HttpClientRequest> openUrl(String method, Uri url) async {
    final request = await delegate.openUrl(method, url);
    return url.path.endsWith('/delayed')
        ? ObservedRequest(request, bodyListening)
        : request;
  }

  @override
  set connectionTimeout(Duration? value) => delegate.connectionTimeout = value;
  @override
  void close({bool force = false}) => delegate.close(force: force);
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class ObservedRequest implements HttpClientRequest {
  final HttpClientRequest delegate;
  final Completer<void> listening;
  ObservedRequest(this.delegate, this.listening);
  @override
  HttpHeaders get headers => delegate.headers;
  @override
  List<Cookie> get cookies => delegate.cookies;
  @override
  set followRedirects(bool value) => delegate.followRedirects = value;
  @override
  Future<HttpClientResponse> close() async =>
      ObservedResponse(await delegate.close(), listening);
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class ObservedResponse extends Stream<List<int>> implements HttpClientResponse {
  final HttpClientResponse delegate;
  final Completer<void> listening;
  ObservedResponse(this.delegate, this.listening);
  @override
  int get statusCode => delegate.statusCode;
  @override
  List<Cookie> get cookies => delegate.cookies;
  @override
  StreamSubscription<List<int>> listen(
    void Function(List<int>)? onData, {
    Function? onError,
    void Function()? onDone,
    bool? cancelOnError,
  }) {
    listening.complete();
    return delegate.listen(
      onData,
      onError: onError,
      onDone: onDone,
      cancelOnError: cancelOnError,
    );
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
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
      final context = fixtureServerContext();
      final server = await HttpServer.bindSecure(
        InternetAddress.loopbackIPv4,
        0,
        context,
      );
      final bodyRelease = Completer<void>(), bodyListening = Completer<void>();
      var revoked = false;
      var meCalls = 0;
      final subscription = server.listen((req) async {
        req.response.headers.contentType = ContentType.json;
        final path = req.uri.path;
        if (path == '/api/delayed') {
          req.response.write('{\"ok\":');
          await req.response.flush();
          await bodyRelease.future;
          req.response.write('true}');
        } else if (path == '/api/logout') {
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
      HttpClient trusted() => HttpClient(context: fixtureClientContext());
      final first = EvApi(base, http: trusted(), vault: vault);
      final second = EvApi(base, http: trusted(), vault: vault);
      final untrusted = EvApi(base);
      final late = EvApi(
        base,
        http: ObservedClient(trusted(), bodyListening),
        vault: vault,
      );
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
        await late.restoreSession();
        final delayed = late.request('delayed');
        await bodyListening.future;
        final deletion = Completer<void>();
        vault.deleteWait = deletion.future;
        final forgetting = late.forgetSession();
        bodyRelease.complete();
        await expectLater(
          delayed.timeout(const Duration(seconds: 3)),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
        );
        deletion.complete();
        await forgetting;
        vault.deleteWait = null;
        expect(vault.entries, isEmpty);
        await second.rememberSession();

        vault.failWrite = true;
        await expectLater(
          second.rememberSession(),
          throwsA(isA<SessionStorageException>()),
        );
        expect(vault.entries, isEmpty);
        expect(second.remembered, isFalse);
        vault.failWrite = false;
        await second.rememberSession();
        vault.failWrite = true;
        vault.failDelete = true;
        await expectLater(
          second.rememberSession(),
          throwsA(isA<SessionStorageException>()),
        );
        expect(second.remembered, isTrue);
        vault.failWrite = false;
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
        late.close();
        if (!bodyRelease.isCompleted) bodyRelease.complete();
        await subscription.cancel();
        await server.close(force: true);
      }
    },
  );
}
