import 'dart:async';
import 'dart:convert';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/unified_discovery.dart';
import 'package:creabiz_ev/licensed_discovery.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/partner_report_map.dart';
import 'test_support.dart';

Map<String,dynamic> catalogRow()=>{'key':'catalog:7','kind':'catalog','station':{'id':7,'name':'Synthetic catalog','connector_type':'CCS2','power_kw':50,'evidence':'SIMULATED','reservable':false}};
Map<String,dynamic> partnerRow()=>{'key':'partner:7','kind':'partner','record_id':7,'provider_label':'Synthetic operator','location':{'name':'Synthetic partner','address':'<img src=x>','country':'ARE','availability':'unknown','remote_control':false,'reservable':false},'evidence':'PARTNER_REPORTED_UNVERIFIED','availability':'unknown','reservable':false,'remote_control':false};
class UnifiedApi extends FixtureApi {
  Completer<dynamic>? pending;
  bool invalid=false;
  bool mapFixtures=false, forgedMapAuthority=false, duplicateIdentity=false;
  int epoch=0;
  final changes=StreamController<int>.broadcast(sync:true);
  @override
  Stream<int> get sessionEvents=>changes.stream;
  void rotate(){epoch++;changes.add(epoch);}
  @override
  bool Function() sessionFence(){final capture=epoch;return()=>capture==epoch;}
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    calls.add('$method $path');queries.add(query);
    if(path=='discovery/open-charge-map/capabilities')return {'provider':'open_charge_map','enabled':true,'evidence':'DISCOVERY_ONLY','requires_explicit_consent':true,'max_radius_km':200,'max_results':25,'attribution_required_per_record':true,'availability':'unknown','remote_control':false,'reservable':false};
    if(pending!=null)return pending!.future;
    return {'data':int.parse(query['page']!)==1?[
      if(mapFixtures) {...catalogRow(),'station':{...Map<String,dynamic>.from(catalogRow()['station'] as Map),'latitude':25.2,'longitude':55.3,if(forgedMapAuthority)'evidence':'LIVE_VERIFIED'}}
      else catalogRow(),
      if(mapFixtures) {...partnerRow(),if(forgedMapAuthority)'evidence':'LIVE_PROVIDER_VERIFIED','location':{...Map<String,dynamic>.from(partnerRow()['location'] as Map),'latitude':'25.25','longitude':'55.35'}}
      else {...partnerRow(),if(invalid)'reservable':true},
      if(duplicateIdentity) catalogRow(),
    ]:[partnerRow()],'current_page':int.parse(query['page']!),'last_page':2,'total':3};
  }
}
class FederatedFixtureApi extends UnifiedApi {
  final bodies=<Map<String,dynamic>>[];
  bool unavailable=false, forged=false;
  Completer<dynamic>? held;
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    if(path!='discovery/federated-nearby')return super.request(path,method:method,body:body,query:query);
    calls.add('$method $path');bodies.add(Map<String,dynamic>.from(body!));
    if(held!=null)return held!.future;
    return envelope();
  }
  Map<String,dynamic> envelope()=>{'evidence':'FEDERATED_GEOGRAPHIC_DISCOVERY_ONLY','distance_basis':'great_circle',
    'complete_geographic_coverage':false,'live_status_verified':forged,'combined_total':null,
    'published':{'data':[catalogRow(),partnerRow()],'current_page':1,'last_page':2,'total':3},
    'licensed':unavailable?{'state':'unavailable','sample':null}:{
      'state':'observed','sample':{'provider':'open_charge_map','evidence':'OPEN_LICENSED_DISCOVERY_ONLY',
        'availability':'unknown','distance_basis':'great_circle','license_policy':'PER_RECORD_ATTRIBUTION_REQUIRED',
        'remote_control':false,'reservable':false,'billing_eligible':false,'count':0,'results':[],
        'quality_sample':{'evidence':'FILTERED_LICENSED_CROWD_SAMPLE_ONLY','max_source_sample':25,
          'complete_geographic_coverage':false,'live_status_verified':false,'returned_count':0,
          'source_time_unknown_or_future_count':0,'source_older_than_24h_count':0,'operational_report_unknown_count':0}
      }}
  };
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
class IncompleteMetadataApi extends UnifiedApi {
  bool missingIdentity=false, missingPartnerMetadata=false, forgedEvidence=false;
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    final raw=Map<String,dynamic>.from(await super.request(path,method:method,body:body,query:query) as Map);
    final entries=List<Map<String,dynamic>>.from((raw['data'] as List).map((row)=>Map<String,dynamic>.from(row as Map)));
    if(entries.isNotEmpty&&entries[0]['kind']=='catalog'){
      entries[0]=forgedEvidence
        ?{
          'key':'catalog:7','kind':'catalog','station':{
            ...Map<String,dynamic>.from(catalogRow()['station'] as Map),
            'evidence':'LIVE_VERIFIED',
          },
        }
        :{
          'key':'catalog:7','kind':'catalog','station':{
            if(!missingIdentity)'id':7,
            'name':'<img src=x>',
            'evidence':'SIMULATED','connector_type':'CCS2',
            // No power metadata. Must remain a non-actionable catalog card.
          },
        };
    }
    if(missingPartnerMetadata&&entries.length>1&&entries[1]['kind']=='partner'){
      entries[1]={
        ...entries[1],
        'provider_label':{'unexpected':'untrusted'},
        'location':{'id':null,'name':null,'address':{'unexpected':'untrusted'},
                    'city':null,'country':null,'latitude':null,'longitude':null},
      };
    }
    raw['data']=entries;
    return raw;
  }
}
class ForgedPublishedApi extends UnifiedApi {
  final Map<String, dynamic> stationPatch;
  ForgedPublishedApi(this.stationPatch);

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final response = Map<String, dynamic>.from(
      await super.request(path, method: method, body: body, query: query) as Map,
    );
    final records = [
      for (final raw in response['data'] as List)
        Map<String, dynamic>.from(raw as Map),
    ];
    if (records.isNotEmpty && records.first['kind'] == 'catalog') {
      records[0] = {
        ...records.first,
        'station': {
          ...Map<String, dynamic>.from(records.first['station'] as Map),
          'latitude': 25.2,
          'longitude': 55.3,
          ...stationPatch,
        },
      };
    }
    response['data'] = records;
    return response;
  }
}

