import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/precharge_pricing.dart';
import 'test_support.dart';

String quoteExpiry(int secondsFromNow) =>
  DateTime.now().toUtc().add(Duration(seconds:secondsFromNow))
    .toIso8601String().replaceFirst(RegExp(r'\.\d+Z$'),'.000000Z');

final price = <String,dynamic>{'version':1,'evidence':'SIMULATED','tariff_version_id':17,'currency':'KWD','minor_units':3,'energy_wh':1500,'rate_minor_per_kwh':'251','tax_bps':500,'subtotal_minor':'377','tax_minor':'19','total_minor':'396','session_fee_minor':'0','idle_fee_minor':'0','external_fees_included':false,'automatic_stop':false,'final_total_depends_on_meter':true,'expires_at':quoteExpiry(300)};
class PricingApi extends FixtureApi {
  final writes = <Map<String,dynamic>>[];
  Completer<dynamic>? pending;
  Completer<dynamic>? pendingConsent;
  int epoch = 0;
  final sessionChanges = StreamController<int>.broadcast(sync:true);
  @override Stream<int> get sessionEvents => sessionChanges.stream;
  void rotate() { epoch++; sessionChanges.add(epoch); }
  bool expiredQuote = false, malformedReceipt = false, mismatchedReceipt = false, sqlReplay = false;
  @override
  bool Function() sessionFence() { final captured = epoch; return () => captured == epoch; }
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}}) async {
    calls.add(path);
    if (path.endsWith('price-estimate')) return pending == null
      ? {'estimate':{...price,if(expiredQuote)'expires_at':quoteExpiry(-60)},
         'quote_token':'synthetic-private-token'}
      : pending!.future;
    writes.add(body!);
    if (pendingConsent != null) return pendingConsent!.future;
    if (malformedReceipt) return {'id':0,'accepted_at':'invalid','valid_until':'not-a-date'};
    if (mismatchedReceipt) return {'id':99,'accepted_at':DateTime.now().toUtc().toIso8601String(), 'valid_until':quoteExpiry(600)};
    return {
      'id':7,
      'accepted_at':DateTime.now().toUtc().toIso8601String(),
      'valid_until':sqlReplay
        ? (price['expires_at'] as String).substring(0,19).replaceFirst('T',' ')
        : price['expires_at'],
    };
  }
}
Widget app(PricingApi api,{bool arabic=false}) => MaterialApp(
  locale:Locale(arabic?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],
  localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],
  home:Builder(builder:(context)=>Scaffold(body:TextButton(onPressed:()=>reviewPrechargePricing(context,api,7),child:const Text('Review')))),
);
void main() {
  for(final ar in [false,true]){
    testWidgets('consent received at quote expiry cannot authorize start ($ar)',(tester)async{
      final api=PricingApi()..pendingConsent=Completer<dynamic>();
      addTearDown(api.close);
      addTearDown(api.sessionChanges.close);
      final expiry=DateTime.parse(price['expires_at'] as String);
      var now=expiry.subtract(const Duration(seconds:30));
      bool? result;
      await tester.pumpWidget(MaterialApp(
        locale:Locale(ar?'ar':'en'),supportedLocales:const[Locale('en'),Locale('ar')],
        localizationsDelegates:const[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],
        home:Builder(builder:(context)=>Scaffold(body:TextButton(
          onPressed:()async{result=await showDialog<bool>(context:context,builder:(_)=>PrechargePricingDialog(api:api,reservation:7,clock:()=>now));},
          child:const Text('Review')))),
      ));
      await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),'1500');
      await tester.tap(find.text(ar?'عرض التقدير':'Get estimate'));await tester.pumpAndSettle();
      await tester.tap(find.text(ar?'قبول السعر':'Accept price'));await tester.pump();
      expect(api.writes,hasLength(1));
      now=expiry;
      api.pendingConsent!.complete({'id':7,'accepted_at':expiry.subtract(const Duration(seconds:29)).toIso8601String(),'valid_until':price['expires_at']});
      await tester.pumpAndSettle();
      expect(result,isNull);
      expect(find.byType(PrechargePricingDialog),findsOneWidget);
      expect(find.textContaining('KWD 0.396'),findsNothing);
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton,ar?'قبول السعر':'Accept price')).onPressed,isNull);
      expect(api.writes,hasLength(1));
      expect(tester.takeException(),isNull);
    });
  }
  for (final ar in [false,true]) {
    testWidgets('rendered quote redacts immediately on same-client account revocation ($ar)', (tester) async {
      final api=PricingApi();
      addTearDown(api.close);
      addTearDown(api.sessionChanges.close);
      await tester.pumpWidget(app(api,arabic:ar));
      await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),'1500');
      await tester.tap(find.text(ar?'عرض التقدير':'Get estimate'));await tester.pumpAndSettle();
      expect(find.textContaining('KWD 0.396'),findsOneWidget);
      final old = tester.widget<FilledButton>(find.widgetWithText(FilledButton,ar?'قبول السعر':'Accept price')).onPressed!;
      api.rotate();await tester.pumpAndSettle();
      expect(find.textContaining('KWD 0.396'),findsNothing);
      expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,'10000');
      old();await tester.pumpAndSettle();
      expect(api.writes,isEmpty);
      expect(tester.widget<TextButton>(find.widgetWithText(TextButton,ar?'عرض التقدير':'Get estimate')).onPressed,isNull);
    });
  }
  testWidgets('a retained consent callback cannot approve a newly reviewed quote', (tester) async {
    final api=PricingApi();
    addTearDown(api.close);
    addTearDown(api.sessionChanges.close);
    await tester.pumpWidget(app(api));await tester.tap(find.text('Review'));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'1500');
    await tester.tap(find.text('Get estimate'));await tester.pumpAndSettle();
    final old=tester.widget<FilledButton>(find.widgetWithText(FilledButton,'Accept price')).onPressed!;
    await tester.tap(find.text('Get estimate'));await tester.pumpAndSettle();
    expect(find.textContaining('KWD 0.396'),findsOneWidget);
    old();await tester.pumpAndSettle();
    expect(api.writes,isEmpty);
    await tester.tap(find.text('Accept price'));await tester.pumpAndSettle();
    expect(api.writes,hasLength(1));
    expect(find.byType(PrechargePricingDialog),findsNothing);
  });


  for(final ar in [false,true]){
    testWidgets('expired charging price is never displayed or accepted ($ar)',(tester) async {
      final api=PricingApi()..expiredQuote=true;
      addTearDown(api.close);
      await tester.pumpWidget(app(api,arabic:ar));
      await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),'1500');
      await tester.tap(find.text(ar?'عرض التقدير':'Get estimate'));
      await tester.pumpAndSettle();
      expect(api.calls.where((c)=>c.contains('price-estimate')).length,1);
      expect(api.writes,isEmpty);
      expect(find.textContaining('KWD 0.396'),findsNothing);
      expect(tester.widget<FilledButton>(
          find.widgetWithText(FilledButton,ar?'قبول السعر':'Accept price'))
        .onPressed,isNull);
      expect(tester.takeException(),isNull);
    });
    testWidgets('malformed consent receipt never closes dialog as success ($ar)',(tester) async {
      final api=PricingApi()..malformedReceipt=true;
      addTearDown(api.close);
      await tester.pumpWidget(app(api,arabic:ar));
      await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),'1500');
      await tester.tap(find.text(ar?'عرض التقدير':'Get estimate'));await tester.pumpAndSettle();
      await tester.tap(find.text(ar?'قبول السعر':'Accept price'));await tester.pumpAndSettle();
      expect(api.writes,hasLength(1));
      expect(find.byType(PrechargePricingDialog),findsOneWidget);
      expect(tester.widget<FilledButton>(
          find.widgetWithText(FilledButton,ar?'قبول السعر':'Accept price'))
        .onPressed,isNull);
      expect(api.calls.where((c)=>c.contains('price-consent')).length,1,
        reason:'An uncertain consent must never be automatically replayed');
      expect(tester.takeException(),isNull);
    });
    testWidgets('initially inactive price dialog does not submit a private request ($ar)',
        (tester) async {
      final api=PricingApi();
      addTearDown(api.close);
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpWidget(app(api,arabic:ar));
      await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      expect(tester.widget<TextButton>(
          find.widgetWithText(TextButton,ar?'عرض التقدير':'Get estimate'))
        .onPressed,isNull);
      expect(api.calls,isEmpty);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.calls,isEmpty,reason:'Resume must not automatically quote or approve');
      expect(tester.widget<TextButton>(
          find.widgetWithText(TextButton,ar?'عرض التقدير':'Get estimate'))
        .onPressed,isNotNull);
      expect(tester.takeException(),isNull);
    });
  }

  test('verified receipt accepts exact PostgreSQL fractional UTC without rollover',() {
    final exp=price['expires_at'] as String;
    final receipt=<String,dynamic>{
      'id':77,
      'accepted_at':DateTime.now().toUtc().toIso8601String(),
      'valid_until':exp.substring(0,19).replaceFirst('T',' ')+'.000000',
    };
    expect(confirmedPrechargeReceipt(receipt,price),true);
    expect(confirmedPrechargeReceipt({...receipt,'id':0},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'valid_until':'2026-02-30 10:00:00'},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'valid_until':'not-a-timestamp'},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'accepted_at':'invalid-but-long-enough-to-pass'},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'accepted_at':'2026-02-30T04:30:00.000000Z'},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'accepted_at':exp},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'valid_until':exp.substring(0,exp.length-2)+'1Z'},price),false);
    expect(confirmedPrechargeReceipt({...receipt,'accepted_at':DateTime.now().toUtc().toIso8601String().substring(0,19).replaceFirst('T',' ')},price),true);
  });


  for(final ar in [false,true]){
    testWidgets('well-formed but wrong-price consent receipt cannot report success ($ar)',(tester)async{
      final api=PricingApi()..mismatchedReceipt=true;
      addTearDown(api.close);
      await tester.pumpWidget(app(api,arabic:ar));
      await tester.tap(find.text('Review'));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),'1500');
      await tester.tap(find.text(ar?'عرض التقدير':'Get estimate'));await tester.pumpAndSettle();
      await tester.tap(find.text(ar?'قبول السعر':'Accept price'));await tester.pumpAndSettle();
      expect(api.writes,hasLength(1));
      expect(find.byType(PrechargePricingDialog),findsOneWidget);
      expect(find.textContaining('KWD 0.396'),findsNothing);
      expect(api.calls.where((c)=>c.contains('price-consent')).length,1);
      expect(tester.takeException(),isNull);
    });
    testWidgets('same retained native dialog switches reservation without inheriting quote ($ar)',(tester)async{
      final api=PricingApi();
      addTearDown(api.close);
      Widget form(int reservation) => MaterialApp(
        locale:Locale(ar?'ar':'en'),
        supportedLocales:const[Locale('en'),Locale('ar')],
        localizationsDelegates:const[
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home:PrechargePricingDialog(
          key:const ValueKey('retained-price-dialog'),
          api:api,reservation:reservation),
      );
      await tester.pumpWidget(form(7));await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField),'1500');
      await tester.tap(find.text(ar?'عرض التقدير':'Get estimate'));await tester.pumpAndSettle();
      expect(find.textContaining('KWD 0.396'),findsOneWidget);
      final reads=api.calls.length;
      api.epoch++;
      await tester.pumpWidget(form(8));await tester.pumpAndSettle();
      expect(api.calls.length,reads,reason:'A changed account must explicitly request new quote');
      expect(find.textContaining('KWD 0.396'),findsNothing);
      expect(tester.widget<TextField>(find.byType(TextField)).controller!.text,'10000');
      expect(tester.widget<FilledButton>(
          find.widgetWithText(FilledButton,ar?'قبول السعر':'Accept price'))
        .onPressed,isNull);
      expect(api.writes,isEmpty);
      expect(tester.takeException(),isNull);
    });
  }

  testWidgets('SQL UTC replay receipt preserves verified price acceptance', (tester) async {
    final api=PricingApi()..sqlReplay=true;
    addTearDown(api.close);
    await tester.pumpWidget(app(api));
    await tester.tap(find.text('Review'));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'1500');
    await tester.tap(find.text('Get estimate'));await tester.pumpAndSettle();
    await tester.tap(find.text('Accept price'));await tester.pumpAndSettle();
    expect(api.writes,hasLength(1));
    expect(find.byType(PrechargePricingDialog),findsNothing);
    expect(tester.takeException(),isNull);
  });

  test('exact estimate rounding and supported price/fee scope agree with Laravel', () {
    expect(validPrechargeEstimate(price),true);
    expect(prechargeAmount('396',3),'0.396');expect(prechargeAmount('396',0),'396');
    for(final update in <Map<String,dynamic>>[
      {'total_minor':'395'},{'tax_minor':'20'},
      {'external_fees_included':true},{'automatic_stop':true},
      {'minor_units':4},{'energy_wh':0},{'rate_minor_per_kwh':'1000001'},
      {'expires_at':'2026-02-30T10:35:00.000000Z'},
      {'expires_at':'2026-04-31T10:35:00.000000Z'},
      {'expires_at':'2026-11-18T24:00:00.000000Z'},
      {'expires_at':'2026-11-18T10:61:00.000000Z'},
      {'expires_at':'2026-11-18T10:35:60.000000Z'},
    ]) expect(validPrechargeEstimate({...price,...update}),false);
    expect(prechargeText(price,true),contains('KWD 0.396'));
    expect(prechargeText(price,true),contains('إصدار التعرفة #17'));
    expect(prechargeText(price,false),contains('Tariff version #17'));
    final fallback={...price,'tariff_version_id':null};
    expect(validPrechargeEstimate(fallback),true);
    expect(prechargeText(fallback,false),contains('Default simulator tariff'));
    expect(prechargeText(fallback,true),contains('تعرفة المحاكاة الافتراضية'));
    final missing={...price}..remove('tariff_version_id');
    expect(validPrechargeEstimate(missing),false);
    for(final id in [0,-1,17.0,'17',true,9007199254740992]){
      expect(validPrechargeEstimate({...price,'tariff_version_id':id}),false);
    }
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
    for (final state in [AppLifecycleState.inactive,AppLifecycleState.hidden,AppLifecycleState.paused]) { tester.binding.handleAppLifecycleStateChanged(state); }await tester.pump();
    api.pending!.complete({'estimate':price,'quote_token':'synthetic-private-token'});await tester.pumpAndSettle();
    expect(find.textContaining('KWD 0.396'),findsNothing);expect(api.writes,isEmpty);
    for (final state in [AppLifecycleState.hidden,AppLifecycleState.inactive,AppLifecycleState.resumed]) { tester.binding.handleAppLifecycleStateChanged(state); }await tester.pump();
  });
  testWidgets('retained dialog cannot approve a prior account price', (tester) async {
    final api=PricingApi();addTearDown(api.close);await tester.pumpWidget(app(api));await tester.tap(find.text('Review'));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'1500');
    await tester.tap(find.text('Get estimate'));await tester.pumpAndSettle();api.epoch++;
    await tester.tap(find.text('Accept price'));await tester.pumpAndSettle();expect(api.writes,isEmpty);expect(find.textContaining('KWD 0.396'),findsNothing);
  });
}
