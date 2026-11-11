import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/charging_provenance.dart';
import 'test_support.dart';

class ProofApi extends FixtureApi {
  bool unsafe = false, noSource = false;
  Completer<Map<String, dynamic>>? delayed;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(path, 'finance/invoices/7/source-evidence');
    if (delayed != null) return delayed!.future;
    return {
      'schema_version': 1,
      'invoice_id': 7,
      'energy_wh': 1000,
      'evidence': 'SIMULATED_ONLY',
      'physical_charging_verified': unsafe,
      'financial_records_changed': false,
      'payment_authority': false,
      'charging_command_authority': false,
      'kind': noSource ? 'APP_SIMULATION' : 'OCPI_SANDBOX',
      'source_verified': !noSource,
      'status': noSource ? 'not_source_verified' : 'verified_simulated',
      'protocol': noSource ? null : 'OCPI 2.2.1',
      'proofs': noSource
          ? <String, String>{}
          : {'session_sha256': 'a' * 64, 'cdr_sha256': 'b' * 64},
    };
  }
}

void main() {
  testWidgets(
    'displays source receipts as simulated proof and rejects physical authority',
    (t) async {
      final api = ProofApi();
      await t.pumpWidget(
        MaterialApp(
          home: ChargingProvenanceView(
            api: api,
            invoiceId: 7,
            onExpired: () {},
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(
        find.text('Source receipts match this simulated invoice'),
        findsOneWidget,
      );
      expect(find.text('a' * 64), findsOneWidget);
      api.unsafe = true;
      await t.tap(find.text('Refresh evidence'));
      await t.pumpAndSettle();
      expect(find.text('a' * 64), findsNothing);
      expect(
        find.text('Charging evidence could not be verified. Retry safely.'),
        findsOneWidget,
      );
    },
  );
  testWidgets('application-only invoice is never promoted to source verified', (
    t,
  ) async {
    final api = ProofApi()..noSource = true;
    await t.pumpWidget(
      MaterialApp(
        home: ChargingProvenanceView(api: api, invoiceId: 7, onExpired: () {}),
      ),
    );
    await t.pumpAndSettle();
    expect(
      find.text('Application simulation without source verification'),
      findsOneWidget,
    );
    expect(
      find.text('Source receipts match this simulated invoice'),
      findsNothing,
    );
  });
  testWidgets(
    'backgrounding drops delayed private proof and only explicit refresh restores it',
    (t) async {
      final api = ProofApi()..delayed = Completer<Map<String, dynamic>>();
      await t.pumpWidget(
        MaterialApp(
          home: ChargingProvenanceView(
            api: api,
            invoiceId: 7,
            onExpired: () {},
          ),
        ),
      );
      await t.pump();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await t.pump();
      final delayed = api.delayed!;
      api.delayed = null;
      delayed.complete(
        Map<String, dynamic>.from(
          await api.request('finance/invoices/7/source-evidence') as Map,
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('a' * 64), findsNothing);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(find.text('a' * 64), findsNothing);
      await t.tap(find.text('Refresh evidence'));
      await t.pumpAndSettle();
      expect(find.text('a' * 64), findsOneWidget);
    },
  );
}
