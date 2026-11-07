import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charging_reviews.dart';
import 'test_support.dart';

class ReviewsApi extends FixtureApi {
  bool lose = false, unsafe = false, denied = false, created = false;
  int writes = 0;
  Map<String, dynamic>? first;
  Map<String, dynamic> review() => {
    'id': 1,
    'session_id': 2,
    'station_id': 3,
    'version': 1,
    'revision': 1,
    'state': 'pending',
    'public_id': 'opaque',
    'rating': 4,
    'body': 'Private test feedback',
    'evidence': 'SIMULATED_SOURCE_OBSERVED',
    'physical_charging_verified': unsafe,
    'created_at': '2026-11-07',
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (denied) throw const ApiException(401, 'PRIVATE account detail');
    if (method != 'GET') {
      writes++;
      first ??= Map<String, dynamic>.from(body!);
      expect(body, first);
      created = true;
      if (lose) {
        lose = false;
        throw const ApiException(503, 'PRIVATE transport');
      }
      return review();
    }
    return {
      'data': path.endsWith('eligible')
          ? [
              {
                'session_id': 2,
                'station_id': 3,
                'station_name': 'Test location',
                'evidence': 'SIMULATED_SOURCE_OBSERVED',
                'physical_charging_verified': false,
                'can_review': true,
              },
            ]
          : created
          ? [review()]
          : <dynamic>[],
      'current_page': 1,
      'last_page': 1,
      'total': created ? 1 : 0,
      'terms_version': 'charging-feedback-v1',
    };
  }
}

Widget app(ReviewsApi api, {VoidCallback? expired, bool arabic = false}) =>
    MaterialApp(
      locale: Locale(arabic ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: ChargingReviewsView(
        api: api,
        role: 'customer',
        onExpired: expired ?? () {},
      ),
    );
void main() {
  testWidgets(
    'consent and lost feedback retain identity; history reads never resend',
    (tester) async {
      final api = ReviewsApi()..lose = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Completed sessions for feedback'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Write feedback'));
      await tester.tap(find.text('Write feedback'));
      await tester.pumpAndSettle();
      final dropdown = find.byType(DropdownButtonFormField<int>);
      await tester.ensureVisible(dropdown);
      await tester.tap(dropdown);
      await tester.pumpAndSettle();
      await tester.tap(find.text('4 / 5').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'Private test feedback');
      await tester.pumpAndSettle();
      final send = find.widgetWithText(FilledButton, 'Send for moderation');
      expect(tester.widget<FilledButton>(send).onPressed, isNull);
      await tester.ensureVisible(find.byType(CheckboxListTile));
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pumpAndSettle();
      await tester.ensureVisible(send);
      await tester.tap(send);
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.first!['rating'], 4);
      expect(api.first!['consent'], true);
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.tap(find.text('My feedback'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Private test feedback'), findsOneWidget);
      expect(
        find.text('Clear pending form after reviewing history'),
        findsOneWidget,
      );
      await tester.ensureVisible(find.text('Retry same feedback request'));
      await tester.tap(find.text('Retry same feedback request'));
      await tester.pumpAndSettle();
      expect(api.writes, 2);
      expect(find.text('Retry same feedback request'), findsNothing);
    },
  );
  testWidgets(
    'unsafe physical capability and expired identity hide private feedback',
    (tester) async {
      final api = ReviewsApi()
        ..created = true
        ..unsafe = true;
      var expired = 0;
      await tester.pumpWidget(app(api, expired: () => expired++));
      await tester.pumpAndSettle();
      expect(find.text('Private test feedback'), findsNothing);
      api.unsafe = false;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Private test feedback'), findsOneWidget);
      api.denied = true;
      await tester.ensureVisible(find.text('Refresh'));
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Private test feedback'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
    },
  );
  testWidgets(
    'Arabic phone feedback and background privacy never issue writes',
    (tester) async {
      final api = ReviewsApi()..created = true;
      await tester.binding.setSurfaceSize(const Size(390, 844));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(app(api, arabic: true));
      await tester.pumpAndSettle();
      expect(find.text('ملاحظات الشحن'), findsOneWidget);
      expect(find.text('Private test feedback'), findsOneWidget);
      expect(tester.takeException(), isNull);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.text('Private test feedback'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      expect(find.text('Private test feedback'), findsOneWidget);
    },
  );
}
