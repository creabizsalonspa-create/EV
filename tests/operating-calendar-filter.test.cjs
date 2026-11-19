'use strict';
const assert=require('node:assert/strict'),{test}=require('node:test');
const {matchesStationFilter,readStationFilter}=require('./operating-calendar.cjs');
const base='http://127.0.0.1:8027',name='Calendar literal100%_! fixture';
const response=(query,method='GET',origin=base)=>({url:()=>origin+'/api/stations?'+new URLSearchParams(query),request:()=>({method:()=>method}),status:()=>200});
test('initial or unrelated catalog replies cannot satisfy a submitted calendar filter',()=>{
 assert.equal(matchesStationFilter(response({page:'1'}),base,name),false);
 assert.equal(matchesStationFilter(response({q:'another fixture'}),base,name),false);
 assert.equal(matchesStationFilter(response({q:name}),base,name),true);
 assert.equal(matchesStationFilter(response({q:name},'POST'),base,name),false);
 assert.equal(matchesStationFilter(response({q:name},'GET','http://localhost:8027'),base,name),false);
});
test('filter barrier includes the actual response and dependent catalog render, not an existing card',async()=>{
 let resolveResponse,resolveRender,clicked=false,settled=false;
 const page={waitForResponse:predicate=>{assert(predicate(response({q:name})));return new Promise(resolve=>resolveResponse=resolve);},locator:selector=>{assert.equal(selector,'#filters button');return{click:async()=>{clicked=true;}};},waitForFunction:()=>new Promise(resolve=>resolveRender=resolve)};
 const pending=readStationFilter(page,base,name).then(()=>settled=true);
 await new Promise(setImmediate);assert(clicked);assert.equal(settled,false);
 resolveResponse(response({q:name}));await new Promise(setImmediate);assert.equal(settled,false);
 resolveRender();await pending;assert.equal(settled,true);
});
