'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
const source=fs.readFileSync('services/business-api/public/platform.js','utf8');
function fixture(){const calls=[],context={user:{id:1,role:'customer'},client:{sessionEpoch:0}};
 vm.createContext(context);vm.runInContext(source.slice(source.indexOf('// Catalog ownership:'),source.indexOf('function reservationForm')),context);
 context.stations=async query=>{calls.push(query);vm.runInContext('renderedCatalogIdentity=catalogIdentityKey()',context)};
 return {calls,context,run:code=>vm.runInContext(code,context)};}
test('late bootstrap cannot replace an explicit catalog or selected reservation form',async()=>{
 const f=fixture();f.run('requestedCatalogQuery="?q=selected";renderedCatalogIdentity=catalogIdentityKey()');
 await f.run('initialCatalog()');assert.deepEqual(f.calls,[]);
});
test('normal startup loads once, while a changed account reloads the selected query under fresh authority',async()=>{
 const f=fixture();await f.run('initialCatalog()');await f.run('initialCatalog()');assert.deepEqual(f.calls,['']);
 f.run('requestedCatalogQuery="?connector_type=CCS2";client.sessionEpoch++;user={id:2,role:"customer"}');
 await f.run('initialCatalog()');assert.deepEqual(f.calls,['','?connector_type=CCS2']);
});
test('anonymous catalog is reloaded for a validated customer and never used as an authenticated grant',async()=>{
 const f=fixture();f.run('user=null;renderedCatalogIdentity=catalogIdentityKey();requestedCatalogQuery="?q=public";user={id:1,role:"customer"}');
 await f.run('initialCatalog()');assert.deepEqual(f.calls,['?q=public']);
});
