import test from 'node:test';
import assert from 'node:assert/strict';
import {bookingMutationFilter,rescheduleSelection} from '../bookingMutationPolicy.mjs';
const fixture=()=>({_id:'booking',userId:'owner',status:'Upcoming',isPaid:false,
 hasArrived:false,date:'2026-11-09',time:'12:00 PM',slotStart:'12:00 PM',slotEnd:'01:00 PM'});
const station={status:'Available',slots:[{startTime:'01:00 PM',endTime:'02:00 PM'},
 {startTime:'02:00 PM',endTime:'02:30 PM'}]};
const body={date:'2026-11-10',time:'01:00 PM',slotStart:'01:00 PM',slotEnd:'02:00 PM'};
test('unused booking mutation pins the inspected slot, owner and payment state',()=>{
 const row=fixture(),filter=bookingMutationFilter(row);
 assert.equal(filter.userId,row.userId);assert.equal(filter.date,row.date);
 assert.equal(filter.slotStart,row.slotStart);assert.equal(filter.isPaid,false);
 assert.deepEqual(filter.hasArrived,{$ne:true});
 assert.deepEqual(filter.ocppTokenHash,{$in:['',null]});
 assert.equal(filter.ocppTokenIssuedAt,null);
});
test('paid, arrived, linked and uncertain authorization cannot release inventory',()=>{
 for(const change of [{status:'Completed'},{status:'Cancelled'},{status:'Charging'},
  {isPaid:true},{isPaid:'false'},{isPaid:null},{hasArrived:true},
  {ocppTokenHash:'uncertain'},{ocppChargerId:'pinned'},
  {ocppTokenIssuedAt:new Date()},{ocppTokenIssuedAt:'invalid'}]){
   assert.equal(bookingMutationFilter({...fixture(),...change}),null);
 }
 assert.equal(bookingMutationFilter(fixture(),{hasSession:true}),null);
});
test('only the server station slot and unchanged duration can be selected',()=>{
 assert.deepEqual(rescheduleSelection(fixture(),body,station),body);
 assert.equal(rescheduleSelection(fixture(),{...body,time:'03:00 PM'},station),null);
 assert.equal(rescheduleSelection(fixture(),{...body,slotEnd:'03:00 PM'},station),null);
 assert.equal(rescheduleSelection(fixture(),{...body,time:'02:00 PM',slotStart:'02:00 PM',
  slotEnd:'02:30 PM'},station),null);
});
test('missing/unavailable station and malformed request do not mutate booking',()=>{
 for(const request of [null,[],{...body,date:{$ne:'x'}},{...body,date:' '},
  {...body,time:['01:00 PM']},{...body,slotStart:12}])
   assert.equal(rescheduleSelection(fixture(),request,station),null);
 assert.equal(rescheduleSelection(fixture(),body,null),null);
 assert.equal(rescheduleSelection(fixture(),body,{...station,status:'Unavailable'}),null);
 assert.equal(rescheduleSelection(fixture(),body,{...station,slots:[]}),null);
});
test('opaque legacy or impossible slot times require review rather than a fake hour',()=>{
 for(const changes of [{slotStart:'25:00 PM'},{slotEnd:'11:00 AM'},
   {slotStart:'12:00'}, {slotStart:'00:00 AM'}, {slotStart:'12:60 PM'}])
   assert.equal(rescheduleSelection({...fixture(),...changes},body,station),null);
});
