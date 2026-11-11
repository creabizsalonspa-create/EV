'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const gate=require('../scripts/staging-env-integrity.cjs');
const ENV='APP_KEY=base64:synthetic_fake_key\nDB_PASSWORD=not_real\nPAYMENT_PROVIDER=mock\n';
function sandbox(fn){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-staging-env-snapshot-'));
 fs.chmodSync(dir,0o700);
 const file=path.join(dir,'.env');
 fs.writeFileSync(file,ENV,{mode:0o600});
 try{return fn({dir,file});}finally{fs.rmSync(dir,{recursive:true,force:true});}
}
test('private regular staging env captures stable in-memory digest without secrets',()=>sandbox(({file})=>{
 const snap=gate.capture(file);
 assert.equal(snap.digest.length,64);
 assert.equal(snap.size,Buffer.byteLength(ENV));
 assert.equal(JSON.stringify(snap).includes('PASSWORD'),false);
 assert.ok(Object.isFrozen(snap));
 assert.equal(gate.assertUnchanged(file,snap),true);
}));
test('changing same-length private config fails digest check',()=>sandbox(({file})=>{
 const snap=gate.capture(file);
 fs.writeFileSync(file,ENV.replace('mock','live'),{mode:0o600});
 assert.throws(()=>gate.assertUnchanged(file,snap),/changed since preflight/);
}));
test('atomic replacement with byte-identical content fails inode identity',()=>sandbox(({dir,file})=>{
 const snap=gate.capture(file);
 const replacement=path.join(dir,'other');
 fs.writeFileSync(replacement,ENV,{mode:0o600});
 fs.renameSync(replacement,file);
 assert.throws(()=>gate.assertUnchanged(file,snap),/changed since preflight/);
}));
test('reparenting the exact same private env inode into a new real directory fails preflight',()=>sandbox(({dir,file})=>{
 const parent=path.join(dir,'private-parent');fs.mkdirSync(parent,{mode:0o700});
 const nested=path.join(parent,'.env');
 fs.renameSync(file,nested);
 const baseline=gate.capture(nested);
 const previous=path.join(dir,'old-parent');
 fs.renameSync(parent,previous);
 fs.mkdirSync(parent,{mode:0o700});
 fs.renameSync(path.join(previous,'.env'),nested);
 assert.equal(fs.statSync(nested).ino,baseline.ino);
 assert.match(baseline.ancestorDigest,/^[a-f0-9]{64}$/);
 assert.equal(JSON.stringify(baseline).includes(dir),false);
 assert.throws(()=>gate.assertUnchanged(nested,baseline),/changed since preflight/);
}));
test('staging env snapshot without baseline directory identity fails closed',()=>sandbox(({file})=>{
 const {ancestorDigest,...unbound}=gate.capture(file);
 assert.ok(ancestorDigest);
 assert.throws(()=>gate.assertUnchanged(file,unbound),/Missing reviewed/);
}));
test('private input cannot be a symbolic link',()=>sandbox(({dir,file})=>{
 const link=path.join(dir,'alias');fs.symlinkSync(file,link);
 assert.throws(()=>gate.capture(link),/cannot be securely opened/);
}));
test('symlink swap after preflight fails without printing content',()=>sandbox(({dir,file})=>{
 const snap=gate.capture(file);
 const original=path.join(dir,'original');fs.renameSync(file,original);fs.symlinkSync(original,file);
 assert.throws(()=>gate.assertUnchanged(file,snap),/cannot be securely opened/);
}));
test('public, hardlinked, missing or oversized file is rejected',()=>sandbox(({dir,file})=>{
 fs.chmodSync(file,0o644);
 assert.throws(()=>gate.capture(file),/type, access or size invalid/);
 fs.chmodSync(file,0o600);
 const hard=path.join(dir,'hardlink');fs.linkSync(file,hard);
 assert.throws(()=>gate.capture(file),/type, access or size invalid/);
 fs.unlinkSync(hard);
 fs.writeFileSync(file,Buffer.alloc(gate.MAX_ENV_BYTES+1,65),{mode:0o600});
 assert.throws(()=>gate.capture(file),/type, access or size invalid/);
 fs.unlinkSync(file);
 assert.throws(()=>gate.capture(file),/cannot be securely opened/);
}));
test('pipe and directory are rejected without blocking',()=>sandbox(({dir})=>{
 const fifo=path.join(dir,'fifo');require('node:child_process').execFileSync('mkfifo',[fifo]);
 fs.chmodSync(fifo,0o600);
 assert.throws(()=>gate.capture(fifo),/type, access or size invalid/);
 assert.throws(()=>gate.capture(dir),/type, access or size invalid/);
}));
test('malformed snapshot or non-absolute path cannot bypass preflight',()=>sandbox(({file})=>{
 assert.throws(()=>gate.capture('.env'),/cannot be securely reviewed/);
 for(const x of [null,{}, {digest:'x'.repeat(64)}]){
   if(x===null||x.digest===undefined)assert.throws(()=>gate.assertUnchanged(file,x),/Missing reviewed/);
   else assert.throws(()=>gate.assertUnchanged(file,x),/Missing reviewed/);
 }
}));
