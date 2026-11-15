import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'sandbox_roaming_test.dart' as fixture;

class ReservationApi extends fixture.RoamingApi {
  bool supported = true;
  final List<String> writes = [];
  @override
  Map<String, dynamic> order() => {
    ...super.order(),
    'intent_kind': 'reservation',
    'reservation_expires_at': '2026-12-03T12:05:00Z',
    'reservation_closed': false,
    'reservation_source_cancelled': false,
    'reservation_billing_supported': false,
    'can_cancel_reservation': true,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') writes.add(path);
    final result = await super.request(
      path,
      method: method,
      body: body,
      query: query,
    );
    if (path.endsWith('sandbox-offers'))
      return {
        ...Map<String, dynamic>.from(result),
        'reservation_supported': supported,
        'reservation_terms_version': 'sandbox-reservation-preauthorization-v1',
      };
    return result;
  }
}

void main() {
  testWidgets(
    'separate reservation consent freezes duration and identity after uncertain transport',
    (tester) async {
      final api = ReservationApi()..lost = true;
      await tester.pumpWidget(fixture.app(api));
      await tester.pumpAndSettle();
      final reserve = find.widgetWithText(FilledButton, 'Reserve sandbox EVSE');
      expect(tester.widget<FilledButton>(reserve).onPressed, isNull);
      await tester.ensureVisible(find.byType(CheckboxListTile).first);
      await tester.tap(find.byType(CheckboxListTile).first);
      await tester.pumpAndSettle();
      expect(tester.widget<FilledButton>(reserve).onPressed, isNull);
      final duration = find.byType(DropdownButtonFormField<int>);
      await tester.ensureVisible(duration);
      await tester.tap(duration);
      await tester.pumpAndSettle();
      await tester.tap(find.text('10').last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byType(CheckboxListTile).last);
      await tester.tap(find.byType(CheckboxListTile).last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(reserve);
      await tester.tap(reserve);
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      expect(api.first!['duration_minutes'], 10);
      expect(
        api.first!['terms_version'],
        'sandbox-reservation-preauthorization-v1',
      );
      expect(api.first!['consent'], true);
      expect(api.writes.single, 'roaming/locations/2/sandbox-reservations');
      final saved = Map<String, dynamic>.from(api.first!);
      await tester.ensureVisible(find.text('Retry same sandbox request'));
      await tester.tap(find.text('Retry same sandbox request'));
      await tester.pumpAndSettle();
      expect(api.attempts, 2);
      expect(api.first, saved);
      expect(api.writes.toSet(), {'roaming/locations/2/sandbox-reservations'});
    },
  );
  testWidgets(
    'reservation cancellation requires confirmation and never issues charging stop',
    (tester) async {
      final api = ReservationApi();
      await tester.pumpWidget(fixture.app(api));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Review sandbox history'));
      await tester.tap(find.text('Review sandbox history'));
      await tester.pumpAndSettle();
      final cancel = find.text('Cancel sandbox reservation');
      await tester.ensureVisible(cancel);
      await tester.tap(cancel);
      await tester.pumpAndSettle();
      expect(api.writes, isEmpty);
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(api.writes, isEmpty);
      await tester.ensureVisible(cancel);
      await tester.tap(cancel);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, ['roaming/sandbox-orders/1/cancel-reservation']);
      expect(api.first!['consent'], true);
      expect(api.first!['request_key'], isNotEmpty);
    },
  );
  testWidgets('operator reservation history exposes no customer mutations', (
    tester,
  ) async {
    final api = ReservationApi();
    await tester.pumpWidget(fixture.app(api, operator: true));
    await tester.pumpAndSettle();
    expect(find.text('Cancel sandbox reservation'), findsNothing);
    expect(find.text('Reserve sandbox EVSE'), findsNothing);
    expect(find.textContaining('Capacity remains held'), findsOneWidget);
    expect(api.writes, isEmpty);
  });
  testWidgets('disabled reservation contract exposes no new consent', (
    tester,
  ) async {
    final api = ReservationApi()..supported = false;
    await tester.pumpWidget(fixture.app(api));
    await tester.pumpAndSettle();
    expect(find.text('Reserve sandbox EVSE'), findsNothing);
    expect(find.byType(CheckboxListTile), findsOneWidget);
    expect(api.writes, isEmpty);
  });
}
