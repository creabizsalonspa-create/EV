import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_certificates.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

class CertificateStore implements FinancialOperationStore {
  final values = <String, Map<String, dynamic>>{};
  @override
  Future<Map<String, dynamic>?> read(String scope) async =>
      values[scope] == null
      ? null
      : Map<String, dynamic>.from(jsonDecode(jsonEncode(values[scope])));
  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    values[scope] = Map<String, dynamic>.from(jsonDecode(jsonEncode(value)));
  }

  @override
  Future<void> remove(String scope) async {
    values.remove(scope);
  }
}

class CertificateApi extends FixtureApi {
  final bodies = <Map<String, dynamic>>[];
  bool lose = true,
      committed = false,
      cancel = false,
      commitOnPost = true,
      wrongActor = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') return {'id': 1, 'role': 'vendor'};
    if (method == 'GET')
      return {
        'schema_version': 1,
        'profile_id': 9,
        'identity': 'ev-test-certificate',
        'credential_version': committed ? 1 : 0,
        'managed_certificate_id': committed ? 8 : null,
        'can_manage': true,
        'enrollment_enabled': true,
        'device_commands_sent': false,
        'connection_close_requested': false,
        'gateway_recheck_may_disconnect': true,
        'current_page': 1,
        'last_page': 1,
        'data': [],
      };
    if (path.endsWith('/resolve'))
      return receipt(
        body!,
        cancel ? 'cancelled' : 'completed',
        body['expected_action'],
      );
    bodies.add(Map<String, dynamic>.from(body!));
    committed = commitOnPost;
    if (lose) {
      lose = false;
      throw const ApiException(503, 'synthetic lost acknowledgement');
    }
    return receipt(body, 'completed', 'enroll');
  }

  Map<String, dynamic> receipt(
    Map<String, dynamic> body,
    String state,
    String action,
  ) => {
    'actor_id': wrongActor ? 2 : 1,
    'profile_id': 9,
    'request_key': body['idempotency_key'],
    'action': action,
    'state': state,
    'result_version': 1,
    'certificate_id': state == 'completed' ? 8 : null,
    'certificate': state == 'completed'
        ? {
            'id': 8,
            'profile_id': 9,
            'identity': 'ev-test-certificate',
            'fingerprint': sha256.convert([0]).toString(),
          }
        : null,
    'device_commands_sent': false,
    'connection_close_requested': false,
    'gateway_recheck_may_disconnect': true,
  };
}

Widget screen(CertificateApi api, CertificateStore store) => MaterialApp(
  home: ChargerCertificatesPage(
    api: api,
    endpoint: 'vendor-ops/companies/1/chargers/2/admission/certificates',
    profileId: 9,
    store: store,
    onExpired: () {},
  ),
);
Future<void> enroll(WidgetTester tester, {String chain = ''}) async {
  await tester.enterText(
    find.widgetWithText(TextField, 'Certificate operation reason'),
    'Explicit isolated certificate enrollment',
  );
  await tester.enterText(
    find.widgetWithText(TextField, 'Public PEM client certificate'),
    '-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----',
  );
  if (chain.isNotEmpty) {
    await tester.scrollUntilVisible(
      find.widgetWithText(
        TextField,
        'Intermediate issuer certificates (optional)',
      ),
      160,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.enterText(
      find.widgetWithText(
        TextField,
        'Intermediate issuer certificates (optional)',
      ),
      chain,
    );
  }
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
  await tester.ensureVisible(find.byType(CheckboxListTile));
  await tester.tap(find.byType(CheckboxListTile));
  await tester.pump();
  await tester.ensureVisible(find.text('Enroll or renew public certificate'));
  await tester.tap(find.text('Enroll or renew public certificate'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'lost acknowledgement retries identical public certificate and identity',
    (tester) async {
      final api = CertificateApi(), store = CertificateStore();
      await tester.pumpWidget(screen(api, store));
      await tester.pumpAndSettle();
      await enroll(tester);
      expect(api.bodies.length, 1);
      expect(store.values.length, 1);
      await tester.ensureVisible(find.text('Retry saved request'));
      await tester.tap(find.text('Retry saved request'));
      await tester.pumpAndSettle();
      expect(api.bodies.length, 2);
      expect(api.bodies[1], api.bodies[0]);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'background clears public PEM and explicit refresh recovers without replay',
    (tester) async {
      final api = CertificateApi(), store = CertificateStore();
      await tester.pumpWidget(screen(api, store));
      await tester.pumpAndSettle();
      await enroll(tester);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.byType(TextField), findsNothing);
      expect(find.textContaining('AA=='), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      await tester.tap(find.text('Refresh certificates'));
      await tester.pumpAndSettle();
      expect(api.bodies.length, 1);
      await tester.ensureVisible(
        find.text('Resolve result or cancel unissued request'),
      );
      await tester.tap(find.text('Resolve result or cancel unissued request'));
      await tester.pumpAndSettle();
      expect(api.bodies.length, 1);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'restart restores only the scoped operation and cancellation stops another intent',
    (tester) async {
      final api = CertificateApi()..commitOnPost = false,
          store = CertificateStore();
      await tester.pumpWidget(screen(api, store));
      await tester.pumpAndSettle();
      await enroll(tester);
      await tester.pumpWidget(const MaterialApp(home: SizedBox()));
      await tester.pumpWidget(screen(api, store));
      await tester.pumpAndSettle();
      expect(api.bodies.length, 1);
      api.cancel = true;
      await tester.ensureVisible(
        find.text('Resolve result or cancel unissued request'),
      );
      await tester.tap(find.text('Resolve result or cancel unissued request'));
      await tester.pumpAndSettle();
      expect(store.values, isEmpty);
      expect(api.bodies.length, 1);
    },
  );
  testWidgets(
    'wrong actor receipt cannot clear a saved certificate operation',
    (tester) async {
      final api = CertificateApi()
        ..lose = false
        ..wrongActor = true;
      final store = CertificateStore();
      await tester.pumpWidget(screen(api, store));
      await tester.pumpAndSettle();
      await enroll(tester);
      expect(store.values.length, 1);
      expect(find.text('Retry saved request'), findsOneWidget);
    },
  );
  testWidgets('issuer chain is frozen with the original enrollment retry', (
    tester,
  ) async {
    final api = CertificateApi(), store = CertificateStore();
    await tester.pumpWidget(screen(api, store));
    await tester.pumpAndSettle();
    const chain =
        '-----BEGIN CERTIFICATE-----\nAQ==\n-----END CERTIFICATE-----';
    await enroll(tester, chain: chain);
    expect(api.bodies.single['issuer_chain'], chain);
    await tester.ensureVisible(find.text('Retry saved request'));
    await tester.tap(find.text('Retry saved request'));
    await tester.pumpAndSettle();
    expect(api.bodies.length, 2);
    expect(api.bodies[0], api.bodies[1]);
    expect(store.values, isEmpty);
  });
  testWidgets(
    'private issuer key is cleared before intent storage or dispatch',
    (tester) async {
      final api = CertificateApi(), store = CertificateStore();
      await tester.pumpWidget(screen(api, store));
      await tester.pumpAndSettle();
      await enroll(
        tester,
        chain: '-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----',
      );
      expect(api.bodies, isEmpty);
      expect(store.values, isEmpty);
      expect(
        tester
            .widget<TextField>(
              find.widgetWithText(
                TextField,
                'Intermediate issuer certificates (optional)',
              ),
            )
            .controller!
            .text,
        '',
      );
    },
  );
}
