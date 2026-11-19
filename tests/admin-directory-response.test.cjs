'use strict';
const assert=require('node:assert/strict');
const {test}=require('node:test');
const {matchesDirectoryRead}=require('./admin-current-authority-e2e.cjs');
const base='http://127.0.0.1:8000';
const expected={company_q:'Literal100%_! synthetic',company_country:'AE',company_status:'rejected'};
const response=(query,method='GET',origin=base)=>({url:()=>origin+'/api/admin/accounts?'+new URLSearchParams(query),request:()=>({method:()=>method})});
test('a delayed bootstrap cannot satisfy a submitted private directory read',()=>{
 assert.equal(matchesDirectoryRead(response({page:'1'}),base,expected),false);
 assert.equal(matchesDirectoryRead(response({...expected,company_status:'approved'}),base,expected),false);
 assert.equal(matchesDirectoryRead(response({...expected,company_q:'another fixture'}),base,expected),false);
 assert.equal(matchesDirectoryRead(response(expected),base,expected),true);
});
test('directory response matching requires the actual origin and read method',()=>{
 assert.equal(matchesDirectoryRead(response(expected,'POST'),base,expected),false);
 assert.equal(matchesDirectoryRead(response(expected,'GET','http://localhost:8000'),base,expected),false);
});
