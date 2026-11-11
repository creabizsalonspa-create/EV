'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { validateFixture, inspectManifest, parseArgs } = require('../scripts/demo-fixture-guard.cjs');

const run='a1b2c3d4';
function fixture(qa=false) {
  const accounts={};
  for (const role of ['customer','vendor','admin']) accounts[role]={
    email:`client-demo-${role}-${run}@example.test`, password:'a'.repeat(48)+'aA9!'
  };
  return {fixture_only:true,run,base:'http://127.0.0.1:8000',company_id:1,accounts,
    stations:[['1.6','16'],['2.0.1','201']].map(([protocol,suffix],i)=>({protocol,
      identity:`ev-test-demo-${run}-${suffix}`,station_id:i+1,charger_id:i+3,binding_id:i+5,
      connector_mapping_id:i+7,reservation_id:qa?i+9:null})),
    qa_current_bookings:qa,physical_charging_verified:false,real_money:false};
}
const copy=x=>JSON.parse(JSON.stringify(x));
function fails(change,expected) {
  const value=fixture();change(value);
  const issues=validateFixture(value);
  assert.ok(issues.some(x=>x.includes(expected)),JSON.stringify(issues));
}
test('ordinary synthetic local demo passes',()=>assert.deepEqual(validateFixture(fixture()),[]));
test('QA shortcuts disabled for external demonstration',()=>{
  assert.ok(validateFixture(fixture(true)).some(x=>x.includes('QA shortcuts')));
  assert.deepEqual(validateFixture(fixture(true),{allowLocalQa:true}),[]);
});
test('source and money/physical false claims enforced',()=>{
  fails(x=>x.real_money=true,'Real/physical');
  fails(x=>x.physical_charging_verified=true,'Real/physical');
  fails(x=>x.base='https://api.hasone.io','isolated local');
});
test('unexpected third-party or customer fields rejected',()=>fails(x=>x.secret='live','Unexpected private demo'));
test('all three synthetic identities required and password format checked',()=>{
  fails(x=>delete x.accounts.admin,'three synthetic');
  fails(x=>x.accounts.customer.email='real@example.com','customer: invalid');
  fails(x=>x.accounts.customer.password='bad','customer: invalid');
});
test('exact simulator station mapping and identity required',()=>{
  fails(x=>x.stations[0].identity='physical-teltonika','Unexpected OCPP');
  fails(x=>x.stations[1].protocol='1.6','Unexpected OCPP');
  fails(x=>x.stations[1].station_id=x.stations[0].station_id,'duplicate');
});
test('reservation shortcut cannot appear in standard demonstration',()=>fails(x=>x.stations[0].reservation_id=42,'future reservations'));
test('private filesystem layout accepted',()=>{
  const base=fs.mkdtempSync(path.join(os.tmpdir(),'ev-guard-'));
  try {
    const parent=path.join(base,'client-demo');fs.mkdirSync(parent,{mode:0o700});
    const dir=path.join(parent,run);fs.mkdirSync(dir,{mode:0o700});
    const file=path.join(dir,'access.json');fs.writeFileSync(file,JSON.stringify(fixture()),{mode:0o600});
    assert.deepEqual(inspectManifest(file),[]);
  }finally{fs.rmSync(base,{recursive:true,force:true});}
});
test('world/group-readable secret files refused', {skip:process.platform==='win32'},()=>{
  const base=fs.mkdtempSync(path.join(os.tmpdir(),'ev-guard-'));
  try {
    const parent=path.join(base,'client-demo');fs.mkdirSync(parent,{mode:0o700});
    const dir=path.join(parent,run);fs.mkdirSync(dir,{mode:0o700});
    const file=path.join(dir,'access.json');fs.writeFileSync(file,JSON.stringify(fixture()),{mode:0o600});
    // Creation modes are masked by the caller's umask. Explicitly make only
    // this disposable synthetic fixture unsafe, then verify the real mode.
    for (const mode of [0o640, 0o604, 0o644]) {
      fs.chmodSync(file,mode);
      assert.equal(fs.statSync(file).mode & 0o777,mode);
      assert.throws(()=>inspectManifest(file));
    }
  }finally{fs.rmSync(base,{recursive:true,force:true});}
});
test('symlinked secret file refused',()=>{
  const base=fs.mkdtempSync(path.join(os.tmpdir(),'ev-guard-'));
  try {
    const parent=path.join(base,'client-demo');fs.mkdirSync(parent,{mode:0o700});
    const dir=path.join(parent,run);fs.mkdirSync(dir,{mode:0o700});
    const source=path.join(dir,'secret');fs.writeFileSync(source,JSON.stringify(fixture()),{mode:0o600});
    const file=path.join(dir,'access.json');fs.symlinkSync(source,file);
    assert.throws(()=>inspectManifest(file));
  }finally{fs.rmSync(base,{recursive:true,force:true});}
});
test('unsupported flags and paths fail closed',()=>{
  assert.deepEqual(parseArgs(['--manifest','private/access.json']),{file:'private/access.json',allowLocalQa:false});
  assert.throws(()=>parseArgs(['--manifest','--dangerous']));
  assert.throws(()=>parseArgs(['--manifest','foo','--production']));
});
