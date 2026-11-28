import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/federated_nearby.dart';
import 'test_support.dart';

Map<String,dynamic> fixtureReply()=> {
  'evidence':'FEDERATED_GEOGRAPHIC_DISCOVERY_ONLY','distance_basis':'great_circle',
  'complete_geographic_coverage':false,'live_status_verified':false,'combined_total':null,
  'published':{'data':[
    {'kind':'catalog','key':'catalog:7','station':{'id':7,'name':'Fixture platform','evidence':'SIMULATED','latitude':25.2,'longitude':55.3}},
    {'kind':'partner','key':'partner:7','record_id':7,'evidence':'PARTNER_REPORTED_UNVERIFIED',
      'availability':'unknown','remote_control':false,'reservable':false,
      'location':{'name':'Fixture partner','latitude':'25.21','longitude':'55.31','availability':'unknown','remote_control':false,'reservable':false}},
  ],'total':2,'current_page':1,'last_page':1,'per_page':25},
  'licensed':{'state':'observed','sample':{
    'provider':'open_charge_map','evidence':'OPEN_LICENSED_DISCOVERY_ONLY','availability':'unknown',
    'remote_control':false,'reservable':false,'billing_eligible':false,'license_policy':'PER_RECORD_ATTRIBUTION_REQUIRED',
    'count':1,'results':[
      {'id':'ocm:123','name':'<img src=x>','latitude':25.22,'longitude':55.32,'source_status_evidence':'OPEN_CHARGE_MAP_DISCOVERY_ONLY',
        'availability':'unknown','remote_control':false,'reservable':false,'billing_eligible':false,
        'data_license':'CC BY-SA 4.0','attribution':'Open Charge Map / Fixture',
        'source_url':'https://openchargemap.org/site/poi/details/123'},
    ],
  }},
};
class FederationApi extends FixtureApi {
  Map<String,dynamic>? sentBody;
  Map<String,String>? sentQuery;
  Completer<dynamic>? pending;
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}})async{
    calls.add('$method $path');sentBody=body;sentQuery=query;
    if(pending!=null)return pending!.future;
    return fixtureReply();
  }
}
Widget app(FederationApi api,bool Function() fence,{bool ar=false,int epoch=0})=>MaterialApp(
  locale:Locale(ar?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],
  localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],
  home:Scaffold(body:SingleChildScrollView(child:FederatedNearbyPanel(key:ValueKey(epoch),api:api,currentSession:fence))),
);
Future<void> fill(WidgetTester tester)async{
  await tester.tap(find.byKey(const Key('federated-nearby-panel')));await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const Key('federated-latitude')),'٢٥٫٢');
  await tester.enterText(find.byKey(const Key('federated-longitude')),'55.3');
  await tester.enterText(find.byKey(const Key('federated-radius_km')),'10');
  await tester.pumpAndSettle();
}
void main(){
  test('federation parses separately attributed evidence and distinguishes unavailable from empty',(){
    final raw=fixtureReply();
    final parsed=FederatedNearbyResult.parse(raw);
    expect(parsed.publishedTotal,2);expect(parsed.published.length,2);expect(parsed.licensed!.single.license,'CC BY-SA 4.0');
    raw['licensed']={'state':'unavailable','sample':null};
    expect(FederatedNearbyResult.parse(raw).licensed,isNull);
    final empty=fixtureReply();
    empty['licensed']['sample']['results']=[];empty['licensed']['sample']['count']=0;
    expect(FederatedNearbyResult.parse(empty).licensed,isEmpty);
  });
  test('federated map retains three source identities, attribution and rejects malformed coordinates',(){
    final raw=fixtureReply();
    final parsed=FederatedNearbyResult.parse(raw);
    expect(parsed.mapPoints.length,3);
    expect(parsed.mapPoints[0].platformCatalog,isTrue);
    expect(parsed.mapPoints[1].platformCatalog,isFalse);
    expect(parsed.mapPoints[1].licensedCrowd,isFalse);
    expect(parsed.mapPoints[2].licensedCrowd,isTrue);
    expect(parsed.mapPoints[2].licensedAttribution,'Open Charge Map / Fixture');
    expect(parsed.mapPoints[2].licensedLicense,'CC BY-SA 4.0');
    for(final invalid in [null,true,'25.22',91.0,double.nan]){
      final altered=fixtureReply();
      altered['licensed']['sample']['results'][0]['latitude']=invalid;
      final result=FederatedNearbyResult.parse(altered);
      expect(result.licensed!.length,1,reason:'Keep a valid text record');
      expect(result.mapPoints.length,2,reason:'Never guess or coerce map coordinates');
    }
    raw['licensed']={'state':'unavailable','sample':null};
    expect(FederatedNearbyResult.parse(raw).mapPoints.length,2);
  });
  test('malformed federation cannot create a partial or promoted source projection',(){
    for(final mutate in <void Function(Map<String,dynamic>)>[
      (r)=>r['combined_total']=3,
      (r)=>r['live_status_verified']=true,
      (r)=>r['published']['data'].add(r['published']['data'][0]),
      (r)=>r['licensed']['sample']['results'][0]['reservable']=true,
      (r)=>r['licensed']['sample']['results'][0]['data_license']='',
      (r)=>r['licensed']['sample']['results'][0]['source_url']='https://untrusted.example/',
    ]){
      final raw=fixtureReply();mutate(raw);
      expect(()=>FederatedNearbyResult.parse(raw),throwsFormatException);
    }
  });
  for(final ar in [false,true]){
    testWidgets('native geographic POST requires renewed consent and displays read-only attribution ($ar)',(tester)async{
      tester.view.physicalSize=const Size(1100,1800);tester.view.devicePixelRatio=1;
      addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
      final api=FederationApi();addTearDown(api.close);
      await tester.pumpWidget(app(api,()=>true,ar:ar));await tester.pumpAndSettle();
      expect(api.calls,isEmpty);
      await fill(tester);
      final submit=find.byKey(const Key('federated-submit')),consent=find.byKey(const Key('federated-consent'));
      await tester.tap(submit);await tester.pumpAndSettle();expect(api.calls,isEmpty);
      await tester.tap(consent);await tester.tap(submit);await tester.pumpAndSettle();
      expect(api.calls,['POST discovery/federated-nearby']);
      expect(api.sentBody,{'consent':true,'latitude':25.2,'longitude':55.3,'radius_km':10.0});
      expect(api.sentQuery,isEmpty);
      expect(tester.widget<CheckboxListTile>(consent).value,isFalse);
      expect(find.text('Fixture platform'),findsOneWidget);
      expect(find.text('Fixture partner'),findsOneWidget);
      expect(find.text('<img src=x>'),findsOneWidget);
      expect(find.textContaining('Open Charge Map / Fixture · CC BY-SA 4.0'),findsOneWidget);
      expect(find.byType(FilledButton),findsOneWidget);
      await tester.tap(submit);await tester.pumpAndSettle();expect(api.calls.length,1);
    });
  }
  testWidgets('retired native request cannot repopulate the replacement private panel',(tester)async{
    tester.view.physicalSize=const Size(1100,1800);tester.view.devicePixelRatio=1;
    addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
    final api=FederationApi()..pending=Completer<dynamic>();addTearDown(api.close);
    var active=true;
    await tester.pumpWidget(app(api,()=>active));await tester.pumpAndSettle();
    await fill(tester);await tester.tap(find.byKey(const Key('federated-consent')));
    await tester.tap(find.byKey(const Key('federated-submit')));await tester.pump();
    expect(api.calls.length,1);active=false;
    await tester.pumpWidget(app(api,()=>true,epoch:1));await tester.pump();
    api.pending!.complete(fixtureReply());await tester.pumpAndSettle();
    expect(find.text('Fixture platform'),findsNothing);
    await tester.tap(find.byKey(const Key('federated-nearby-panel')));await tester.pumpAndSettle();
    expect(tester.widget<TextField>(find.byKey(const Key('federated-latitude'))).controller!.text,isEmpty);
    expect(tester.widget<CheckboxListTile>(find.byKey(const Key('federated-consent'))).value,isFalse);
    expect(api.calls.length,1);
  });
}
