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
  bool failRead = false;
  int writes = 0;
  Future<void>? deleteWait;
  Future<void>? readWait;
  Completer<void>? readStarted;
  @override
  Future<String?> read(String origin) async {
    // Simulate a platform read that captured an old credential before logout.
    final captured = entries[origin];
    if (readStarted != null && !readStarted!.isCompleted) {
      readStarted!.complete();
    }
    if (readWait != null) await readWait;
    if (failRead) throw StateError('secure platform read failed');
    return captured;
  }
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
  test('in-flight secure storage restore cannot undo forgetSession', () async {
    final origin = Uri.parse('https://example.test');
    final vault = MemoryVault();
    final release = Completer<void>();
    final started = Completer<void>();
    vault.readWait = release.future;
    vault.readStarted = started;
    final now = DateTime.now().toUtc();
    final expiry = now.add(const Duration(minutes: 25));
    vault.entries[origin.origin] = jsonEncode({
      'version': 1,
      'origin': origin.origin,
      'saved_at': now.toIso8601String(),
      'expires': expiry.toIso8601String(),
      'cookies': [
        'session=stale; Path=/; Secure; HttpOnly; '
            'Expires=${HttpDate.format(expiry)}',
      ],
    });
    final api = EvApi(origin, vault: vault);
    try {
      final pendingRestore = api.restoreSession();
      await started.future;
      // Forget increments the identity epoch before the serialized vault
      // deletion completes. The late read must never make a network request
      // using the stale session or show a restored account.
      final forget = api.forgetSession();
      release.complete();
      expect(await pendingRestore, isNull);
      await forget;
      expect(vault.entries, isEmpty);
      expect(api.remembered, isFalse);
    } finally {
      if (!release.isCompleted) release.complete();
      api.close();
    }
  });

  test('obsolete secure storage read failure cannot escape after logout', () async {
    final origin = Uri.parse('https://example.test');
    final vault = MemoryVault();
    final release = Completer<void>();
    final started = Completer<void>();
    vault.entries[origin.origin] = 'opaque-old-secret';
    vault.readWait = release.future;
    vault.readStarted = started;
    vault.failRead = true;
    final api = EvApi(origin, vault: vault);
    try {
      final pendingRestore = api.restoreSession();
      await started.future;
      final forgetting = api.forgetSession();
      release.complete();
      expect(await pendingRestore, isNull);
      await forgetting;
      expect(vault.entries, isEmpty);
    } finally {
      if (!release.isCompleted) release.complete();
      api.close();
    }
  });

  test('active secure storage read failure remains visible', () async {
    final origin = Uri.parse('https://example.test');
    final vault = MemoryVault()..failRead = true;
    final api = EvApi(origin, vault: vault);
    try {
      await expectLater(
        api.restoreSession(),
        throwsA(isA<SessionStorageException>()),
      );
    } finally {
      api.close();
    }
  });

  test('closing the client invalidates a pending vault restore', () async {
    final origin = Uri.parse('https://example.test');
    final vault = MemoryVault();
    final release = Completer<void>();
    final started = Completer<void>();
    vault.readWait = release.future;
    vault.readStarted = started;
    vault.entries[origin.origin] = 'stale-snapshot';
    final api = EvApi(origin, vault: vault);
    final pendingRestore = api.restoreSession();
    await started.future;
    api.close();
    release.complete();
    expect(await pendingRestore, isNull);
    // Closing the client must not delete secure storage: only an explicit
    // forget or logout has permission to destroy persisted credentials.
    expect(vault.entries[origin.origin], 'stale-snapshot');
  });

  test('overlapping sign-ins do not resurrect the superseded identity', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final emails = <String>[];
    final subscription = server.listen((req) async {
      req.response.headers.contentType = ContentType.json;
      if (req.uri.path == '/api/csrf') {
        req.response.cookies.add(Cookie('session', 'current'));
        req.response.write(jsonEncode({'token': 'fixture-csrf'}));
      } else if (req.uri.path == '/api/login') {
        final payload = jsonDecode(await utf8.decoder.bind(req).join()) as Map;
        emails.add(payload['email'] as String);
        req.response.write(jsonEncode({
          'id': 2, 'name': 'Latest account', 'role': 'customer',
        }));
      } else {
        req.response.statusCode = 404;
        req.response.write('{}');
      }
      await req.response.close();
    });
    final release = Completer<void>();
    final vault = MemoryVault()..deleteWait = release.future;
    final api = EvApi(
      Uri.parse('http://127.0.0.1:${server.port}'),
      vault: vault,
    );
    try {
      final older = api.signIn('older@example.test', 'not-persisted');
      final newer = api.signIn('latest@example.test', 'not-persisted');
      release.complete();
      await expectLater(
        older,
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      final account = await newer;
      expect(account.name, 'Latest account');
      expect(emails, ['latest@example.test']);
    } finally {
      if (!release.isCompleted) release.complete();
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('old logout awaiting storage cannot revoke the next login', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var logoutCalls = 0;
    final subscription = server.listen((req) async {
      req.response.headers.contentType = ContentType.json;
      switch (req.uri.path) {
        case '/api/csrf':
          req.response.cookies.add(Cookie('session', 'fresh'));
          req.response.write(jsonEncode({'token': 'fresh-csrf'}));
        case '/api/login':
          req.response.write(jsonEncode({
            'id': 4, 'name': 'New user', 'role': 'customer',
          }));
        case '/api/logout':
          logoutCalls++;
          req.response.statusCode = 204;
        default:
          req.response.statusCode = 404;
          req.response.write('{}');
      }
      await req.response.close();
    });
    final deletion = Completer<void>();
    final vault = MemoryVault()..deleteWait = deletion.future;
    final api = EvApi(
      Uri.parse('http://127.0.0.1:${server.port}'), vault: vault,
    );
    try {
      final oldLogout = api.signOut();
      final newLogin = api.signIn('new@example.test', 'no-persist');
      deletion.complete();
      await oldLogout;
      expect((await newLogin).name, 'New user');
      expect(logoutCalls, 0);
    } finally {
      if (!deletion.isCompleted) deletion.complete();
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('old logout response cannot clear credentials after new login', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final logoutStarted = Completer<void>();
    final releaseLogout = Completer<void>();
    final subscription = server.listen((req) async {
      req.response.headers.contentType = ContentType.json;
      switch (req.uri.path) {
        case '/api/logout':
          logoutStarted.complete();
          await releaseLogout.future;
          req.response.statusCode = 204;
        case '/api/csrf':
          req.response.cookies.add(
            Cookie('session', 'new-session')..httpOnly = true,
          );
          req.response.write(jsonEncode({'token': 'new-csrf'}));
        case '/api/login':
          req.response.write(jsonEncode({
            'id': 5, 'name': 'New signed-in user', 'role': 'vendor',
          }));
        case '/api/me':
          final valid = req.cookies.any(
            (c) => c.name == 'session' && c.value == 'new-session',
          );
          if (!valid) {
            req.response.statusCode = 401;
            req.response.write(jsonEncode({'message': 'Unauthenticated'}));
          } else {
            req.response.write(jsonEncode({
              'id': 5, 'name': 'New signed-in user', 'role': 'vendor',
            }));
          }
        default:
          req.response.statusCode = 404;
          req.response.write('{}');
      }
      await req.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
    try {
      final oldLogout = api.signOut();
      await logoutStarted.future;
      final newest = await api.signIn('new@example.test', 'no-persist');
      expect(newest.name, 'New signed-in user');
      releaseLogout.complete();
      await expectLater(
        oldLogout,
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      expect((await api.request('me'))['name'], 'New signed-in user');
    } finally {
      if (!releaseLogout.isCompleted) releaseLogout.complete();
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('failed secure deletion after server 401 never claims token erased', () async {
    final server = await HttpServer.bindSecure(
      InternetAddress.loopbackIPv4,
      0,
      fixtureServerContext(usePkcs12: true),
    );
    final subscription = server.listen((req) async {
      req.response.headers.contentType = ContentType.json;
      if (req.uri.path == '/api/csrf') {
        req.response.cookies.add(
          Cookie('session', 'synthetic-session')
            ..secure = true
            ..httpOnly = true
            ..expires = DateTime.now().toUtc().add(
              const Duration(minutes: 30),
            )
            ..path = '/',
        );
        req.response.write(jsonEncode({'token': 'synthetic-csrf'}));
      } else if (req.uri.path == '/api/login') {
        req.response.write(jsonEncode({
          'id': 7, 'name': 'Signed-in vendor', 'role': 'vendor',
        }));
      } else if (req.uri.path == '/api/me') {
        req.response.statusCode = 401;
        req.response.write(jsonEncode({'message': 'Expired'}));
      } else {
        req.response.statusCode = 404;
        req.response.write('{}');
      }
      await req.response.close();
    });
    final base = Uri.parse('https://127.0.0.1:${server.port}');
    final vault = MemoryVault();
    final api = EvApi(
      base,
      http: HttpClient(context: fixtureClientContext()),
      vault: vault,
    );
    try {
      await api.signIn('synthetic@example.test', 'never-persist-password');
      expect(await api.rememberSession(), isTrue);
      final encryptedSnapshot = vault.entries[base.origin];
      expect(encryptedSnapshot, isNotNull);
      vault.failDelete = true;
      await expectLater(
        api.request('me'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
      );
      // The remote account is rejected, but the device vault may still hold
      // the rejected cookie. Flagging it forgotten would be misleading.
      expect(api.remembered, isTrue);
      expect(vault.entries[base.origin], encryptedSnapshot);
      vault.failDelete = false;
      await api.forgetSession();
      expect(vault.entries, isEmpty);
      expect(api.remembered, isFalse);
    } finally {
      vault.failDelete = false;
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('logout blocks unrelated requests during delayed vault deletion', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var otherRequests = 0;
    var logouts = 0;
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path == '/api/logout') {
        logouts++;
      } else {
        otherRequests++;
      }
      request.response.write('{}');
      await request.response.close();
    });
    final gate = Completer<void>();
    final vault = MemoryVault()..deleteWait = gate.future;
    final api = EvApi(
      Uri.parse('http://127.0.0.1:${server.port}'),
      vault: vault,
    );
    try {
      final logout = api.signOut();
      await expectLater(
        api.request('notes', method: 'POST', body: {'value': 'never-send'}),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      expect(otherRequests, 0);
      gate.complete();
      await logout;
      expect(logouts, 1);
      expect(otherRequests, 0);
    } finally {
      if (!gate.isCompleted) gate.complete();
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('sign-in blocks stale UI actions while old vault is clearing', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var forbiddenRequests = 0;
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      switch (request.uri.path) {
        case '/api/csrf':
          request.response.write(jsonEncode({'token': 'fresh-csrf'}));
        case '/api/login':
          request.response.write(jsonEncode({
            'id': 23, 'name': 'Current user', 'role': 'customer',
          }));
        default:
          forbiddenRequests++;
          request.response.write('{}');
      }
      await request.response.close();
    });
    final gate = Completer<void>();
    final vault = MemoryVault()..deleteWait = gate.future;
    final api = EvApi(
      Uri.parse('http://127.0.0.1:${server.port}'),
      vault: vault,
    );
    try {
      final login = api.signIn('current@example.test', 'synthetic');
      await expectLater(
        api.request('notes', method: 'POST', body: {'value': 'stale'}),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      expect(forbiddenRequests, 0);
      gate.complete();
      expect((await login).name, 'Current user');
      expect(forbiddenRequests, 0);
    } finally {
      if (!gate.isCompleted) gate.complete();
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

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
      final context = fixtureServerContext(usePkcs12: true);
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
      final delayedClient = EvApi(
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
        await delayedClient.restoreSession();
        final delayed = delayedClient.request('delayed');
        await bodyListening.future;
        final deletion = Completer<void>();
        vault.deleteWait = deletion.future;
        final forgetting = delayedClient.forgetSession();
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
        delayedClient.close();
        if (!bodyRelease.isCompleted) bodyRelease.complete();
        await subscription.cancel();
        await server.close(force: true);
      }
    },
  );
}
