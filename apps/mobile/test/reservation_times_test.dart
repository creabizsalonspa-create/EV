import 'package:creabiz_ev/reservation_times.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

Future<void> showTime(
  WidgetTester tester,
  Map<String, dynamic> row, {
  String language = 'en',
  String field = 'starts_at',
}) => tester.pumpWidget(
  MaterialApp(
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    locale: Locale(language),
    home: Builder(
      builder: (context) => MediaQuery(
        data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true),
        child: Builder(
          builder: (context) => Text(reservationTime(context, row, field)),
        ),
      ),
    ),
  ),
);

void main() {
  testWidgets(
    'station wall clock honors non-hour UTC offsets and localizes RTL',
    (tester) async {
      const row = <String, dynamic>{
        'starts_at': '2026-11-01 06:30:00',
        'station_timezone': 'Asia/Kathmandu',
        'station_times': {'starts_at': '2026-11-01T12:15:00+05:45'},
      };
      await showTime(tester, row);
      final label = find.textContaining('Asia/Kathmandu (UTC+05:45)');
      expect(label, findsOneWidget);
      expect(tester.widget<Text>(label).data, contains('12:15'));
      await showTime(tester, row, language: 'ar');
      await tester.pumpAndSettle();
      expect(label, findsOneWidget);
      expect(Directionality.of(tester.element(label)), TextDirection.rtl);
    },
  );

  testWidgets('both repeated local hours show separate offsets', (
    tester,
  ) async {
    const row = <String, dynamic>{
      'starts_at': '2026-11-01 06:30:00',
      'checked_in_at': '2026-11-01T05:30:00.000000Z',
      'station_timezone': 'America/New_York',
      'station_times': {
        'starts_at': '2026-11-01T01:30:00-05:00',
        'checked_in_at': '2026-11-01T01:30:00-04:00',
      },
    };
    await showTime(tester, row);
    expect(
      find.textContaining('01:30 · \u2068America/New_York (UTC-05:00)'),
      findsOneWidget,
    );
    await showTime(tester, row, field: 'checked_in_at');
    expect(
      find.textContaining('01:30 · \u2068America/New_York (UTC-04:00)'),
      findsOneWidget,
    );
  });

  testWidgets('older rows and conflicting metadata fall back to UTC', (
    tester,
  ) async {
    await showTime(tester, {'starts_at': '2026-11-01T06:30:00Z'});
    final fallback = find.textContaining('06:30 · \u2068UTC');
    expect(fallback, findsOneWidget);
    await showTime(tester, {
      'starts_at': '2026-11-01T06:30:00Z',
      'station_timezone': 'Asia/Kathmandu',
      'station_times': {'starts_at': '2026-11-01T13:15:00+05:45'},
    });
    expect(fallback, findsOneWidget);
    expect(find.textContaining('Asia/Kathmandu'), findsNothing);
  });
}
