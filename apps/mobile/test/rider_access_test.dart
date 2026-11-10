import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/rider_access.dart';
import 'test_support.dart';

class RiderApi extends FixtureApi {
  final bodies = <Map<String, dynamic>>[];
  bool loseResponse = true;
  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'rider-access/tokens');
    if (method == 'POST') {
      bodies.add(Map<String, dynamic>.from(body!));
      if (loseResponse) throw const ApiException(503, 'PRIVATE TRANSPORT');
      // A committed request replay cannot reveal the identifier again.
      return {'created': false, 'token': {'id': 1}};
    }
    return {'data': <Map<String, dynamic>>[], 'current_page': 1, 'last_page': 1, 'total': 0};
  }
}


class PrivateRiderApi extends FixtureApi {
  int authGeneration=0;
  Completer<dynamic>? delayedRead;
  Completer<dynamic>? delayedIssue;
  int readCount=0;
  int mutationCount=0;
  String tokenLabel='Private test card';

  @override
  bool Function() sessionFence() {
    final captured=authGeneration;
    return () => captured==authGeneration;
  }

  Map<String,dynamic> get tokenPage => {
    'data': [
      {
        'id': 42,
        'label': tokenLabel,
        'token_type': 'Central',
        'suffix': '7777',
        'expires_at': '2026-12-01T00:00:00Z',
        'provisioning_state': 'test',
        'revoked_at': null,
      },
    ],
    'current_page': 1,
    'last_page': 1,
    'total': 1,
  };

  @override
  Future<dynamic> request(
    String path, {
    String method='GET',
    Map<String,dynamic>? body,
    Map<String,String> query=const {},
  }) async {
    expect(path,'rider-access/tokens');
    if(method=='GET'){
      readCount++;
      if(delayedRead!=null)return delayedRead!.future;
      return tokenPage;
    }
    if(method=='POST'){
      mutationCount++;
      if(delayedIssue!=null)return delayedIssue!.future;
      return {'value_once': 'TEST-ONE-TIME-SECRET'};
    }
    mutationCount++;
    throw StateError('Unexpected test method: $method');
  }
}

void main() {
  testWidgets('uncertain registration waits for explicit retry and keeps its original request identity', (tester) async {
    final api = RiderApi();
    await tester.pumpWidget(MaterialApp(home: RiderAccessView(api: api, onExpired: () {})));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register charging access'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first, 'My private card');
    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    expect(api.bodies, hasLength(1));
    expect(find.textContaining('PRIVATE TRANSPORT'), findsNothing);
    await tester.pump(const Duration(seconds: 5));
    expect(api.bodies, hasLength(1));
    api.loseResponse = false;
    await tester.tap(find.text('Retry saved request'));
    await tester.pumpAndSettle();
    expect(api.bodies, hasLength(2));
    expect(api.bodies[1], api.bodies[0]);
    expect(find.text('Show identifier'), findsNothing);
    expect(find.text('Register charging access'), findsOneWidget);
  });

  testWidgets('stale rider token load cannot show a prior account', (tester) async {
    final api=PrivateRiderApi()..delayedRead=Completer<dynamic>();
    var expired=0;
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:api,onExpired:()=>expired++),
    ));
    await tester.pump();
    api.authGeneration++;
    api.delayedRead!.complete(api.tokenPage);
    await tester.pumpAndSettle();
    expect(find.text('Private test card'),findsNothing);
    expect(find.text('Session expired. Sign in again.'),findsOneWidget);
    expect(expired,1);
  });

  testWidgets('background hides private rider records until explicit refresh', (tester) async {
    final api=PrivateRiderApi();
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:api,onExpired:(){}),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Private test card'),findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('Private test card'),findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.text('Private test card'),findsNothing);
    expect(api.readCount,1);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Private test card'),findsOneWidget);
    expect(api.readCount,2);
  });

  testWidgets('background clears revealed one-time token and saved retry', (tester) async {
    final api=PrivateRiderApi();
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:api,onExpired:(){}),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register charging access'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first,'Private test card');
    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Show identifier'));
    await tester.pump();
    expect(find.text('TEST-ONE-TIME-SECRET'),findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('TEST-ONE-TIME-SECRET'),findsNothing);
    expect(find.text('Show identifier'),findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.text('TEST-ONE-TIME-SECRET'),findsNothing);
  });

  testWidgets('late token issuance after account switch reveals no secret', (tester) async {
    final api=PrivateRiderApi()..delayedIssue=Completer<dynamic>();
    var expired=0;
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:api,onExpired:()=>expired++),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register charging access'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first,'Private test card');
    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pump();
    api.authGeneration++;
    api.delayedIssue!.complete({'value_once':'PRIOR-ACCOUNT-SECRET'});
    await tester.pumpAndSettle();
    expect(find.text('PRIOR-ACCOUNT-SECRET'),findsNothing);
    expect(find.text('Show identifier'),findsNothing);
    expect(expired,1);
  });


  testWidgets('switching API identity clears previous rider records and old response', (tester) async {
    final oldApi=PrivateRiderApi()..delayedRead=Completer<dynamic>();
    final newApi=PrivateRiderApi()..tokenLabel='New account token';
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:oldApi,onExpired:(){}),
    ));
    await tester.pump();
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:newApi,onExpired:(){}),
    ));
    oldApi.delayedRead!.complete(oldApi.tokenPage);
    await tester.pumpAndSettle();
    expect(find.text('Private test card'),findsNothing);
    expect(find.text('New account token'),findsOneWidget);
  });


  testWidgets('registration form from previous session cannot issue token', (tester) async {
    final api=PrivateRiderApi();
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:api,onExpired:(){}),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register charging access'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first,'Old session card');
    api.authGeneration++;
    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    expect(api.mutationCount,0);
    expect(find.text('Show identifier'),findsNothing);
  });

  testWidgets('confirmation started in another session cannot revoke token', (tester) async {
    final api=PrivateRiderApi();
    await tester.pumpWidget(MaterialApp(
      home:RiderAccessView(api:api,onExpired:(){}),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Revoke token'));
    await tester.pumpAndSettle();
    api.authGeneration++;
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.mutationCount,0);
  });

}
