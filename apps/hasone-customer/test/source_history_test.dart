import 'dart:async';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Booking/history/source_history.dart';
import 'package:evcharg/feature/Booking/history/source_history_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

const newest = '000000000000000000000030';
const older = '000000000000000000000020';
Map<String, dynamic> record({String id = newest}) => {
  'sessionId': id,
  'bookingId': '000000000000000000000001',
  'stationName': 'Private station',
  'chargerId': 'A',
  'protocol': 'ocpp1.6',
  'sourceVerified': true,
  'meterVerified': true,
  'readOnly': true,
  'chargerEnabled': true,
  'state': 'ended',
  'energyWh': 5000,
  'energyKwh': 5,
  'startedAt': '2026-11-07T10:00:00Z',
  'endedAt': '2026-11-07T10:30:00Z',
  'billingStatus': 'calculated_unpaid',
  'paymentStatus': 'not_collected',
  'subtotalFils': 500,
  'vatFils': 25,
  'totalFils': 525,
};
Map<String, dynamic> envelope({
  List<Map<String, dynamic>>? rows,
  String? next,
  int unavailable = 0,
}) {
  rows ??= [record()];
  return {
    'status': 'Success',
    'data': {
      'entries': rows,
      'scanned': rows.length + unavailable,
      'unavailableCount': unavailable,
      'hasMore': next != null,
      'nextCursor': next,
      'currency': 'AED',
      'billingMode': 'calculation_only_no_collection',
      'readOnly': true,
    },
  };
}

SourceHistoryPage page({String id = newest, String? next, String? cursor}) =>
    SourceHistoryPage.fromJson(
      envelope(
        rows: [record(id: id)],
        next: next,
      ),
      cursor: cursor,
    );

