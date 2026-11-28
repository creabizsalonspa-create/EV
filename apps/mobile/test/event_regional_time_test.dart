import 'package:creabiz_ev/rider_history.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'rider_history_test.dart' as rider;
import 'charging_reviews_test.dart' as reviews;
import 'notification_email_test.dart' as notices;

class ClockHistory extends rider.HistoryApi {
  String stamp = '2026-11-07T12:00:00+04:00';
  @override
  Map<String, dynamic> page(String kind) {
    final result = super.page(kind);
    (result['data'] as List).first['created_at'] = stamp;
    return result;
  }
}

class ClockReviews extends reviews.ReviewsApi {
  String stamp = '2026-11-07T12:00:00+04:00';
  @override
  Map<String, dynamic> review() => {...super.review(), 'created_at': stamp};
}

class ClockNotices extends notices.NoticeApi {
  String stamp = '2026-11-07T12:00:00+04:00';
  @override
  Future<dynamic> request(String path, {String method = 'GET',
    Map<String, dynamic>? body, Map<String, String> query = const {}}) async {
    final result = await super.request(path, method: method, body: body, query: query);
    if (path.endsWith('/history')) {
      (result['receipts']['data'] as List).first['created_at'] = stamp;
    }
    return result;
  }
}

void main() {
  for (final ar in [false, true]) {
    final unavailable = ar ? 'وقت الحدث غير متاح' : 'Event time unavailable';
    final refresh = ar ? 'تحديث' : 'Refresh';
    testWidgets('rider history uses verified UTC clocks ($ar)', (tester) async {
      final api = ClockHistory();
      await tester.pumpWidget(MaterialApp(
        locale: Locale(ar ? 'ar' : 'en'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: RiderHistoryView(api: api, onExpired: () {}, tokenId: 4),
      ));
      await tester.pumpAndSettle();
      expect(find.textContaining('UTC'), findsWidgets);
      expect(find.textContaining(api.stamp), findsNothing);
      api.stamp = '2026-02-30T12:00:00Z';
      await tester.tap(find.text(refresh));
      await tester.pumpAndSettle();
      expect(find.textContaining(unavailable), findsOneWidget);
      expect(find.textContaining(api.stamp), findsNothing);
    });
    testWidgets('charging review clocks never display malformed source ($ar)', (tester) async {
      final api = ClockReviews()..created = true;
      await tester.pumpWidget(reviews.app(api, arabic: ar));
      await tester.pumpAndSettle();
      expect(find.textContaining('UTC'), findsWidgets);
      expect(find.textContaining(api.stamp), findsNothing);
      api.stamp = '2026-02-30T12:00:00Z';
      await tester.tap(find.text(refresh));
      await tester.pumpAndSettle();
      expect(find.text(unavailable), findsOneWidget);
      expect(api.writes, 0);
    });
    testWidgets('notice history displays UTC or unavailable without writing ($ar)', (tester) async {
      final api = ClockNotices();
      addTearDown(api.close);
      await tester.pumpWidget(notices.screen(api, ar: ar));
      await tester.pumpAndSettle();
      expect(find.textContaining('UTC'), findsWidgets);
      expect(find.textContaining(api.stamp), findsNothing);
      api.stamp = '2026-02-30T12:00:00Z';
      await tester.tap(find.text(refresh));
      await tester.pumpAndSettle();
      expect(find.textContaining(unavailable), findsOneWidget);
      expect(api.writes, 0);
    });
  }
}
