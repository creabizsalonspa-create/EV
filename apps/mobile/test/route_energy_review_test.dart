import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/discovery_client.dart';
import 'package:creabiz_ev/route_planner.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

const raw = {'capacity':'60','soc':'80','reserve':'20','consumption':'20'};
void main() {
  test('provider route contract bounds geometry before accepting a route', () {
    final point = {'latitude':0,'longitude':0};
    final valid = <String,dynamic>{
      'provider':'geoapify','evidence':'PROVIDER_ROUTE_ESTIMATE',
      'traffic_verified':false,'charging_time_included':false,
      'distance_meters':1250,'duration_seconds':220,
      'segments':[[point,point]],
    };
    final route=RouteEstimate.fromJson(valid);
    expect(route.meters,1250);
    expect(route.segments.single.length,2);
    expect(()=>route.segments.clear(),throwsUnsupportedError);
    expect(()=>route.segments.single.clear(),throwsUnsupportedError);
    expect(RouteEstimate.fromJson({...valid,'segments':[List.filled(10000,point)]})
      .segments.single.length,10000);
    for(final bad in <Map<String,dynamic>>[
      {'distance_meters':'1250'},{'duration_seconds':double.infinity},
      {'traffic_verified':true},{'charging_time_included':true},
      {'segments':[]},{'segments':[[point]]},
      {'segments':List.filled(101,[point,point])},
      {'segments':[List.filled(10001,point)]},
      {'segments':[[point,{'latitude':'1','longitude':1}]]},
      {'segments':[[point,{'latitude':true,'longitude':0}]]},
      {'segments':[[point,{'latitude':91,'longitude':0}]]},
      {'segments':[[point,null]]},
    ]) {
      expect(()=>RouteEstimate.fromJson({...valid,...bad}),throwsFormatException,
        reason:bad.keys.join(','));
    }
  });

  test('driver energy arithmetic preserves reserve and distinguishes lower bound from road range', () {
    final result = routeEnergyReview(raw,routeMeters:200000,
      origin:GeoPoint(0,0),firstStop:GeoPoint(0,2))!;
    expect(result.budget,36);
    expect(result.km,180);
    expect(result.needed,40);
    expect(result.totalExceeds,true);
    expect(result.firstExceeds,true);
    expect(routeEnergyReview(raw,routeMeters:180000)!.totalExceeds,false);
    expect(routeEnergyReview(raw)!.needed,isNull);
    expect(routeEnergyReview(raw,routeMeters:double.nan)!.needed,isNull);
    expect(routeEnergyReview(raw,routeMeters:40000001)!.needed,isNull);
    expect(routeEnergyReview(raw,origin:GeoPoint(0,179.9),firstStop:GeoPoint(0,-179.9))!.firstExceeds,false);
    expect(routeEnergyReview({'capacity':'٦٠','soc':'۸۰','reserve':'٢٠','consumption':'٢٠٫٠'})!.km,180);
    for(final change in [
      {'capacity':'0'},{'capacity':'251'},{'soc':'101'},{'reserve':'80'},
      {'consumption':'0'},{'consumption':'1e1'},{'consumption':'1.123'},
      {'capacity':'0x30'},{'soc':'NaN'},
    ]) {
      expect(routeEnergyReview({...raw,...change}),isNull,reason:'$change');
    }
  });

  test('energy candidates are bounded, source-qualified and ordered by straight-line distance', () {
    Station station(int id, double lng, {String connector='CCS2',
      String evidence='DISCOVERY_ONLY', int power=50}) => Station.fromJson({
      'id':id,'name':'Station $id','connector_type':connector,'power_kw':power,
      'evidence':evidence,'latitude':0,'longitude':lng,
    });
    final list = [
      station(4,1),station(2,0.5),station(1,0.5),
      station(5,2),station(6,0.1,connector:'Type2'),
      station(7,0.1,evidence:'UNKNOWN'),station(8,0.1,power:0),
      station(9,0.1),station(9,0.2),station(10,181),
    ];
    final candidates = routeEnergyCandidates(raw,GeoPoint(0,0),list,connector:'CCS2');
    expect(candidates.map((c)=>c.station.id),[1,2,4]);
    expect(candidates.first.km,closeTo(55.597,0.001));
    expect(routeEnergyCandidates(raw,GeoPoint(0,0),list,connector:'Type2')
      .map((c)=>c.station.id),[6]);
    expect(routeEnergyCandidates(raw,null,list),isEmpty);
    expect(routeEnergyCandidates({...raw,'reserve':'80'},GeoPoint(0,0),list),isEmpty);
    expect(routeEnergyCandidates(raw,GeoPoint(0,0),
      List.generate(26,(i)=>station(i+1,0.01))),isEmpty);
    expect(routeEnergyCandidates(raw,GeoPoint(0,0),
      List.generate(8,(i)=>station(i+1,0.01))).length,5);
  });

  for(final ar in [false,true]) {
    testWidgets('energy review is local, bilingual, and cleared on background ($ar)', (tester) async {
      var calls=0;
      final client=DiscoveryClient.withRequest((path,method,body) async {
        calls++;
        expect(path,'capabilities');
        expect(body,isNull);
        return {'provider':'geoapify','max_charging_stops':3,'geocoding':false,'routing':false};
      });
      final stop=Station.fromJson({
        'id':1,'name':'First stop','connector_type':'CCS2','power_kw':50,
        'evidence':'DISCOVERY_ONLY','latitude':0,'longitude':2,
      });
      tester.view.physicalSize=const Size(390,1000);
      tester.view.devicePixelRatio=1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(MaterialApp(
        locale:Locale(ar?'ar':'en'),
        supportedLocales:const [Locale('en'),Locale('ar')],
        localizationsDelegates:GlobalMaterialLocalizations.delegates,
        home:RoutePlannerView(client:client,stations:[stop],origin:GeoPoint(0,0),initialStop:stop),
      ));
      await tester.pumpAndSettle();
      final panel=find.byKey(const Key('journey-energy-review'));
      // EditableText inputs also create Scrollables; target the outer
      // itinerary ListView rather than selecting an ambiguous generic type.
      final itinerary = find.descendant(
        of: find.byType(ListView),
        matching: find.byType(Scrollable),
      ).first;
      await tester.scrollUntilVisible(panel,300,scrollable:itinerary);
      await tester.tap(panel);
      await tester.pumpAndSettle();
      for(final e in raw.entries) {
        final field=find.byKey(Key('energy-${e.key}'));
        await tester.scrollUntilVisible(field,150,scrollable:itinerary);
        await tester.enterText(field,e.value);
        await tester.pumpAndSettle();
      }
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      final warning=find.text(ar?'أول توقف يتجاوز المدى المفترض حتى بخط مستقيم.':
        'First stop exceeds the assumed range even in a straight line.');
      await tester.scrollUntilVisible(warning,200,scrollable:itinerary);
      expect(warning,findsOneWidget);
      expect(calls,1);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(warning,findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(warning,findsNothing);
      expect(calls,1);
      expect(tester.takeException(),isNull);
    });
  }
  testWidgets('revoked provider consent rejects delayed route and clears a displayed estimate',(tester) async {
    final replies=<Completer<dynamic>>[];
    final requests=<Map<String,dynamic>>[];
    final client=DiscoveryClient.withRequest((path,method,body) async {
      if(path=='capabilities') return {
        'provider':'geoapify','max_charging_stops':3,'geocoding':false,'routing':true,
      };
      expect(path,'route');
      requests.add(body!);
      final reply=Completer<dynamic>();
      replies.add(reply);
      return reply.future;
    });
    final result={
      'provider':'geoapify','evidence':'PROVIDER_ROUTE_ESTIMATE',
      'traffic_verified':false,'charging_time_included':false,
      'distance_meters':1250,'duration_seconds':220,
      'segments':[[{'latitude':25.2,'longitude':55.3},{'latitude':25.3,'longitude':55.4}]],
    };
    await tester.binding.setSurfaceSize(const Size(800,1600));
    addTearDown(()=>tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(home:RoutePlannerView(
      client:client,stations:const [],origin:GeoPoint(25.2,55.3),
    )));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('destination-lat')),'25.3');
    await tester.enterText(find.byKey(const Key('destination-lng')),'55.4');
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    // Render the newly edited itinerary before re-authorizing its consent.
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    final consent=find.byKey(const Key('route-provider-consent'));
    final preview=find.byKey(const Key('route-preview'));
    final itinerary=find.descendant(of:find.byType(ListView),matching:find.byType(Scrollable)).first;
    await tester.scrollUntilVisible(consent,200,scrollable:itinerary);
    await tester.tap(consent);
    await tester.pumpAndSettle();
    await tester.ensureVisible(preview);
    await tester.tap(preview);
    await tester.pump();
    expect(requests.length,1);
    await tester.ensureVisible(consent);
    await tester.tap(consent);
    await tester.pumpAndSettle();
    expect(find.byType(LinearProgressIndicator),findsNothing);
    replies[0].complete(result);
    await tester.pumpAndSettle();
    expect(find.textContaining('1.3 km'),findsNothing);
    expect(tester.widget<CheckboxListTile>(consent).value,false);
    await tester.tap(consent);
    await tester.pumpAndSettle();
    await tester.ensureVisible(preview);
    await tester.tap(preview);
    await tester.pump();
    expect(requests.length,2);
    replies[1].complete(result);
    await tester.pumpAndSettle();
    expect(find.textContaining('1.3 km'),findsOneWidget);
    await tester.ensureVisible(consent);
    await tester.tap(consent);
    await tester.pumpAndSettle();
    expect(find.textContaining('1.3 km'),findsNothing);
    expect(requests.length,2);
    expect(tester.takeException(),isNull);
  });

  testWidgets('discovery epoch event immediately hides route and refuses stale preview',(tester) async {
    bool current=true;
    final events=StreamController<int>.broadcast(sync:true);
    addTearDown(events.close);
    var routeCalls=0;
    final client=DiscoveryClient.withRequest((path,method,body) async {
      if(path=='capabilities') return {
        'provider':'geoapify','max_charging_stops':3,'geocoding':false,'routing':true,
      };
      routeCalls++;
      throw StateError('Old account must not dispatch');
    },currentSession:()=>current,sessionEvents:events.stream);
    await tester.binding.setSurfaceSize(const Size(800,1600));
    addTearDown(()=>tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(home:RoutePlannerView(
      client:client,stations:const [],origin:GeoPoint(25.2,55.3),
    )));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('destination-lat')),'25.3');
    await tester.enterText(find.byKey(const Key('destination-lng')),'55.4');
    // Render the newly edited itinerary before re-authorizing its consent.
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    final consent=find.byKey(const Key('route-provider-consent'));
    final preview=find.byKey(const Key('route-preview'));
    final itinerary=find.descendant(of:find.byType(ListView),matching:find.byType(Scrollable)).first;
    await tester.scrollUntilVisible(consent,200,scrollable:itinerary);
    await tester.tap(consent);
    await tester.pumpAndSettle();
    final stale=tester.widget<FilledButton>(preview).onPressed!;
    current=false;
    events.add(1);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('origin-lat')),findsNothing);
    expect(find.byKey(const Key('journey-energy-review')),findsNothing);
    stale();
    await tester.pumpAndSettle();
    expect(routeCalls,0);
    expect(tester.takeException(),isNull);
  });
}
