import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/unified_discovery.dart';
import 'package:creabiz_ev/api.dart';
import 'test_support.dart';

Map<String,dynamic> catalogRow()=>{'key':'catalog:7','kind':'catalog','station':{'id':7,'name':'Synthetic catalog','connector_type':'CCS2','power_kw':50,'evidence':'SIMULATED','reservable':false}};
Map<String,dynamic> partnerRow()=>{'key':'partner:7','kind':'partner','record_id':7,'provider_label':'Synthetic operator','location':{'name':'Synthetic partner','address':'<img src=x>','country':'ARE'},'evidence':'PARTNER_REPORTED_UNVERIFIED','availability':'unknown','reservable':false,'remote_control':false};
class UnifiedApi extends FixtureApi {
  Completer<dynamic>? pending;
  bool invalid=false;
  int epoch=0;
  @override
  bool Function() sessionFence(){final capture=epoch;return()=>capture==epoch;}
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    calls.add('$method $path');queries.add(query);
    if(pending!=null)return pending!.future;
    return {'data':int.parse(query['page']!)==1?[catalogRow(),{...partnerRow(),if(invalid)'reservable':true}]:[partnerRow()],'current_page':int.parse(query['page']!),'last_page':2,'total':3};
  }
}
Widget app(UnifiedApi api,ValueChanged<Station> onCatalog,{bool ar=false})=>MaterialApp(locale:Locale(ar?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],home:UnifiedDiscoveryView(api:api,onCatalog:onCatalog));
void main(){
 for(final ar in [false,true]){
  testWidgets('unified query and typed source results in both languages ($ar)',(tester)async{
   tester.view.physicalSize=const Size(1100,1800);tester.view.devicePixelRatio=1;addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
   final api=UnifiedApi();addTearDown(api.close);final chosen=<Station>[];
   await tester.pumpWidget(app(api,chosen.add,ar:ar));expect(api.calls,isEmpty);
   await tester.enterText(find.byKey(const Key('unified-query')),'Shared');await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.single['q'],'Shared');expect(find.text('Synthetic catalog'),findsOneWidget);expect(find.text('Synthetic partner'),findsOneWidget);expect(find.text('<img src=x> · ARE'),findsOneWidget);
   final open=find.text(ar?'عرض المحطة وخيارات الحجز':'View station and booking options');await tester.ensureVisible(open);await tester.tap(open);expect(chosen.single.id,7);
   expect(find.byType(FilledButton),findsOneWidget);expect(api.calls.every((path)=>path=='GET discovery/search'),true);
   final next=find.text(ar?'التالي':'Next');await tester.ensureVisible(next);await tester.tap(next);await tester.pumpAndSettle();expect(api.queries.last['page'],'2');expect(find.text('Synthetic catalog'),findsNothing);
  });
 }
 testWidgets('partner control claims fail closed without a station action',(tester)async{
  final api=UnifiedApi()..invalid=true;addTearDown(api.close);await tester.pumpWidget(app(api,(_)=>fail('Unexpected action')));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(find.text('Search failed. Retry explicitly.'),findsOneWidget);expect(find.text('Synthetic partner'),findsNothing);
 });
 testWidgets('background and old account responses cannot repopulate results',(tester)async{
  final api=UnifiedApi()..pending=Completer<dynamic>();addTearDown(api.close);await tester.pumpWidget(app(api,(_)=>fail('Unexpected action')));await tester.tap(find.byType(FilledButton));await tester.pump();
  for(final state in [AppLifecycleState.inactive,AppLifecycleState.hidden,AppLifecycleState.paused]){tester.binding.handleAppLifecycleStateChanged(state);}
  api.epoch++;api.pending!.complete({'data':[partnerRow()],'current_page':1,'last_page':1,'total':1});await tester.pump();expect(find.text('Synthetic partner'),findsNothing);
  for(final state in [AppLifecycleState.hidden,AppLifecycleState.inactive,AppLifecycleState.resumed]){tester.binding.handleAppLifecycleStateChanged(state);}
  await tester.pumpAndSettle();expect(find.text('Synthetic partner'),findsNothing);expect(api.calls.length,1);
 });
}
