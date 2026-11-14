import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_availability_blocks.dart';

class BlockApi extends EvApi {
  BlockApi():super(Uri.parse('https://example.invalid'));
  final writes=<Map<String,dynamic>>[];
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}}) async {
    if(method=='GET')return {'station_id':7,'data':<dynamic>[]};
    writes.add({'path':path,...?body});
    if(writes.length==1)throw const ApiException(503,'Uncertain');
    return {'station_id':7,'id':'receipt'};
  }
}
void main(){
 testWidgets('blocks require explicit publication and uncertain retries preserve frozen identity',(tester)async{
  tester.view.physicalSize=const Size(900,1600);tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
  final api=BlockApi();addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));await tester.pumpAndSettle();expect(api.writes,isEmpty);
  for(final entry in {'starts_at':'2026-11-17T09:00:00Z','ends_at':'2026-11-17T10:00:00Z','reason_en':'Maintenance','reason_ar':'صيانة'}.entries){await tester.enterText(find.byKey(Key('block-${entry.key}')),entry.value);}
  await tester.tap(find.text('Publish station block'));await tester.pumpAndSettle();expect(api.writes,hasLength(1));
  expect(tester.widget<TextField>(find.byKey(const Key('block-reason_en'))).enabled,isFalse);
  await tester.tap(find.text('Retry same block request'));await tester.pumpAndSettle();expect(api.writes,hasLength(2));expect(api.writes[0],api.writes[1]);
  await tester.tap(find.text('Refresh station blocks'));await tester.pumpAndSettle();expect(api.writes,hasLength(2));
 });
}
