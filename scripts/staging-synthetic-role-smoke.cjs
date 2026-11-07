#!/usr/bin/env node
'use strict';

// Agent 10: synthetic local-only browser session demonstration smoke.
// Logs no credentials, response bodies or account identifiers; no business mutations.
// POST login/logout only to establish/revoke synthetic sessions.
const fs=require('node:fs');
const http=require('node:http');
const path=require('node:path');
const {inspectManifest}=require('./demo-fixture-guard.cjs');

const BASE='127.0.0.1';
const PORT=8000;
const ROLES=['customer','vendor','admin'];
const MAX=64*1024;
const TIMEOUT=6000;

function args(argv) {
  if(argv.length!==2 || argv[0]!=='--manifest' || !argv[1] || argv[1].startsWith('--')) {
    throw Error('Use --manifest private synthetic access.json');
  }
  return {file:argv[1]};
}
function load(file) {
  const errors=inspectManifest(file,{allowLocalQa:false});
  if(errors.length)throw Error('Local synthetic fixture is not safe for customer demo');
  const fixture=JSON.parse(fs.readFileSync(file,'utf8'));
  if(fixture.base!=='http://127.0.0.1:8000' || fixture.qa_current_bookings)throw Error('Only normal private loopback demo');
  return fixture;
}
function readCookies(jar, headers) {
  const list=headers['set-cookie']||[];
  if(!Array.isArray(list) || list.length>16 || jar.size>8)throw Error('Excessive cookies');
  for(const raw of list) {
    if(typeof raw!=='string')throw Error('Invalid cookie');
    const first=raw.split(';')[0];
    const i=first.indexOf('=');
    if(i<1)continue;
    const name=first.slice(0,i),value=first.slice(i+1);
    if(!/^[A-Za-z0-9_-]+$/.test(name) || value.length>4096)throw Error('Invalid cookie');
    jar.set(name,value);
    if(jar.size>8)throw Error('Excessive cookie jar');
  }
}
function jsonRequest(route, method='GET', payload=undefined,jar=new Map(),client=http) {
  const allowed={
    '/api/csrf':'GET','/api/login':'POST','/api/me':'GET',
    '/api/vehicles':'GET','/api/reservations':'GET',
    '/api/admin/overview':'GET','/api/logout':'POST',
  };
  if(allowed[route]!==method || !jar || !(jar instanceof Map))throw Error('Unsupported synthetic route');
  if((method==='GET' && payload!==undefined) || (method==='POST' && route!=='/api/login' && payload!==undefined)){
    throw Error('Unexpected synthetic HTTP payload');
  }
  const body=payload===undefined?null:JSON.stringify(payload);
  if(body!==null && Buffer.byteLength(body)>8192)throw Error('Synthetic login payload too large');
  const headers={Accept:'application/json','Cache-Control':'no-store'};
  if(body!==null){headers['Content-Type']='application/json';headers['Content-Length']=Buffer.byteLength(body);}
  const cookies=[...jar].filter(([k])=>k!=='__csrf_token');
  if(cookies.length)headers.Cookie=cookies.map(([k,v])=>k+'='+v).join('; ');
  if(jar.has('__csrf_token')) headers['X-CSRF-TOKEN']=jar.get('__csrf_token');
  return new Promise((resolve,reject)=>{
    const request=client.request({host:BASE,port:PORT,path:route,method,headers,agent:false,timeout:TIMEOUT},response=>{
      const chunks=[];let size=0;
      response.on('data',chunk=>{
        size+=chunk.length;
        if(size>MAX){request.destroy(Error('Oversized synthetic response'));return;}
        chunks.push(chunk);
      });
      response.on('error',reject);
      response.on('end',()=>{
        try{
          if(response.statusCode>=300 && response.statusCode<400)throw Error('Unexpected redirect');
          readCookies(jar,response.headers);
          const text=Buffer.concat(chunks).toString('utf8');
          const result=text?JSON.parse(text):null;
          resolve({status:response.statusCode,body:result,headers:response.headers});
        }catch(error){reject(error);}
      });
    });
    request.on('error',reject);
    request.on('timeout',()=>request.destroy(Error('Local synthetic response timeout')));
    if(body!==null)request.write(body);
    request.end();
  });
}
async function checkRole(role,account,send=jsonRequest) {
  const jar=new Map();
  const csrf=await send('/api/csrf','GET',undefined,jar);
  if(csrf.status!==200 || typeof csrf.body?.token!=='string' || csrf.body.token.length<12) {
    throw Error('Synthetic CSRF bootstrap failed');
  }
  jar.set('__csrf_token',csrf.body.token);
  const login=await send('/api/login','POST',{email:account.email,password:account.password},jar);
  if(login.status!==200 || login.body?.role!==role || login.body?.email!==account.email ||
    !Number.isInteger(login.body.id))throw Error('Synthetic browser login identity mismatch');
  const me=await send('/api/me','GET',undefined,jar);
  if(me.status!==200 || me.body?.id!==login.body.id || me.body?.role!==role ||
    me.body?.email!==account.email)throw Error('Synthetic authenticated identity leaked or changed');
  // Use only authenticated, read-only feature routes. Never start a reservation/charger/payment.
  for(const route of ['/api/vehicles','/api/reservations']) {
    const data=await send(route,'GET',undefined,jar);
    if(data.status!==200 || !Array.isArray(data.body?.data) ||
      !Number.isSafeInteger(data.body?.total) || data.body.total<0 ||
      data.body.data.length>25)throw Error('Synthetic vehicle/reservation catalog unavailable');
    if(role==='customer' && route==='/api/reservations' &&
      data.body.data.some(item=>item?.user_id!==login.body.id)) {
      throw Error('Customer synthetic reservation scope leak');
    }
  }
  const overview=await send('/api/admin/overview','GET',undefined,jar);
  if(role==='admin') {
    if(overview.status!==200 || !Number.isInteger(overview.body?.users))throw Error('Synthetic Admin overview unavailable');
  } else if(overview.status!==403) {
    throw Error('Non-admin synthetic session accessed Admin scope');
  }
  const logout=await send('/api/logout','POST',undefined,jar);
  if(logout.status!==204)throw Error('Synthetic logout failed');
  const after=await send('/api/me','GET',undefined,jar);
  if(![401,403].includes(after.status))throw Error('Synthetic session remained active after logout');
  return {role,authenticated:true,read_only_features_verified:true,
    admin_authorized:role==='admin',post_logout_denied:true};
}
async function run(file,send=jsonRequest) {
  const fixture=load(file);
  const results=[];
  for(const role of ROLES)results.push(await checkRole(role,fixture.accounts[role],send));
  return {ok:true,source:'LOCAL_SYNTHETIC_ONLY',results,physical_charging_verified:false,
    real_money_moved:false,production_accepted:false};
}
async function main(argv=process.argv.slice(2),send=jsonRequest) {
  try{
    const {file}=args(argv);
    const result=await run(file,send);
    if(!result.ok)throw Error('Synthetic role smoke failed');
    process.stdout.write('PASS: private synthetic customer/vendor/Admin login, scoped Admin access and logout verified on loopback. No charging, financial or production acceptance.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: local synthetic browser demo session or role boundary needs review; no credentials or response bodies disclosed.\n');
    return 1;
  }
}
if(require.main===module)main().then(code=>{process.exitCode=code;});
module.exports={BASE,PORT,ROLES,args,load,readCookies,jsonRequest,checkRole,run,main};
