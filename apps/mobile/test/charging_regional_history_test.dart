import 'package:creabiz_ev/charger_firmware.dart';
import 'package:creabiz_ev/charger_logs.dart';
import 'package:creabiz_ev/charger_repairs.dart';
import 'package:creabiz_ev/charging_operations.dart';
import 'package:creabiz_ev/reservation_times.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'charger_firmware_test.dart' show FirmwareApi;
import 'charger_logs_test.dart' show LogsApi;
import 'charging_operations_test.dart' show OpsApi;
import 'charger_repairs_test.dart' show RepairApi;

const validClock = '2026-11-06T12:01:00Z';
const invalidClock = '2026-02-30T12:01:00Z';

Widget regionalApp(String language, Widget page) => MaterialApp(
  locale: Locale(language),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: page,
);

class RegionalLogsApi extends LogsApi {
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final result = await super.request(path, method: method, body: body, query: query);
    if (method == 'GET') {
      result['data'][0]['expires_at'] = invalidClock;
      result['data'][0]['file_received_at'] = validClock;
      result['data'][0]['file_expires_at'] = '2026-11-07T12:01:00Z';
    }
    return result;
  }
}

class RegionalRepairApi extends RepairApi {
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/history')) {
      return {
        'data': [
          {'version': 2, 'note': 'Second repair', 'created_at': validClock},
          {'version': 1, 'note': 'First repair', 'created_at': invalidClock},
        ],
        'current_page': 1, 'last_page': 1, 'total': 2,
      };
    }
    final result = await super.request(path, method: method, body: body, query: query);
    if (method == 'GET') {
      result['data'] = [{'id': 8, 'alert_id': 7, 'state': 'closed', 'version': 2}];
      result['total'] = 1;
      result['can_manage'] = false;
    }
    return result;
  }
}

class RegionalOpsApi extends OpsApi {
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final result = await super.request(path, method: method, body: body, query: query);
    if (path == 'charging-operations/7/case' && method == 'GET') {
      result['events'] = {
        'data': [
          {'action': 'opened', 'note': 'Source review', 'created_at': validClock},
          {'action': 'source_inspected', 'note': 'Invalid clock', 'created_at': invalidClock},
        ],
        'current_page': 1, 'last_page': 1, 'total': 2,
      };
    }
    return result;
  }
}

void main() {
  for (final language in ['en', 'ar']) {
    final ar = language == 'ar';
    final unavailable = ar ? 'وقت الحدث غير متاح' : 'Event time unavailable';
    testWidgets('diagnostic receipt and retention are distinct source clocks ($language)', (tester) async {
      tester.view.physicalSize = const Size(1100, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = RegionalLogsApi();
      await tester.pumpWidget(regionalApp(language, ChargerLogsView(
        api: api, companyId: 2, chargerId: 3, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      final context = tester.element(find.byType(ChargerLogsView));
      final receipt = serverEventTime(context, validClock);
      final retention = serverEventTime(context, '2026-11-07T12:01:00Z');
      expect(receipt, contains('UTC'));
      expect(retention, isNot(receipt));
      expect(find.text('${ar ? 'تم استلام الملف' : 'File received'}: $receipt'), findsOneWidget);
      expect(find.text('${ar ? 'تنتهي مدة الاحتفاظ بالملف' : 'File retention expires'}: $retention'), findsOneWidget);
      expect(find.text('${ar ? 'تنتهي صلاحية الرفع' : 'Upload expires'}: $unavailable'), findsOneWidget);
      expect(find.textContaining(invalidClock), findsNothing);
      expect(api.attempts, 0);
    });
    testWidgets('firmware history redacts malformed server date ($language)', (tester) async {
      tester.view.physicalSize = const Size(1100, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = FirmwareApi()..blocked = true..manager = false;
      await tester.pumpWidget(regionalApp(language, ChargerFirmwareView(
        api: api, companyId: 1, chargerId: 2, manufacturer: 'Synthetic',
        model: 'Fixture', onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(find.text(unavailable), findsOneWidget);
      expect(find.text('2026-11-14'), findsNothing);
      expect(api.attempts, isEmpty);
    });
    testWidgets('repair and recovery histories display verified UTC only ($language)', (tester) async {
      tester.view.physicalSize = const Size(1100, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final repair = RegionalRepairApi();
      await tester.pumpWidget(regionalApp(language, ChargerRepairsView(
        api: repair, companyId: 2, chargerId: 3, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      await tester.tap(find.text(ar ? 'سجل الإصلاح' : 'Repair history'));
      await tester.pumpAndSettle();
      final ctx = tester.element(find.text('Second repair'));
      expect(find.text('#2 · ${serverEventTime(ctx, validClock)}'), findsOneWidget);
      expect(find.text('#1 · $unavailable'), findsOneWidget);
      expect(find.textContaining(invalidClock), findsNothing);
      expect(repair.writes, 0);
      await tester.pumpWidget(const SizedBox.shrink());
      final ops = RegionalOpsApi();
      await tester.pumpWidget(regionalApp(language, ChargingOperationsView(
        api: ops, onExpired: () {}, focusCommandId: 7,
      )));
      await tester.pumpAndSettle();
      await tester.tap(find.text(ar ? 'سجل الحالة' : 'Case history'));
      await tester.pumpAndSettle();
      final valid = find.textContaining('Source review');
      final context = tester.element(valid);
      expect(find.text('Source review\n${serverEventTime(context, validClock)}'), findsOneWidget);
      expect(find.text('Invalid clock\n$unavailable'), findsOneWidget);
      expect(find.textContaining(invalidClock), findsNothing);
      expect(ops.writes, 0);
    });
  }
}
