import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/partner_report_map.dart';
import 'package:creabiz_ev/foreground_location.dart';

class FakeMapTiles extends TileProvider {
  int requests = 0;

  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    requests++;
    return MemoryImage(base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=',
    ));
  }
}

void main() {

  for(final ar in [false,true]){
    for(final platform in [false,true]){
      testWidgets('map marker screen-reader provenance is localized ($ar/$platform)',(tester)async{
        final point=platform
          ?PartnerMapPoint.fromPublishedCatalog({
              'id':77,'name':'Visible source','latitude':25.2,
              'longitude':55.3,'evidence':'DISCOVERY_ONLY',
            })!
          :PartnerMapPoint.fromPublishedLocation({
              'name':'Visible source','latitude':25.2,'longitude':55.3,
            })!;
        final tiles=FakeMapTiles();
        await tester.pumpWidget(MaterialApp(
          locale:Locale(ar?'ar':'en'),
          supportedLocales:const [Locale('en'),Locale('ar')],
          localizationsDelegates:const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home:PartnerReportMap(points:[point],tileProvider:tiles),
        ));
        expect(tiles.requests,0);
        await tester.tap(find.byKey(const Key('partner-map-enable')));
        await tester.pumpAndSettle();
        final suffix=ar
          ?(platform?'كتالوج المنصة، التوفر غير مؤكد':'تقرير شريك غير مؤكد')
          :(platform?'platform catalog, availability unverified':'unverified partner report');
        expect(find.byWidgetPredicate((widget)=>widget is Semantics &&
          (widget.properties.label??'').contains('Visible source — $suffix')),
          findsOneWidget);
        if(ar)expect(find.byWidgetPredicate((widget)=>widget is Semantics &&
          (widget.properties.label??'').contains('availability unverified')),
          findsNothing);
        expect(tester.takeException(),isNull);
      });
    }
  }
  test('partner map does not invent missing or unsupported coordinates', () {
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': null,
      'longitude': null}), isNull);
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': 89.99,
      'longitude': 55.3}), isNull);
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': double.infinity,
      'longitude': 55.3}), isNull);
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': '25.2',
      'longitude': '-180'})?.longitude, -180);
    final zero = PartnerMapPoint.fromPublishedLocation({
      'latitude': 0, 'longitude': 0, 'name': 'Literal <img src=x>',
    });
    expect(zero, isNotNull);
    expect(zero!.name, 'Literal <img src=x>');
    expect(zero.latitude, 0);
    expect(zero.longitude, 0);
  });

  test('published catalog map points preserve their own evidence and reject malformed sources', () {
    final row=<String,dynamic>{'id':7,'name':'Platform source','latitude':'25.2','longitude':'55.3','evidence':'SIMULATED'};
    final point=PartnerMapPoint.fromPublishedCatalog(row)!;
    expect(point.platformCatalog,true);expect(point.catalogEvidence,'SIMULATED');expect(point.providerLabel,isNull);
    for(final update in <Map<String,dynamic>>[
      {'id':0}, {'latitude':89}, {'evidence':'LIVE_VERIFIED'}, {'name':null},
      {'evidence':'DISCOVERY_ONLY', 'reservable':true},
    ]) expect(PartnerMapPoint.fromPublishedCatalog({...row,...update}),isNull);
  });

  testWidgets('mixed map keeps supplier labels and tile consent independent of source', (tester) async {
    final tiles=FakeMapTiles();
    final platform=PartnerMapPoint.fromPublishedCatalog({'id':7,'name':'Platform fixture','latitude':25.2,'longitude':55.3,'evidence':'SIMULATED'})!;
    final partner=PartnerMapPoint.fromPublishedLocation({'name':'Partner fixture','latitude':25.2,'longitude':55.3})!;
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(points:[platform,partner],tileProvider:tiles)));
    expect(tiles.requests,0);expect(find.byType(FlutterMap),findsNothing);
    expect(find.text('Published location map (not live)'),findsOneWidget);
    await tester.tap(find.text('Platform fixture'));await tester.pumpAndSettle();
    expect(find.textContaining('Platform catalog · SIMULATED. Availability'),findsOneWidget);
    expect(find.textContaining('Partner-reported place; live availability'),findsNothing);expect(tiles.requests,0);
    await tester.tap(find.byKey(const Key('partner-report-details-close')));await tester.pumpAndSettle();
    await tester.tap(find.text('Partner fixture'));await tester.pumpAndSettle();
    expect(find.textContaining('Partner-reported place; live availability'),findsOneWidget);expect(tiles.requests,0);
  });

  testWidgets('consent gates every partner map tile and map has no charging controls',
      (tester) async {
    final tiles = FakeMapTiles();
    final point = PartnerMapPoint.fromPublishedLocation({
      'latitude': '25.2',
      'longitude': '55.3',
      'name': 'Literal <img src=x>',
    }, providerLabel: 'Unverified network', reportedAgeMinutes: 1600)!;
    await tester.pumpWidget(MaterialApp(
      home: PartnerReportMap(points: [point], tileProvider: tiles),
    ));
    expect(tiles.requests, 0);
    expect(find.byType(FlutterMap), findsNothing);
    expect(find.text('Enable report map'), findsOneWidget);
    expect(find.text('Start charging'), findsNothing);
    expect(find.text('Reserve connector'), findsNothing);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    expect(tiles.requests, greaterThan(0));
    await tester.tap(find.byTooltip('Literal <img src=x>'));
    await tester.pumpAndSettle();
    expect(find.text('Literal <img src=x>'), findsWidgets);
    expect(find.textContaining('Partner-reported place; live availability'),
        findsOneWidget);
    expect(find.text('Reserve connector'), findsNothing);
    await tester.tap(find.byKey(const Key('partner-report-details-close')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('partner-map-disable')));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap), findsNothing);
    expect(tester.takeException(), isNull);
  });

  test('search viewport is bounded, normalized, and wraps the date line without source authority', () {
    expect(discoveryViewportBounds(24,26,54,56), {
      'south':'24.000000','north':'26.000000','west':'54.000000','east':'56.000000',
    });
    expect(discoveryViewportBounds(9,11,179,181)?['east'],'-179.000000');
    expect(discoveryViewportBounds(-90,90,-300,300),{
      'south':'-90.000000','north':'90.000000','west':'-180.000000','east':'180.000000',
    });
    for(final bounds in [
      [double.nan,2,3,4], [2,double.infinity,3,4], [10,9,3,4],
      [1,2,5,5], [1,1,5,7], [10,10.00000001,5,7],
    ]) {
      expect(discoveryViewportBounds(bounds[0].toDouble(),bounds[1].toDouble(),bounds[2].toDouble(),bounds[3].toDouble()),isNull);
    }
  });

  testWidgets('view search remains user-initiated after native tile consent and returns bounds only', (tester) async {
    final tiles=FakeMapTiles();
    final chosen=<Map<String,String>>[];
    final point=PartnerMapPoint.fromPublishedLocation({
      'name':'Unverified network','latitude':25.2,'longitude':55.3,
    })!;
    await tester.pumpWidget(MaterialApp(home:Builder(builder:(context)=>Scaffold(
      body:FilledButton(key:const Key('open-native-map'),onPressed:()async{
        final bounds=await Navigator.of(context).push<Map<String,String>>(
          MaterialPageRoute(builder:(_)=>PartnerReportMap(
            points:[point],tileProvider:tiles,allowViewportSearch:true,
          )),
        );
        if(bounds!=null)chosen.add(bounds);
      },child:const Text('Open map')),
    ))));
    await tester.tap(find.byKey(const Key('open-native-map')));
    await tester.pumpAndSettle();
    expect(tiles.requests,0);
    expect(chosen,isEmpty);
    expect(find.byKey(const Key('unified-native-viewport-search')),findsNothing);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    expect(tiles.requests,greaterThan(0));
    expect(chosen,isEmpty,reason:'Enabling a map must never issue search');
    final button=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(chosen.length,1);
    final bounds=chosen.single;
    expect(double.parse(bounds['south']!),lessThan(25.2));
    expect(double.parse(bounds['north']!),greaterThan(25.2));
    expect(double.parse(bounds['west']!),lessThan(55.3));
    expect(double.parse(bounds['east']!),greaterThan(55.3));
    expect(find.byType(FlutterMap),findsNothing);
    expect(tester.takeException(),isNull);
  });

  test('loaded published sources group into separate identified records at map zoom', () {
    final platform = PartnerMapPoint.fromPublishedCatalog({
      'id':17,'name':'Platform source','latitude':25.2,'longitude':55.3,'evidence':'DISCOVERY_ONLY',
    })!;
    final partner = PartnerMapPoint.fromPublishedLocation({
      'name':'Partner source','latitude':'25.2','longitude':'55.3',
    }, providerLabel:'Unverified operator')!;
    final other = PartnerMapPoint.fromPublishedLocation({
      'name':'Remote source','latitude':-25.2,'longitude':-55.3,
    })!;
    final groups = clusterPublishedMapPoints([platform,partner,other],11);
    expect(groups.length,2);
    expect(groups.first.length,2);
    expect(groups.first.first.platformCatalog,true);
    expect(groups.first[1].platformCatalog,false);
    expect(groups.last.single.name,'Remote source');
    final missing = PartnerMapPoint(latitude:double.infinity,longitude:0,name:'Invalid');
    expect(clusterPublishedMapPoints([platform,missing],11).single.single.name,'Platform source');
    expect(clusterPublishedMapPoints([platform,partner],double.nan).length,2);
  });

  testWidgets('cluster marker discloses two separate sources with no charging control', (tester) async {
    final tiles=FakeMapTiles();
    final platform=PartnerMapPoint.fromPublishedCatalog({
      'id':3,'name':'Published station','latitude':25.2,'longitude':55.3,'evidence':'SIMULATED',
    })!;
    final partner=PartnerMapPoint.fromPublishedLocation({
      'name':'Unverified network','latitude':25.2,'longitude':55.3,
    },providerLabel:'Crowd supplier')!;
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(
      points:[platform,partner],tileProvider:tiles,
    )));
    expect(find.byKey(const Key('partner-report-cluster-0')),findsNothing);
    expect(tiles.requests,0);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    expect(tiles.requests,greaterThan(0));
    final cluster=find.byKey(const Key('partner-report-cluster-0'));
    expect(cluster,findsOneWidget);
    expect(find.byKey(const Key('partner-report-pin-0')),findsNothing);
    await tester.tap(cluster);await tester.pumpAndSettle();
    expect(find.textContaining('2 loaded locations'),findsOneWidget);
    expect(find.textContaining('A cluster is not one charger'),findsOneWidget);
    expect(find.text('Published station'),findsWidgets);
    expect(find.text('Unverified network'),findsWidgets);
    await tester.tap(find.text('Unverified network').last);
    await tester.pumpAndSettle();
    expect(find.textContaining('Partner-reported place; live availability'),findsOneWidget);
    expect(find.text('Reserve connector'),findsNothing);
    expect(find.text('Start charging'),findsNothing);
    await tester.tap(find.byKey(const Key('partner-report-details-close')));
    await tester.pumpAndSettle();
    expect(tester.takeException(),isNull);
  });

  testWidgets('empty partner-only map never accesses tiles or invents a station', (tester) async {
    final tiles=FakeMapTiles();
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(
      points:const [],tileProvider:tiles,
    )));
    expect(find.text('No map-compatible coordinates on this page.'),findsOneWidget);
    expect(find.byKey(const Key('partner-map-enable')),findsNothing);
    expect(find.byType(FlutterMap),findsNothing);
    expect(find.byKey(const Key('unified-native-viewport-search')),findsNothing);
    expect(tiles.requests,0);
  });

  testWidgets('empty exploratory map requires separate tile consent and deliberate viewport submit', (tester) async {
    final tiles=FakeMapTiles();
    final requested=<Map<String,String>>[];
    await tester.pumpWidget(MaterialApp(home:Builder(builder:(context)=>Scaffold(
      body:FilledButton(key:const Key('open-empty-exploration'),onPressed:()async{
        final result=await Navigator.of(context).push<Map<String,String>>(
          MaterialPageRoute(builder:(_)=>PartnerReportMap(
            points:const [],tileProvider:tiles,allowViewportSearch:true,
          )),
        );
        if(result!=null)requested.add(result);
      },child:const Text('Open discovery area')),
    ))));
    await tester.tap(find.byKey(const Key('open-empty-exploration')));
    await tester.pumpAndSettle();
    expect(tiles.requests,0);
    expect(find.byType(FlutterMap),findsNothing);
    expect(find.byKey(const Key('unified-native-viewport-search')),findsNothing);
    expect(find.textContaining('neutral world view'),findsOneWidget);
    expect(find.text('Choose discovery search area'),findsOneWidget);
    expect(find.text('Enable discovery area map'),findsOneWidget);
    expect(find.byKey(const Key('partner-map-enable')),findsOneWidget);
    expect(requested,isEmpty);

    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap),findsOneWidget);
    expect(tiles.requests,greaterThan(0));
    expect(requested,isEmpty);
    expect(find.byKey(const Key('partner-report-pin-0')),findsNothing);
    expect(find.byKey(const Key('partner-report-cluster-0')),findsNothing);

    final action=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(action);
    await tester.tap(action);
    await tester.pumpAndSettle();
    expect(requested.length,1);
    final bounds=requested.single;
    for(final key in ['south','north','west','east']){
      expect(double.tryParse(bounds[key]??''),isNotNull);
    }
    expect(double.parse(bounds['south']!),lessThan(0));
    expect(double.parse(bounds['north']!),greaterThan(0));
    expect(double.parse(bounds['west']!),lessThan(0));
    expect(double.parse(bounds['east']!),greaterThan(0));
    expect(find.byType(FlutterMap),findsNothing);
    expect(tester.takeException(),isNull);
  });

  test('map clusters respect wrapped screen distance at the antimeridian without inventing identity', () {
    final west = PartnerMapPoint(
      latitude: 0,longitude: 179.999,name: 'West edge',
      platformCatalog: true,catalogEvidence: 'DISCOVERY_ONLY',
    );
    final east = PartnerMapPoint(
      latitude: 0,longitude: -179.999,name: 'East edge',
      providerLabel: 'Unverified partner',
    );
    final distant = PartnerMapPoint(
      latitude: 0,longitude: -179.5,name: 'Not overlapping',
    );
    final groups=clusterPublishedMapPoints([west,east,distant],11);
    expect(groups.length,2);
    expect(groups.first.map((point)=>point.name).toList(),
      ['West edge','East edge'],
      reason:'Two actual source pins separated only by the map seam must cluster');
    expect(groups.first.first.platformCatalog,true);
    expect(groups.first.last.platformCatalog,false);
    expect(groups.last.single.name,'Not overlapping');
    final seam=clusterPublishedMapPoints([
      const PartnerMapPoint(latitude:0,longitude:180,name:'Exactly 180'),
      const PartnerMapPoint(latitude:0,longitude:-180,name:'Exactly -180'),
    ],11);
    expect(seam.length,1);
    expect(seam.single.length,2);
    expect(seam.single.first.longitude,180,
      reason:'Cluster anchor is the first real coordinate; never invent a centroid');
  });

  test('latitude separation can prevent a false cluster even with matching longitude', () {
    final points=<PartnerMapPoint>[
      const PartnerMapPoint(latitude:25,longitude:55,name:'Source one'),
      const PartnerMapPoint(latitude:25.2,longitude:55,name:'Source two'),
    ];
    expect(clusterPublishedMapPoints(points,11).length,2);
  });

  testWidgets('empty map enable-disable-reenable resets attached controller state safely', (tester) async {
    final tiles=FakeMapTiles();
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(
      points:const [],tileProvider:tiles,allowViewportSearch:true,
    )));
    expect(tiles.requests,0);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    final action=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(action);
    expect(tester.widget<OutlinedButton>(action).onPressed,isNotNull);
    await tester.tap(find.byKey(const Key('partner-map-disable')));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap),findsNothing);
    expect(action,findsNothing);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap),findsOneWidget);
    await tester.ensureVisible(action);
    expect(tester.widget<OutlinedButton>(action).onPressed,isNotNull,
      reason:'Fresh controller attachment must be required on each enable');
    expect(tester.takeException(),isNull);
  });

  testWidgets('optional one-time GPS needs separate consent and map search after positioning', (tester) async {
    final tiles=FakeMapTiles();
    final submitted=<Map<String,String>>[];
    var reads=0;
    await tester.pumpWidget(MaterialApp(home:Builder(builder:(context)=>Scaffold(
      body:FilledButton(key:const Key('open-geo-area'),onPressed:()async{
        final result=await Navigator.of(context).push<Map<String,String>>(
          MaterialPageRoute(builder:(_)=>PartnerReportMap(
            points:const [],tileProvider:tiles,allowViewportSearch:true,
            locationReader:()async{
              reads++;
              return const ForegroundFix(25.2,55.3,15);
            },
          )),
        );
        if(result!=null)submitted.add(result);
      },child:const Text('Choose search area')),
    ))));
    await tester.tap(find.byKey(const Key('open-geo-area')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('unified-native-gps-once')),findsNothing);
    expect(reads,0);expect(submitted,isEmpty);expect(tiles.requests,0);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    final gps=find.byKey(const Key('unified-native-gps-once'));
    await tester.ensureVisible(gps);
    expect(tester.widget<OutlinedButton>(gps).onPressed,isNotNull);
    await tester.tap(gps);await tester.pumpAndSettle();
    expect(find.textContaining('one foreground location only after permission'),findsOneWidget);
    expect(reads,0,reason:'Opening consent dialog cannot collect GPS');
    await tester.tap(find.text('Cancel'));await tester.pumpAndSettle();
    expect(reads,0);expect(submitted,isEmpty);

    await tester.tap(gps);await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-consent')));
    await tester.pumpAndSettle();
    expect(reads,1);
    expect(submitted,isEmpty,reason:'GPS may reposition tiles, not submit a search');
    expect(find.byKey(const Key('unified-native-gps-error')),findsNothing);
    final viewport=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(viewport);
    await tester.tap(viewport);await tester.pumpAndSettle();
    expect(submitted.length,1);
    final bounds=submitted.single;
    expect(double.parse(bounds['south']!),lessThan(25.2));
    expect(double.parse(bounds['north']!),greaterThan(25.2));
    expect(double.parse(bounds['west']!),lessThan(55.3));
    expect(double.parse(bounds['east']!),greaterThan(55.3));
    expect(tester.takeException(),isNull);
  });

  testWidgets('foreground GPS denial has an explicit localized manual-map fallback', (tester)async{
    final tiles=FakeMapTiles();
    var reads=0;
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(
      points:const [],tileProvider:tiles,allowViewportSearch:true,
      locationReader:()async{
        reads++;
        throw const LocationLookupFailure('denied');
      },
    )));
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-once')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-consent')));
    await tester.pumpAndSettle();
    expect(reads,1);
    expect(find.textContaining('Move the map manually'),findsOneWidget);
    final action=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(action);
    expect(tester.widget<OutlinedButton>(action).onPressed,isNotNull);
    expect(tester.takeException(),isNull);
  });

  testWidgets('late one-time GPS callback cannot update a backgrounded map', (tester)async{
    final pending=Completer<ForegroundFix>();
    var reads=0;
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(
      points:const [],tileProvider:FakeMapTiles(),allowViewportSearch:true,
      locationReader:(){
        reads++;
        return pending.future;
      },
    )));
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-once')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-consent')));
    await tester.pump();
    expect(reads,1);
    expect(find.byKey(const Key('unified-native-gps-loading')),findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    expect(find.byType(FlutterMap),findsNothing,
      reason:'True backgrounding must release consented tile UI');
    expect(find.byKey(const Key('unified-native-gps-once')),findsNothing);
    pending.complete(const ForegroundFix(25.2,55.3,10));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('unified-native-gps-loading')),findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap),findsNothing,
      reason:'Foreground resume cannot silently restore consented GPS map tiles');
    expect(find.byKey(const Key('partner-map-enable')),findsOneWidget);
    expect(find.byKey(const Key('unified-native-gps-error')),findsNothing);
    expect(tester.takeException(),isNull);
  });

  testWidgets('temporary inactive OS permission sheet does not discard consented foreground GPS fix',(tester)async{
    final pending=Completer<ForegroundFix>();
    final selected=<Map<String,String>>[];
    await tester.pumpWidget(MaterialApp(home:Builder(builder:(context)=>Scaffold(
      body:FilledButton(key:const Key('open-inactive-map'),onPressed:()async{
        final result=await Navigator.of(context).push<Map<String,String>>(
          MaterialPageRoute(builder:(_)=>PartnerReportMap(
            points:const [],tileProvider:FakeMapTiles(),allowViewportSearch:true,
            locationReader:()=>pending.future,
          )),
        );
        if(result!=null)selected.add(result);
      },child:const Text('Open explicit map')),
    ))));
    await tester.tap(find.byKey(const Key('open-inactive-map')));await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('partner-map-enable')));await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-once')));await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('unified-native-gps-consent')));await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    pending.complete(const ForegroundFix(25.2,55.3,20));
    // Inactive permission sheets are not animation-idle; a map tile ticker
    // can keep scheduling frames. Drive a finite layout frame instead of
    // waiting for app-wide settle while the OS sheet is still foreground.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 32));
    expect(tester.takeException(),isNull,
      reason:'Consciously consented GPS must not mutate a building map while inactive');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(tester.takeException(),isNull,
      reason:'An OS permission sheet must not leave a stale map frame on resumed');
    expect(find.byKey(const Key('unified-native-gps-error')),findsNothing);
    expect(selected,isEmpty,reason:'Permission result may pan map, never search');
    final search=find.byKey(const Key('unified-native-viewport-search'));
    await tester.ensureVisible(search);await tester.pumpAndSettle();
    expect(tester.takeException(),isNull,
      reason:'Map scroll and layout must remain safe before explicit viewport submission');
    await tester.tap(search);await tester.pumpAndSettle();
    expect(selected.length,1);
    expect(double.parse(selected.single['south']!),lessThan(25.2));
    expect(double.parse(selected.single['north']!),greaterThan(25.2));
    expect(double.parse(selected.single['west']!),lessThan(55.3));
    expect(double.parse(selected.single['east']!),greaterThan(55.3));
    expect(tester.takeException(),isNull);
  });

  test('manual map coordinates normalize Arabic digits but never fabricate invalid positions',() {
    final arabic=manualDiscoveryMapCenter('٢٥٫٢','٥٥٫٣');
    expect(arabic,isNotNull);
    expect(arabic!.latitude,25.2);
    expect(arabic.longitude,55.3);
    final negative=manualDiscoveryMapCenter('−٢٥٫٢','−٥٥٫٣');
    expect(negative?.latitude,-25.2);
    expect(negative?.longitude,-55.3);
    final zero=manualDiscoveryMapCenter('٠','٠');
    expect(zero?.latitude,0);
    expect(zero?.longitude,0,
      reason:'A valid neutral coordinate cannot become a fabricated station marker');
    for(final pair in <List<String>>[
      ['','50'],['25',''],['89','55'],['25','181'],
      ['NaN','55'],['Infinity','55'],['1e999','55'],
      ['25','100000000'],['25.2<script>','55'],
    ]) {
      expect(manualDiscoveryMapCenter(pair[0],pair[1]),isNull);
    }
  });
}
