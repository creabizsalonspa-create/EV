import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:file_picker/file_picker.dart';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_logs.dart';
import 'test_support.dart';

class LogsApi extends FixtureApi {
  bool lose = false, unsafe = false, wrongBytes = false, deny = false;
  int attempts = 0;
  Map<String, dynamic>? first;
  final bytes = Uint8List.fromList(
    'Private synthetic device diagnostic log'.codeUnits,
  );
  @override
  Future<PrivateDocument> downloadDiagnosticLog(String path) async =>
      PrivateDocument(
        wrongBytes ? Uint8List.fromList([1, 2, 3]) : bytes,
        'application/octet-stream',
      );
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (deny) throw const ApiException(401, 'PRIVATE auth detail');
    if (method == 'POST') {
      attempts++;
      first ??= Map.from(body!);
      expect(body, first);
      if (lose) {
        lose = false;
        throw const ApiException(503, 'PRIVATE uncertain transport');
      }
      return {'id': 1};
    }
    return {
      'can_request': true,
      'can_manage': true,
      'maximum_file_bytes': 524288,
      'evidence': 'SIMULATED_SOURCE',
      'charging_capacity_changed': unsafe,
      'current_page': 1,
      'last_page': 1,
      'data': [
        {
          'id': 1,
          'protocol': '1.6',
          'evidence': 'SIMULATED_SOURCE',
          'physical_device_verified': false,
          'can_download': true,
          'dispatch_state': 'transport_submitted',
          'expired': false,
          'cancelled_at': null,
          'device_status': 'legacy_status_unattributed',
          'expires_at': '2026-11-06T12:15:00Z',
          'file_received_at': '2026-11-06T12:01:00Z',
          'file_expires_at': '2026-11-07T12:01:00Z',
          'accepted_at': '2026-11-06T12:00:01Z',
          'file_size': bytes.length,
          'file_sha256': sha256.convert(bytes).toString(),
        },
      ],
    };
  }
}

class ExportPicker extends FilePicker {
  int saves = 0;
  Uint8List? saved;
  @override
  Future<String?> saveFile({
    String? dialogTitle,
    String? fileName,
    String? initialDirectory,
    FileType type = FileType.any,
    List<String>? allowedExtensions,
    Uint8List? bytes,
    bool lockParentWindow = false,
  }) async {
    saves++;
    saved = bytes;
    expect(fileName, 'charger-log-1.bin');
    return null;
  }
}

Widget app(LogsApi api, VoidCallback expired) => MaterialApp(
  home: ChargerLogsView(
    api: api,
    companyId: 2,
    chargerId: 3,
    repairOrderId: 7,
    onExpired: expired,
  ),
);
void main() {
  testWidgets(
    'lost diagnostic request keeps its original identity and refresh never redispatches',
    (tester) async {
      final api = LogsApi()..lose = true;
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Request diagnostic file'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Inspect this synthetic source log safely',
      );
      await tester.pump();
      await tester.tap(find.text('Request'));
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.tap(find.text('Refresh diagnostic history'));
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      await tester.tap(find.text('Resolve diagnostic request'));
      await tester.pumpAndSettle();
      expect(api.attempts, 2);
      expect(api.first!['repair_order_id'], 7);
      expect(find.text('Request diagnostic file'), findsOneWidget);
    },
  );
  testWidgets(
    'only integrity-checked bytes reach explicit OS export and expiry clears private receipts',
    (tester) async {
      final api = LogsApi();
      final picker = ExportPicker();
      FilePicker.platform = picker;
      var expired = 0;
      await tester.pumpWidget(app(api, () => expired++));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Save private diagnostic file'),
        150,
      );
      await tester.tap(find.text('Save private diagnostic file'));
      await tester.pumpAndSettle();
      expect(picker.saves, 1);
      expect(picker.saved, api.bytes);
      api.wrongBytes = true;
      await tester.tap(find.text('Save private diagnostic file'));
      await tester.pumpAndSettle();
      expect(picker.saves, 1);
      await Scrollable.ensureVisible(
        tester.element(find.text('Refresh diagnostic history')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      api.deny = true;
      await tester.tap(find.text('Refresh diagnostic history'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Save private diagnostic file'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
    },
  );
  testWidgets('unexpected charger capability fails closed', (tester) async {
    final api = LogsApi()..unsafe = true;
    await tester.pumpWidget(app(api, () {}));
    await tester.pumpAndSettle();
    expect(find.text('Request diagnostic file'), findsNothing);
    expect(find.text('Save private diagnostic file'), findsNothing);
    expect(api.attempts, 0);
  });
}
