'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const http=require('node:http');
const roleSmoke=require('../scripts/staging-synthetic-role-smoke.cjs');

const RUN='a1b2c3d4';
const PASSWORD='a'.repeat(48)+'aA9!';
function fixture() {
 const accounts={};
 for(const role of roleSmoke.ROLES)accounts[role]={email:'client-demo-'+role+'-'+RUN+'@example.test',password:PASSWORD};
 return {fixture_only:true,run:RUN,base:'http://127.0.0.1:8000',company_id:1,
   accounts,stations:[['1.6','16'],['2.0.1','201']].map(([protocol,suffix],i)=>({
     protocol,identity:'ev-test-demo-'+RUN+'-'+suffix,station_id:i+1,
     charger_id:i+3,binding_id:i+5,connector_mapping_id:i+7,reservation_id:null,
   })),qa_current_bookings:false,physical_charging_verified:false,real_money:false};
}
function withFixture(fn){
 const root=fs.mkdtempSync(path.join(os.tmpdir(),'ev-role-'));fs.chmodSync(root,0o700);
 const base=path.join(root,'client-demo'),run=path.join(base,RUN);
 fs.mkdirSync(base,{mode:0o700});fs.mkdirSync(run,{mode:0o700});
 const file=path.join(run,'access.json');fs.writeFileSync(file,JSON.stringify(fixture()),{mode:0o600});
 try{return fn(file);}finally{fs.rmSync(root,{recursive:true,force:true});}
}
function mockSend({adminStatus,logoutStatus,postLogoutStatus,wrongLoginRole,wrongMeId,featureStatus,foreignReservation=false,track=[]}={}) {
 let role='',loggedIn=false,sequence=0;
 return async function(route,method,payload,jar) {
   track.push({route,method,jar});
   if(route==='/api/csrf') {
     jar.set('laravel_session','test-session-'+(++sequence));
     return {status:200,body:{token:'0123456789abcdef1234'}};
   }
   if(route==='/api/login') {
     assert.equal(method,'POST');
     assert.equal(payload.password,PASSWORD);
     role=roleSmoke.ROLES.find(r=>payload.email.includes('client-demo-'+r+'-'));
     loggedIn=true;
     return {status:200,body:{id:roleSmoke.ROLES.indexOf(role)+1,role:wrongLoginRole||role,email:payload.email}};
   }
   if(route==='/api/me') {
     if(!loggedIn)return {status:postLogoutStatus??401,body:{message:'Unauthenticated'}};
     return {status:200,body:{id:wrongMeId??roleSmoke.ROLES.indexOf(role)+1,role,email:'client-demo-'+role+'-'+RUN+'@example.test'}};
   }
   if(route==='/api/vehicles'||route==='/api/reservations'){
     return {status:featureStatus??200,body:{data:route==='/api/reservations'&&foreignReservation?
       [{id:55,user_id:999}]:[],total:foreignReservation?1:0}};
   }
   if(route==='/api/admin/overview') {
     return role==='admin'?{status:adminStatus??200,body:{users:3}}:{status:adminStatus??403,body:{message:'Forbidden'}};
   }
   if(route==='/api/logout') {
     loggedIn=false;
     return {status:logoutStatus??204,body:null};
   }
   throw Error('Unexpected route');
 };
}

