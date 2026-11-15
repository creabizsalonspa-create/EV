import 'dart:async';
import 'dart:convert';
import 'package:flutter_map/flutter_map.dart';
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
  bool mapFixtures=false;
  int epoch=0;
  @override
  bool Function() sessionFence(){final capture=epoch;return()=>capture==epoch;}
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    calls.add('$method $path');queries.add(query);
    if(pending!=null)return pending!.future;
    return {'data':int.parse(query['page']!)==1?[
      if(mapFixtures) {...catalogRow(),'station':{...Map<String,dynamic>.from(catalogRow()['station'] as Map),'latitude':25.2,'longitude':55.3}}
      else catalogRow(),
      if(mapFixtures) {...partnerRow(),'location':{...Map<String,dynamic>.from(partnerRow()['location'] as Map),'latitude':'25.25','longitude':'55.35'}}
      else {...partnerRow(),if(invalid)'reservable':true},
    ]:[partnerRow()],'current_page':int.parse(query['page']!),'last_page':2,'total':3};
  }
}
class UnifiedFixtureTiles extends TileProvider {
  int requests=0;
  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    requests++;
    return MemoryImage(base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=',
    ));
  }
}
Widget app(UnifiedApi api,ValueChanged<Station> onCatalog,{bool ar=false,bool customer=false,TileProvider? tileProvider})=>MaterialApp(locale:Locale(ar?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],home:UnifiedDiscoveryView(api:api,onCatalog:onCatalog,customer:customer,mapTileProvider:tileProvider));
class SavedUnifiedApi extends UnifiedApi {
  final saved=<String>{};
  final writes=<String>[];
  bool unknown=false;
  Completer<dynamic>? pendingWrite;
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    if(method!='GET'){
      writes.add('$method $path');
      if(pendingWrite!=null)return pendingWrite!.future;
      if(unknown)throw const ApiException(0,'Unknown response');
      final key=path.startsWith('roaming/')?'partner:7':'catalog:7';
      method=='PUT'?saved.add(key):saved.remove(key);
      return {'is_favorite':method=='PUT'};
    }
    final data=Map<String,dynamic>.from(await super.request(path,method:method,body:body,query:query) as Map);
    data['data']=[for(final row in data['data'] as List){...Map<String,dynamic>.from(row as Map),'is_favorite':saved.contains(row['key'])}];
    return data;
  }
}
void roomy(WidgetTester tester){tester.view.physicalSize=const Size(1100,1800);tester.view.devicePixelRatio=1;addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);}
void main(){
 testWidgets('changing accounts clears advanced search drafts and submitted fields',(tester)async{
  roomy(tester);final first=UnifiedApi(),second=UnifiedApi();addTearDown(first.close);addTearDown(second.close);await tester.pumpWidget(app(first,(_){}));
  await tester.enterText(find.byKey(const Key('unified-query')),'Private place');
  await tester.ensureVisible(find.byType(ExpansionTile));await tester.tap(find.byType(ExpansionTile));await tester.pumpAndSettle();
  final operator=find.byKey(const Key('unified-operator'));await tester.ensureVisible(operator);await tester.enterText(operator,'Private operator');
  await tester.pumpWidget(app(second,(_){}));await tester.pumpAndSettle();
  expect(tester.widget<TextField>(operator).controller!.text,isEmpty);
  await tester.scrollUntilVisible(find.byKey(const Key('unified-query')),-300,scrollable:find.byType(Scrollable).first);
  expect(tester.widget<TextField>(find.byKey(const Key('unified-query'))).controller!.text,isEmpty);
  await tester.ensureVisible(find.byType(FilledButton));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
  expect(first.calls,isEmpty);expect(second.queries.single.containsKey('operator'),false);expect(second.queries.single['q'],'');
 });

 for(final ar in [false,true]){
  testWidgets('advanced filters normalize rate digits and preserve submitted pagination ($ar)',(tester)async{
   roomy(tester);final api=UnifiedApi();addTearDown(api.close);await tester.pumpWidget(app(api,(_){},ar:ar));
   final expand=find.byType(ExpansionTile);await tester.ensureVisible(expand);await tester.tap(expand);await tester.pumpAndSettle();
   for(final field in {'operator':'100%_operator','address':'دبي','access':'Visitors','max_rate_per_kwh':'١٫٥٠','price_currency':'aed'}.entries){
    final input=find.byKey(Key('unified-${field.key}'));await tester.ensureVisible(input);await tester.enterText(input,field.value);
   }
   final search=find.byType(FilledButton);await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(api.queries.single['operator'],'100%_operator');expect(api.queries.single['address'],'دبي');expect(api.queries.single['access'],'Visitors');expect(api.queries.single['max_rate_per_kwh'],'1.50');expect(api.queries.single['price_currency'],'AED');
   final next=find.text(ar?'التالي':'Next');await tester.ensureVisible(next);await tester.tap(next);await tester.pumpAndSettle();expect(api.queries.last['page'],'2');expect(api.queries.last['max_rate_per_kwh'],'1.50');
   expect(api.calls.every((path)=>path=='GET discovery/search'),true);
  });
 }

 for(final ar in [false,true]){
  testWidgets('unified query and typed source results in both languages ($ar)',(tester)async{
  roomy(tester);

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
  roomy(tester);
  final api=UnifiedApi()..invalid=true;addTearDown(api.close);await tester.pumpWidget(app(api,(_)=>fail('Unexpected action')));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(find.text('Search failed. Retry explicitly.'),findsOneWidget);expect(find.text('Synthetic partner'),findsNothing);
 });
 testWidgets('unified saved places use both existing writers and scoped read filtering',(tester)async{
  roomy(tester);

  final api=SavedUnifiedApi();addTearDown(api.close);await tester.pumpWidget(app(api,(_){},customer:true));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('unified-favorite-catalog:7')));await tester.pumpAndSettle();expect(api.writes,['PUT favorites/7']);
  await tester.tap(find.byKey(const ValueKey('unified-favorite-partner:7')));await tester.pumpAndSettle();expect(api.writes.last,'PUT roaming/favorites/7');expect(api.saved.length,2);
  await tester.tap(find.byType(CheckboxListTile));await tester.pump();await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(api.queries.last['saved_only'],'1');
  await tester.tap(find.byKey(const ValueKey('unified-favorite-catalog:7')));await tester.pumpAndSettle();expect(api.writes.last,'DELETE favorites/7');expect(api.saved,{'partner:7'});
 });
 testWidgets('unknown saved outcomes block replay until explicit refresh',(tester)async{
  roomy(tester);

  final api=SavedUnifiedApi()..unknown=true;addTearDown(api.close);await tester.pumpWidget(app(api,(_){},customer:true));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();await tester.tap(find.byKey(const ValueKey('unified-favorite-catalog:7')));await tester.pumpAndSettle();
  expect(api.writes.length,1);expect(tester.widget<TextButton>(find.byKey(const ValueKey('unified-favorite-partner:7'))).onPressed,isNull);
  api.unknown=false;await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(tester.widget<TextButton>(find.byKey(const ValueKey('unified-favorite-partner:7'))).onPressed,isNotNull);
  api.epoch++;await tester.tap(find.byKey(const ValueKey('unified-favorite-partner:7')));await tester.pump();expect(api.writes.length,1);
 });
 testWidgets('source report filter preserves the explicit normalized status query',(tester)async{
  roomy(tester);

  final api=UnifiedApi();addTearDown(api.close);await tester.pumpWidget(app(api,(_){ }));await tester.tap(find.byKey(const Key('unified-source-status')));await tester.pumpAndSettle();await tester.tap(find.text('Charging').last);await tester.pumpAndSettle();await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(api.queries.single['recent_source_status'],'Charging');
 });
 testWidgets('partner source-age selection sends bounded hours and persists across pages',(tester)async{
  roomy(tester);
  final api=UnifiedApi();addTearDown(api.close);await tester.pumpWidget(app(api,(_){ }));
  await tester.tap(find.byKey(const Key('unified-source-age')));await tester.pumpAndSettle();await tester.tap(find.text('Last 24 hours').last);await tester.pumpAndSettle();
  await tester.ensureVisible(find.byType(FilledButton));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(api.queries.single['max_source_age_hours'],'24');
  await tester.ensureVisible(find.text('Next'));await tester.tap(find.text('Next'));await tester.pumpAndSettle();expect(api.queries.last['max_source_age_hours'],'24');
 });
 testWidgets('an open view cannot acquire the next accounts private saved state',(tester)async{
  roomy(tester);
  final api=UnifiedApi()..pending=Completer<dynamic>();addTearDown(api.close);await tester.pumpWidget(app(api,(_)=>fail('Unexpected station handoff'),customer:true));await tester.tap(find.byType(FilledButton));await tester.pump();api.epoch++;
  api.pending!.complete({'data':[partnerRow()],'current_page':1,'last_page':1,'total':1});await tester.pumpAndSettle();expect(find.text('Synthetic partner'),findsNothing);expect(find.text('Account changed. Reopen search.'),findsOneWidget);
  await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(api.calls.length,1);
 });
 testWidgets('background and old account responses cannot repopulate results',(tester)async{
  roomy(tester);
  final api=UnifiedApi()..pending=Completer<dynamic>();addTearDown(api.close);await tester.pumpWidget(app(api,(_)=>fail('Unexpected action')));await tester.tap(find.byType(FilledButton));await tester.pump();
  for(final state in [AppLifecycleState.inactive,AppLifecycleState.hidden,AppLifecycleState.paused]){tester.binding.handleAppLifecycleStateChanged(state);}
  api.epoch++;api.pending!.complete({'data':[partnerRow()],'current_page':1,'last_page':1,'total':1});await tester.pump();expect(find.text('Synthetic partner'),findsNothing);
  for(final state in [AppLifecycleState.hidden,AppLifecycleState.inactive,AppLifecycleState.resumed]){tester.binding.handleAppLifecycleStateChanged(state);}
  await tester.pumpAndSettle();expect(find.text('Synthetic partner'),findsNothing);expect(api.calls.length,1);
 });

 for(final ar in [false,true]){
  testWidgets('native opt-in map viewport search reuses authenticated query and pagination ($ar)',(tester)async{
   roomy(tester);
   final api=UnifiedApi()..mapFixtures=true;
   final tiles=UnifiedFixtureTiles();
   addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('No navigation into booking'),ar:ar,tileProvider:tiles));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.length,1);
   final open=find.byKey(const Key('unified-map-open'));
   await tester.ensureVisible(open);await tester.tap(open);await tester.pumpAndSettle();
   expect(api.queries.length,1,'Opening native map must not search');
   expect(tiles.requests,0,'No tile access without separate opt-in');
   expect(find.byKey(const Key('unified-native-viewport-search')),findsNothing);
   await tester.tap(find.byKey(const Key('partner-map-enable')));await tester.pumpAndSettle();
   expect(api.queries.length,1,'Tiles do not transmit viewport to API');
   final search=find.byKey(const Key('unified-native-viewport-search'));
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(api.queries.length,2);
   final params=api.queries.last;
   for(final key in ['south','north','west','east'])expect(double.tryParse(params[key]??''),isNotNull);
   expect(double.parse(params['south']!),lessThan(25.2));
   expect(double.parse(params['north']!),greaterThan(25.2));
   expect(params['page'],'1');
   expect(api.calls.every((path)=>path=='GET discovery/search'),true);
   final next=find.text(ar?'التالي':'Next');
   await tester.ensureVisible(next);await tester.tap(next);await tester.pumpAndSettle();
   expect(api.queries.last['page'],'2');
   expect(api.queries.last['west'],params['west']);
   await tester.ensureVisible(find.byType(FilledButton));await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.last.containsKey('west'),false,'Ordinary explicit search must clear stale viewport');
  });
 }
}
