import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  test(
    'case evidence transport rejects invalid source, category, type and four-MiB overflow before I/O',
    () {
      final api = EvApi(Uri.parse('http://127.0.0.1:9'));
      addTearDown(api.close);
      for (final args in [
        (0, 'receipt', 'application/pdf', 1),
        (1, 'identity', 'application/pdf', 1),
        (1, 'receipt', 'text/html', 1),
        (1, 'receipt', 'application/pdf', 4 * 1024 * 1024 + 1),
      ]) {
        expect(
          () => api.uploadCaseEvidence(
            args.$1,
            kind: args.$2,
            mime: args.$3,
            bytes: Uint8List(args.$4),
            key: operationKey(),
          ),
          throwsA(isA<ApiException>()),
        );
      }
    },
  );

  test(
    'multipart evidence reuses CSRF/cookie scope, fixed filename and stable operation identity',
    () async {
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      int uploads = 0;
      final key = operationKey();
      final sub = server.listen((r) async {
        r.response.headers.contentType = ContentType.json;
        if (r.uri.path == '/api/csrf') {
          r.response.cookies.add(
            Cookie('session', 'synthetic-private-cookie')
              ..httpOnly = true
              ..path = '/',
          );
          r.response.write(jsonEncode({'token': 'synthetic-csrf'}));
        } else {
          uploads++;
          expect(r.headers.contentType!.mimeType, 'multipart/form-data');
          expect(r.headers.value('X-CSRF-TOKEN'), 'synthetic-csrf');
          expect(r.cookies.single.value, 'synthetic-private-cookie');
          final body = latin1.decode(
            await r.fold<List<int>>([], (a, b) => a..addAll(b)),
          );
          expect(body, contains('filename="document.pdf"'));
          expect(body, contains(key));
          expect(body, contains('%PDF-1.4'));
          expect(body, contains('name="kind"'));
          r.response.write('{"id":9}');
        }
        await r.response.close();
      });
      final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
      addTearDown(() async {
        api.close();
        await sub.cancel();
        await server.close(force: true);
      });
      await api.csrf();
      await api.uploadDocument(
        'vendor-ops/companies/1/documents',
        kind: 'trade_license',
        bytes: Uint8List.fromList(utf8.encode('%PDF-1.4')),
        mime: 'application/pdf',
        key: key,
      );
      expect(uploads, 1);
    },
  );
  test(
    'document type and file-size input limits fail before any request',
    () async {
      final api = EvApi(Uri.parse('http://127.0.0.1:9'));
      addTearDown(api.close);
      expect(
        () => api.uploadDocument(
          'vendor-ops/companies/1/documents',
          kind: 'identity',
          bytes: Uint8List(5 * 1024 * 1024 + 1),
          mime: 'image/png',
          key: operationKey(),
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
      );
      expect(
        () => api.uploadDocument(
          'vendor-ops/companies/1/documents',
          kind: 'identity',
          bytes: Uint8List(1),
          mime: 'text/html',
          key: operationKey(),
        ),
        throwsA(isA<ApiException>()),
      );
    },
  );
  test(
    'private binary download preserves bytes and rejects redirects without forwarding a cookie',
    () async {
      final target = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      int leaked = 0;
      final trap = target.listen((r) async {
        leaked++;
        await r.response.close();
      });
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      final bytes = Uint8List.fromList([137, 80, 78, 71, 13, 10, 26, 10]);
      final sub = server.listen((r) async {
        if (r.uri.path.endsWith('/1')) {
          r.response.headers.contentType = ContentType('image', 'png');
          r.response.add(bytes);
        } else {
          r.response.statusCode = 302;
          r.response.headers.set(
            HttpHeaders.locationHeader,
            'http://127.0.0.1:${target.port}/leak',
          );
        }
        await r.response.close();
      });
      final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
      addTearDown(() async {
        api.close();
        await sub.cancel();
        await trap.cancel();
        await server.close(force: true);
        await target.close(force: true);
      });
      final doc = await api.downloadDocument(
        'vendor-ops/companies/1/documents/1',
      );
      expect(doc.bytes, bytes);
      expect(doc.mime, 'image/png');
      await expectLater(
        api.downloadDocument('vendor-ops/companies/1/documents/2'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 302)),
      );
      expect(leaked, 0);
    },
  );
  test(
    'session expiry during document access clears cookies and never returns private bytes',
    () async {
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      bool cookieAfter = false;
      final sub = server.listen((r) async {
        r.response.headers.contentType = ContentType.json;
        if (r.uri.path == '/api/csrf') {
          r.response.cookies.add(
            Cookie('session', 'synthetic-private-cookie')..path = '/',
          );
          r.response.write('{"token":"synthetic"}');
        } else if (r.uri.path.endsWith('documents/1')) {
          r.response.statusCode = 401;
          r.response.write('{"message":"private diagnostic"}');
        } else {
          cookieAfter = r.cookies.isNotEmpty;
          r.response.write('{}');
        }
        await r.response.close();
      });
      final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
      addTearDown(() async {
        api.close();
        await sub.cancel();
        await server.close(force: true);
      });
      await api.csrf();
      await expectLater(
        api.downloadDocument('vendor-ops/companies/1/documents/1'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
      );
      await api.request('me');
      expect(cookieAfter, false);
    },
  );
  test(
    'logout fences a private document whose body finishes afterward',
    () async {
      final started = Completer<void>(), release = Completer<void>();
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      final sub = server.listen((r) async {
        if (r.uri.path.endsWith('documents/1')) {
          r.response.headers.contentType = ContentType('image', 'png');
          r.response.write('private-');
          await r.response.flush();
          started.complete();
          await release.future;
          r.response.write('body');
        } else {
          r.response.statusCode = 204;
        }
        await r.response.close();
      });
      final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
      addTearDown(() async {
        api.close();
        await sub.cancel();
        await server.close(force: true);
      });
      final pending = api.downloadDocument(
        'vendor-ops/companies/1/documents/1',
      );
      final assertion = expectLater(
        pending,
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await started.future;
      await api.signOut();
      release.complete();
      await assertion;
    },
  );
}
