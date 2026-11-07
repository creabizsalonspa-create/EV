import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_information.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  Map<String, dynamic> station() => {
    'id': 14, 'name': 'Central Plaza', 'connector_type': 'CCS2',
    'power_kw': 50, 'evidence': 'SIMULATED', 'reservable': false,
    'timezone': 'Asia/Dubai',
    'information': {
      'revision': 2, 'source_label': 'VENDOR_REPORTED_UNVERIFIED',
      'description_en': 'Level B', 'description_ar': 'الطابق ب',
      'access_en': 'Gate B', 'access_ar': 'البوابة ب',
      'weekly_hours': {
        for (final day in stationInfoDays)
          day: {'open': day == 'fri' ? null : '08:00', 'close': day == 'fri' ? null : '22:00'},
      },
      'closed_dates': ['2026-12-02'],
      'amenities': ['wifi'],
      'accessibility': ['step_free'],
    },
  };

  test('metadata survives favorite and connector copies', () {
    final s = Station.fromJson(station());
    expect(s.timezone, 'Asia/Dubai');
    expect(s.information!['access_en'], 'Gate B');
    final favorite = Station.withFavorite(s, true);
    expect(favorite.information!['revision'], 2);
    final connector = Station.forConnector(s, {
      'id': 3, 'evse_number': 1, 'connector_number': 1,
      'connector_type': 'Type2', 'max_power_kw': 22, 'reservable': false,
    });
    expect(connector.information!['access_en'], 'Gate B');
    expect(connector.timezone, 'Asia/Dubai');
  });

  testWidgets('native catalog details show vendor-reported closures, access and truthful availability', (tester) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ListView(children: [
      StationInformationPanel(information: Station.fromJson(station()).information, timezone: 'Asia/Dubai'),
    ]))));
    await tester.tap(find.text('Hours, access & facilities'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Fri: Closed'), findsOneWidget);
    expect(find.textContaining('Holiday closures: 2026-12-02'), findsOneWidget);
    expect(find.textContaining('Step-free access'), findsOneWidget);
    expect(find.textContaining('Gate B'), findsOneWidget);
    expect(find.textContaining('availability is unknown'), findsOneWidget);
  });

  testWidgets('missing vendor data cannot be presented as published opening times', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: StationInformationPanel(
      information: null, timezone: 'UTC',
    ))));
    await tester.tap(find.text('Hours, access & facilities'));
    await tester.pumpAndSettle();
    expect(find.textContaining('has not published'), findsOneWidget);
    expect(find.textContaining('Mon:'), findsNothing);
  });

  testWidgets('Arabic customer view preserves an explicit unknown availability warning', (tester) async {
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Scaffold(body: ListView(children: [
        StationInformationPanel(information: Station.fromJson(station()).information, timezone: 'Asia/Dubai'),
      ])),
    ));
    await tester.tap(find.text('ساعات العمل والدخول والمرافق'));
    await tester.pumpAndSettle();
    expect(find.textContaining('الجمعة: مغلق'), findsOneWidget);
    expect(find.textContaining('البوابة ب'), findsOneWidget);
    expect(find.textContaining('غير معروف'), findsWidgets);
  });
}
