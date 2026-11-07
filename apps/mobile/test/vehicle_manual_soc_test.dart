import 'dart:convert';
import 'dart:io';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_manual_soc.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main(){
  testWidgets('customer explicitly records a battery estimate with source labels', (tester) async {
    final server=await HttpServer.bind(InternetAddress.loopbackIPv4,0);
    final sent=<Map<String,dynamic>>[];
    int revision=0;
    int? percent;
    final subscription=server.listen((request) async {
      request.response.headers.contentType=ContentType.json;
      if(request.uri.path=='/api/vehicles/17/manual-soc'&&request.method=='GET'){
        request.response.write(jsonEncode({
          'vehicle_id':17,'revision':revision,'battery_percent':percent,
          'source':'CUSTOMER_MANUAL_SELF_REPORT',
          'measured_by_phone':false,
          'measured_by_vehicle_api':false,
          'charging_capability_verified':false,
          'reported_at':percent==null?null:'2026-11-07 14:00:00',
          'replayed':false,
        }));
      }else if(request.uri.path=='/api/vehicles/17/manual-soc/history'){
        request.response.write(jsonEncode({
          'current_page':1,'last_page':1,'total':revision,
          'data':revision==0?[]:[{
            'vehicle_id':17,'battery_percent':percent,'revision':revision,
            'reported_at':'2026-11-07 14:00:00',
            'source':'CUSTOMER_MANUAL_SELF_REPORT',
          }],
        }));
      }else if(request.uri.path=='/api/vehicles/17/manual-soc'&&request.method=='POST'){
        final body=jsonDecode(await utf8.decoder.bind(request).join()) as Map<String,dynamic>;
        sent.add(body);
        revision++;
        percent=body['battery_percent'] as int;
        request.response.statusCode=201;
        request.response.write(jsonEncode({
          'revision':revision,'battery_percent':percent,
          'source':'CUSTOMER_MANUAL_SELF_REPORT',
          'measured_by_phone':false,'measured_by_vehicle_api':false,
        }));
      }else{
        request.response.statusCode=404;request.response.write('{}');
      }
      await request.response.close();
    });
    final api=EvApi(Uri.parse('http://127.0.0.1:'+server.port.toString()));
    try{
      await tester.pumpWidget(MaterialApp(home:VehicleManualSocView(
        api:api,vehicleId:17,onExpired:(){},
      )));
      await tester.pumpAndSettle();
      expect(find.textContaining('phone does not measure vehicle SOC'),findsOneWidget);
      expect(find.text('No report yet.'),findsOneWidget);
      await tester.enterText(find.byKey(const Key('manual-soc-percent')),'61');
      await tester.ensureVisible(find.byKey(const Key('manual-soc-save')));
      await tester.tap(find.byKey(const Key('manual-soc-save')));
      await tester.pumpAndSettle();
      expect(sent,hasLength(1));
      expect(sent.single['battery_percent'],61);
      expect(sent.single['expected_revision'],0);
      expect((sent.single['operation_key'] as String).length,36);
      expect(find.text('Manual battery report recorded.'),findsOneWidget);
      expect(find.textContaining('Latest estimate: 61%'),findsOneWidget);
    }finally{
      api.close();await subscription.cancel();await server.close(force:true);
    }
  });
}
