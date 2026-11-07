#!/usr/bin/env node
'use strict';
// Agent 10: inspect fully resolved (but still private) Docker Compose staging config.
// Never prints interpolated secrets or changes container/service state.
const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');

const project='ev-staging',expected=['application','worker','scheduler','database','gateway'];
const PINNED_DATABASE_IMAGE='postgres:17-alpine@sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b';
const scriptRoot=path.resolve(__dirname,'..');
const compose=path.join(scriptRoot,'infra/staging/compose.yaml');
const envFile=path.join(scriptRoot,'infra/staging/.env');
const LIMIT=2*1024*1024;

function parseResolved(text) {
  if(typeof text!=='string' || !text || Buffer.byteLength(text)>LIMIT)
    throw Error('Invalid or oversized effective Compose config');
  const parsed=JSON.parse(text);
  if(!parsed || typeof parsed!=='object' || Array.isArray(parsed))throw Error('Compose config must be an object');
  return parsed;
}
function normalizedEnvironment(service) {
  const env=service?.environment;
  if(!env || typeof env!=='object' || Array.isArray(env))throw Error('Resolved service environment missing');
  return env;
}
function canonical(value) {
  return JSON.stringify(Object.fromEntries(Object.entries(value).sort(([a],[b])=>a.localeCompare(b))));
}
function assertResolved(input,configCheck) {
  const problems=[];
  if(input.name!==project)problems.push('Compose project name changed');
  const services=input.services;
  if(!services || typeof services!=='object' || Array.isArray(services) ||
    Object.keys(services).sort().join(',')!==[...expected].sort().join(',')) {
    return {ok:false,problems:['Expected exactly five staging services']};
  }
  const app=services.application,worker=services.worker,scheduler=services.scheduler,
    database=services.database,gateway=services.gateway;
  const image=app.image;
  if(typeof image!=='string'||!/^creabiz-ev-business:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/.test(image) ||
    worker.image!==image || scheduler.image!==image)problems.push('Application roles must share a valid review image tag');
  if(database.image!==PINNED_DATABASE_IMAGE) {
    problems.push('Database image must match reviewed pinned PostgreSQL digest');
  }
  if(!/^caddy:2-alpine(?:@sha256:[a-f0-9]{64})?$/.test(gateway.image||''))problems.push('Gateway must be Caddy 2 Alpine');
  const envs={};
  for(const name of ['application','worker','scheduler']) {
    try{envs[name]=normalizedEnvironment(services[name]);}
    catch{problems.push(name+': unresolved environment');}
  }
  if(envs.application) {
    const env=envs.application;
    for(const [key,wanted] of [
      ['APP_ENV','staging'],['APP_DEBUG','false'],['DB_CONNECTION','pgsql'],
      ['DB_HOST','database'],['DB_PORT','5432'],['DB_DATABASE','ev_business'],
      ['DB_USERNAME','ev_business'],['SESSION_DRIVER','database'],
      ['SESSION_ENCRYPT','true'],['SESSION_SECURE_COOKIE','true'],
      ['QUEUE_CONNECTION','database'],['CSMS_SIMULATOR_ENABLED','false']
    ]) {
      if(String(env[key])!==wanted)problems.push('application: reviewed '+key+' setting changed');
    }
    if(typeof env.APP_URL!=='string'||!env.APP_URL.startsWith('https://'))problems.push('application: HTTPS origin missing');
    if(env.APP_URL!=='https://'+gateway.environment?.STAGING_HOST+':8443') {
      problems.push('application: HTTPS origin must match private staging hostname and port 8443');
    }
    if(typeof env.DB_PASSWORD!=='string'||env.DB_PASSWORD.length<24)problems.push('application: private database password missing');
    if(typeof env.APP_KEY!=='string'||!env.APP_KEY.startsWith('base64:'))problems.push('application: Laravel application key missing');
    if(env.DB_URL && env.DB_URL!=='null')problems.push('application: DB_URL may override the isolated database');
    if(env.DATABASE_URL && env.DATABASE_URL!=='null')problems.push('application: DATABASE_URL may override the isolated database');
    if(env.PAYMENT_PROVIDER && env.PAYMENT_PROVIDER!=='mock')problems.push('application: real payment provider is disabled');
    if(env.MAIL_MAILER && env.MAIL_MAILER!=='log')problems.push('application: direct outbound mail is disabled');
    if(typeof configCheck==='function') {
      // Pass only a strictly constrained app+gateway view to the canonical PR #62 preflight.
      // Never include raw validation values in errors.
      const contextual={...env,
        STAGING_HOST:gateway.environment?.STAGING_HOST,
        ACME_EMAIL:gateway.environment?.ACME_EMAIL,
      };
      const checks=configCheck(contextual);
      if(!Array.isArray(checks) || checks.length)problems.push('application: sensitive staging isolation preflight failed');
    }
    if(envs.worker && canonical(envs.worker)!==canonical(env))problems.push('worker: environment differs from application');
    if(envs.scheduler && canonical(envs.scheduler)!==canonical(env))problems.push('scheduler: environment differs from application');
  }
  function boundPorts(service,name,expectedGateway=false) {
    const ports=service?.ports || [];
    if(!Array.isArray(ports)) {problems.push(name+': invalid resolved ports');return;}
    if(!expectedGateway && ports.length)problems.push(name+': internal service published on host');
    if(expectedGateway && (ports.length!==1 ||
      ports[0]?.host_ip!=='127.0.0.1' ||
      Number(ports[0]?.published)!==8443 ||
      Number(ports[0]?.target)!==443 ||
      ports[0]?.protocol!=='tcp'))problems.push('gateway: must bind exactly private loopback 8443/TCP');
  }
  for(const [name,service] of Object.entries(services)) {
    boundPorts(service,name,name==='gateway');
    if(service.privileged===true || Boolean(service.network_mode) ||
      service.pid==='host' || service.ipc==='host' || service.userns_mode==='host' ||
      (Array.isArray(service.devices)&&service.devices.length) ||
      (Array.isArray(service.cap_add)&&service.cap_add.length) ||
      (Array.isArray(service.security_opt)&&service.security_opt.some(x=>/unconfined/i.test(String(x))))) {
      problems.push(name+': privileged or host networking forbidden');
    }
  }
  // Reject a project-external named volume even if it ends with _database or
  // _private-storage: accidentally mounting a live dataset into a demo must fail.
  function privateVolume(service,name,target,suffix) {
    const volumes=service?.volumes || [];
    if(!Array.isArray(volumes)||!volumes.some(v=>v&&v.type==='volume'&&
      v.target===target&&v.source===project+'_'+suffix)) {
      problems.push(name+': expected exact project-owned persistent private volume missing');
    }
  }
  const expectedVolumes=['certificates','database','gateway-config','private-storage'];
  const declaredVolumes=input.volumes;
  if(!declaredVolumes || typeof declaredVolumes!=='object' ||
    Array.isArray(declaredVolumes) ||
    Object.keys(declaredVolumes).sort().join(',')!==expectedVolumes.join(',')) {
    problems.push('Staging Compose must declare exactly four project-owned persistent volumes');
  } else {
    for(const name of expectedVolumes) {
      const entry=declaredVolumes[name];
      if(!entry || typeof entry!=='object' || Array.isArray(entry) ||
        entry.external===true || entry.name!==project+'_'+name ||
        (entry.driver && entry.driver!=='local') ||
        (entry.driver_opts && Object.keys(entry.driver_opts).length)) {
        problems.push('Staging volume '+name+' may not reference an external or remapped dataset');
      }
    }
  }
  const networks=input.networks;
  if(!networks || typeof networks!=='object' || Array.isArray(networks) ||
    Object.keys(networks).join(',')!=='default' ||
    !networks.default || networks.default.external===true ||
    networks.default.name!==project+'_default' ||
    (networks.default.driver && networks.default.driver!=='bridge')) {
    problems.push('Only the isolated project-default network is permitted');
  }
  for(const [name,service] of Object.entries(services)) {
    const attached=service.networks;
    if(attached!==undefined && attached!==null) {
      const names=Array.isArray(attached)?attached:Object.keys(attached);
      if(names.length!==1 || names[0]!=='default' ||
        (!Array.isArray(attached) && attached.default &&
         Object.keys(attached.default).length)) {
        problems.push(name+': attached to an unreviewed network');
      }
    }
  }
  for(const role of ['application','worker','scheduler'])
    privateVolume(services[role],role,'/var/www/html/storage/app/private','private-storage');
  privateVolume(database,'database','/var/lib/postgresql/data','database');
  privateVolume(gateway,'gateway','/data','certificates');
  privateVolume(gateway,'gateway','/config','gateway-config');
  // Restrict all runtime mounts: no surprise host paths to credentials or other containers.
  for(const role of ['application','worker','scheduler','database']) {
    const mounts=services[role].volumes;
    if(!Array.isArray(mounts) || mounts.length!==1 || mounts[0]?.type!=='volume') {
      problems.push(role+': unexpected runtime mount or host bind');
    }
  }
  const gatewayMounts=gateway.volumes;
  if(!Array.isArray(gatewayMounts) || gatewayMounts.length!==3 ||
    !gatewayMounts.some(x=>x?.type==='bind'&&x.target==='/etc/caddy/Caddyfile'&&x.read_only===true) ||
    gatewayMounts.some(x=>x?.type==='bind'&&x.target!=='/etc/caddy/Caddyfile')) {
    problems.push('gateway: only a read-only Caddyfile host bind is permitted');
  }
  if(app.command || app.entrypoint || worker.entrypoint || scheduler.entrypoint ||
    database.entrypoint || gateway.entrypoint)problems.push('Unexpected runtime entrypoint or app command override');
  if(JSON.stringify(worker.command)!==JSON.stringify(['php','artisan','queue:work','--sleep=3','--tries=3','--timeout=90']) ||
    JSON.stringify(scheduler.command)!==JSON.stringify(['php','artisan','schedule:work'])) {
    problems.push('Worker/scheduler command differs from reviewed application lifecycle');
  }
  if(!gateway.environment || gateway.environment.STAGING_HOST===undefined ||
    gateway.environment.ACME_EMAIL===undefined)problems.push('gateway: certificate hostname/contact missing');
  return {ok:!problems.length,problems};
}
function readPrivate(file) {
  const f=fs.lstatSync(file),d=fs.lstatSync(path.dirname(path.resolve(file)));
  if(!f.isFile()||f.isSymbolicLink()||f.size===0||f.size>LIMIT ||
    !d.isDirectory()||d.isSymbolicLink()||
    (process.platform!=='win32'&&((f.mode&0o077)!==0||(d.mode&0o077)!==0)))throw Error('Private Compose snapshot invalid');
  return fs.readFileSync(file,'utf8');
}
function liveConfig(run=execFileSync,environment=process.env) {
  // Compose config only resolves YAML. It never starts services or connects to a daemon.
  if(environment.DOCKER_HOST || (environment.DOCKER_CONTEXT && environment.DOCKER_CONTEXT!=='default'))
    throw Error('Remote Docker environment prohibited');
  return String(run('docker',['compose','--project-name',project,'-f',compose,
    '--env-file',envFile,'config','--format','json'],{
      encoding:'utf8',timeout:15000,maxBuffer:LIMIT,env:environment,
    }));
}
function runReview(source,checker) {
  const validated=assertResolved(parseResolved(source),checker);
  if(!validated.ok)throw Error(validated.problems.join(', '));
  return validated;
}
function main(args=process.argv.slice(2),deps={}) {
  try {
    let source;
    if(args.length===1 && args[0]==='--live')source=liveConfig(deps.runner||execFileSync,deps.env||process.env);
    else if(args.length===2 && args[0]==='--snapshot' && args[1] && !args[1].startsWith('--')) source=readPrivate(args[1]);
    else throw Error('Expected --live or --snapshot path');
    let checker=deps.checkValues;
    if(!checker) {
      const file=path.join(__dirname,'staging-preflight.cjs');
      if(!fs.existsSync(file))throw Error('Canonical staging isolation guard not merged');
      checker=require(file).checkValues;
    }
    runReview(source,checker);
    process.stdout.write('PASS: effective private Compose environment, network ports, service roles and named volumes verified; no secrets printed.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: resolved private staging Compose settings are unsafe or unavailable; source values withheld.\n');
    return 1;
  }
}
if(require.main===module)process.exitCode=main();
module.exports={parseResolved,normalizedEnvironment,assertResolved,readPrivate,liveConfig,runReview,main};