class RecordingAdapter implements HttpClientAdapter {
  final requests = <RequestOptions>[];
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? body,
    Future<void>? cancel,
  ) async {
    requests.add(options);
    return ResponseBody.fromString(
      '{"status":"fail"}',
      503,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test(
    'source-verified history supports both protocols and exact zero amounts',
    () {
      for (final protocol in ['ocpp1.6', 'ocpp2.0.1']) {
        final entry = SourceHistoryEntry.fromJson({
          ...record(),
          'protocol': protocol,
          'energyWh': 0,
          'energyKwh': 0,
          'subtotalFils': 0,
          'vatFils': 0,
          'totalFils': 0,
        });
        expect(entry.energyWh, 0);
        expect(entry.totalFils, 0);
        expect(entry.state, 'ended');
      }
    },
  );
  test(
    'verified connector scope preserves protocol and both physical locators',
    () {
      for (final protocol in ['ocpp1.6', 'ocpp2.0.1']) {
        final entry = SourceHistoryEntry.fromJson({
          ...record(),
          'protocol': protocol,
          'resourceVerified': true,
          'resourceScope': {
            'protocol': protocol,
            'connectorId': 2,
            'evseId': protocol == 'ocpp2.0.1' ? 3 : null,
          },
        });
        expect(entry.resource!.connectorId, 2);
        expect(entry.resource!.evseId, protocol == 'ocpp2.0.1' ? 3 : null);
        expect(entry.energyWh, 5000);
      }
    },
  );
  test(
    'malformed verified resource cannot be presented as connector proof',
    () {
      for (final scope in [
        null,
        {},
        {'protocol': 'ocpp2.0.1', 'connectorId': 2, 'evseId': 3},
        {'protocol': 'ocpp1.6', 'connectorId': '2'},
        {'protocol': 'ocpp1.6', 'connectorId': 0},
        {'protocol': 'ocpp1.6', 'connectorId': 2, 'evseId': 3},
      ]) {
        expect(
          () => SourceHistoryEntry.fromJson({
            ...record(),
            'resourceVerified': true,
            'resourceScope': scope,
          }),
          throwsFormatException,
        );
      }
    },
  );
  test(
    'legacy unscoped rows never invent a connector from raw source metadata',
    () {
      final entry = SourceHistoryEntry.fromJson({
        ...record(),
        'resourceVerified': false,
        'resourceScope': {'protocol': 'ocpp1.6', 'connectorId': 7},
      });
      expect(entry.resource, isNull);
      expect(entry.energyWh, 5000);
    },
  );
  test('missing proof and malformed meters never present legacy estimates', () {
    for (final changed in [
      {'protocol': 'unknown'},
      {'sourceVerified': 'true'},
      {'sourceVerified': false},
      {'meterVerified': false},
      {'energyWh': -1},
      {'energyWh': '5000'},
      {'energyKwh': double.infinity},
      {'energyKwh': 5000},
    ]) {
      final entry = SourceHistoryEntry.fromJson({...record(), ...changed});
      expect(entry.energyWh, isNull, reason: '$changed');
      expect(entry.totalFils, isNull);
      expect(entry.state, 'unverified');
    }
  });
  test(
    'money requires exact ended-source calculation and never collection',
    () {
      for (final changed in [
        {'totalFils': 524},
        {'subtotalFils': -500},
        {'vatFils': '25'},
        {'billingStatus': 'paid'},
        {'paymentStatus': 'collected'},
        {'state': 'active'},
        {'endedAt': '2026-11-07T09:00:00Z'},
        {'startedAt': '2026-11-07T10:00:00'},
        {'endedAt': null},
      ]) {
        expect(
          SourceHistoryEntry.fromJson({...record(), ...changed}).totalFils,
          isNull,
          reason: '$changed',
        );
      }
    },
  );
  test('invalid row identity and mutable history contract fail closed', () {
    for (final changed in [
      {'sessionId': '../x'},
      {'bookingId': null},
      {'readOnly': false},
    ]) {
      expect(
        () => SourceHistoryEntry.fromJson({...record(), ...changed}),
        throwsFormatException,
      );
    }
  });
  test(
    'cursor order, duplicate rows, counts and contract changes are rejected',
    () {
      for (final value in [
        envelope(rows: [record(), record()]),
        envelope(
          rows: [
            record(id: older),
            record(),
          ],
        ),
        envelope(next: 'bad'),
        envelope(next: newest),
        envelope(rows: List.generate(26, (_) => record())),
      ]) {
        expect(
          () => SourceHistoryPage.fromJson(value, cursor: newest),
          throwsFormatException,
        );
      }
      final data = envelope();
      (data['data'] as Map)['currency'] = 'USD';
      expect(() => SourceHistoryPage.fromJson(data), throwsFormatException);
      final count = envelope();
      (count['data'] as Map)['scanned'] = 2;
      expect(() => SourceHistoryPage.fromJson(count), throwsFormatException);
      final immutable = page();
      expect(() => immutable.entries.clear(), throwsUnsupportedError);
    },
  );
  test('empty redacted pages still allow seeking older records', () {
    final value = SourceHistoryPage.fromJson(
      envelope(rows: [], next: older, unavailable: 1),
    );
    expect(value.entries, isEmpty);
    expect(value.nextCursor, older);
    expect(value.unavailableCount, 1);
  });
  test(
    'failed page retains loaded rows and retries the same immutable cursor',
    () async {
      final calls = <String?>[];
      final controller = SourceHistoryController(
        identity: () => 'A',
        load: (cursor) async {
          calls.add(cursor);
          if (cursor == null) return page(next: newest);
          if (calls.length == 2) throw StateError('network');
          return page(id: older, cursor: cursor);
        },
      );
      await controller.refresh();
      await controller.more();
      expect(controller.failed, true);
      expect(controller.entries.single.sessionId, newest);
      await controller.more();
      expect(calls, [null, newest, newest]);
      expect(controller.entries.map((e) => e.sessionId), [newest, older]);
      expect(controller.hasMore, false);
      controller.dispose();
    },
  );
  test('concurrent load-more calls make one request', () async {
    final pending = Completer<SourceHistoryPage>();
    var seeks = 0;
    final controller = SourceHistoryController(
      identity: () => 'A',
      load: (cursor) async {
        if (cursor == null) return page(next: newest);
        seeks++;
        return pending.future;
      },
    );
    await controller.refresh();
    final first = controller.more();
    await controller.more();
    expect(seeks, 1);
    pending.complete(page(id: older, cursor: newest));
    await first;
    controller.dispose();
  });
  test('refresh invalidates a slower seek response', () async {
    final pending = Completer<SourceHistoryPage>();
    final controller = SourceHistoryController(
      identity: () => 'A',
      load: (cursor) async =>
          cursor == null ? page(next: newest) : pending.future,
    );
    await controller.refresh();
    final seek = controller.more();
    await controller.refresh();
    pending.complete(page(id: older, cursor: newest));
    await seek;
    expect(controller.entries.length, 1);
    controller.dispose();
  });
  test(
    'account switches hide loaded records and discard in-flight history',
    () async {
      var owner = 'A';
      final pending = Completer<SourceHistoryPage>();
      final controller = SourceHistoryController(
        identity: () => owner,
        load: (cursor) async =>
            cursor == null ? page(next: newest) : pending.future,
      );
      await controller.refresh();
      final seek = controller.more();
      owner = 'B';
      expect(controller.entries, isEmpty);
      pending.complete(page(id: older, cursor: newest));
      await seek;
      expect(controller.entries, isEmpty);
      controller.dispose();
    },
  );
  test(
    'unsigned refresh clears rendered data and cannot leave stale loading',
    () async {
      String? owner = 'A';
      var calls = 0;
      final controller = SourceHistoryController(
        identity: () => owner,
        load: (_) async {
          calls++;
          return page();
        },
      );
      await controller.refresh();
      var notifications = 0;
      controller.addListener(() => notifications++);
      owner = null;
      await controller.refresh();
      expect(notifications, 1);
      expect(controller.entries, isEmpty);
      expect(controller.loading, false);
      expect(calls, 1);
      controller.dispose();
    },
  );
  test('background clearing and disposal discard late responses', () async {
    final pending = Completer<SourceHistoryPage>();
    final controller = SourceHistoryController(
      identity: () => 'A',
      load: (_) => pending.future,
    );
    final request = controller.refresh();
    controller.suspend();
    expect(controller.entries, isEmpty);
    controller.dispose();
    pending.complete(page());
    await request;
  });
  test(
    'API is a private GET with bounded query and shared session headers',
    () async {
      final adapter = RecordingAdapter();
      final dio = Dio(
        BaseOptions(
          baseUrl: 'https://example.test',
          validateStatus: (_) => true,
        ),
      )..httpClientAdapter = adapter;
      final client = NetworkClient(
        dio: dio,
        onUnAuthorize: () {},
        commonHeaders: () => {'Authorization': 'Bearer fixture'},
      );
      await expectLater(
        SourceHistoryApi(client).load(older),
        throwsFormatException,
      );
      final request = adapter.requests.single;
      expect(request.method, 'GET');
      expect(request.path, '/api/v1/ChargingSession/history');
      expect(request.queryParameters, {'limit': '25', 'cursor': older});
      expect(request.headers['Authorization'], 'Bearer fixture');
      dio.close();
    },
  );
  testWidgets('verified physical resource is shown in English and Arabic', (
    tester,
  ) async {
    final controller = SourceHistoryController(
      identity: () => 'A',
      load: (_) async => SourceHistoryPage.fromJson(
        envelope(
          rows: [
            {
              ...record(),
              'protocol': 'ocpp2.0.1',
              'resourceVerified': true,
              'resourceScope': {
                'protocol': 'ocpp2.0.1',
                'connectorId': 2,
                'evseId': 3,
              },
            },
          ],
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(home: SourceHistoryScreen(controller: controller)),
    );
    await tester.pumpAndSettle();
    expect(find.text('EVSE 3 · Connector 2'), findsOneWidget);
    await tester.tap(find.text('العربية'));
    await tester.pumpAndSettle();
    expect(find.text('وحدة الشحن 3 · الموصل 2'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets(
    'narrow history is read only, unpaid, Arabic RTL and clears on background',
    (tester) async {
      tester.view.physicalSize = const Size(320, 740);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final controller = SourceHistoryController(
        identity: () => 'A',
        load: (_) async => page(),
      );
      await tester.pumpWidget(
        MaterialApp(home: SourceHistoryScreen(controller: controller)),
      );
      await tester.pumpAndSettle();
      expect(find.text('Private station'), findsOneWidget);
      expect(find.text('5.000 kWh'), findsOneWidget);
      expect(find.text('AED 5.25'), findsOneWidget);
      expect(find.textContaining('not collected'), findsOneWidget);
      expect(find.text('Stop Charging'), findsNothing);
      await tester.tap(find.text('العربية'));
      await tester.pumpAndSettle();
      expect(find.text('سجل الشحن'), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.text('سجل الشحن'))),
        TextDirection.rtl,
      );
      expect(tester.takeException(), isNull);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(controller.entries, isEmpty);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      expect(find.text('Private station'), findsNothing);
      await tester.pumpWidget(const SizedBox());
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    },
  );
  testWidgets(
    'empty redacted first page offers older records and retry retains rows',
    (tester) async {
      var failed = false;
      final controller = SourceHistoryController(
        identity: () => 'A',
        load: (cursor) async {
          if (cursor == null) {
            return SourceHistoryPage.fromJson(
              envelope(rows: [], next: newest, unavailable: 1),
            );
          }
          if (!failed) {
            failed = true;
            throw StateError('offline');
          }
          return page(id: older, cursor: newest);
        },
      );
      await tester.pumpWidget(
        MaterialApp(home: SourceHistoryScreen(controller: controller)),
      );
      await tester.pumpAndSettle();
      expect(find.text('Records requiring review: 1'), findsOneWidget);
      await tester.tap(find.text('Load older sessions'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(find.text('Private station'), findsOneWidget);
      expect(find.text('Load older sessions'), findsNothing);
      await tester.pumpWidget(const SizedBox());
    },
  );
}
