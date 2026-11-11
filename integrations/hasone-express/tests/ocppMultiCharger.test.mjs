import test from 'node:test';
import assert from 'node:assert/strict';
import {chooseBookingCharger,sourceMatchesBookingCharger} from '../ocppMultiCharger.mjs';
const a={chargerId:'CH-1',enabled:true,protocol:'ocpp1.6'}, b={chargerId:'CH-2',enabled:true,protocol:'ocpp2.0.1'};
test('one explicitly qualified charger defaults safely',()=>{
 const x=chooseBookingCharger({chargers:[a]});assert.equal(x.charger.chargerId,'CH-1');
});
test('two approved chargers require explicit choice',()=>{
 const x=chooseBookingCharger({chargers:[b,a]});
 assert.equal(x.charger,null);assert.equal(x.reason,'explicit_selection_required');
 assert.deepEqual(x.choices.map(y=>y.chargerId),['CH-1','CH-2']);
});
test('requested charger must belong to active station list',()=>{
 assert.equal(chooseBookingCharger({requestChargerId:'NOT-STATION',chargers:[a,b]}).charger,null);
 assert.equal(chooseBookingCharger({requestChargerId:'CH-2',chargers:[a,b]}).charger.chargerId,'CH-2');
});
test('disabled charger cannot be selected',()=>{
 const z=chooseBookingCharger({requestChargerId:'CH-2',chargers:[a,{...b,enabled:false}]});
 assert.equal(z.charger,null);
});
test('booking bound to charger cannot switch to another',()=>{
 const z=chooseBookingCharger({bookedChargerId:'CH-1',requestChargerId:'CH-2',chargers:[a,b]});
 assert.equal(z.charger,null);assert.equal(z.reason,'booking_charger_already_pinned');
});
test('existing booking pin takes precedence on multi-charger station',()=>{
 const z=chooseBookingCharger({bookedChargerId:'CH-2',chargers:[a,b]});
 assert.equal(z.charger.chargerId,'CH-2');
});
test('missing approved charger cannot be used after booking pin',()=>{
 const z=chooseBookingCharger({bookedChargerId:'CH-2',chargers:[a]});
 assert.equal(z.charger,null);assert.equal(z.reason,'approved_charger_unavailable');
});
test('source proof rejects cross-charger and unpinned multi-charger stations',()=>{
 assert.equal(sourceMatchesBookingCharger({booking:{ocppChargerId:'CH-1'},chargerId:'CH-2',chargers:[a,b]}),false);
 assert.equal(sourceMatchesBookingCharger({booking:{ocppChargerId:'CH-1'},chargerId:'CH-1',chargers:[a,b]}),true);
 assert.equal(sourceMatchesBookingCharger({booking:{},chargerId:'CH-1',chargers:[a,b]}),false);
 assert.equal(sourceMatchesBookingCharger({booking:{},chargerId:'CH-1',chargers:[a]}),true);
});

test('malformed or ambiguous approved registry cannot authorize any charger',()=>{
 for(const rows of [null,{},[...Array(129)].map((_,i)=>({...a,chargerId:'CH-'+i})),
     [a,{...b,protocol:undefined}],[a,{...b,protocol:'ocpp9'}],[a,{...b,chargerId:'bad id'}],
     [a,{...a,protocol:'ocpp2.0.1'}],[a,a]]) {
  for(const requestChargerId of ['','CH-1','CH-2']) {
   const result=chooseBookingCharger({chargers:rows,requestChargerId});
   assert.equal(result.charger,null);assert.deepEqual(result.choices,[]);
   assert.equal(result.reason,'approved_charger_registry_requires_review');
  }
 }
});
test('missing protocol never becomes implicit OCPP1.6 for existing booking pins',()=>{
 for(const protocol of [undefined,null,'',false,'OCPP1.6']) {
  assert.equal(chooseBookingCharger({bookedChargerId:'CH-1',chargers:[{...a,protocol}]}).charger,null);
  assert.equal(sourceMatchesBookingCharger({booking:{ocppChargerId:'CH-1'},chargerId:'CH-1',chargers:[{...a,protocol}]}),false);
 }
});
