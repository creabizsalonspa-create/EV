import 'package:creabiz_ev/support.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

void main(){
  for(final ar in [false,true]){
    testWidgets('support timestamp uses explicit UTC and rejects invalid source ($ar)',(tester)async{
      late BuildContext context;
      await tester.pumpWidget(MaterialApp(
        locale:Locale(ar?'ar':'en'),
        supportedLocales:const [Locale('en'),Locale('ar')],
        localizationsDelegates:GlobalMaterialLocalizations.delegates,
        home:Builder(builder:(c){context=c;return const Scaffold();}),
      ));
      await tester.pumpAndSettle();
      final valid=supportMessageTime(context,'2026-11-25T15:32:00.000000Z');
      expect(valid,contains('UTC'));
      expect(valid,supportMessageTime(context,'2026-11-25 15:32:00'));
      final unavailable=ar?'وقت الرسالة غير متاح':'Message time unavailable';
      for(final raw in [null,'2026-02-30T15:32:00Z','2026-11-25T25:32:00Z','unknown']){
        expect(supportMessageTime(context,raw),unavailable);
      }
    });
  }
}
