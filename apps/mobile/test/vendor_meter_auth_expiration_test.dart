import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/session_vault.dart';
import 'package:creabiz_ev/vendor_session_observations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

// Exercise the real EvApi credential invalidation, not an override of request.
class RejectedClient implements HttpClient {
  final int code;
  RejectedClient(this.code);
  @override
  set connectionTimeout(Duration? value) {}
  @override
  Future<HttpClientRequest> openUrl(String method, Uri url) async =>
      RejectedRequest(code);
  @override
  void close({bool force = false}) {}
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class RejectedHeaders implements HttpHeaders {
  @override
  void set(String name, Object value, {bool preserveHeaderCase = false}) {}
  @override
  set contentType(ContentType? value) {}
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class RejectedRequest implements HttpClientRequest {
  final int code;
  RejectedRequest(this.code);
  @override
  final HttpHeaders headers = RejectedHeaders();
  @override
  final List<Cookie> cookies = [];
  @override
  set followRedirects(bool value) {}
  @override
  Future<HttpClientResponse> close() async => RejectedResponse(code);
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class RejectedResponse extends Stream<List<int>> implements HttpClientResponse {
  @override
  final int statusCode;
  RejectedResponse(this.statusCode);
  @override
  final List<Cookie> cookies = [];
  @override
  StreamSubscription<List<int>> listen(
    void Function(List<int>)? onData, {
    Function? onError,
    void Function()? onDone,
    bool? cancelOnError,
  }) => Stream.value(utf8.encode(jsonEncode({'message': 'PRIVATE'}))).listen(
    onData,
    onError: onError,
    onDone: onDone,
    cancelOnError: cancelOnError,
  );
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class GatedVault implements SessionVault {
  final started = Completer<void>(), release = Completer<void>();
  bool hold = true, fail = false;
  @override
  Future<String?> read(String origin) async => null;
  @override
  Future<void> write(String origin, String value) async {}
  @override
  Future<void> delete(String origin) async {
    if (!started.isCompleted) started.complete();
    if (hold) await release.future;
    if (fail) throw StateError('locked');
  }
}

// Buffer only delivery so real EvApi I/O runs in tester.runAsync; the client,
// cookie deletion, auth epoch and thrown error are the actual implementation.
class BufferedTransportApi extends EvApi {
  final response = Completer<dynamic>();
  String? path;
  Map<String, String> query = const {};
  BufferedTransportApi(int code, {SessionVault? vault})
    : super(
        Uri.parse('http://127.0.0.1'),
        http: RejectedClient(code),
        vault: vault,
      );
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    expect(method, 'GET');
    expect(body, isNull);
    this.path = path;
    this.query = query;
    return response.future;
  }

  Future<void> deliver() async {
    try {
      response.complete(await super.request(path!, query: query));
    } catch (e, stack) {
      response.completeError(e, stack);
    }
  }
}

Widget view(EvApi api, VoidCallback expired) => MaterialApp(
  home: VendorSessionObservationsView(
    api: api,
    companyId: 4,
    sessionId: 27,
    onExpired: expired,
  ),
);
void main() {
  test('controlled transport reaches actual credential rejection', () async {
    final api = EvApi(Uri.parse('http://127.0.0.1'), http: RejectedClient(401));
    addTearDown(api.close);
    await expectLater(
      api.request('test'),
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
    );
  });
  for (final code in [401, 419])
    testWidgets(
      'real EvApi $code expires the same view after clearing its credential epoch',
      (tester) async {
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        final api = BufferedTransportApi(code);
        addTearDown(api.close);
        var expired = 0;
        await tester.pumpWidget(view(api, () => expired++));
        await tester.runAsync(api.deliver);
        await tester.pumpAndSettle();
        expect(expired, 1);
      },
    );
  testWidgets(
    'old unauthorized deletion cannot expire a newer credential transition',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      late GatedVault vault;
      final api = (await tester.runAsync(() async {
        vault = GatedVault();
        return BufferedTransportApi(401, vault: vault);
      }))!;
      addTearDown(api.close);
      var expired = 0;
      late bool Function() newerFence;
      await tester.pumpWidget(view(api, () => expired++));
      await tester.runAsync(() async {
        final delivery = api.deliver();
        await vault.started.future;
        final newer = api.forgetSession();
        newerFence = api.sessionFence();
        vault.hold = false;
        vault.release.complete();
        await Future.wait([delivery, newer]);
      });
      await tester.pumpAndSettle();
      expect(expired, 0);
      expect(newerFence(), isTrue);
    },
  );
  testWidgets(
    'failed secure deletion still reports current expired authentication truthfully',
    (tester) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = (await tester.runAsync(() async {
        final vault = GatedVault()
          ..hold = false
          ..fail = true;
        return BufferedTransportApi(401, vault: vault);
      }))!;
      addTearDown(api.close);
      var expired = 0;
      await tester.pumpWidget(view(api, () => expired++));
      await tester.runAsync(api.deliver);
      await tester.pumpAndSettle();
      expect(expired, 1);
    },
  );
  test(
    'real unauthorized exception ceases ownership after a later transition',
    () async {
      final api = EvApi(
        Uri.parse('http://127.0.0.1'),
        http: RejectedClient(401),
      );
      addTearDown(api.close);
      ApiException? rejection;
      try {
        await api.request('test');
      } on ApiException catch (e) {
        rejection = e;
      }
      expect(rejection, isNotNull);
      expect(rejection!.endsCurrentSession, isTrue);
      await api.forgetSession();
      expect(rejection.endsCurrentSession, isFalse);
    },
  );
  test('business conflicts never become current authentication failures', () {
    for (final status in [0, 400, 403, 409, 422, 429, 500]) {
      expect(
        ApiException(
          status,
          'private',
          expirationIsCurrent: () => true,
        ).endsCurrentSession,
        isFalse,
      );
    }
  });
}