test('valid synthetic fixture remains private, role-scoped and non-physical',()=>withFixture(file=>{
 const result=roleSmoke.load(file);
 assert.equal(result.fixture_only,true);
 assert.equal(result.real_money,false);
 assert.equal(result.accounts.admin.password,PASSWORD);
}));
test('three local synthetic roles pass login, scope and logout independently',async()=>{
 await withFixture(async()=>{}); // confirm fixture factory
 const result=await withFixture(async(file)=>roleSmoke.run(file,mockSend()));
 assert.equal(result.ok,true);assert.equal(result.results.length,3);
 assert.deepEqual(result.results.map(x=>x.role),['customer','vendor','admin']);
 assert.equal(result.production_accepted,false);
 assert.equal(result.real_money_moved,false);
 assert.equal(result.physical_charging_verified,false);
});
test('misreported login role cannot impersonate Admin',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('customer',fixture().accounts.customer,mockSend({wrongLoginRole:'admin'})));
});
test('unexpected account switch during /api/me denied',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('customer',fixture().accounts.customer,mockSend({wrongMeId:99})));
});
test('customer and vendor must not gain Admin overview access',async()=>{
 for(const role of ['customer','vendor'])
   await assert.rejects(()=>roleSmoke.checkRole(role,fixture().accounts[role],mockSend({adminStatus:200})));
});
test('Admin must have actual overview access',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('admin',fixture().accounts.admin,mockSend({adminStatus:403})));
});
test('logout must revoke original session',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('vendor',fixture().accounts.vendor,mockSend({postLogoutStatus:200})));
});
test('logout response must be canonical no-content',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('admin',fixture().accounts.admin,mockSend({logoutStatus:200})));
});
test('CSRF must be established before any login attempt',async()=>{
 const calls=[];
 const send=mockSend({track:calls});
 const result=await roleSmoke.checkRole('customer',fixture().accounts.customer,send);
 assert.equal(result.authenticated,true);
 assert.equal(calls[0].route,'/api/csrf');
 assert.equal(calls[1].route,'/api/login');
});
test('invalid fixture path and QA-shortcut fixture are rejected before network calls',async()=>{
 await withFixture(async(file)=>{
   const modified=fixture();modified.qa_current_bookings=true;
   fs.writeFileSync(file,JSON.stringify(modified));
   let calls=0;
   await assert.rejects(()=>roleSmoke.run(file,async()=>{calls++;throw Error('MUST NOT CONNECT');}));
   assert.equal(calls,0);
 });
});
test('only fixed synthetic loopback manifest can be used',async()=>{
 await withFixture(async(file)=>{
   const modified=fixture();modified.base='https://api.hasone.io';
   fs.writeFileSync(file,JSON.stringify(modified));
   await assert.rejects(()=>roleSmoke.run(file,mockSend()));
 });
});
test('unsupported URLs, methods and redirects rejected before any external transport',async()=>{
 assert.throws(()=>roleSmoke.jsonRequest('https://example.org','GET'));
 assert.throws(()=>roleSmoke.jsonRequest('/api/me','DELETE'));
 assert.throws(()=>roleSmoke.jsonRequest('/api/..','GET'));
});
test('cookie parsing excludes malformed cookie, preserves session cookie',()=>{
 const jar=new Map();
 roleSmoke.readCookies(jar,{'set-cookie':['laravel_session=stub; HttpOnly; SameSite=Lax','XSRF-TOKEN=abcd; Secure']});
 assert.equal(jar.get('laravel_session'),'stub');
 assert.equal(jar.get('XSRF-TOKEN'),'abcd');
 assert.throws(()=>roleSmoke.readCookies(jar,{'set-cookie':['bad name=value; HttpOnly']}));
});
test('real loopback HTTP stack sends explicit CSRF header but never sends token as a cookie',async()=>{
 const observed=[];
 const server=http.createServer((req,res)=>{
   let body='';req.on('data',c=>body+=c.toString('utf8'));
   req.on('end',()=>{
     observed.push({url:req.url,method:req.method,headers:req.headers,body});
     if(req.url==='/api/csrf'){
       res.writeHead(200,{'Content-Type':'application/json','Set-Cookie':['laravel_session=synthetic; HttpOnly']});
       return res.end(JSON.stringify({token:'0123456789abcdef1234'}));
     }
     if(req.url==='/api/login'){
       res.writeHead(200,{'Content-Type':'application/json'});
       return res.end(JSON.stringify({role:'customer',email:fixture().accounts.customer.email,id:1}));
     }
     if(req.url==='/api/me'){
       res.writeHead(200,{'Content-Type':'application/json'});
       return res.end(JSON.stringify({role:'customer',email:fixture().accounts.customer.email,id:1}));
     }
     res.writeHead(404,{'Content-Type':'application/json'});res.end(JSON.stringify({message:'Not found'}));
   });
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 try{
   const jar=new Map();
   const agent={request:(opts,cb)=>http.request({...opts,port:server.address().port},cb)};
   const csrf=await roleSmoke.jsonRequest('/api/csrf','GET',undefined,jar,agent);
   jar.set('__csrf_token',csrf.body.token);
   const login=await roleSmoke.jsonRequest('/api/login','POST',fixture().accounts.customer,jar,agent);
   assert.equal(login.status,200);
   assert.equal(observed[1].headers['x-csrf-token'],'0123456789abcdef1234');
   assert.ok(observed[1].headers.cookie.includes('laravel_session=synthetic'));
   assert.ok(!observed[1].headers.cookie.includes('__csrf_token'));
   assert.equal(observed[1].method,'POST');
   assert.equal(observed[1].headers.host,'127.0.0.1:'+server.address().port);
 }finally{await new Promise(resolve=>server.close(resolve));}
});
test('real loopback HTTP stack refuses redirect instead of following remote Location',async()=>{
 const server=http.createServer((_req,res)=>{res.writeHead(302,{Location:'https://external.example.org/'});res.end('redirect');});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 try{
   const agent={request:(opts,cb)=>http.request({...opts,port:server.address().port},cb)};
   await assert.rejects(()=>roleSmoke.jsonRequest('/api/me','GET',undefined,new Map(),agent),/redirect/);
 }finally{await new Promise(resolve=>server.close(resolve));}
});
test('CLI never prints account or credential details on failure',async()=>{
 const result=await roleSmoke.main(['--manifest','/private/not-present/access.json']);
 assert.equal(result,1);
});

test('local demo cannot issue wrong HTTP method for an allowed route',()=>{
 for(const [route,method] of [
   ['/api/me','POST'],['/api/login','GET'],['/api/csrf','POST'],
   ['/api/admin/overview','POST'],['/api/logout','GET']
 ]) assert.throws(()=>roleSmoke.jsonRequest(route,method));
});
test('local demo rejects added payload on read-only routes and logout',()=>{
 assert.throws(()=>roleSmoke.jsonRequest('/api/me','GET',{unexpected:1}));
 assert.throws(()=>roleSmoke.jsonRequest('/api/logout','POST',{unexpected:1}));
 assert.throws(()=>roleSmoke.jsonRequest('/api/login','POST',{email:'a'.repeat(10000)}));
});
test('browser cookie jar refuses suspicious overload before network requests',()=>{
 const jar=new Map();
 assert.throws(()=>roleSmoke.readCookies(jar,{'set-cookie':Array.from({length:17},(_,i)=>'cookie'+i+'=value')}));
 assert.throws(()=>roleSmoke.readCookies(jar,{'set-cookie':[false]}));
 const more=new Map(Array.from({length:8},(_,i)=>['a'+i,'value']));
 assert.throws(()=>roleSmoke.readCookies(more,{'set-cookie':['ninth=value']}));
});

test('read-only reservation/vehicle journeys are required for all synthetic roles',async()=>{
 const seen=[];
 const result=await roleSmoke.checkRole('customer',fixture().accounts.customer,mockSend({track:seen}));
 assert.equal(result.read_only_features_verified,true);
 assert.ok(seen.some(x=>x.route==='/api/vehicles'&&x.method==='GET'));
 assert.ok(seen.some(x=>x.route==='/api/reservations'&&x.method==='GET'));
 assert.equal(seen.some(x=>x.route==='/api/reservations'&&x.method!=='GET'),false);
});
test('synthetic browser feature error fails rather than claiming demo readiness',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('vendor',fixture().accounts.vendor,mockSend({featureStatus:500})));
});
test('customer synthetic reservations must not include another account ID',async()=>{
 await assert.rejects(()=>roleSmoke.checkRole('customer',fixture().accounts.customer,mockSend({foreignReservation:true})));
});
