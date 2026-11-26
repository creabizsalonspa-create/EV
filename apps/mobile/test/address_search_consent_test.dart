import 'dart:async';
import 'package:creabiz_ev/address_search.dart';
import 'package:creabiz_ev/discovery_client.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String,dynamic> address(String label,{String id='geoapify:1'}) => {
  'id':id,'label':label,'latitude':25.2,'longitude':55.3,
};
Map<String,dynamic> reply(List<Map<String,dynamic>> places) => {
  'provider':'geoapify','evidence':'GEOCODING_RESULT','places':places,
};

void main() {
  for (final ar in [false,true]) {
    testWidgets('address progress has a localized accessible label at large text size ($ar)', (tester) async {
      await tester.binding.setSurfaceSize(const Size(390,844));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final semantics = tester.ensureSemantics();
      try {
        final pending = Completer<dynamic>();
        final client = DiscoveryClient.withRequest((_,__,___) => pending.future);
        await tester.pumpWidget(MaterialApp(
          locale:Locale(ar?'ar':'en'),
          supportedLocales:const [Locale('en'),Locale('ar')],
          localizationsDelegates:GlobalMaterialLocalizations.delegates,
          builder:(context,child)=>MediaQuery(
            data:MediaQuery.of(context).copyWith(textScaler:TextScaler.linear(2)),
            child:child!),
          home:Scaffold(body:SingleChildScrollView(child:AddressSearch(
            client:client,onSelected:(_){},
          ))),
        ));
        await tester.tap(find.byKey(const Key('address-consent')));
        await tester.pump();
        final input=find.byKey(const Key('address-query'));
        await tester.ensureVisible(input);
        await tester.enterText(input,'Dubai');
        await tester.pump(const Duration(milliseconds:650));
        final progress = find.byType(LinearProgressIndicator);
        expect(tester.widget<LinearProgressIndicator>(progress).semanticsLabel,
          ar?'جارٍ البحث عن العناوين':'Searching addresses');
        expect(find.ancestor(of:progress,matching:find.byWidgetPredicate(
          (w)=>w is Semantics && w.properties.liveRegion == true)),findsWidgets);
        pending.complete(reply([]));
        await tester.pumpAndSettle();
        expect(progress,findsNothing);
        expect(find.text(ar?'لا توجد عناوين مطابقة. جرب الإحداثيات اليدوية.':
          'No address matches. Try manual coordinates.'),findsOneWidget);
        expect(tester.takeException(),isNull);
      } finally {
        semantics.dispose();
      }
    });
  }

  test('malformed or duplicate provider identities cannot supply a selection',() async {
    for(final places in [
      [address('First'),address('Second')],
      [address('First',id:'geoapify:')],
      [address('   ')],
    ]) {
      final client=DiscoveryClient.withRequest((_,__,___) async=>reply(places));
      await expectLater(client.addresses('Dubai','en'),throwsFormatException);
    }
  });
  for(final ar in [false,true]) {
    testWidgets('address consent is erased on background and old responses cannot select ($ar)',(tester) async {
      final calls=<Map<String,dynamic>>[];
      final responses=<Completer<dynamic>>[];
      final selected=<GeoPlace>[];
      final client=DiscoveryClient.withRequest((path,method,body) {
        expect(path,'addresses');
        expect(method,'POST');
        calls.add(body!);
        final response=Completer<dynamic>();
        responses.add(response);
        return response.future;
      });
      await tester.pumpWidget(MaterialApp(
        locale:Locale(ar?'ar':'en'),
        supportedLocales:const [Locale('en'),Locale('ar')],
        localizationsDelegates:GlobalMaterialLocalizations.delegates,
        home:Scaffold(body:SingleChildScrollView(child:AddressSearch(client:client,onSelected:selected.add))),
      ));
      final consent=find.byKey(const Key('address-consent'));
      final field=find.byKey(const Key('address-query'));
      await tester.tap(consent);
      await tester.pump();
      await tester.enterText(field,'Private address');
      await tester.pump(const Duration(milliseconds:650));
      expect(calls.length,1);
      expect(calls.single['language'],ar?'ar':'en');
      expect(calls.single['consent'],true);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      addTearDown(()=>tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));
      await tester.pump();
      responses[0].complete(reply([address('Old private address')]));
      await tester.pumpAndSettle();
      expect(find.text('Old private address'),findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(tester.widget<CheckboxListTile>(consent).value,false);
      expect(tester.widget<TextField>(field).controller!.text,isEmpty);
      expect(calls.length,1);
      await tester.tap(consent);
      await tester.pump();
      await tester.enterText(field,'New address');
      await tester.pump(const Duration(milliseconds:650));
      expect(calls.length,2);
      responses[1].complete(reply([address('New result')]));
      await tester.pumpAndSettle();
      final row=find.widgetWithText(ListTile,'New result');
      final stale=tester.widget<ListTile>(row).onTap!;
      await tester.tap(consent);
      await tester.pumpAndSettle();
      stale();
      expect(selected,isEmpty);
      expect(tester.widget<TextField>(field).controller!.text,isEmpty);
      await tester.tap(consent);
      await tester.pump(const Duration(seconds:1));
      expect(calls.length,2);
      expect(tester.takeException(),isNull);
    });
  }
  testWidgets('same client epoch invalidation immediately removes private address controls',(tester) async {
    bool current=true;
    final events=StreamController<int>.broadcast(sync:true);
    addTearDown(events.close);
    var selected=0;
    final client=DiscoveryClient.withRequest((_,__,___) async=>reply([address('Private result')]),
      currentSession:()=>current,sessionEvents:events.stream);
    await tester.pumpWidget(MaterialApp(home:Scaffold(body:SingleChildScrollView(child:
      AddressSearch(client:client,onSelected:(_)=>selected++)))));
    await tester.tap(find.byKey(const Key('address-consent')));
    await tester.pump();
    await tester.enterText(find.byKey(const Key('address-query')),'Dubai');
    await tester.pump(const Duration(milliseconds:650));
    await tester.pumpAndSettle();
    final stale=tester.widget<ListTile>(find.widgetWithText(ListTile,'Private result')).onTap!;
    current=false;
    events.add(1);
    await tester.pumpAndSettle();
    expect(find.text('Private result'),findsNothing);
    expect(find.byKey(const Key('address-query')),findsNothing);
    stale();
    expect(selected,0);
    expect(tester.takeException(),isNull);
  });
}
