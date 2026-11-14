import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/precharge_pricing.dart';
import 'test_support.dart';

final price = <String,dynamic>{'version':1,'evidence':'SIMULATED','currency':'KWD','minor_units':3,'energy_wh':1500,'rate_minor_per_kwh':'251','tax_bps':500,'subtotal_minor':'377','tax_minor':'19','total_minor':'396','session_fee_minor':'0','idle_fee_minor':'0','external_fees_included':false,'automatic_stop':false,'final_total_depends_on_meter':true,'expires_at':'2026-11-18T10:35:00.000000Z'};
class PricingApi extends FixtureApi {
  final writes = <Map<String,dynamic>>[];
  Completer<dynamic>? pending;
  int epoch = 0;
  @override
  bool Function() sessionFence() { final captured = epoch; return () => captured == epoch; }
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}}) async {
    calls.add(path);
    if (path.endsWith('price-estimate')) return pending == null ? {'estimate':price,'quote_token':'synthetic-private-token'} : pending!.future;
    writes.add(body!); return {'id':7};
  }
}
Widget app(PricingApi api,{bool arabic=false}) => MaterialApp(
  locale:Locale(arabic?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],
  localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],
  home:Builder(builder:(context)=>Scaffold(body:TextButton(onPressed:()=>reviewPrechargePricing(context,api,7),child:const Text('Review')))),
);
void main() {
  test('exact estimate rounding and supported price/fee scope agree with Laravel', () {
    expect(validPrechargeEstimate(price),true);
    expect(prechargeAmount('396',3),'0.396');expect(prechargeAmount('396',0),'396');
    for(final update in <Map<String,dynamic>>[{'total_minor':'395'},{'tax_minor':'20'},{'external_fees_included':true},{'automatic_stop':true},{'minor_units':4},{'energy_wh':0},{'rate_minor_per_kwh':'1000001'}]) expect(validPrechargeEstimate({...price,...update}),false);
    expect(prechargeText(price,true),contains('KWD 0.396'));
    expect(prechargeText(price,false),contains('not a spending cap'));
  });
  for (final arabic in [false,true]) {
    testWidgets('explicit price review posts the reviewed token in both languages ($arabic)', (tester) async {
      final api=PricingApi();addTearDown(api.close);
      await tester.pumpWidget(app(api,arabic:arabic));await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),arabic?'١٥٠٠':'1500');
      await tester.tap(find.text(arabic?'عرض التقدير':'Get estimate'));await tester.pumpAndSettle();
      expect(find.textContaining('KWD 0.396'),findsOneWidget);expect(api.writes,isEmpty);
      final accept=find.text(arabic?'قبول السعر':'Accept price');await tester.ensureVisible(accept);await tester.tap(accept);await tester.pumpAndSettle();
      expect(api.writes,[{'quote_token':'synthetic-private-token','accepted':true}]);
      expect(find.byType(PrechargePricingDialog),findsNothing);
    });
  }
  testWidgets('changing energy clears the previously reviewed token', (tester) async {
    final api=PricingApi();addTearDown(api.close);await tester.pumpWidget(app(api));await tester.tap(find.text('Review'));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'1500');
    await tester.tap(find.text('Get estimate'));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'2000');await tester.pump();
    final button=tester.widget<FilledButton>(find.widgetWithText(FilledButton,'Accept price'));expect(button.onPressed,isNull);expect(find.textContaining('KWD 0.396'),findsNothing);expect(api.writes,isEmpty);
  });
  testWidgets('backgrounding clears pending and visible confirmations', (tester) async {
    final api=PricingApi()..pending=Completer<dynamic>();addTearDown(api.close);
    await tester.pumpWidget(app(api));await tester.tap(find.text('Review'));await tester.pumpAndSettle();await tester.enterText(find.byType(TextField),'1500');await tester.tap(find.text('Get estimate'));await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);await tester.pump();
    api.pending!.complete({'estimate':price,'quote_token':'synthetic-private-token'});await tester.pumpAndSettle();
    expect(find.textContaining('KWD 0.396'),findsNothing);expect(api.writes,isEmpty);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);await tester.pump();
  });
  testWidgets('retained dialog cannot approve a prior account price', (tester) async {
    final api=PricingApi();addTearDown(api.close);await tester.pumpWidget(app(api));await tester.tap(find.text('Review'));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'1500');
    await tester.tap(find.text('Get estimate'));await tester.pumpAndSettle();api.epoch++;
    await tester.tap(find.text('Accept price'));await tester.pumpAndSettle();expect(api.writes,isEmpty);expect(find.textContaining('KWD 0.396'),findsNothing);
  });
}
