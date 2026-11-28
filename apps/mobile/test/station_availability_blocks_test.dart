import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_availability_blocks.dart';

class BlockApi extends EvApi {
  BlockApi():super(Uri.parse('https://example.invalid'));
  final writes=<Map<String,dynamic>>[];
  bool expired = false, invalidSource = false, invalidReceipt = false;
  String? receiptStart;
  bool failFirstWrite = true;
  final reads = <int>[];
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}}) async {
    if(method=='GET') {
      final stationId = int.parse(path.split('/')[1]);
      reads.add(stationId);
      if (expired) throw const ApiException(401,'PRIVATE UPSTREAM TOKEN');
      return {'station_id': stationId, 'data': invalidSource
        ? <dynamic>[{'station_id': 999, 'id': 'unsafe', 'scope': 'REAL_HARDWARE'}]
        : <dynamic>[]};
    }
    writes.add({'path':path,...?body});
    if(failFirstWrite && writes.length==1 && !invalidReceipt)throw const ApiException(503,'Uncertain');
    return {...?body, if(receiptStart!=null) 'starts_at':receiptStart, 'station_id':7,
      'id':'11111111-1111-4111-8111-111111111111',
      'revision':1, 'scope':'STATION_WIDE_OPERATOR_POLICY',
      'cancelled':false, 'physical_availability_verified':invalidReceipt};
  }
}
class CancellationApi extends EvApi {
  CancellationApi():super(Uri.parse('https://example.invalid'));
  int reads=0, writes=0, epoch=0;
  bool matchingCancel = false;
  final changes = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => changes.stream;
  @override
  bool Function() sessionFence() { final captured=epoch; return ()=>captured==epoch; }
  void revoke() { epoch++; changes.add(epoch); }
  final block=<String,dynamic>{
    'station_id':7,'id':'11111111-1111-4111-8111-111111111111',
    'starts_at':'2026-11-17T09:00:00Z','ends_at':'2026-11-17T10:00:00Z',
    'reason_en':'Maintenance','reason_ar':'صيانة','revision':1,
    'scope':'STATION_WIDE_OPERATOR_POLICY','cancelled':false,
    'physical_availability_verified':false,
  };
  @override
  Future<dynamic> request(String path,{String method='GET',Map<String,dynamic>? body,Map<String,String> query=const {}}) async {
    if(method=='GET'){reads++;return {'station_id':7,'data':[block]};}
    writes++;
    return {...block,
      if(!matchingCancel) 'id':'22222222-2222-4222-8222-222222222222',
      if(matchingCancel) 'starts_at':'2026-11-17T13:00:00+04:00',
      if(matchingCancel) 'ends_at':'2026-11-17T14:00:00+04:00',
      'cancelled':true,'revision':2};
  }
}
void main(){
 testWidgets('native rejects invalid draft and subsecond receipt, then confirms equivalent offset',(tester) async {
  tester.view.physicalSize=const Size(900,1600);tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
  final api=BlockApi()..failFirstWrite=false..receiptStart='2026-11-17T09:00:00.000001Z';
  addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));
  await tester.pumpAndSettle();
  for(final entry in {'starts_at':'2026-02-30T09:00:00Z','ends_at':'2026-11-17T10:00:00Z','reason_en':'Maintenance','reason_ar':'صيانة'}.entries) {
    await tester.enterText(find.byKey(Key('block-${entry.key}')),entry.value);
  }
  await tester.tap(find.text('Publish station block'));await tester.pumpAndSettle();
  expect(api.writes,isEmpty);
  expect(find.textContaining('Choose a valid increasing UTC interval'),findsOneWidget);
  await tester.enterText(find.byKey(const Key('block-starts_at')),'2026-11-17T09:00:00Z');
  await tester.tap(find.text('Publish station block'));await tester.pumpAndSettle();
  expect(api.writes,hasLength(1));expect(api.reads,hasLength(1));
  expect(find.text('Retry same block request'),findsOneWidget);
  api.receiptStart='2026-11-17T13:00:00+04:00';
  await tester.tap(find.text('Retry same block request'));await tester.pumpAndSettle();
  expect(api.writes,hasLength(2));expect(api.writes.first,api.writes.last);
  expect(api.reads,hasLength(2));expect(find.text('Publish station block'),findsOneWidget);
 });
 testWidgets('native cancellation accepts equivalent offset but rejects invalid active revision',(tester) async {
  tester.view.physicalSize=const Size(900,1600);tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
  final api=CancellationApi()..matchingCancel=true;
  addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Cancel this block'));await tester.pumpAndSettle();
  expect(api.writes,1);expect(api.reads,2);
  expect(find.textContaining('Action not confirmed.'),findsNothing);
  api.block['revision']=2147483647;
  await tester.tap(find.text('Refresh station blocks'));await tester.pumpAndSettle();
  expect(find.text('Cancel this block'),findsNothing);
  expect(find.textContaining('Action not confirmed.'),findsOneWidget);
 });
 testWidgets('invalid source interval retires old cancellation grant', (tester) async {
  tester.view.physicalSize=const Size(900,1600);tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
  final api=CancellationApi();addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));
  await tester.pumpAndSettle();
  final old=tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton,'Cancel this block')).onPressed!;
  expect(find.textContaining('2026-11-17T09:00:00Z'),findsNothing);
  api.block['starts_at']='2026-02-30T09:00:00Z';
  await tester.tap(find.text('Refresh station blocks'));await tester.pumpAndSettle();
  expect(find.text('Cancel this block'),findsNothing);
  api.block['starts_at']='2026-11-17T09:00:00Z';
  await tester.tap(find.text('Refresh station blocks'));await tester.pumpAndSettle();
  expect(find.text('Cancel this block'),findsOneWidget);
  old();await tester.pumpAndSettle();expect(api.writes,0);
  final fresh=tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton,'Cancel this block')).onPressed!;
  api.revoke();await tester.pumpAndSettle();fresh();await tester.pumpAndSettle();
  expect(find.text('Maintenance'),findsNothing);expect(api.writes,0);
 });
 testWidgets('mismatched cancellation receipt clears native cancel grant', (tester) async {
  tester.view.physicalSize=const Size(900,1600);tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);addTearDown(tester.view.resetDevicePixelRatio);
  final api=CancellationApi();addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Cancel this block'));await tester.pumpAndSettle();
  expect(api.writes,1);expect(api.reads,1);
  expect(find.text('Cancel this block'),findsNothing);
  expect(find.textContaining('Action not confirmed.'),findsOneWidget);
 });

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
 testWidgets('forged physical publish receipt retains exact pending intent', (tester) async {
  tester.view.physicalSize=const Size(900,1600);
  tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  final api=BlockApi()..invalidReceipt=true;
  addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));
  await tester.pumpAndSettle();
  for(final entry in {'starts_at':'2026-11-17T09:00:00Z','ends_at':'2026-11-17T10:00:00Z','reason_en':'Maintenance','reason_ar':'صيانة'}.entries){
    await tester.enterText(find.byKey(Key('block-${entry.key}')),entry.value);
  }
  await tester.tap(find.text('Publish station block'));
  await tester.pumpAndSettle();
  expect(api.reads,hasLength(1));
  expect(find.text('Retry same block request'),findsOneWidget);
  api.invalidReceipt=false;
  await tester.tap(find.text('Retry same block request'));
  await tester.pumpAndSettle();
  expect(api.writes,hasLength(2));
  expect(api.writes[0],api.writes[1]);
  expect(api.reads,hasLength(2));
  expect(find.text('Publish station block'),findsOneWidget);
 });
 testWidgets('invalid policy data never yields a cancel or trusted source claim', (tester) async {
  final api=BlockApi()..invalidSource=true;
  addTearDown(api.close);
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(api:api,stationId:7)));
  await tester.pumpAndSettle();
  expect(find.text('Cancel this block'),findsNothing);
  expect(find.textContaining('Action not confirmed.'),findsOneWidget);
  expect(find.textContaining('REAL_HARDWARE'),findsNothing);
  expect(api.writes,isEmpty);
 });
 testWidgets('expired owner read clears policy and notifies caller without private error', (tester) async {
  final api=BlockApi();
  addTearDown(api.close);
  int expirations=0;
  await tester.pumpWidget(MaterialApp(home:StationAvailabilityBlocksView(
    api:api,stationId:7,onExpired:()=>expirations++,
  )));
  await tester.pumpAndSettle();
  api.expired=true;
  await tester.tap(find.text('Refresh station blocks'));
  await tester.pumpAndSettle();
  expect(expirations,1);
  expect(find.textContaining('PRIVATE UPSTREAM TOKEN'),findsNothing);
  expect(find.text('Cancel this block'),findsNothing);
  expect(api.writes,isEmpty);
 });
 testWidgets('reusing the editor for another station resets pending request identity', (tester) async {
  tester.view.physicalSize=const Size(900,1600);
  tester.view.devicePixelRatio=1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  final api=BlockApi();
  addTearDown(api.close);
  Widget view(int id)=>MaterialApp(home:StationAvailabilityBlocksView(
    api:api,stationId:id,
  ));
  await tester.pumpWidget(view(7));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const Key('block-reason_en')),'PRIVATE OLD STATION');
  await tester.pumpWidget(view(8));
  await tester.pumpAndSettle();
  expect(api.reads,containsAllInOrder([7,8]));
  expect(tester.widget<TextField>(find.byKey(const Key('block-reason_en'))).controller!.text,isEmpty);
  expect(api.writes,isEmpty);
 });
}
