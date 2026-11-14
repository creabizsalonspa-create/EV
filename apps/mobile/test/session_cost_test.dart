import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/session_cost.dart';
import 'package:creabiz_ev/sessions.dart';
import 'test_support.dart';

final cost=<String,dynamic>{'state':'estimated','evidence':'SIMULATED','scope':'CANONICAL_SESSION_QUOTE','physical_charging_verified':false,'real_payment_processed':false,'external_fees_included':false,'spending_cap':false,'automatic_stop':false,'currency':'KWD','minor_units':3,'tax_bps':500,'rate_minor_per_kwh':'251','energy_wh':'1500','subtotal_minor':'377','tax_minor':'19','total_minor':'396','as_of':'2026-11-14T06:00:00.000000Z','source_meter_at':null,'meter_freshness_at_read':'APPLICATION_SIMULATOR','invoice_id':null};
class CostApi extends FixtureApi {
  Completer<dynamic>? pending;
  int epoch=0,stops=0,reads=0;
  @override
  bool Function() sessionFence() {final captured=epoch;return ()=>captured==epoch;}
  Map<String,dynamic> page()=>{'data':[{'id':1,'reservation_id':1,'status':'charging','energy_wh':1500,'total_minor':null,'currency':'KWD','csms_linked':false,'cost_projection':cost}],'current_page':1,'last_page':1,'total':1};
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}}) async {
    if(method=='POST') {stops++;return {};}
    reads++;return pending==null?page():pending!.future;
  }
}
Widget app(CostApi api,{bool arabic=false,VoidCallback? onExpired})=>MaterialApp(locale:Locale(arabic?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],home:Scaffold(body:SessionsView(api:api,role:'customer',onExpired:onExpired??(){})));
void main() {
  test('money projection is exact, bounded and distinct from payment or a spend limit',(){
    expect(validSessionCost(cost),true);expect(sessionCostText(cost,false),contains('KWD 0.396'));expect(sessionCostText({...cost,'minor_units':0,'currency':'JPY'},true),contains('JPY 396'));
    for(final change in <Map<String,dynamic>>[{'total_minor':'395'},{'tax_minor':'20'},{'real_payment_processed':true},{'physical_charging_verified':true},{'spending_cap':true},{'as_of':'today'},{'scope':'OTHER'},{'state':'invoiced'}])expect(validSessionCost({...cost,...change}),false);
    expect(sessionCostText({...cost,'state':'invoiced','invoice_id':7},false),contains('Simulated invoice total'));
    expect(sessionCostText({...cost,'meter_freshness_at_read':'STALE'},true),contains('قديم عند القراءة'));
  });
  for(final arabic in [false,true]) {
    testWidgets('session screen shows the recorded cost including tax ($arabic)',(tester) async {
      final api=CostApi();addTearDown(api.close);await tester.pumpWidget(app(api,arabic:arabic));await tester.pumpAndSettle();
      expect(find.textContaining('KWD 0.396'),findsOneWidget);expect(find.textContaining('KWD 0.019'),findsOneWidget);expect(find.textContaining('minor units'),findsNothing);expect(tester.takeException(),isNull);
    });
  }
  testWidgets('background drops delayed private session data until explicit foreground refresh',(tester) async {
    final api=CostApi()..pending=Completer<dynamic>();addTearDown(api.close);await tester.pumpWidget(app(api));await tester.pump();
    for (final state in [AppLifecycleState.inactive,AppLifecycleState.hidden,AppLifecycleState.paused]) { tester.binding.handleAppLifecycleStateChanged(state); }api.pending!.complete(api.page());await tester.pumpAndSettle();expect(find.textContaining('KWD 0.396'),findsNothing);
    for (final state in [AppLifecycleState.hidden,AppLifecycleState.inactive,AppLifecycleState.resumed]) { tester.binding.handleAppLifecycleStateChanged(state); }api.pending=null;await tester.pump();expect(api.reads,1);
    await tester.tap(find.text('Refresh'));await tester.pumpAndSettle();expect(find.textContaining('KWD 0.396'),findsOneWidget);
  });
  testWidgets('old account denial cannot reveal prices or sign out a newer account',(tester) async {
    final api=CostApi()..pending=Completer<dynamic>();var expired=0;addTearDown(api.close);await tester.pumpWidget(app(api,onExpired:()=>expired++));await tester.pump();api.epoch++;
    api.pending!.completeError(const ApiException(401,'old session'));await tester.pumpAndSettle();expect(expired,0);expect(find.textContaining('KWD 0.396'),findsNothing);
    await tester.tap(find.text('Refresh'));await tester.pumpAndSettle();expect(api.reads,1);
  });
  testWidgets('a stop confirmation opened before background cannot send an old command',(tester) async {
    final api=CostApi();addTearDown(api.close);await tester.pumpWidget(app(api));await tester.pumpAndSettle();
    final stop=find.text('Stop simulation');await tester.ensureVisible(stop);await tester.tap(stop);await tester.pumpAndSettle();
    for (final state in [AppLifecycleState.inactive,AppLifecycleState.hidden,AppLifecycleState.paused]) { tester.binding.handleAppLifecycleStateChanged(state); }await tester.pump();await tester.tap(find.text('Confirm'));await tester.pumpAndSettle();expect(api.stops,0);
    for (final state in [AppLifecycleState.hidden,AppLifecycleState.inactive,AppLifecycleState.resumed]) { tester.binding.handleAppLifecycleStateChanged(state); }await tester.pump();
  });
}
