'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const crypto=require('node:crypto');
const {spawnSync}=require('node:child_process');
const review=require('../scripts/staging-public-ca-review.cjs');

function temp(fn){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-root-ca-review-'));
 fs.chmodSync(dir,0o700);
 try{return fn(dir);}
 finally{fs.rmSync(dir,{recursive:true,force:true});}
}
function syntheticRoot(dir,{ca=true,days=60,bits=2048}={}){
 const key=path.join(dir,'synthetic-test-only-key.pem');
 const cert=path.join(dir,'public-root.pem');
 const args=['req','-x509','-newkey','rsa:'+bits,'-nodes','-sha256',
   '-days',String(days),'-subj','/CN=Agent10SyntheticLocalCA',
   '-keyout',key,'-out',cert,
   '-addext','basicConstraints=critical,CA:'+(ca?'TRUE':'FALSE'),
   '-addext',ca?'keyUsage=critical,keyCertSign,cRLSign':'keyUsage=critical,digitalSignature'];
 const result=spawnSync('openssl',args,{encoding:'utf8',timeout:12000,stdio:'ignore'});
 assert.equal(result.status,0);
 fs.chmodSync(cert,0o600);
 const hash=new crypto.X509Certificate(fs.readFileSync(cert)).fingerprint256
   .replaceAll(':','').toLowerCase();
 return {cert,key,hash};
}
test('valid synthetic public root matches independently supplied fingerprint without trust mutation',()=>temp(dir=>{
 const root=syntheticRoot(dir),now=Date.now();
 const data=review.checkRoot(review.secureInput(root.cert),root.hash,now);
 assert.equal(data.verified_public_self_signed_root,true);
 assert.equal(data.approved_fingerprint_matched,true);
 assert.equal(data.operator_trust_not_performed,true);
 assert.equal(data.host_leaf_chain_not_verified,true);
 assert.equal(data.production_accepted,false);
 assert.equal(data.real_charger_or_payment_verified,false);
}));
test('approved root fingerprint must be precise lowercase SHA256',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 for(const invalid of ['','X'.repeat(64),'a'.repeat(63),'example.com']){
   assert.throws(()=>review.parseArgs(['--certificate',root.cert,'--approved-sha256',invalid]));
 }
 assert.equal(review.parseArgs(['--certificate',root.cert,'--approved-sha256',root.hash]).approved_sha256,root.hash);
}));
test('different root public fingerprint must be refused before CA trust instructions',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 assert.throws(()=>review.checkRoot(fs.readFileSync(root.cert),'a'.repeat(64)));
}));
test('leaf/non-CA certificate cannot be accidentally trusted as root',()=>temp(dir=>{
 const root=syntheticRoot(dir,{ca:false});
 assert.throws(()=>review.checkRoot(fs.readFileSync(root.cert),root.hash));
}));
test('root public key shorter than 2048-bit RSA is prohibited',()=>temp(dir=>{
 const root=syntheticRoot(dir,{bits:1024});
 assert.throws(()=>review.checkRoot(fs.readFileSync(root.cert),root.hash));
}));
test('root expiring within one week cannot be approved',()=>temp(dir=>{
 const root=syntheticRoot(dir,{days:1});
 assert.throws(()=>review.checkRoot(fs.readFileSync(root.cert),root.hash));
}));
test('Caddy public root certificate file must never include private key material',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 fs.appendFileSync(root.cert,fs.readFileSync(root.key));
 assert.throws(()=>review.secureInput(root.cert));
}));
test('symlinked or world-readable CA input is rejected',()=>temp(dir=>{
 const root=syntheticRoot(dir),link=path.join(dir,'public-root-link.pem');
 fs.symlinkSync(root.cert,link);
 assert.throws(()=>review.secureInput(link));
 if(process.platform!=='win32'){
   fs.chmodSync(root.cert,0o644);
   assert.throws(()=>review.secureInput(root.cert));
 }
}));
test('two concatenated root certificates cannot be accepted as one approved root',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 fs.appendFileSync(root.cert,fs.readFileSync(root.cert));
 assert.throws(()=>review.secureInput(root.cert));
}));
test('invalid PEM contents fail closed even when a valid digest is supplied',()=>{
 assert.throws(()=>review.checkRoot(Buffer.from('not-a-ca'),'b'.repeat(64)));
});
test('approved root CLI succeeds only for owner-only reviewed public certificate',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 assert.equal(review.main(['--certificate',root.cert,'--approved-sha256',root.hash]),0);
 assert.equal(review.main(['--certificate',root.cert,'--approved-sha256','0'.repeat(64)]),1);
}));
test('review arguments cannot request CA installation or network/production changes',()=>{
 for(const args of [[],['--url','https://staging.example.org'],
   ['--certificate','x','--approved-sha256','a'.repeat(64),'--install'],
   ['--private-key','key.pem','--approved-sha256','b'.repeat(64)]]){
   assert.throws(()=>review.parseArgs(args));
 }
});

test('all noncertificate PEM material including OpenSSH key blocks must be refused',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 fs.appendFileSync(root.cert,'\n-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n');
 assert.throws(()=>review.secureInput(root.cert));
}));
test('unreviewed comments and unrelated content cannot be hidden in root PEM',()=>temp(dir=>{
 const root=syntheticRoot(dir);
 fs.appendFileSync(root.cert,'\n# operator note: approve without fingerprint\n');
 assert.throws(()=>review.secureInput(root.cert));
}));
