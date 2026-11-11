import test from 'node:test';
import assert from 'node:assert/strict';
import {reservationChargerChoices,selectReservationCharger} from '../reservationChargerChoice.mjs';
import {chooseBookingCharger,sourceMatchesBookingCharger} from '../ocppMultiCharger.mjs';
const a={chargerId:'a',protocol:'ocpp1.6',enabled:true,password:'private'},b={chargerId:'b',protocol:'ocpp2.0.1',enabled:true};
test('approved identity catalog is bounded, sorted, redacted and never claims live capacity',()=>{
 assert.deepEqual(reservationChargerChoices([b,a]),[{chargerId:'a',protocol:'ocpp1.6'},{chargerId:'b',protocol:'ocpp2.0.1'}]);
 for(const rows of [[a,a],[{...a,enabled:false}],[{...a,protocol:'unknown'}],[{...a,chargerId:'bad/id'}],Array(65).fill(a)])assert.equal(reservationChargerChoices(rows),null);
});
test('customer selection is required for multiple approved chargers and cannot name another station',()=>{
 assert.equal(selectReservationCharger([a,b]).ok,false);
 assert.equal(selectReservationCharger([a,b],'foreign').ok,false);
 assert.equal(selectReservationCharger([a,b],{$ne:null}).ok,false);
 assert.deepEqual(selectReservationCharger([a,b],'b'),{ok:true,chargerId:'b'});
 assert.deepEqual(selectReservationCharger([a]),{ok:true,chargerId:'a'});
 assert.deepEqual(selectReservationCharger([]),{ok:true,chargerId:''});
});
test('reservation charger choice survives authorization without redirect or disabled fallback',()=>{
 assert.equal(chooseBookingCharger({reservedChargerId:'b',chargers:[a,b]}).charger.chargerId,'b');
 for(const fields of [{requestChargerId:'a'},{bookedChargerId:'a'},{chargers:[a,{...b,enabled:false}]}])
  assert.equal(chooseBookingCharger({reservedChargerId:'b',chargers:[a,b],...fields}).charger,null);
 assert.equal(sourceMatchesBookingCharger({booking:{reservedChargerId:'b'},chargerId:'a',chargers:[a,b]}),false);
 assert.equal(sourceMatchesBookingCharger({booking:{reservedChargerId:'b'},chargerId:'b',chargers:[a,b]}),true);
});
