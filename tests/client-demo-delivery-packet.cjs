'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const packet=require('../scripts/client-demo-delivery-packet.cjs');
const {fixtureSha256}=require('../scripts/client-demo-evidence-fingerprint.cjs');
const NOW=Date.parse('2026-11-07T20:00:00.000Z'),F='a'.repeat(64);
const DIGEST=['b'.repeat(64),'c'.repeat(64),'d'.repeat(64)];
const ROLE=['customer','vendor','admin'],OCPP=['1.6','2.0.1'];
function stages(fingerprint=F){
 const ready={schema:'ev-client-demo-lab-readiness-v1',status:'PRIVATE_LAB_DEMO_READY',
   fixture_sha256:fingerprint,observed_at_utc:'2026-11-07T18:00:00.000Z',
   checked_until_utc:'2026-11-07T18:01:00.000Z',simulated_protocols:OCPP,
   browser_roles:ROLE,physical_charging_verified:false,real_money_moved:false,
   live_ocpi_verified:false,production_accepted:false};
 const complete={schema:'ev-local-csms-demo-completion-v2',
   status:'LOCAL_SIMULATOR_COMPLETION_VERIFIED',fixture_sha256:fingerprint,
   observed_from_utc:'2026-11-07T18:15:00.000Z',
   checked_until_utc:'2026-11-07T18:17:00.000Z',
   protocols:OCPP.map(protocol=>({protocol,stopped:true,positive_simulated_energy:true,
     mock_invoice_verified:true,no_real_payment:true,evidence:'SIMULATED_CSMS'})),
   completion_count:2,simulated_invoices_verified:true,physical_charging_verified:false,
   real_money_moved:false,production_accepted:false,customer_contract_accepted:false};
 const ledger={schema:'ev-local-csms-ledger-audit-v1',
   status:'LOCAL_SIMULATED_LEDGER_VERIFIED',fixture_sha256:fingerprint,
   checked_from_utc:'2026-11-07T18:30:00.000Z',checked_until_utc:'2026-11-07T18:32:00.000Z',
   simulated_protocols:OCPP,balanced_invoice_postings:2,
   physical_charging_verified:false,real_money_moved:false,
   payment_processor_verified:false,production_accepted:false,customer_contract_accepted:false};
 return [ready,complete,ledger].map((body,i)=>({body,digest:DIGEST[i]}));
}
function inspect(report){return packet.validatePacket(F,report,NOW);}
function fixture(){
 const accounts={};
 for(const role of ROLE)accounts[role]={email:'client-demo-'+role+'-a1b2c3d4@example.test',
   password:'a'.repeat(48)+'aA9!'};
 return {fixture_only:true,run:'a1b2c3d4',base:'http://127.0.0.1:8000',company_id:1,
   accounts,stations:[['1.6','16'],['2.0.1','201']].map(([protocol,suffix],i)=>({
     protocol,station_id:i+1,charger_id:i+3,binding_id:i+5,connector_mapping_id:i+7,
     identity:'ev-test-demo-a1b2c3d4-'+suffix,reservation_id:null
   })),qa_current_bookings:false,physical_charging_verified:false,real_money:false};
}
function temp(fn){
 const root=fs.mkdtempSync(path.join(os.tmpdir(),'ev-handoff-evidence-'));
 fs.chmodSync(root,0o700);
 try{return fn(root);}finally{fs.rmSync(root,{recursive:true,force:true});}
}
function setup(root){
 const runDir=path.join(root,'client-demo','a1b2c3d4');
 fs.mkdirSync(path.join(root,'client-demo'),{mode:0o700});
 fs.mkdirSync(runDir,{mode:0o700});
 const manifest=path.join(runDir,'access.json');
 fs.writeFileSync(manifest,JSON.stringify(fixture()),{mode:0o600});
 const sha=fixtureSha256(manifest),files={manifest,output:path.join(root,'packet.json')};
 const phases=stages(sha);
 for(const [i,name] of ['readiness','completion','ledger'].entries()){
   files[name]=path.join(root,name+'.json');
   fs.writeFileSync(files[name],JSON.stringify(phases[i].body),{mode:0o600});
 }
 files.phases=phases;
 files.args=['--manifest',manifest,'--readiness',files.readiness,'--completion',files.completion,
   '--ledger',files.ledger,'--output',files.output];
 return files;
}
test('three ordered, same-fixture synthetic reports produce only bounded private packet',()=>{
 const result=inspect(stages());
 assert.equal(result.status,'PRIVATE_LAB_EVIDENCE_BUNDLE_VERIFIED');
 assert.deepEqual(result.simulated_protocols,OCPP);
 assert.deepEqual(result.browser_roles,ROLE);
 assert.equal(result.fixture_sha256,F);
 assert.equal(result.evidence_sha256.readiness,DIGEST[0]);
 for(const key of ['production_accepted','customer_contract_accepted','real_money_moved',
   'payment_processor_verified','live_ocpi_verified','physical_charging_verified',
   'source_signed_or_independently_attested'])assert.equal(result[key],false);
});
test('report for a different synthetic fixture is not merged into packet',()=>{
 const reports=stages();reports[2].body.fixture_sha256='e'.repeat(64);
 assert.throws(()=>inspect(reports));
});
test('pre-demo browser role or station provenance must be complete',()=>{
 for(const invalid of [
   r=>r.status='INCOMPLETE',r=>r.browser_roles=['customer','admin'],
   r=>r.simulated_protocols=['2.0.1','1.6'],r=>r.production_accepted=true,
   r=>delete r.live_ocpi_verified,
 ]){
   const reports=stages();invalid(reports[0].body);
   assert.throws(()=>inspect(reports));
 }
});
test('completed simulator phase requires two positive nonpaying source transactions',()=>{
 for(const invalid of [
   r=>r.completion_count=1,r=>r.protocols[1].mock_invoice_verified=false,
   r=>r.protocols[0].evidence='PHYSICAL',r=>r.real_money_moved=true,
   r=>r.simulated_invoices_verified=false,r=>r.customer_contract_accepted=true
 ]){
   const reports=stages();invalid(reports[1].body);assert.throws(()=>inspect(reports));
 }
});
test('private finance audit must prove two balanced invoices, no payment claim',()=>{
 for(const invalid of [
   r=>r.balanced_invoice_postings=1,r=>r.payment_processor_verified=true,
   r=>r.status='INCOMPLETE',r=>r.simulated_protocols=['1.6'],
   r=>r.physical_charging_verified=true
 ]){
   const reports=stages();invalid(reports[2].body);assert.throws(()=>inspect(reports));
 }
});
test('overlap, wrong order, stale, future or longer than 24h window rejected',()=>{
 for(const invalid of [
   reports=>reports[1].body.observed_from_utc='2026-11-07T17:00:00.000Z',
   reports=>reports[2].body.checked_from_utc='2026-11-07T18:00:00.000Z',
   reports=>reports[0].body.observed_at_utc='2026-11-06T01:00:00.000Z',
   reports=>reports[2].body.checked_until_utc='2026-11-08T18:30:00.000Z',
   reports=>reports[0].body.checked_until_utc='2026-11-07T17:59:00.000Z'
 ]){
   const reports=stages();invalid(reports);assert.throws(()=>inspect(reports));
 }
 const reports=stages();
 assert.throws(()=>packet.validatePacket(F,reports,NOW+73*3600*1000));
});
test('UTC timestamps must be actual ISO dates rather than loosely parsed strings',()=>{
 for(const value of ['yesterday','2026-99-99T12:00:00.000Z','2026-11-07 18:00:00','2026-11-07T18:00:00+04:00'])
   assert.throws(()=>packet.utc(value));
 assert.equal(packet.utc('2026-11-07T18:00:00.000Z'),Date.parse('2026-11-07T18:00:00Z'));
});
test('private reports reject symlink, public readable file and leaked credentials',()=>temp(root=>{
 const x=setup(root);
 assert.equal(packet.readPrivateReport(x.readiness).body.status,'PRIVATE_LAB_DEMO_READY');
 const link=path.join(root,'alias.json');fs.symlinkSync(x.readiness,link);
 assert.throws(()=>packet.readPrivateReport(link));
 if(process.platform!=='win32'){
   fs.chmodSync(x.readiness,0o644);
   assert.throws(()=>packet.readPrivateReport(x.readiness));
   fs.chmodSync(x.readiness,0o600);
 }
 const leak={...x.phases[0].body,password:'never_publish_this'};
 fs.writeFileSync(x.readiness,JSON.stringify(leak),{mode:0o600});
 assert.throws(()=>packet.readPrivateReport(x.readiness));
}));
test('fingerprint is an exact hash of private fixture and changes with its contents',()=>temp(root=>{
 const x=setup(root);const a=fixtureSha256(x.manifest);
 assert.match(a,/^[a-f0-9]{64}$/);
 const modified=JSON.parse(fs.readFileSync(x.manifest,'utf8'));
 modified.accounts.customer.password='b'.repeat(48)+'aA9!';
 fs.writeFileSync(x.manifest,JSON.stringify(modified));
 assert.notEqual(fixtureSha256(x.manifest),a);
 assert.throws(()=>packet.bundle(x,{now:NOW}));
}));
test('bundle refuses different evidence paths, including output reused as source',()=>temp(root=>{
 const x=setup(root);x.output=x.ledger;
 assert.throws(()=>packet.bundle(x,{now:NOW}));
}));
test('valid current synthetic source artifacts create private nonoverwriting final packet',()=>temp(root=>{
 const x=setup(root);
 assert.equal(packet.main(x.args,{now:NOW}),0);
 const proof=JSON.parse(fs.readFileSync(x.output,'utf8'));
 assert.equal(proof.status,'PRIVATE_LAB_EVIDENCE_BUNDLE_VERIFIED');
 assert.equal(proof.production_accepted,false);
 if(process.platform!=='win32')assert.equal(fs.statSync(x.output).mode&0o077,0);
 for(const [i,name] of ['readiness','completion','ledger'].entries()){
   const expected=crypto.createHash('sha256').update(fs.readFileSync(x[name])).digest('hex');
   assert.equal(Object.values(proof.evidence_sha256)[i],expected);
 }
 assert.equal(packet.main(x.args,{now:NOW}),1);
}));
test('a single tampered stage never produces final summary even if local API tests previously passed',()=>temp(root=>{
 const x=setup(root);
 const body=JSON.parse(fs.readFileSync(x.completion,'utf8'));
 body.simulated_invoices_verified=false;
 fs.writeFileSync(x.completion,JSON.stringify(body));
 assert.equal(packet.main(x.args,{now:NOW}),1);
 assert.equal(fs.existsSync(x.output),false);
}));
test('forged output acceptance flag cannot be copied into customer handoff',()=>{
 const reports=stages();
 reports[2].body.production_accepted=true;
 assert.throws(()=>packet.validatePacket(F,reports,NOW));
});
test('script accepts only five explicit paths and never network/charging command',()=>{
 assert.throws(()=>packet.args(['--url','https://production.example.org']));
 assert.throws(()=>packet.args(['--manifest','a','--readiness','b','--completion','c',
   '--ledger','d','--output','e','--launch-real']));
 const good=packet.args(['--manifest','a','--readiness','b','--completion','c','--ledger','d','--output','e']);
 assert.equal(good.output,'e');
});
