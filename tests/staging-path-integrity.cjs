'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const gate=require('../scripts/staging-path-integrity.cjs');
const env=require('../scripts/staging-env-integrity.cjs');
const compose=require('../scripts/staging-compose-integrity.cjs');
function sandbox(fn){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-real-ancestor-'));
 try {return fn(dir)} finally {fs.rmSync(dir,{recursive:true,force:true});}
}
test('snapshots real ancestors up to root and matches unchanged chain',()=>sandbox(dir=>{
 const deeper=path.join(dir,'nested');fs.mkdirSync(deeper);
 const snapshot=gate.ancestorSnapshot(path.join(deeper,'config.yaml'));
 assert.ok(snapshot.length>=3);
 assert.ok(Object.isFrozen(snapshot));
 assert.equal(gate.assertAncestorsUnchanged(snapshot),true);
}));
test('rejects missing ancestors and relative files',()=>sandbox(dir=>{
 assert.throws(()=>gate.ancestorSnapshot('config.yaml'),/absolute/);
 assert.throws(()=>gate.ancestorSnapshot(path.join(dir,'absent','config.yaml')),/inaccessible/);
 assert.throws(()=>gate.assertAncestorsUnchanged([]),/Missing staging/);
}));
test('rejects symlinked grandparent, including symlink to same real directory',()=>sandbox(dir=>{
 const real=path.join(dir,'real','nested');fs.mkdirSync(real,{recursive:true});
 const alias=path.join(dir,'alias');fs.symlinkSync(path.join(dir,'real'),alias);
 assert.throws(()=>gate.ancestorSnapshot(path.join(alias,'nested','config.yaml')),/real directory/);
}));
test('detects ancestor directory replacement without accepting stale inode provenance',()=>sandbox(dir=>{
 const folder=path.join(dir,'original');fs.mkdirSync(folder);
 const previous=gate.ancestorSnapshot(path.join(folder,'config.yaml'));
 fs.renameSync(folder,path.join(dir,'saved'));
 fs.mkdirSync(folder);
 assert.throws(()=>gate.assertAncestorsUnchanged(previous),/changed during inspection/);
}));

test('Compose rejects symlink in grandparent path, not just its direct parent',()=>sandbox(dir=>{
 const deep=path.join(dir,'real','nested');fs.mkdirSync(deep,{recursive:true});
 const file=path.join(deep,'compose.yaml');fs.writeFileSync(file,'name: ev-staging\nservices:\n  application: {}\n');
 const alias=path.join(dir,'redirect');fs.symlinkSync(path.join(dir,'real'),alias);
 assert.throws(()=>compose.capture(path.join(alias,'nested','compose.yaml')),/unsafe parent/);
}));
test('Compose detects ancestor directory renamed and replaced with a symlink',()=>sandbox(dir=>{
 const nested=path.join(dir,'nested');fs.mkdirSync(nested);
 const file=path.join(nested,'compose.yaml');fs.writeFileSync(file,'name: ev-staging\nservices:\n  application: {}\n');
 const before=compose.capture(file);
 fs.renameSync(nested,path.join(dir,'moved'));fs.symlinkSync(path.join(dir,'moved'),nested);
 assert.throws(()=>compose.assertUnchanged(file,before),/unsafe parent/);
}));
test('private environment refuses symlinked grandparent directory',()=>sandbox(dir=>{
 const nested=path.join(dir,'real','nested');fs.mkdirSync(nested,{recursive:true});
 const file=path.join(nested,'.env');fs.writeFileSync(file,'APP_KEY=synthetic\nDB_PASSWORD=synthetic\n',{mode:0o600});
 const alias=path.join(dir,'redirect');fs.symlinkSync(path.join(dir,'real'),alias);
 assert.throws(()=>env.capture(path.join(alias,'nested','.env')),/unsafe parent/);
}));
test('private environment detects parent replaced with symlink to original bytes',()=>sandbox(dir=>{
 const nested=path.join(dir,'nested');fs.mkdirSync(nested);
 const file=path.join(nested,'.env');fs.writeFileSync(file,'APP_KEY=synthetic\nDB_PASSWORD=synthetic\n',{mode:0o600});
 const before=env.capture(file);
 fs.renameSync(nested,path.join(dir,'moved'));fs.symlinkSync(path.join(dir,'moved'),nested);
 assert.throws(()=>env.assertUnchanged(file,before),/unsafe parent/);
}));
