// Existing local source fixture and actual canonical API. No upstream requests,
// source edits, charger commands or accounting writes beyond synthetic setup.
const {execFileSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-native-catalog-'));
 try{
  const password=randomBytes(24).toString('hex')+'Aa9!',run=randomBytes(4).toString('hex');
  const fixture=JSON.parse(execFileSync('./scripts/php',['tests/Fixtures/partner-catalog-observations.php',run,password],{encoding:'utf8',stdio:['ignore','pipe','pipe']}));assert.equal(fixture.fixture_only,true);
  const input=path.join(dir,'fixture.json');fs.writeFileSync(input,JSON.stringify({...fixture,password,base:'http://127.0.0.1:8000'}),{mode:0o600});
  execFileSync('flutter',['test','--no-pub','test/live_ocpi_catalog_observations_test.dart','--reporter','expanded'],{cwd:'apps/mobile',stdio:'inherit',env:{...process.env,EV_NATIVE_CATALOG_OBSERVATIONS:input}});
  console.log('PASS actual Laravel/native stored country observations for all/locations/tariffs and private customer denial; synthetic fixture only, no partner request, charging or finance action.');
 }finally{fs.rmSync(dir,{recursive:true,force:true});}
})().catch(()=>{console.error('Native catalog observations failed; private fixture/credentials withheld.');process.exitCode=1;});
