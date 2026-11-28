import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/connector_choices.dart';

Station station(int id)=>Station.fromJson({
  'id':id,'name':'Private station $id','connector_type':'CCS2',
  'power_kw':50,'evidence':'SIMULATED','reservable':true,
});
Map<String,dynamic> option(int id,int stationId)=> {
  'id':id,'station_id':stationId,'evse_number':1,'connector_number':1,
  'connector_type':'CCS2','max_power_kw':50,'evidence':'SIMULATED',
  'availability':'unknown','reservable':true,'csms_bound':false,
};
ApiPage<Map<String,dynamic>> result(List<Map<String,dynamic>> rows)=>
  ApiPage(items:rows,current:1,last:1,total:rows.length);

void main(){
  testWidgets('connector evidence rejects foreign or duplicate mapping before any reservation',(tester) async {
    var calls=0,writes=0;
    var rows=[option(11,7),option(12,8)];
    await tester.pumpWidget(MaterialApp(home:ConnectorChoicesView(
      station:station(7),loader:(_)async{calls++;return result(rows);},
      onReserve:(_)async{writes++;},
    )));
    await tester.pumpAndSettle();
    expect(find.byType(FilledButton),findsNothing);
    rows=[option(11,7),option(11,7)];
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.byType(FilledButton),findsNothing);
    rows=[option(11,7)];
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.byType(FilledButton),findsOneWidget);
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();
    expect(calls,3);
    expect(writes,1);
  });
  for(final arabic in [false,true]){
    testWidgets('connector status announces the localized UTC observation ($arabic)', (tester) async {
      final semantics = tester.ensureSemantics();
      addTearDown(semantics.dispose);
      final rows = [
        {
          ...option(11, 7),
          'status_freshness': {
            'state': 'recent', 'reported_status': 'Available',
            'evidence': 'SIMULATED_SOURCE', 'physical_availability_verified': false,
            'max_age_seconds': 300,
            'observed_at': '2026-11-14T05:00:00Z',
            'checked_at': '2026-11-14T05:04:00Z',
            'valid_until': '2026-11-14T05:05:00Z',
          },
        },
      ];
      await tester.pumpWidget(MaterialApp(
        locale: Locale(arabic ? 'ar' : 'en'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: ConnectorChoicesView(
          station: station(7), loader: (_) async => result(rows),
          onReserve: (_) async {},
        ),
      ));
      await tester.pumpAndSettle();
      final status = find.textContaining(arabic ? 'متاح بحسب المصدر' : 'Available');
      expect(status, findsOneWidget);
      expect(tester.widget<Text>(status).data, contains('UTC'));
      expect(tester.widget<Text>(status).data, isNot(contains('2026-11-14T05:00:00Z')));
      expect(find.ancestor(
        of: status,
        matching: find.byWidgetPredicate(
          (widget) => widget is Semantics && widget.properties.liveRegion == true,
        ),
      ), findsOneWidget);
    });
    testWidgets('foreground connector choice rejects old account and delayed page ($arabic)',(tester) async {
      final events=StreamController<int>.broadcast(sync:true);
      addTearDown(events.close);
      var current=true,reads=0,writes=0;
      final pending=Completer<ApiPage<Map<String,dynamic>>>();
      final fence=()=>current;
      await tester.binding.setSurfaceSize(const Size(390,844));
      addTearDown(()=>tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(MaterialApp(
        locale:Locale(arabic?'ar':'en'),
        supportedLocales:const [Locale('en'),Locale('ar')],
        localizationsDelegates:GlobalMaterialLocalizations.delegates,
        home:ConnectorChoicesView(station:station(7),currentSession:fence,sessionEvents:events.stream,
          loader:(_)async{reads++;if(reads==1)return result([option(11,7)]);return await pending.future;},
          onReserve:(_)async{writes++;},
        ),
      ));
      await tester.pumpAndSettle();
      final stale=tester.widget<FilledButton>(find.byType(FilledButton)).onPressed!;
      await tester.tap(find.text(arabic?'تحديث':'Refresh'));
      await tester.pump();
      expect(reads,2);
      current=false;
      events.add(1);
      pending.complete(result([option(12,7)]));
      await tester.pumpAndSettle();
      stale();
      await tester.pumpAndSettle();
      expect(writes,0);
      expect(find.text('Private station 7'),findsNothing);
      expect(find.byType(FilledButton),findsNothing);
      expect(tester.takeException(),isNull);
    });
  }
  testWidgets('inactive mount does not fetch and resume requires an explicit refresh',(tester) async {
    var reads=0;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    addTearDown(()=>tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(MaterialApp(home:ConnectorChoicesView(
      station:station(7),loader:(_)async{reads++;return result([option(11,7)]);},
      onReserve:(_)async{},
    )));
    await tester.pumpAndSettle();
    expect(reads,0);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(reads,0);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(reads,1);
    expect(find.byType(FilledButton),findsOneWidget);
  });
}
