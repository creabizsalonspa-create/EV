import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  test(
    'diagnostic binary transport remains path/type/size restricted without widening private document MIME rules',
    () async {
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      var overflow = false, wrongMime = false, requests = 0;
      final sub = server.listen((r) async {
        requests++;
        r.response.headers.contentType = ContentType(
          wrongMime ? 'text' : 'application',
          wrongMime ? 'html' : 'octet-stream',
        );
        r.response.add(List<int>.filled(overflow ? 524289 : 8, 42));
        await r.response.close();
      });
      final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
      addTearDown(() async {
        api.close();
        await sub.cancel();
        await server.close(force: true);
      });
      const path =
          'vendor-ops/companies/1/chargers/2/diagnostic-logs/3/download';
      final doc = await api.downloadDiagnosticLog(path);
      expect(doc.bytes.length, 8);
      await expectLater(
        api.downloadDocument(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 502)),
      );
      wrongMime = true;
      await expectLater(
        api.downloadDiagnosticLog(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 502)),
      );
      wrongMime = false;
      overflow = true;
      await expectLater(
        api.downloadDiagnosticLog(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 502)),
      );
      final before = requests;
      await expectLater(
        api.downloadDiagnosticLog('vendor-ops/companies/1/documents/3'),
        throwsArgumentError,
      );
      expect(requests, before);
    },
  );
}
