import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {loopbackBase,relayAdminRequest} from '../../../tools/agent2/isolatedAdminApiRelay.mjs';
const ui='http://127.0.0.1:3443',token='isolated-test-only';
test('relay accepts only explicit loopback origins without credentials or paths',()=>{
 for(const value of ['https://api.hasone.io','http://localhost:3443',
   'http://127.0.0.1','http://u:p@127.0.0.1:3443','http://127.0.0.1:3443/path',
   'http://127.0.0.1:3443?redirect=x']){
   assert.throws(()=>loopbackBase(value));
 }
 assert.equal(loopbackBase(ui),ui);
});
test('actual relay preserves API status/body and forwards no browser cookies',async()=>{
 let observed;
 const server=createServer(async(req,res)=>{
   let body='';for await(const part of req)body+=part;
   observed={url:req.url,method:req.method,authorization:req.headers.authorization,
     cookie:req.headers.cookie,body};
   res.writeHead(409,{'content-type':'application/json','set-cookie':'not-forwarded=x'});
   res.end(JSON.stringify({status:'fail',message:'Actual fixture server response'}));
 });
 await new Promise(done=>server.listen(0,'127.0.0.1',done));
 try{
   const api='http://127.0.0.1:'+server.address().port;
   const path='/api/v1/admin/bookings/507f1f77bcf86cd799439011/ocpp/issue-token';
   const result=await relayAdminRequest({url:ui+path,method:'POST',
     authorization:'Bearer '+token,body:'{"chargerId":"test"}'},
     {uiUrl:ui,apiUrl:api,adminToken:token});
   assert.equal(result.status,409);
   assert.equal(JSON.parse(result.body).message,'Actual fixture server response');
   assert.deepEqual(observed,{url:path,method:'POST',authorization:'Bearer '+token,
     cookie:undefined,body:'{"chargerId":"test"}'});
   assert.equal(result.headers['set-cookie'],undefined);
   assert.equal(result.headers['cache-control'],'no-store');
 }finally{await new Promise(done=>server.close(done));}
});
test('relay rejects wrong origin, wrong identity and non-test mutation routes before transport',async()=>{
 const options={uiUrl:ui,apiUrl:'http://127.0.0.1:1',adminToken:token};
 const valid={url:ui+'/api/v1/admin/bookings',method:'GET',authorization:'Bearer '+token};
 for(const request of [
   {...valid,url:'http://127.0.0.1:3444/api/v1/admin/bookings'},
   {...valid,authorization:'Bearer foreign'},
   {...valid,method:'DELETE'},
   {...valid,url:ui+'/api/v1/payment/stripe/webhook',method:'POST'},
   {...valid,url:ui+'/api/v1/admin/bookings/507f1f77bcf86cd799439011/ocpp/reissue-token',method:'POST'}
 ])await assert.rejects(relayAdminRequest(request,options));
});
test('actual relay never follows API redirects',async()=>{
 let followed=false;
 const server=createServer((req,res)=>{
   if(req.url==='/unexpected'){followed=true;res.end('bad');return;}
   res.writeHead(302,{location:'/unexpected'});res.end();
 });
 await new Promise(done=>server.listen(0,'127.0.0.1',done));
 try{
   await assert.rejects(relayAdminRequest({url:ui+'/api/v1/admin/bookings',method:'GET',
     authorization:'Bearer '+token},{uiUrl:ui,apiUrl:'http://127.0.0.1:'+server.address().port,
       adminToken:token}),/redirect refused/);
   assert.equal(followed,false);
 }finally{await new Promise(done=>server.close(done));}
});

