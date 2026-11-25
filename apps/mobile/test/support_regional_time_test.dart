import 'package:creabiz_ev/support.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

void main(){
  test('ticket creation receipt matches owner, exact subject and valid state', () {
    final valid=<String,dynamic>{'id':42,'user_id':9,'subject':'Support','status':'open'};
    bool matches(dynamic row)=>supportTicketReceiptMatches(row,'Support',accountId:9);
    for(final status in ['open','in_progress','resolved','closed']){
      expect(matches({...valid,'status':status}),isTrue);
    }
    for(final row in [null,{'id':42},{...valid,'id':0},
      {...valid,'user_id':10},{...valid,'user_id':'9'},
      {...valid,'subject':'Other'},{...valid,'status':'unknown'}]){
      expect(matches(row),isFalse,reason:'Malformed receipt: $row');
    }
  });
  test('reply receipt matches exact ticket, content, public visibility and actor', () {
    final valid=<String,dynamic>{
      'id':42,'support_ticket_id':7,'author_id':9,
      'body':'Original reply','internal':false,
    };
    bool matches(dynamic row)=>supportReplyReceiptMatches(
      row,7,'Original reply',accountId:9);
    expect(matches(valid),isTrue);
    for(final visibility in [false,0,'0']){
      expect(matches({...valid,'internal':visibility}),isTrue);
    }
    for(final invalid in [
      null, {'id':42}, {...valid,'id':0}, {...valid,'id':'42'},
      {...valid,'support_ticket_id':8}, {...valid,'support_ticket_id':'7'},
      {...valid,'author_id':10}, {...valid,'author_id':null},
      {...valid,'body':'Edited reply'}, {...valid,'internal':true},
      {...valid,'internal':1}, {...valid,'internal':null},
      {...valid,'internal':'false'},
    ]){
      expect(matches(invalid),isFalse,reason:'Malformed receipt: $invalid');
    }
  });
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
