import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_availability_blocks.dart';

class BlockApi extends EvApi {
  BlockApi():super(Uri.parse('https://example.invalid'));
  final writes=<Map<String,dynamic>>[];
  bool expired = false, invalidSource = false, invalidReceipt = false;
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
    if(writes.length==1 && !invalidReceipt)throw const ApiException(503,'Uncertain');
    return {...?body, 'station_id':7,
      'id':'11111111-1111-4111-8111-111111111111',
      'revision':1, 'scope':'STATION_WIDE_OPERATOR_POLICY',
      'cancelled':false, 'physical_availability_verified':invalidReceipt};
  }
}
class CancellationApi extends EvApi {
  CancellationApi():super(Uri.parse('https://example.invalid'));
  int reads=0, writes=0;
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
    return {...block,'id':'22222222-2222-4222-8222-222222222222',
      'cancelled':true,'revision':2};
  }
}
class ConfirmedWriteApi extends CancellationApi {
  final bool publish;
  final int refreshError;

  ConfirmedWriteApi(this.publish, this.refreshError);

  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'POST') {
      writes++;
      return publish ? block : {...block, 'cancelled': true, 'revision': 2};
    }
    if (++reads == 2) throw ApiException(refreshError, 'PRIVATE LIST ERROR');
    return {'station_id': 7, 'data': reads == 1 && !publish ? [block] : []};
  }
}

void main(){
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
 for (final publish in [true, false]) {
  testWidgets('keeps a confirmed ${publish ? 'publish' : 'cancel'} when the list fetch fails', (tester) async {
   tester.view.physicalSize = const Size(900, 1600);
   tester.view.devicePixelRatio = 1;
   addTearDown(tester.view.resetPhysicalSize);
   addTearDown(tester.view.resetDevicePixelRatio);
   final api = ConfirmedWriteApi(publish, 503);
   addTearDown(api.close);
   await tester.pumpWidget(MaterialApp(home: StationAvailabilityBlocksView(api: api, stationId: 7)));
   await tester.pumpAndSettle();
   if (publish) {
    for (final key in ['starts_at', 'ends_at', 'reason_en', 'reason_ar']) {
     await tester.enterText(find.byKey(Key('block-$key')), api.block[key] as String);
    }
   }
   await tester.tap(find.text(publish ? 'Publish station block' : 'Cancel this block'));
   await tester.pumpAndSettle();
   expect(find.textContaining(publish ? 'Station block published.' : 'Station block cancelled.'), findsOneWidget);
   expect(find.textContaining('The block list is unavailable.'), findsOneWidget);
   expect(find.textContaining('Action not confirmed.'), findsNothing);
   expect(find.textContaining('PRIVATE LIST ERROR'), findsNothing);
   expect(find.text('Retry same block request'), findsNothing);
   expect(find.text('Cancel this block'), findsNothing);
   await tester.tap(find.text('Refresh station blocks'));
   await tester.pumpAndSettle();
   expect(api.reads, 3);
   expect(api.writes, 1);
  });
 }

 testWidgets('post-write authorization expiry still clears the private view', (tester) async {
  final api = ConfirmedWriteApi(false, 419);
  addTearDown(api.close);
  int expirations = 0;
  await tester.pumpWidget(MaterialApp(home: StationAvailabilityBlocksView(
   api: api, stationId: 7, onExpired: () => expirations++,
  )));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Cancel this block'));
  await tester.pumpAndSettle();
  expect(api.writes, 1);
  expect(expirations, 1);
  expect(find.text('Cancel this block'), findsNothing);
  expect(find.textContaining('Station block cancelled.'), findsNothing);
  expect(find.textContaining('PRIVATE LIST ERROR'), findsNothing);
 });

}
