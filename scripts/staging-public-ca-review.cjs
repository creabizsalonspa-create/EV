#!/usr/bin/env node
'use strict';
// Agent 10 offline ONLY: inspect the PUBLIC root certificate of a private staging CA.
// Does not import it, modify trust, start TLS, issue a cert, connect to networks or expose keys.
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');

const SHA=/^[a-f0-9]{64}$/;
const MAX_BYTES=128*1024;
const MIN_DAYS=7;
function parseArgs(argv){
  if(!Array.isArray(argv)||argv.length!==4||argv[0]!=='--certificate'||
    !argv[1]||argv[1].startsWith('--')||argv[2]!=='--approved-sha256'||
    typeof argv[3]!=='string'||!SHA.test(argv[3])){
    throw Error('Use --certificate PRIVATE_ROOT_PUBLIC_PEM --approved-sha256 exact-reviewed-64-hex-fingerprint');
  }
  return {certificate:argv[1],approved_sha256:argv[3]};
}
function secureInput(file){
  const absolute=path.resolve(file);
  const st=fs.lstatSync(absolute),parent=fs.lstatSync(path.dirname(absolute));
  if(!st.isFile()||st.isSymbolicLink()||st.size<64||st.size>MAX_BYTES ||
    !parent.isDirectory()||parent.isSymbolicLink()||
    (process.platform!=='win32'&&((st.mode&0o077)!==0||(parent.mode&0o077)!==0))){
    throw Error('Staging public root certificate must come from reviewed owner-only file');
  }
  const bytes=fs.readFileSync(absolute);
  const text=bytes.toString('utf8');
  if(!/^\s*-----BEGIN CERTIFICATE-----[A-Za-z0-9+/=\s]+-----END CERTIFICATE-----\s*$/.test(text) ||
    (text.match(/-----BEGIN CERTIFICATE-----/g)||[]).length!==1){
    // Do not allow ANY second PEM block, private key flavor, comment or hidden content.
    throw Error('Expected only one public root PEM with no private key or extra materials');
  }
  return bytes;
}
function checkRoot(pem,expectedSha,now=Date.now()){
  if(typeof expectedSha!=='string'||!SHA.test(expectedSha)||!Number.isFinite(now))
    throw Error('Independent reviewed root fingerprint is required');
  const cert=new crypto.X509Certificate(pem);
  const fingerprint=cert.fingerprint256.replaceAll(':','').toLowerCase();
  if(fingerprint!==expectedSha)throw Error('Public root fingerprint differs from approved value');
  if(cert.ca!==true || cert.subject!==cert.issuer ||
    !cert.verify(cert.publicKey))throw Error('Supplied PEM is not a self-signed CA root');
  const from=Date.parse(cert.validFrom),until=Date.parse(cert.validTo);
  if(!Number.isFinite(from)||!Number.isFinite(until)||
    from>now || until<=now+MIN_DAYS*86400000){
    throw Error('Private root CA is expired, not yet valid or expires too soon');
  }
  const key=cert.publicKey,kind=key.asymmetricKeyType,details=key.asymmetricKeyDetails||{};
  if(!(kind==='rsa'&&details.modulusLength>=2048) &&
     !(kind==='ec'&&['prime256v1','secp384r1','secp521r1'].includes(details.namedCurve))) {
    throw Error('Unreviewed or too-weak root CA public key');
  }
  return {fingerprint_sha256:fingerprint,verified_public_self_signed_root:true,
    approved_fingerprint_matched:true,operator_trust_not_performed:true,
    host_leaf_chain_not_verified:true,real_charger_or_payment_verified:false,
    production_accepted:false};
}
function review(args,dependencies={}){
  const {certificate,approved_sha256}=parseArgs(args);
  return checkRoot(secureInput(certificate),approved_sha256,
    dependencies.now===undefined?Date.now():dependencies.now);
}
function main(argv=process.argv.slice(2),dependencies={}){
  try{
    const report=review(argv,dependencies);
    process.stdout.write('PASS: private staging public CA root fingerprint '+report.fingerprint_sha256+
      ' matches independent approval; no trust installation, TLS leaf validation, hardware or production acceptance.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: private staging public CA root failed file, fingerprint, signature, key or expiry review; details withheld.\n');
    return 1;
  }
}
if(require.main===module)process.exitCode=main();
module.exports={parseArgs,secureInput,checkRoot,review,main};
