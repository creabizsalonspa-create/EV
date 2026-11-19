const {test}=require('node:test');
const assert=require('node:assert/strict');
const {provisionTestRole}=require('./helpers/browser-role.cjs');
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};};
test('role mutation waits for every active browser bootstrap and executes once',async()=>{
 const first=deferred(),second=deferred(),seen=[],calls=[];
 const pages=[first,second].map((d,i)=>({waitForLoadState:state=>{seen.push([i,state]);return d.promise;}}));
 const action=provisionTestRole('synthetic@example.test','vendor',{pages,runner:'private-runner',runCommand:(...args)=>calls.push(args)});
 await Promise.resolve();assert.equal(calls.length,0);
 first.resolve();await Promise.resolve();assert.equal(calls.length,0);
 second.resolve();await action;assert.equal(calls.length,1);
 assert.deepEqual(seen,[[0,'networkidle'],[1,'networkidle']]);
 assert.deepEqual(calls[0].slice(0,2),['private-runner',['artisan','platform:role','synthetic@example.test','vendor']]);
});
test('bootstrap failure cannot execute a privileged mutation',async()=>{
 let calls=0;
 await assert.rejects(provisionTestRole('synthetic@example.test','admin',{pages:[{waitForLoadState:async()=>{throw Error('bootstrap incomplete');}}],runCommand:()=>calls++}),/bootstrap incomplete/);
 assert.equal(calls,0);
});
test('unknown CLI outcomes are never replayed and private diagnostics remain redacted',async()=>{
 let calls=0;
 await assert.rejects(provisionTestRole('private@example.test','vendor',{pages:[{waitForLoadState:async()=>{}}],runCommand:async()=>{calls++;throw Object.assign(Error('private SQL secret'),{status:1,stdout:'SQLSTATE[HY000]: database is locked; private@example.test secret'});}}),error=>{
  assert.match(error.message,/SQLSTATE HY000.*SQLITE_BUSY_OR_LOCKED/);
  assert.equal(error.message.includes('private@example.test'),false);assert.equal(error.message.includes('secret'),false);return true;
 });
 assert.equal(calls,1);
});
test('role fixture requires explicit browser ownership',async()=>{
 let calls=0;
 await assert.rejects(provisionTestRole('synthetic@example.test','vendor',{pages:[],runCommand:()=>calls++}),/Explicit browser pages/);assert.equal(calls,0);
});
