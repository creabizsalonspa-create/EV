'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const gate=require('../scripts/staging-effective-config.cjs');

function sample(){
 const appEnv={APP_ENV:'staging',APP_DEBUG:'false',APP_KEY:'base64:'+'x'.repeat(44),
  APP_URL:'https://staging.ev-example.org',DB_CONNECTION:'pgsql',DB_HOST:'database',DB_PORT:'5432',
  DB_DATABASE:'ev_business',DB_USERNAME:'ev_business',DB_PASSWORD:'synthetic-database-secret-very-long',
  SESSION_DRIVER:'database',SESSION_ENCRYPT:'true',SESSION_SECURE_COOKIE:'true',
  QUEUE_CONNECTION:'database',CSMS_SIMULATOR_ENABLED:'false',
  PAYMENT_PROVIDER:'mock',MAIL_MAILER:'log'};
 const privateMount={type:'volume',source:'ev-staging_private-storage',target:'/var/www/html/storage/app/private'};
 return {name:'ev-staging',
  volumes:Object.fromEntries(['certificates','database','gateway-config','private-storage']
    .map(name=>[name,{name:'ev-staging_'+name}])),
  networks:{default:{name:'ev-staging_default'}},
  services:{
   application:{image:'creabiz-ev-business:review',environment:{...appEnv},volumes:[privateMount]},
   worker:{image:'creabiz-ev-business:review',environment:{...appEnv},volumes:[privateMount],
     command:['php','artisan','queue:work','--sleep=3','--tries=3','--timeout=90']},
   scheduler:{image:'creabiz-ev-business:review',environment:{...appEnv},volumes:[privateMount],
     command:['php','artisan','schedule:work']},
   database:{image:'postgres:17-alpine',volumes:[{type:'volume',source:'ev-staging_database',target:'/var/lib/postgresql/data'}]},
   gateway:{image:'caddy:2-alpine',environment:{STAGING_HOST:'staging.ev-example.org',ACME_EMAIL:'ops@ev-example.org'},
     ports:[{host_ip:'127.0.0.1',published:'8443',target:443,protocol:'tcp'}],
     volumes:[{type:'volume',source:'ev-staging_certificates',target:'/data'},
       {type:'volume',source:'ev-staging_gateway-config',target:'/config'},
       {type:'bind',source:'/private/Caddyfile',target:'/etc/caddy/Caddyfile',read_only:true}]},
 }};
}
const copy=x=>JSON.parse(JSON.stringify(x));
const verify=x=>gate.assertResolved(x,()=>[]);
function fail(mut,term){
 const x=sample();mut(x);const result=verify(x);
 assert.equal(result.ok,false);
 assert.ok(result.problems.some(p=>p.includes(term)),JSON.stringify(result.problems));
}
function temp(fn){const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-effective-'));fs.chmodSync(dir,0o700);try{return fn(dir);}finally{fs.rmSync(dir,{force:true,recursive:true});}}
test('safe, resolved five-service staging Compose passes',()=>{
 const result=verify(sample());
 assert.equal(result.ok,true);
 assert.deepEqual(result.problems,[]);
});
test('outside/extra service or incorrect project fails',()=>{
 fail(x=>x.name='production','project');
 fail(x=>x.services.extra={image:'attacker:latest'},'exactly five');
});
test('app/worker/scheduler shared image and environment are mandatory',()=>{
 fail(x=>x.services.worker.image='creabiz-ev-business:older','share');
 fail(x=>x.services.worker.environment.APP_DEBUG='true','environment differs');
 fail(x=>x.services.scheduler.environment.QUEUE_CONNECTION='sync','environment differs');
});
test('application must keep staging, PostgreSQL, encrypted sessions and queue',()=>{
 for(const [key,value] of [
   ['APP_ENV','production'],['APP_DEBUG','true'],['DB_HOST','external.prod'],
   ['DB_PORT','3306'],['SESSION_SECURE_COOKIE','false'],['SESSION_ENCRYPT','false'],
   ['QUEUE_CONNECTION','sync'],['CSMS_SIMULATOR_ENABLED','true'],
 ])fail(x=>x.services.application.environment[key]=value,key);
});
test('real PSP, SMTP and alternate DB URL are refused',()=>{
 fail(x=>x.services.application.environment.DB_URL='postgresql://prod.example.org/app','DB_URL');
 fail(x=>x.services.application.environment.DATABASE_URL='postgresql://prod.example.org/app','DATABASE_URL');
 fail(x=>x.services.application.environment.PAYMENT_PROVIDER='stripe','payment provider');
 fail(x=>x.services.application.environment.MAIL_MAILER='smtp','mail');
});
test('canonical PR62 preflight failure is enforced without leaking values',()=>{
 const confidential='very_secret_do_not_print';
 const result=gate.assertResolved(sample(),()=>['DB_PASSWORD='+confidential]);
 assert.equal(result.ok,false);
 assert.equal(JSON.stringify(result.problems).includes(confidential),false);
});
test('gateway must be private loopback and certificate-ready',()=>{
 fail(x=>x.services.gateway.ports[0].host_ip='0.0.0.0','loopback');
 fail(x=>x.services.gateway.ports[0].published='443','loopback');
 fail(x=>x.services.gateway.ports.push({...x.services.gateway.ports[0]}),'loopback');
 fail(x=>delete x.services.gateway.environment.ACME_EMAIL,'certificate');
});
test('app, database and worker host port exposure refused',()=>{
 for(const service of ['application','worker','scheduler','database'])
  fail(x=>x.services[service].ports=[{host_ip:'0.0.0.0',published:8000,target:80,protocol:'tcp'}],service);
});
test('runtime image identity and protected volumes required',()=>{
 fail(x=>x.services.gateway.image='nginx:latest','Caddy');
 fail(x=>x.services.database.image='postgres:latest','PostgreSQL');
 fail(x=>x.services.application.volumes=[],'private volume');
 fail(x=>x.services.database.volumes=[],'private volume');
 fail(x=>x.services.gateway.volumes=[],'private volume');
});
test('volume and network isolation cannot inherit production data or foreign networks',()=>{
  fail(x=>x.services.database.volumes[0].source='production_database','exact project-owned');
  fail(x=>x.services.application.volumes[0].source='ev-live_private-storage','exact project-owned');
  fail(x=>x.volumes.database.name='production_database','external or remapped');
  fail(x=>x.volumes.database.external=true,'external or remapped');
  fail(x=>x.volumes.database.driver='local-zfs','external or remapped');
  fail(x=>x.volumes.database.driver_opts={device:'/var/lib/production'},'external or remapped');
  fail(x=>delete x.volumes.database,'exactly four');
  fail(x=>x.networks.default.name='production_default','project-default');
  fail(x=>x.networks.default.external=true,'project-default');
  fail(x=>x.networks.production={name:'production_default',external:true},'project-default');
  fail(x=>x.services.application.networks=['default','production'],'unreviewed network');
  fail(x=>x.services.gateway.networks={production:null},'unreviewed network');
});
test('privileged or host-network container refused',()=>{
 fail(x=>x.services.application.privileged=true,'privileged');
 fail(x=>x.services.gateway.network_mode='host','privileged');
 fail(x=>x.services.worker.cap_add=['SYS_ADMIN'],'privileged');
 fail(x=>x.services.database.devices=['/dev/kvm'],'privileged');
});
test('invalid JSON and huge snapshots reject without leaking text',()=>{
 assert.throws(()=>gate.parseResolved('garbage'));
 assert.throws(()=>gate.parseResolved('x'.repeat(2*1024*1024+1)));
 assert.throws(()=>gate.parseResolved('[]'));
});
test('private snapshot only, refuse public file and symlink',()=>temp(dir=>{
 const file=path.join(dir,'snapshot.json');
 fs.writeFileSync(file,JSON.stringify(sample()),{mode:0o600});
 assert.equal(gate.parseResolved(gate.readPrivate(file)).name,'ev-staging');
 const link=path.join(dir,'link.json');fs.symlinkSync(file,link);
 assert.throws(()=>gate.readPrivate(link));
 if(process.platform!=='win32'){fs.chmodSync(file,0o644);assert.throws(()=>gate.readPrivate(file));}
}));
test('live mode only runs read-only Docker Compose config without startup',()=>{
 const seen=[];
 const fake=(cmd,args,opts)=>{
   seen.push([cmd,...args]);
   assert.equal(cmd,'docker');
   assert.ok(args.includes('config') && args.includes('--format') && args.includes('json'));
   assert.equal(args.includes('up'),false);
   return JSON.stringify(sample());
 };
 assert.equal(gate.main(['--live'],{runner:fake,checkValues:()=>[],env:{}}),0);
 assert.equal(seen.length,1);
});
test('live mode forbids Docker remote endpoint override',()=>{
 const fake=()=>{throw Error('MUST NOT EXECUTE');};
 assert.equal(gate.main(['--live'],{runner:fake,checkValues:()=>[],env:{DOCKER_HOST:'tcp://remote:2375'}}),1);
});
test('snapshot mode verifies without Docker and does not leak secrets',()=>temp(dir=>{
 const file=path.join(dir,'snapshot.json');fs.writeFileSync(file,JSON.stringify(sample()),{mode:0o600});
 assert.equal(gate.main(['--snapshot',file],{checkValues:()=>[]}),0);
 assert.equal(gate.main(['--snapshot',file,'--execute'],{checkValues:()=>[]}),1);
}));

test('application HTTPS origin must match the actual staging certificate hostname',()=>{
  fail(x=>x.services.application.environment.APP_URL='https://other-operator.example.org','HTTPS origin does not match');
});
test('unexpected host binds, writable gateway config or runtime command overrides forbidden',()=>{
  fail(x=>x.services.database.volumes.push({type:'bind',source:'/etc',target:'/host'}),'host bind');
  fail(x=>x.services.application.volumes.push({type:'bind',source:'/secrets',target:'/app/secrets'}),'host bind');
  fail(x=>x.services.gateway.volumes.find(x=>x.type==='bind').read_only=false,'read-only Caddyfile');
  fail(x=>x.services.worker.command=['sh','-c','curl https://remote.example.org'],'command differs');
  fail(x=>x.services.scheduler.command=['sh','-c','arbitrary command'],'command differs');
  fail(x=>x.services.application.entrypoint=['sh'],'entrypoint');
  fail(x=>x.services.gateway.network_mode='container:production','privileged');
  fail(x=>x.services.database.security_opt=['seccomp=unconfined'],'privileged');
});
