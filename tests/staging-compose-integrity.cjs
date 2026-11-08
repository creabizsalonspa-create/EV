'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const gate=require('../scripts/staging-compose-integrity.cjs');
const COMPOSE='name: ev-staging\nservices:\n  application:\n    image: creabiz-ev-business:reviewed\n';
function sandbox(fn){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-compose-review-'));
 fs.chmodSync(dir,0o700);
 const file=path.join(dir,'compose.yaml');
 fs.writeFileSync(file,COMPOSE,{mode:0o644});
 try{return fn({dir,file});}finally{fs.rmSync(dir,{recursive:true,force:true});}
}
test('regular reviewed Compose file remains acceptable',()=>sandbox(({file})=>{
 const snapshot=gate.capture(file);
 assert.equal(snapshot.size,Buffer.byteLength(COMPOSE));
 assert.equal(snapshot.digest.length,64);
 assert.ok(Object.isFrozen(snapshot));
 assert.equal(gate.assertUnchanged(file,snapshot),true);
 assert.equal(JSON.stringify(snapshot).includes('ev-staging'),false);
}));
test('same-length Compose mutation fails closed',()=>sandbox(({file})=>{
 const snapshot=gate.capture(file);
 fs.writeFileSync(file,COMPOSE.replace('reviewed','replaced'),{mode:0o644});
 assert.throws(()=>gate.assertUnchanged(file,snapshot),/changed after review/);
}));
test('atomic replacement with same content is rejected by inode identity',()=>sandbox(({dir,file})=>{
 const snapshot=gate.capture(file);
 const newer=path.join(dir,'replacement');fs.writeFileSync(newer,COMPOSE,{mode:0o644});
 fs.renameSync(newer,file);
 assert.throws(()=>gate.assertUnchanged(file,snapshot),/changed after review/);
}));
test('file symlinks and parent-directory symlinks are rejected',()=>sandbox(({dir,file})=>{
 const link=path.join(dir,'alias');fs.symlinkSync(file,link);
 assert.throws(()=>gate.capture(link),/cannot be securely opened/);
 const parent=path.join(dir,'stage-link');fs.symlinkSync(dir,parent);
 assert.throws(()=>gate.capture(path.join(parent,'compose.yaml')),/unsafe parent/);
}));
test('world-writable, hardlinked, missing and oversized Compose rejected',()=>sandbox(({dir,file})=>{
 fs.chmodSync(file,0o666);
 assert.throws(()=>gate.capture(file),/permissions invalid/);
 fs.chmodSync(file,0o644);
 const link=path.join(dir,'hard');fs.linkSync(file,link);
 assert.throws(()=>gate.capture(file),/permissions invalid/);
 fs.unlinkSync(link);
 fs.writeFileSync(file,Buffer.alloc(gate.MAX_BYTES+1,65),{mode:0o644});
 assert.throws(()=>gate.capture(file),/permissions invalid/);
 fs.unlinkSync(file);
 assert.throws(()=>gate.capture(file),/cannot be securely opened/);
}));
test('FIFO and directories are rejected without blocking',()=>sandbox(({dir})=>{
 const fifo=path.join(dir,'fifo');require('node:child_process').execFileSync('mkfifo',[fifo]);
 assert.throws(()=>gate.capture(fifo),/permissions invalid/);
 assert.throws(()=>gate.capture(dir),/permissions invalid/);
}));
test('invalid baseline and relative paths fail before checking deployment',()=>sandbox(({file})=>{
 assert.throws(()=>gate.capture('compose.yaml'),/cannot be securely inspected/);
 for(const bad of [null,{}, {digest:'incorrect'}]){
  assert.throws(()=>gate.assertUnchanged(file,bad),/Missing reviewed/);
 }
}));
