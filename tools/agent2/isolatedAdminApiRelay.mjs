/**
 * Local test transport: forward actual Admin HTTP calls to the disposable
 * Express app. No fixtures, redirects, cookies or production origins.
 */
export function loopbackBase(value){
 const url=new URL(value);
 if(url.protocol!=='http:' || url.hostname!=='127.0.0.1' || !url.port ||
    url.username || url.password || url.pathname!=='/' || url.search || url.hash)
   throw Error('An explicit loopback-only HTTP origin is required');
 return url.origin;
}
export async function relayAdminRequest(request,{apiUrl,uiUrl,adminToken}){
 const api=loopbackBase(apiUrl),ui=loopbackBase(uiUrl);
 const url=new URL(request.url);
 if(url.origin!==ui || typeof adminToken!=='string' || !adminToken ||
    request.authorization!=='Bearer '+adminToken)
   throw Error('Unapproved isolated Admin request');
 const get=request.method==='GET' && (
   url.pathname==='/api/v1/admin/bookings' ||
   /^\/api\/v1\/admin\/bookings\/[a-f0-9]{24}\/ocpp\/operations$/i.test(url.pathname) ||
   /^\/api\/v1\/admin\/stations\/[a-f0-9]{24}\/ocpp\/(booking-sources|start-exceptions)$/i.test(url.pathname));
 const post=request.method==='POST' &&
   /^\/api\/v1\/admin\/bookings\/[a-f0-9]{24}\/ocpp\/(issue-token|bind-source|finalize-source)$/i.test(url.pathname);
 if(!get && !post)throw Error('Non-test Admin operation blocked');
 const response=await fetch(api+url.pathname+url.search,{
   method:request.method,redirect:'manual',signal:AbortSignal.timeout(10000),
   headers:{authorization:request.authorization,'content-type':'application/json'},
   ...(post?{body:request.body??'{}'}:{})
 });
 if(response.status>=300 && response.status<400)
   throw Error('Isolated API redirect refused');
 return {status:response.status,
   headers:{'content-type':response.headers.get('content-type')||'application/json',
     'cache-control':'no-store'},body:await response.text()};
}