void roomy(WidgetTester tester){tester.view.physicalSize=const Size(1100,1800);tester.view.devicePixelRatio=1;addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);}
void main(){
  for(final ar in [false,true])testWidgets('federated nearby uses one explicit consent and never replays it ($ar)',(tester)async{
    roomy(tester);
    final api=FederatedFixtureApi();addTearDown(api.close);
    await tester.pumpWidget(app(api,(_)=>fail('Unexpected action'),ar:ar));
    final advanced=find.text(ar?'مرشحات متقدمة':'Advanced filters');
    await tester.ensureVisible(advanced);await tester.tap(advanced);await tester.pumpAndSettle();
    for(final entry in {'latitude':'25.2','longitude':'55.3','radius_km':'10'}.entries){
      final field=find.byKey(Key('unified-${entry.key}'));
      await tester.ensureVisible(field);await tester.enterText(field,entry.value);
    }
    final consent=find.byKey(const Key('unified-federated-consent'));
    final submit=find.byKey(const Key('unified-federated-submit'));
    await tester.ensureVisible(submit);await tester.tap(submit);await tester.pumpAndSettle();
    expect(api.calls,isEmpty);
    await tester.ensureVisible(consent);await tester.tap(consent);await tester.pumpAndSettle();
    await tester.ensureVisible(submit);await tester.tap(submit);await tester.pumpAndSettle();
    expect(api.calls,['POST discovery/federated-nearby']);
    expect(api.bodies.single,{'latitude':'25.2','longitude':'55.3','radius_km':'10','consent':true});
    expect(tester.widget<CheckboxListTile>(consent).value,false);
    final count=find.text(ar?'0 تقريراً مرخصاً (عينة محدودة)':'0 licensed reports (bounded sample)');
    await tester.scrollUntilVisible(count,200,scrollable:find.byType(Scrollable).first);
    expect(count,findsOneWidget);
    expect(find.byKey(const Key('unified-recent-0')),findsNothing);
    final next=find.text(ar?'التالي':'Next');
    await tester.ensureVisible(next);await tester.tap(next);await tester.pumpAndSettle();
    expect(api.calls,['POST discovery/federated-nearby','GET discovery/search']);
    expect(api.queries.single['radius_km'],'10');
    expect(find.text(ar?'0 تقريراً مرخصاً (عينة محدودة)':'0 licensed reports (bounded sample)'),findsNothing);
    api.rotate();await tester.pumpAndSettle();
    expect(consent,findsNothing);
    expect(tester.takeException(),isNull);
  });
  for(final mode in ['unavailable','forged','revoked'])testWidgets('federated result boundary $mode',(tester)async{
    roomy(tester);
    final api=FederatedFixtureApi();addTearDown(api.close);
    api.unavailable=mode=='unavailable';api.forged=mode=='forged';
    if(mode=='revoked')api.held=Completer<dynamic>();
    await tester.pumpWidget(app(api,(_)=>fail('Unexpected action')));
    final advanced=find.text('Advanced filters');
    await tester.ensureVisible(advanced);await tester.tap(advanced);await tester.pumpAndSettle();
    for(final entry in {'latitude':'25.2','longitude':'55.3','radius_km':'10'}.entries){
      final field=find.byKey(Key('unified-${entry.key}'));
      await tester.ensureVisible(field);await tester.enterText(field,entry.value);
    }
    final consent=find.byKey(const Key('unified-federated-consent')),submit=find.byKey(const Key('unified-federated-submit'));
    await tester.ensureVisible(consent);await tester.tap(consent);await tester.pumpAndSettle();
    await tester.ensureVisible(submit);await tester.tap(submit);await tester.pump();
    if(mode=='revoked'){api.rotate();api.held!.complete(api.envelope());}
    await tester.pumpAndSettle();
    expect(api.calls,['POST discovery/federated-nearby']);
    final catalog=find.byKey(const ValueKey('catalog:7'));
    final unavailable=find.text('Licensed source unavailable; published results remain available. This is not zero coverage.');
    if(mode=='unavailable'){
      await tester.scrollUntilVisible(catalog,200,scrollable:find.byType(Scrollable).first);
      expect(catalog,findsOneWidget);
      await tester.scrollUntilVisible(unavailable,200,scrollable:find.byType(Scrollable).first);
    }else{
      expect(catalog,findsNothing);
    }
    expect(find.text('Licensed source unavailable; published results remain available. This is not zero coverage.'),mode=='unavailable'?findsOneWidget:findsNothing);
    expect(find.text('0 licensed reports (bounded sample)'),findsNothing);
    expect(tester.takeException(),isNull);
  });

  for(final ar in [false,true])testWidgets('manual published radius never enters recent filters ($ar)',(tester)async{
    roomy(tester);
    final api=UnifiedApi();addTearDown(api.close);
    await tester.pumpWidget(app(api,(_)=>fail('Unexpected catalog action'),ar:ar,customer:true));
    final advanced=find.text(ar?'مرشحات متقدمة':'Advanced filters');
    await tester.ensureVisible(advanced);await tester.tap(advanced);await tester.pumpAndSettle();
    final values=ar?['٢٥٫٢','٥٥٫٣','١٠']:['25.2','55.3','10'];
    for(final entry in ['latitude','longitude','radius_km'].asMap().entries){
      final field=find.byKey(Key('unified-${entry.value}'));
      await tester.ensureVisible(field);await tester.enterText(field,values[entry.key]);
    }
    expect(api.calls,isEmpty);
    final submit=find.widgetWithText(FilledButton,ar?'البحث في كل المصادر':'Search all sources');
    await tester.ensureVisible(submit);await tester.tap(submit);await tester.pumpAndSettle();
    expect(api.queries.single['latitude'],'25.2');
    expect(api.queries.single['longitude'],'55.3');
    expect(api.queries.single['radius_km'],'10');
    expect(find.byKey(const Key('unified-recent-0')),findsNothing);
    final radius=find.byKey(const Key('unified-radius_km'));
    await tester.ensureVisible(radius);await tester.enterText(radius,'201');
    await tester.ensureVisible(submit);await tester.tap(submit);await tester.pumpAndSettle();
    expect(api.calls.length,1);
    expect(find.byKey(const ValueKey('catalog:7')),findsNothing);
    api.rotate();await tester.pumpAndSettle();
    expect(find.byKey(const Key('unified-radius_km')),findsNothing);
    expect(api.calls.length,1);
    expect(tester.takeException(),isNull);
  });
  for(final ar in [false,true])testWidgets('unified licensed source opens existing consent workflow without forwarding drafts ($ar)',(tester)async{
    roomy(tester);
    final api=UnifiedApi();addTearDown(api.close);
    await tester.pumpWidget(app(api,(_)=>fail('Unexpected catalog action'),ar:ar,customer:true));
    await tester.enterText(find.byKey(const Key('unified-query')),'private query');
    expect(api.calls,isEmpty);
    final open=find.byKey(const Key('unified-licensed-source'));
    await tester.tap(open);await tester.pumpAndSettle();
    expect(find.byType(LicensedDiscoveryScreen),findsOneWidget);
    expect(api.calls,['GET discovery/open-charge-map/capabilities']);
    expect(find.text('private query'),findsNothing);
    expect(api.queries.single,isEmpty);
    final back=find.descendant(of:find.byType(LicensedDiscoveryScreen),matching:find.byType(BackButton));
    expect(back,findsOneWidget);
    await tester.tap(back);await tester.pumpAndSettle();
    expect(find.text('private query'),findsOneWidget);
    expect(api.calls,['GET discovery/open-charge-map/capabilities']);
    final stale=tester.widget<IconButton>(open).onPressed!;
    api.rotate();await tester.pumpAndSettle();
    stale();await tester.pumpAndSettle();
    expect(find.byType(LicensedDiscoveryScreen),findsNothing);
    expect(api.calls.length,1);
    expect(tester.takeException(),isNull);
  });


  for (final ar in [false, true]) {
    testWidgets('unverified union source cannot grant a map pin or saved-place action ($ar)', (tester) async {
      roomy(tester);
      final api=UnifiedApi()..mapFixtures=true..forgedMapAuthority=true;
      addTearDown(api.close);
      await tester.pumpWidget(app(api, (_)=>fail('Unverified booking'), ar:ar, customer:true));
      await tester.ensureVisible(find.byType(FilledButton));
      await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
      expect(find.text('Synthetic catalog'),findsOneWidget);
      expect(find.text('Synthetic partner'),findsOneWidget);
      expect(find.byKey(const ValueKey('unified-favorite-catalog:7')), findsNothing);
      expect(find.byKey(const ValueKey('unified-favorite-partner:7')), findsNothing);
      final open=find.byKey(const Key('unified-map-open'));
      await tester.ensureVisible(open);await tester.tap(open);await tester.pumpAndSettle();
      final map=tester.widget<PartnerReportMap>(find.byType(PartnerReportMap));
      expect(map.points,isEmpty,reason:'No falsified live or provider location may produce a pin');
      expect(api.calls,['GET discovery/search']);
      expect(tester.takeException(),isNull);
    });

    testWidgets('duplicate typed source identity rejects entire native page ($ar)', (tester) async {
      roomy(tester);
      final api=UnifiedApi()..duplicateIdentity=true;
      addTearDown(api.close);
      await tester.pumpWidget(app(api, (_)=>fail('Untrusted map/booking'), ar:ar, customer:true));
      await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
      expect(find.text('Synthetic catalog'),findsNothing);
      expect(find.text('Synthetic partner'),findsNothing);
      expect(find.text(ar?'تعذر البحث. أعد المحاولة صراحةً.':'Search failed. Retry explicitly.'),findsOneWidget);
      expect(find.byKey(const ValueKey('unified-favorite-partner:7')),findsNothing);
      expect(api.calls,['GET discovery/search']);
      expect(tester.takeException(),isNull);
    });
  }

 testWidgets('same API object changing account epoch hides all previous native search data and private drafts',(tester)async{
   roomy(tester);
   final api=UnifiedApi(),next=UnifiedApi();
   addTearDown(api.close);addTearDown(next.close);
   var launches=0;
   await tester.pumpWidget(app(api,(_)=>launches++,customer:true));
   await tester.enterText(find.byKey(const Key('unified-query')),'Private customer address');
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.text('Synthetic catalog'),findsOneWidget);
   expect(find.byKey(const Key('unified-recent-0')),findsOneWidget);
   final savedNavigation=tester.widget<TextButton>(find.widgetWithText(TextButton,'View station and booking options')).onPressed!;
   final requestCount=api.calls.length;
   // EvApi may swap authenticated identity without replacing the API object.
   // Rebuilding that same widget must not expose another actor's catalog,
   // query drafts, partner label, favorite controls or recent search history.
   api.epoch++;
   savedNavigation();
   expect(launches,0,reason:'A stale pre-rendered booking callback must reject the previous account');
   await tester.pumpWidget(app(api,(_)=>fail('No old-account action'),customer:true));
   await tester.pumpAndSettle();
   expect(find.text('Account changed. Reopen search.'),findsOneWidget);
   expect(find.text('Synthetic catalog'),findsNothing);
   expect(find.text('Synthetic partner'),findsNothing);
   expect(find.byKey(const Key('unified-query')),findsNothing);
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   expect(find.byKey(const ValueKey('unified-favorite-catalog:7')),findsNothing);
   expect(api.calls.length,requestCount,
       reason:'A same-object account transition must not search for either actor');
   await tester.pumpWidget(app(next,(_)=>fail('No implicit navigation'),customer:true));
   await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-query')),findsOneWidget);
   expect(tester.widget<TextField>(find.byKey(const Key('unified-query'))).controller!.text,isEmpty);
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   expect(next.calls,isEmpty);
   expect(tester.takeException(),isNull);
 });
 testWidgets('changing accounts clears advanced search drafts and submitted fields',(tester)async{
  roomy(tester);final first=UnifiedApi(),second=UnifiedApi();addTearDown(first.close);addTearDown(second.close);await tester.pumpWidget(app(first,(_){}));
  await tester.enterText(find.byKey(const Key('unified-query')),'Private place');
  await tester.ensureVisible(find.byType(ExpansionTile));await tester.tap(find.byType(ExpansionTile));await tester.pumpAndSettle();
  final operator=find.byKey(const Key('unified-operator'));await tester.ensureVisible(operator);await tester.enterText(operator,'Private operator');
  await tester.pumpWidget(app(second,(_){}));await tester.pumpAndSettle();
  // Identity change remounts the form with collapsed private inputs.
  // Reopen it to verify the previous account's advanced draft is absent.
  expect(operator,findsNothing);
  await tester.ensureVisible(find.byType(ExpansionTile));
  await tester.tap(find.byType(ExpansionTile));await tester.pumpAndSettle();
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
   for(final field in {'operator':'100%_operator','address':'دبي','access':'Visitors','min_power_kw':'٤٠','max_power_kw':'٦٠','max_rate_per_kwh':'١٫٥٠','price_currency':'aed'}.entries){
    final input=find.byKey(Key('unified-${field.key}'));await tester.ensureVisible(input);await tester.enterText(input,field.value);
   }
   final search=find.byType(FilledButton);await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(api.queries.single['operator'],'100%_operator');expect(api.queries.single['address'],'دبي');expect(api.queries.single['access'],'Visitors');expect(api.queries.single['min_power_kw'],'40');expect(api.queries.single['max_power_kw'],'60');expect(api.queries.single['max_rate_per_kwh'],'1.50');expect(api.queries.single['price_currency'],'AED');
   // Expanded advanced fields and in-memory history extend the list;
   // collapse the panel, then finish scrolling before pagination.
   await tester.ensureVisible(expand);await tester.tap(expand);await tester.pumpAndSettle();
   final next=find.text(ar?'التالي':'Next');
   await tester.scrollUntilVisible(next,200,scrollable:find.byType(Scrollable).first);await tester.pumpAndSettle();
   await tester.tap(next);await tester.pumpAndSettle();
   expect(api.queries.last['page'],'2');expect(api.queries.last['max_rate_per_kwh'],'1.50');expect(api.queries.last['min_power_kw'],'40');expect(api.queries.last['max_power_kw'],'60');
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
  await tester.tap(find.byKey(const Key('unified-saved-only')));await tester.pump();await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();expect(api.queries.last['saved_only'],'1');
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
  // The account-revocation surface hides all controls; a stale view cannot
  // initiate another search after an in-flight old-account response.
  expect(find.byType(FilledButton),findsNothing);
  expect(find.byKey(const Key('unified-query')),findsNothing);
  expect(api.calls.length,1);
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
   expect(api.queries.length,1,reason:'Opening native map must not search');
   expect(tiles.requests,0,reason:'No tile access without separate opt-in');
   expect(find.byKey(const Key('unified-native-viewport-search')),findsNothing);
   await tester.tap(find.byKey(const Key('partner-map-enable')));await tester.pumpAndSettle();
   expect(api.queries.length,1,reason:'Tiles do not transmit viewport to API');
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
   expect(api.queries.last.containsKey('west'),false,reason:'Ordinary explicit search must clear stale viewport');
  });
 }

 for(final ar in [false,true]){
  testWidgets('country filter preserves pagination and resets visible control after identity change ($ar)',(tester)async{
   roomy(tester);
   final first=UnifiedApi(),second=UnifiedApi();
   addTearDown(first.close);addTearDown(second.close);
   await tester.pumpWidget(app(first,(_){},ar:ar));
   final market=find.byKey(const Key('unified-country'));
   await tester.ensureVisible(market);await tester.tap(market);await tester.pumpAndSettle();
   await tester.tap(find.text(ar?'الولايات المتحدة':'United States').last);await tester.pumpAndSettle();
   expect(tester.widget<DropdownButton<String>>(market).value,'US');
   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(first.queries.single['country'],'US');
   final next=find.text(ar?'التالي':'Next');
   await tester.ensureVisible(next);await tester.tap(next);await tester.pumpAndSettle();
   expect(first.queries.last['country'],'US');
   expect(first.queries.last['page'],'2');
   await tester.pumpWidget(app(second,(_){},ar:ar));await tester.pumpAndSettle();
   expect(tester.widget<DropdownButton<String>>(market).value,'');
   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(second.queries.single.containsKey('country'),false);
  });
 }

 for(final ar in [false,true]){
  testWidgets('native recent search filters restore privately without submitting and clear with identity ($ar)',(tester)async{
   roomy(tester);
   final first=UnifiedApi(),second=UnifiedApi();
   addTearDown(first.close);addTearDown(second.close);
   await tester.pumpWidget(app(first,(_){},ar:ar,customer:true));
   final input=find.byKey(const Key('unified-query'));
   await tester.enterText(input,'Private location');
   final market=find.byKey(const Key('unified-country'));
   await tester.ensureVisible(market);await tester.tap(market);await tester.pumpAndSettle();
   await tester.tap(find.text(ar?'كندا':'Canada').last);await tester.pumpAndSettle();
   final search=find.byType(FilledButton);
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(first.queries.length,1);
   expect(first.queries.last['country'],'CA');
   expect(find.byKey(const Key('unified-recent-0')),findsOneWidget);
   await tester.enterText(input,'Another location');
   await tester.ensureVisible(market);await tester.tap(market);await tester.pumpAndSettle();
   await tester.tap(find.text(ar?'الولايات المتحدة':'United States').last);await tester.pumpAndSettle();
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(first.queries.length,2);
   final old=find.byKey(const Key('unified-recent-1'));
   await tester.ensureVisible(old);await tester.tap(old);await tester.pumpAndSettle();
   expect(first.queries.length,2,reason:'Restoring fields must not call API');
   expect(tester.widget<TextField>(input).controller!.text,'Private location');
   expect(tester.widget<DropdownButton<String>>(market).value,'CA');
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(first.queries.last['q'],'Private location');
   expect(first.queries.last['country'],'CA');
   expect(first.queries.length,3);
   expect(find.byKey(const Key('unified-recent-2')),findsNothing,
     reason:'Duplicate successful search must not grow the list');
   final clear=find.byKey(const Key('unified-recent-clear'));
   await tester.ensureVisible(clear);await tester.tap(clear);await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   expect(first.queries.length,3,reason:'Clearing history cannot call API');
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsOneWidget);
   await tester.pumpWidget(app(second,(_){},ar:ar,customer:true));
   await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   expect(tester.widget<TextField>(input).controller!.text,isEmpty);
  });
 }
 testWidgets('native failed, backgrounded and account-expired searches do not preserve private history',(tester)async{
   roomy(tester);
   final api=UnifiedApi();addTearDown(api.close);
   api.invalid=true;
   await tester.pumpWidget(app(api,(_){},customer:true));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   api.invalid=false;
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsOneWidget);
   // Follow actual lifecycle transitions: paused directly from resumed
   // is invalid in AppLifecycleListener and never occurs on a device.
   for(final state in [
     AppLifecycleState.inactive,AppLifecycleState.hidden,
     AppLifecycleState.paused,AppLifecycleState.hidden,
     AppLifecycleState.inactive,AppLifecycleState.resumed,
   ]) {
     tester.binding.handleAppLifecycleStateChanged(state);
     await tester.pump();
   }
   await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   api.epoch++;
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
 });

 for(final ar in [false,true]){
  testWidgets('incomplete catalog metadata stays visible without booking or saved action ($ar)',(tester)async{
   roomy(tester);
   final api=IncompleteMetadataApi();addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('No catalog action without connector metadata'),
     ar:ar,customer:true));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.text('<img src=x>'),findsOneWidget);
   expect(find.textContaining(ar?'غير مكتملة':'details incomplete'),findsOneWidget);
   expect(find.text(ar?'عرض المحطة وخيارات الحجز':'View station and booking options'),findsNothing);
   expect(find.byKey(const ValueKey('unified-favorite-catalog:7')),findsNothing);
   expect(find.text('Synthetic partner'),findsOneWidget);
   expect(find.byKey(const ValueKey('unified-favorite-partner:7')),findsOneWidget);
   expect(tester.takeException(),isNull);
  });
 }
 testWidgets('missing canonical identity fails closed without inventing a station id',(tester)async{
   roomy(tester);
   final api=IncompleteMetadataApi()..missingIdentity=true;addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('Unexpected booking action')));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.text('Search failed. Retry explicitly.'),findsOneWidget);
   expect(find.text('<img src=x>'),findsNothing);
   expect(find.text('Synthetic partner'),findsNothing);
 });

 for(final ar in [false,true]){
  testWidgets('missing partner provider address or geometry are explicit list-only metadata ($ar)',(tester)async{
   roomy(tester);
   final api=IncompleteMetadataApi()..missingPartnerMetadata=true;
   addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('No unauthorized actions'),ar:ar,customer:true));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.textContaining(ar?'سجل الشريك #7':'Partner record #7'),findsOneWidget);
   expect(find.textContaining(ar?'اسم مقدم الخدمة غير متاح':'Provider label unavailable'),findsOneWidget);
   expect(find.textContaining(ar?'تفاصيل العنوان غير متاحة':'Address details unavailable'),findsOneWidget);
   expect(find.textContaining(ar?'إحداثيات الخريطة غير متاحة':'Map coordinates unavailable'),findsOneWidget);
   expect(find.textContaining('[object Object]'),findsNothing);
   // Integrated zero-pin exploration remains available, but missing
   // coordinates must never become a station marker or implicit lookup.
   final explore=find.byKey(const Key('unified-map-open'));
   await tester.ensureVisible(explore);await tester.tap(explore);await tester.pumpAndSettle();
   expect(tester.widget<PartnerReportMap>(find.byType(PartnerReportMap)).points,isEmpty);
   expect(find.byType(TileLayer),findsNothing);
   expect(api.calls.length,1);
   expect(tester.takeException(),isNull);
  });
 }

 testWidgets('Arabic recent filter captions stay localized for empty query and country',(tester)async{
   roomy(tester);
   final api=UnifiedApi();addTearDown(api.close);
   await tester.pumpWidget(app(api,(_){},ar:true));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.calls.length,1);
   final caption=find.textContaining('كل المواقع · كل الدول · كل المصادر المنشورة');
   expect(caption,findsOneWidget);
   final recent=find.byKey(const Key('unified-recent-0'));
   await tester.ensureVisible(recent);await tester.tap(recent);await tester.pumpAndSettle();
   expect(api.calls.length,1,reason:'Localized restore cannot submit search');
 });
 testWidgets('native successful recent filter presets retain at most five entries',(tester)async{
   roomy(tester);
   final api=UnifiedApi();addTearDown(api.close);
   await tester.pumpWidget(app(api,(_){ }));
   final input=find.byKey(const Key('unified-query'));
   final search=find.byType(FilledButton);
   for(var i=0;i<7;i++){
     await tester.enterText(input,'Private session $i');
     await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   }
   expect(api.queries.length,7);
   expect(find.byKey(const Key('unified-recent-4')),findsOneWidget);
   expect(find.byKey(const Key('unified-recent-5')),findsNothing);
   expect(find.textContaining('Private session 0'),findsNothing);
 });

 testWidgets('native account changes reset source, connector and age dropdowns alongside country',(tester)async{
   roomy(tester);
   final first=UnifiedApi(),second=UnifiedApi();
   addTearDown(first.close);addTearDown(second.close);
   await tester.pumpWidget(app(first,(_){ }));
   final scope=find.byKey(const Key('unified-scope'));
   final connector=find.byKey(const Key('unified-connector'));
   final age=find.byKey(const Key('unified-source-age'));
   await tester.ensureVisible(scope);await tester.tap(scope);await tester.pumpAndSettle();
   await tester.tap(find.text('Authorized partner reports').last);await tester.pumpAndSettle();
   await tester.ensureVisible(connector);await tester.tap(connector);await tester.pumpAndSettle();
   await tester.tap(find.text('CCS2').last);await tester.pumpAndSettle();
   await tester.ensureVisible(age);await tester.tap(age);await tester.pumpAndSettle();
   await tester.tap(find.text('Last 24 hours').last);await tester.pumpAndSettle();
   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(first.queries.last['scope'],'partner');
   expect(first.queries.last['connector'],'CCS2');
   expect(first.queries.last['max_source_age_hours'],'24');
   await tester.pumpWidget(app(second,(_){ }));await tester.pumpAndSettle();
   expect(tester.widget<DropdownButtonFormField<String>>(scope).initialValue,'all');
   expect(tester.widget<DropdownButtonFormField<String>>(connector).initialValue,'');
   expect(tester.widget<DropdownButtonFormField<String>>(age).initialValue,'');
   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(second.queries.last['scope'],'all');
   expect(second.queries.last['connector'],'');
   expect(second.queries.last.containsKey('max_source_age_hours'),false);
 });

 testWidgets('native customer-to-vendor role downgrade purges recent search and saved-only fields on same API',(tester)async{
   roomy(tester);
   final api=UnifiedApi();addTearDown(api.close);
   await tester.pumpWidget(app(api,(_){},customer:true));
   final input=find.byKey(const Key('unified-query'));
   await tester.enterText(input,'Private customer place');
   await tester.ensureVisible(find.byKey(const Key('unified-saved-only')));
   await tester.tap(find.byKey(const Key('unified-saved-only')));await tester.pump();
   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.last['saved_only'],'1');
   expect(find.byKey(const Key('unified-recent-0')),findsOneWidget);
   await tester.pumpWidget(app(api,(_){},customer:false));await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
   expect(tester.widget<TextField>(input).controller!.text,isEmpty);
   expect(find.byKey(const Key('unified-saved-only')),findsNothing);
   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.last['saved_only'],'0');
   expect(api.queries.last['q'],'');
 });


 for (final adversarial in <({String label, Map<String, dynamic> station})>[
   (label: 'discovery-only claims reservable', station: {
     'evidence': 'DISCOVERY_ONLY', 'reservable': true,
   }),
   (label: 'server returns text distance', station: {
     'distance_km': '5.2',
   }),
   (label: 'server returns invalid timezone', station: {
     'timezone': <String, dynamic>{'unexpected': 'zone'},
   }),
   (label: 'negative station distance', station: {
     'distance_km': -1,
   }),
 ]) {
   for (final ar in [false, true]) {
     testWidgets('malformed optional catalog metadata remains read-only: ${adversarial.label} / $ar', (tester) async {
       roomy(tester);
       final api = ForgedPublishedApi(adversarial.station);
       addTearDown(api.close);
       await tester.pumpWidget(app(
         api, (_) => fail('Forged catalog must never open station actions'),
         customer: true, ar: ar,
       ));
       await tester.tap(find.byType(FilledButton));
       await tester.pumpAndSettle();
       expect(find.text('Synthetic catalog'), findsOneWidget);
       expect(find.textContaining(ar ? 'غير مكتملة' : 'details incomplete'), findsOneWidget);
       expect(find.byKey(const ValueKey('unified-favorite-catalog:7')), findsNothing);
       expect(find.text(ar ? 'عرض المحطة وخيارات الحجز' : 'View station and booking options'), findsNothing);
       expect(find.text('Synthetic partner'), findsOneWidget);
       expect(tester.takeException(), isNull);
     });
   }
 }

 testWidgets('contradictory discovery-only reservation never creates a native map pin', (tester) async {
   roomy(tester);
   final api = ForgedPublishedApi({
     'evidence': 'DISCOVERY_ONLY',
     'reservable': true,
   });
   addTearDown(api.close);
   await tester.pumpWidget(app(api, (_) => fail('No booking')));
   await tester.tap(find.byType(FilledButton));
   await tester.pumpAndSettle();
   final open = find.byKey(const Key('unified-map-open'));
   await tester.ensureVisible(open);
   await tester.tap(open);
   await tester.pumpAndSettle();
   final map = tester.widget<PartnerReportMap>(find.byType(PartnerReportMap));
   expect(map.points, isEmpty);
   expect(api.calls.length, 1);
   expect(tester.takeException(), isNull);
 });

 testWidgets('unrecognized live evidence with otherwise complete station metadata grants no native booking',(tester)async{
   roomy(tester);
   final api=IncompleteMetadataApi()..forgedEvidence=true;
   addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('Unsafe station handoff'),
      customer:true));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(find.text('Synthetic catalog'),findsOneWidget);
   expect(find.textContaining('details incomplete'),findsOneWidget);
   expect(find.byKey(const ValueKey('unified-favorite-catalog:7')),findsNothing);
   expect(find.text('View station and booking options'),findsNothing);
   expect(find.text('Synthetic partner'),findsOneWidget);
   expect(tester.takeException(),isNull);
 });

 for(final ar in [false,true]){
  testWidgets('empty native discovery page can explicitly select map viewport without source/GPS inference ($ar)',(tester)async{
   roomy(tester);
   final api=UnifiedApi();
   final tiles=UnifiedFixtureTiles();
   addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('No implicit station booking'),ar:ar,tileProvider:tiles));
   expect(api.calls,isEmpty,reason:'Opening an empty catalog does not search');
   final open=find.byKey(const Key('unified-map-open'));
   expect(open,findsOneWidget);
   await tester.ensureVisible(open);await tester.tap(open);await tester.pumpAndSettle();
   expect(api.calls,isEmpty,reason:'Map opening never contacts the catalog or a provider');
   expect(tiles.requests,0,reason:'No tile access until explicit opt in');
   expect(find.byKey(const Key('unified-native-viewport-search')),findsNothing);
   expect(find.textContaining(ar?'عرض العالم المحايد':'neutral world view'),findsOneWidget);

   await tester.tap(find.byKey(const Key('partner-map-enable')));
   await tester.pumpAndSettle();
   expect(tiles.requests,greaterThan(0));
   expect(api.calls,isEmpty,reason:'Map tile enable is not a catalog search');
   final search=find.byKey(const Key('unified-native-viewport-search'));
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(api.calls,['GET discovery/search']);
   final bounds=api.queries.single;
   for(final key in ['south','north','west','east'])expect(double.tryParse(bounds[key]??''),isNotNull);
   expect(bounds['page'],'1');
   expect(bounds['scope'],'all');
   expect(bounds.containsKey('latitude'),false);
   expect(bounds.containsKey('longitude'),false);
   expect(bounds.containsKey('radius_km'),false);
   expect(find.text('Synthetic catalog'),findsOneWidget);
   expect(find.byKey(const Key('unified-map-open')),findsOneWidget);

   final next=find.text(ar?'التالي':'Next');
   await tester.ensureVisible(next);await tester.tap(next);await tester.pumpAndSettle();
   expect(api.queries.length,2);
   expect(api.queries.last['page'],'2');
   expect(api.queries.last['west'],bounds['west']);

   await tester.ensureVisible(find.byType(FilledButton));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.last.containsKey('west'),false,
      reason:'Ordinary search must clear user-submitted empty-map rectangle');
   expect(tester.takeException(),isNull);
  });
 }

 testWidgets('stale account epoch while empty map is open clears old discovery data without lookup',(tester)async{
   roomy(tester);
   final api=UnifiedApi();
   final tiles=UnifiedFixtureTiles();
   addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('Stale booking action'),tileProvider:tiles,customer:true));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.calls,['GET discovery/search']);
   expect(find.text('Synthetic catalog'),findsOneWidget);
   final opener=find.byKey(const Key('unified-map-open'));
   await tester.ensureVisible(opener);await tester.tap(opener);await tester.pumpAndSettle();
   expect(tiles.requests,0);
   final enable = tester.widget<FilledButton>(
     find.byKey(const Key('partner-map-enable')),
   ).onPressed!;
   api.rotate();
   enable();
   await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-native-viewport-search')), findsNothing);
   expect(find.textContaining('Private map hidden.'), findsOneWidget);
   expect(tiles.requests, 0, reason: 'Revocation must precede tile access');
   await tester.pageBack();
   await tester.pumpAndSettle();
   expect(api.calls.length,1,reason:'A stale map must never query under the new account');
   expect(find.text('Synthetic catalog'),findsNothing,
      reason:'Old customer location metadata must not survive epoch change');
   expect(find.text('Account changed. Reopen search.'),findsOneWidget);
   expect(tester.takeException(),isNull);
 });

 for(final ar in [false,true]){
  testWidgets('empty viewport discovery preserves explicitly submitted country across map and pagination ($ar)',(tester)async{
    roomy(tester);
    final api=UnifiedApi(),tiles=UnifiedFixtureTiles();
    addTearDown(api.close);
    await tester.pumpWidget(app(api,(_)=>fail('No charging action'),ar:ar,tileProvider:tiles));
    final market=find.byKey(const Key('unified-country'));
    await tester.ensureVisible(market);await tester.tap(market);await tester.pumpAndSettle();
    await tester.tap(find.text(ar?'الولايات المتحدة':'United States').last);
    await tester.pumpAndSettle();
    final submit=find.byType(FilledButton);
    await tester.ensureVisible(submit);await tester.tap(submit);await tester.pumpAndSettle();
    expect(api.queries.single['country'],'US');
    final open=find.byKey(const Key('unified-map-open'));
    await tester.ensureVisible(open);await tester.tap(open);await tester.pumpAndSettle();
    expect(api.calls.length,1);
    expect(tiles.requests,0);
    await tester.tap(find.byKey(const Key('partner-map-enable')));await tester.pumpAndSettle();
    final bounds=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(bounds);await tester.tap(bounds);await tester.pumpAndSettle();
    expect(api.queries.length,2);
    expect(api.queries.last['country'],'US');
    expect(api.queries.last.containsKey('west'),true);
    await tester.ensureVisible(find.text(ar?'التالي':'Next'));
    await tester.tap(find.text(ar?'التالي':'Next'));await tester.pumpAndSettle();
    expect(api.queries.last['country'],'US');
    expect(api.queries.last['west'],api.queries[1]['west']);
    expect(api.calls.every((entry)=>entry=='GET discovery/search'),true);
    expect(tester.takeException(),isNull);
  });
 }

 testWidgets('Arabic manual map centre moves an empty map without GPS or automatic catalog search',(tester)async{
   roomy(tester);
   final api=UnifiedApi(),tiles=UnifiedFixtureTiles();
   addTearDown(api.close);
   await tester.pumpWidget(app(api,(_)=>fail('No implicit charging'),ar:true,tileProvider:tiles));
   await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
   expect(api.queries.length,1);
   final open=find.byKey(const Key('unified-map-open'));
   await tester.ensureVisible(open);await tester.tap(open);await tester.pumpAndSettle();
   expect(find.byKey(const Key('unified-native-manual-centre')),findsNothing);
   expect(tiles.requests,0);
   await tester.tap(find.byKey(const Key('partner-map-enable')));await tester.pumpAndSettle();
   final latitude=find.byKey(const Key('unified-map-manual-latitude'));
   final longitude=find.byKey(const Key('unified-map-manual-longitude'));
   await tester.ensureVisible(latitude);await tester.enterText(latitude,'٢٥٫٢');
   await tester.ensureVisible(longitude);await tester.enterText(longitude,'٥٥٫٣');
   final reposition=find.byKey(const Key('unified-native-manual-centre'));
   await tester.ensureVisible(reposition);await tester.tap(reposition);await tester.pumpAndSettle();
   expect(api.queries.length,1,reason:'Typing and moving the map are not catalog API requests');
   expect(find.byKey(const Key('unified-native-gps-error')),findsNothing);
   final search=find.byKey(const Key('unified-native-viewport-search'));
   await tester.ensureVisible(search);await tester.tap(search);await tester.pumpAndSettle();
   expect(api.queries.length,2);
   final bbox=api.queries.last;
   expect(double.parse(bbox['south']!),lessThan(25.2));
   expect(double.parse(bbox['north']!),greaterThan(25.2));
   expect(double.parse(bbox['west']!),lessThan(55.3));
   expect(double.parse(bbox['east']!),greaterThan(55.3));
   expect(bbox.containsKey('latitude'),false);
   expect(bbox.containsKey('longitude'),false);
   expect(tester.takeException(),isNull);
 });
 for(final ar in [false,true])for(final transition in ['account','role','clear']){
  testWidgets('discarded recent-filter callback cannot enter current native form ($ar $transition)',(tester)async{
   roomy(tester);
   final first=UnifiedApi(),second=UnifiedApi();
   addTearDown(first.close);addTearDown(second.close);
   await tester.pumpWidget(app(first,(_){},ar:ar,customer:true));
   final input=find.byKey(const Key('unified-query'));
   await tester.enterText(input,'Private old location');
   final submit=find.byType(FilledButton);
   await tester.ensureVisible(submit);await tester.tap(submit);await tester.pumpAndSettle();
   final restore=tester.widget<OutlinedButton>(find.byKey(const Key('unified-recent-0'))).onPressed!;
   if(transition=='account'){
    await tester.pumpWidget(app(second,(_){},ar:ar,customer:true));
   }else if(transition=='role'){
    await tester.pumpWidget(app(first,(_){},ar:ar,customer:false));
   }else{
    final clear=find.byKey(const Key('unified-recent-clear'));
    await tester.ensureVisible(clear);await tester.tap(clear);
   }
   await tester.pumpAndSettle();
   await tester.enterText(input,'Current draft');
   final before=first.calls.length+second.calls.length;
   restore();await tester.pumpAndSettle();
   expect(tester.widget<TextField>(input).controller!.text,'Current draft');
   expect(first.calls.length+second.calls.length,before);
   expect(find.byKey(const Key('unified-recent-0')),findsNothing);
  });
 }

  testWidgets('retired favorite and catalog callbacks cannot act on successor page',(tester)async{
    roomy(tester);
    final old=SavedUnifiedApi(),next=SavedUnifiedApi();
    addTearDown(old.close);addTearDown(next.close);
    addTearDown(old.changes.close);addTearDown(next.changes.close);
    var launches=0;
    await tester.pumpWidget(app(old,(_)=>launches++,customer:true));
    await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
    final star=tester.widget<TextButton>(find.byKey(const ValueKey('unified-favorite-catalog:7'))).onPressed!;
    final station=tester.widget<TextButton>(find.widgetWithText(TextButton,'View station and booking options')).onPressed!;
    await tester.pumpWidget(app(next,(_)=>launches++,customer:true));
    await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
    star();station();await tester.pumpAndSettle();
    expect(old.writes,isEmpty);expect(next.writes,isEmpty);expect(launches,0);
    await tester.tap(find.byKey(const ValueKey('unified-favorite-catalog:7')));
    await tester.pumpAndSettle();
    expect(next.writes,['PUT favorites/7']);
  });
  for(final ar in [false,true]){
    testWidgets('revoked unified map hides loaded pins and an open details sheet ($ar)',(tester)async{
      roomy(tester);
      final api=UnifiedApi()..mapFixtures=true;
      addTearDown(api.close);addTearDown(api.changes.close);
      final tiles=UnifiedFixtureTiles();
      await tester.pumpWidget(app(api,(_)=>fail('Unexpected booking'),ar:ar,customer:true,tileProvider:tiles));
      await tester.tap(find.byType(FilledButton));await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('unified-map-open')));
      await tester.tap(find.byKey(const Key('unified-map-open')));await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('partner-map-enable')));await tester.pumpAndSettle();
      final viewport=find.byKey(const Key('unified-native-viewport-search'));
      final stale=tester.widget<OutlinedButton>(viewport).onPressed!;
      final listRow=find.widgetWithText(ListTile,'Synthetic partner');
      await tester.scrollUntilVisible(listRow,200,scrollable:find.byType(Scrollable).first);
      await tester.tap(listRow);await tester.pumpAndSettle();
      expect(find.byKey(const Key('partner-report-details-close')),findsOneWidget);
      expect(find.text('25.250000, 55.350000'),findsOneWidget);
      api.rotate();await tester.pumpAndSettle();
      expect(find.text('25.250000, 55.350000'),findsNothing);
      expect(find.text('Synthetic partner'),findsNothing);
      expect(find.byType(FlutterMap),findsNothing);
      stale();await tester.pumpAndSettle();
      expect(api.calls.length,1);
      expect(tester.takeException(),isNull);
    });
  }

}
