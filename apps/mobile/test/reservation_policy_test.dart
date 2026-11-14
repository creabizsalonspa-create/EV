import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/reservation_policy.dart';
import 'package:creabiz_ev/reservation_intent.dart';

const terms=<String,dynamic>{'version':1,'slot_minutes':30,'arrival_before_minutes':15,'unused_expiry_minutes':30,'no_show_fee_minor':0,'cancellation_fee_minor':0,'cancellation_rule':'BEFORE_ADMITTED_OR_UNCERTAIN_START','occupancy_basis':'CANONICAL_LOGICAL_SESSION_ONLY','occupancy_enforcement':'START_ADMISSION','physical_occupancy_verified':false,'unresolved_session_requires_reconciliation':true,'automatic_stop':false,'evidence':'SIMULATED_BUSINESS_POLICY'};
void main(){
 test('unrecognized fees, policy revisions and physical claims are never described as free supported terms',(){
  expect(supportedReservationTerms(terms),isTrue);
  for(final change in [{'version':2},{'no_show_fee_minor':500},{'cancellation_fee_minor':1},{'physical_occupancy_verified':true},{'automatic_stop':true}]){expect(supportedReservationTerms({...terms,...change}),isFalse);}
  expect(supportedReservationTerms(null),isFalse);
 });
 test('connector and favorite projections preserve the selected server terms',(){
  final station=Station.fromJson({'id':7,'name':'Synthetic','connector_type':'CCS2','evidence':'SIMULATED','power_kw':50,'reservable':true,'reservation_terms':terms});
  final connector=Station.forConnector(station,{'id':9,'connector_type':'CCS2','max_power_kw':50,'reservable':true,'evse_number':1,'connector_number':1});
  expect(connector.reservationTerms,terms);expect(Station.withFavorite(connector,true).reservationTerms,terms);
 });
 test('uncertain reservation identity cannot be reused across a policy change',(){
  final keys=ReservationIntentKeys(),api=Object();
  String key(int version)=>keys.forBooking(api:api,ownerId:1,stationId:7,mappingId:null,vehicleId:null,startsAtUtc:'2026-11-17T09:00:00Z',termsVersion:version,sessionFence:()=>true);
  final first=key(1);expect(key(1),first);expect(key(2),isNot(first));
 });
 testWidgets('Arabic receipt presents policy and never implies physical availability',(tester)async{
  await tester.pumpWidget(const MaterialApp(locale:Locale('ar'),supportedLocales:[Locale('en'),Locale('ar')],localizationsDelegates:[GlobalMaterialLocalizations.delegate,GlobalWidgetsLocalizations.delegate,GlobalCupertinoLocalizations.delegate],home:Scaffold(body:ReservationPolicyPanel(terms:terms))));
  await tester.tap(find.byType(ExpansionTile));await tester.pumpAndSettle();
  expect(find.textContaining('رسوم الإلغاء وعدم الحضور صفر'),findsOneWidget);expect(find.textContaining('لا يضمن المكان الفعلي'),findsOneWidget);
 });
}
