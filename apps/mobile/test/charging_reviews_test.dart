import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charging_reviews.dart';
import 'test_support.dart';

class ReviewsApi extends FixtureApi {
  bool lose = false, unsafe = false, denied = false, created = false;
  bool wrongReceipt=false, invalidEligible=false, invalidGroups=false;
  int version=1;
  int reads = 0, epoch = 0;
  final changes = StreamController<int>.broadcast(sync:true);
  Completer<dynamic>? nextRead;
  @override
  bool Function() sessionFence() {
    final original = epoch;
    return () => epoch == original;
  }
  @override
  Stream<int> get sessionEvents => changes.stream;
  void rotate() { epoch++; changes.add(epoch); }

  int writes = 0;
  Map<String, dynamic>? first;
  Map<String, dynamic> review() => {
    'id': 1,
    'session_id': 2,
    'station_id': 3,
    'version': version,
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
      if (method!='POST') version=2;
      if (lose) {
        lose = false;
        throw const ApiException(503, 'PRIVATE transport');
      }
      return wrongReceipt?{...review(),'id':99,'session_id':99}:review();
    }
    reads++;
    final held = nextRead;
    nextRead = null;
    if (held != null) return held.future;
    if(path=='stations/3/reviews') return {
      'data':[review()],'current_page':1,'last_page':1,'total':1,
      'physical_charging_verified':false,'station_name':'Test location',
      'groups':[
        {'evidence':'SIMULATED_SOURCE_OBSERVED','count':1,'rating_sum':invalidGroups?99:4},
        {'evidence':'SIMULATED_APP_ONLY','count':0,'rating_sum':0},
      ],
    };
    return {
      'data': path.endsWith('eligible')
          ? [
              {
                'session_id': 2,
                'station_id': 3,
                'station_name': 'Test location',
                'evidence': invalidEligible?'UNKNOWN':'SIMULATED_SOURCE_OBSERVED',
                'physical_charging_verified': false,
                'can_review': true,
              },
            ]
          : created
          ? [review()]
          : <dynamic>[],
      'current_page': 1,
      'last_page': 1,
      'total': path.endsWith('eligible')||created ? 1 : 0,
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
  testWidgets('first inactive review mount makes no request until foreground',(tester) async {
    final api=ReviewsApi()..created=true;
    addTearDown(api.changes.close);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    addTearDown(()=>tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(api.reads,0);
    expect(find.text('Private test feedback'),findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads,1);
    expect(find.text('Private test feedback'),findsOneWidget);
  });

  testWidgets('credential rotation clears private feedback and rejects stale withdrawal',(tester) async {
    final api=ReviewsApi()..created=true;
    addTearDown(api.changes.close);
    await tester.binding.setSurfaceSize(const Size(800,1200));
    addTearDown(()=>tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    final withdrawal=find.widgetWithText(TextButton,'Withdraw publication');
    final stale=tester.widget<TextButton>(withdrawal).onPressed!;
    api.rotate();
    await tester.pumpAndSettle();
    expect(find.text('Private test feedback'),findsNothing);
    expect(find.text('Account changed. Reopen charging feedback.'),findsOneWidget);
    stale();
    await tester.pumpAndSettle();
    expect(api.writes,0);
    expect(api.reads,1);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(api.reads,1);
    expect(find.text('Private test feedback'),findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads,1);
    expect(api.writes,0);
  });

  testWidgets('late source reply after rotation cannot repaint the retired account',(tester) async {
    final api=ReviewsApi();
    addTearDown(api.changes.close);
    final held=Completer<dynamic>();
    api.nextRead=held;
    var expired=0;
    await tester.pumpWidget(app(api,expired:()=>expired++));
    await tester.pump();
    expect(api.reads,1);
    api.rotate();
    held.complete({'data':[api.review()],'current_page':1,'last_page':1,'total':1});
    await tester.pumpAndSettle();
    expect(find.text('Private test feedback'),findsNothing);
    expect(find.byType(LinearProgressIndicator),findsNothing);
    expect(expired,0);
    expect(api.writes,0);
  });

  testWidgets('uncertain write remains same-account only and is not replayed on resume',(tester) async {
    final api=ReviewsApi()..created=true..lose=true;
    addTearDown(api.changes.close);
    await tester.binding.setSurfaceSize(const Size(800,1200));
    addTearDown(()=>tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(TextButton,'Withdraw publication'));
    await tester.pumpAndSettle();
    expect(api.writes,1);
    final retry=find.widgetWithText(TextButton,'Retry same feedback request');
    expect(retry,findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(retry,findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.writes,1);
    expect(retry,findsOneWidget);
    final stale=tester.widget<TextButton>(retry).onPressed!;
    api.rotate();
    await tester.pumpAndSettle();
    expect(retry,findsNothing);
    stale();
    await tester.pumpAndSettle();
    expect(api.writes,1);
    expect(find.text('Private test feedback'),findsNothing);
  });
  testWidgets('malformed eligible evidence never grants review authority',(tester) async {
    final api=ReviewsApi()..invalidEligible=true;
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Completed sessions for feedback'));
    await tester.pumpAndSettle();
    expect(find.text('Write feedback'),findsNothing);
    expect(find.text('Feedback unavailable. Refresh before another action.'),findsOneWidget);
    api.invalidEligible=false;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Write feedback'),findsOneWidget);
    expect(api.writes,0);
  });

  testWidgets('public aggregate rejects impossible rating totals before rendering',(tester) async {
    final api=ReviewsApi()..invalidGroups=true;
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(MaterialApp(home:ChargingReviewsView(
      api:api,stationId:3,onExpired:(){},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Private test feedback'),findsNothing);
    expect(find.text('Feedback unavailable. Refresh before another action.'),findsOneWidget);
    api.invalidGroups=false;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('1 · 4.0 / 5'),findsOneWidget);
    expect(find.text('Private test feedback'),findsOneWidget);
    expect(tester.takeException(),isNull);
  });

  testWidgets('failed source refresh withdraws permission to clear uncertain write',(tester) async {
    final api=ReviewsApi()..created=true..lose=true;
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.binding.setSurfaceSize(const Size(800,1200));
    addTearDown(()=>tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Withdraw publication'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('My feedback'));
    await tester.pumpAndSettle();
    expect(find.text('Clear pending form after reviewing history'),findsOneWidget);
    api.unsafe=true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Clear pending form after reviewing history'),findsNothing);
    expect(find.text('Retry same feedback request'),findsOneWidget);
    expect(api.writes,1);
  });
  testWidgets('wrong review acknowledgement retains frozen withdrawal request',(tester) async {
    final api=ReviewsApi()..created=true..wrongReceipt=true;
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.binding.setSurfaceSize(const Size(800,1200));
    addTearDown(()=>tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Withdraw publication'));
    await tester.pumpAndSettle();
    expect(api.writes,1);
    expect(find.text('Retry same feedback request'),findsOneWidget);
    expect(find.text('Private test feedback'),findsNothing);
    api.wrongReceipt=false;
    await tester.tap(find.text('Retry same feedback request'));
    await tester.pumpAndSettle();
    expect(api.writes,2);
    expect(find.text('Retry same feedback request'),findsNothing);
    expect(find.text('Private test feedback'),findsOneWidget);
  });

}
