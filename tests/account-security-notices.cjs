'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element{
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.text='';}
 append(...nodes){this.children.push(...nodes);}
 replaceChildren(...nodes){this.children=nodes;}
 set textContent(value){this.text=String(value);this.children=[];}
 get textContent(){return this.text+this.children.map(n=>n.textContent||'').join('');}
}
const source=fs.readFileSync('services/business-api/public/platform.js','utf8');
const actual=source.slice(source.indexOf('async function refreshInbox() {'),source.indexOf('async function ticketDetail('));
for(const ar of [false,true])test('actual private inbox renders security events safely in '+(ar?'Arabic':'English'),async()=>{
 const window={};vm.runInNewContext(fs.readFileSync('services/business-api/public/locale-data.js','utf8'),{window});
 const root=new Element('section'),calls=[],rows=['enabled','disabled','recovery_rotated','recovery_used'].map((event,index)=>({id:index+1,kind:'account.security.'+event,read_at:null,metadata:{secret:'PRIVATE-RECOVERY-CODE',html:'<img src=x>'}}));
 const context={client:{sessionEpoch:1},user:{id:7,role:'customer'},inboxGeneration:0,inboxPage:1,inboxUnreadOnly:false,inboxCategory:'all',inboxPreferencesFor:null,inboxPreferences:null,document:{createElement:tag=>new Element(tag)},el:()=>root,EvLocale:{attribute(){}},api:async(path,method)=>{calls.push({path,method});return path==='notifications/summary'?{unread_count:0,latest_id:4}:{data:rows,current_page:1,last_page:1};},ui:(tag,key)=>{const n=new Element(tag);n.textContent=window.EV_MESSAGES[key]?.[ar?'ar':'en']||key;return n;}};
 vm.runInNewContext(actual,context);await context.refreshInbox();
 assert.equal(root.children.filter(n=>n.dataset.notificationId).length,4);
 for(const row of root.children.filter(n=>n.dataset.notificationId)){assert.match(row.textContent,ar?/الدعم/:/contact support/);assert.doesNotMatch(row.textContent,/PRIVATE-RECOVERY-CODE|<img/);assert.equal(row.children.filter(n=>n.tag==='button').length,1);}
 const before=calls.length,retained=root.children.find(n=>n.dataset.notificationId).children[0].onclick;context.user={id:8,role:'customer'};await retained();assert.equal(calls.length,before,'Old-account notice controls must not write');
});

const wrapper=source.slice(source.indexOf('async function api('),source.indexOf('window.dispatchEvent('));
function csrfFixture(result){
 const context=vm.createContext({csrf:'a'.repeat(40),user:{id:7},AbortSignal,navigator:{onLine:true},calls:0});
 context.fetch=async()=>{context.calls++;return {status:200,ok:true,json:async()=>result};};
 context.clearIdentity=()=>{context.user=null;context.client.invalidateSession();};
 vm.runInContext(fs.readFileSync('services/business-api/public/client.js','utf8')+'\nglobalThis.client=new EvApi(csrf);\n'+wrapper+'\nglobalThis.invoke=api;',context);return context;
}
test('proven current-account MFA changes hand off rotated CSRF without replay',async()=>{
 for(const path of ['enable','disable','recovery-codes']){
  const c=csrfFixture({reauthentication_required:false,csrf_token:'b'.repeat(40)});await c.invoke('account/second-factor/'+path,'POST',{});assert.equal(c.csrf,'b'.repeat(40));assert.equal(c.client.token,c.csrf);assert.equal(c.calls,1);
 }
 for(const [path,result] of [['account/profile',{reauthentication_required:false,csrf_token:'b'.repeat(40)}],['account/second-factor/enable',{reauthentication_required:true,csrf_token:'b'.repeat(40)}],['account/second-factor/enable',{reauthentication_required:false,csrf_token:'<img src=x>'}]]){
  const c=csrfFixture(result);await c.invoke(path,'POST',{});assert.equal(c.csrf,'a'.repeat(40));assert.equal(c.calls,1);
 }
});
test('late MFA token handoff cannot cross an account change',async()=>{
 const c=csrfFixture(null);let finish;c.fetch=()=>new Promise(resolve=>finish=resolve);const pending=c.invoke('account/second-factor/enable','POST',{});c.client.invalidateSession();c.user={id:8};finish({status:200,ok:true,json:async()=>({reauthentication_required:false,csrf_token:'b'.repeat(40)})});await assert.rejects(pending,{name:'SessionChangedError'});assert.equal(c.csrf,'a'.repeat(40));assert.equal(c.user.id,8);
});

test('successful MFA rotation fences earlier private responses without signing out the current browser',async()=>{
 const c=csrfFixture(null);let finishOld;
 c.fetch=url=>url.endsWith('/account/second-factor/enable')
  ?Promise.resolve({status:200,ok:true,json:async()=>({reauthentication_required:false,csrf_token:'b'.repeat(40)})})
  :new Promise(resolve=>finishOld=resolve);
 const earlier=c.invoke('vehicles');earlier.catch(()=>{});
 await c.invoke('account/second-factor/enable','POST',{});
 finishOld({status:401,ok:false,json:async()=>({message:'Earlier session was revoked'})});
 await assert.rejects(earlier,{name:'SessionChangedError'});
 assert.equal(c.user.id,7);assert.equal(c.csrf,'b'.repeat(40));
});

test('MFA changes fence earlier failures before the response and block unrelated sends until settled',async()=>{
 const c=csrfFixture(null);let finishOld,finishMfa,sent=0;
 c.fetch=url=>{sent++;return new Promise(resolve=>{if(url.endsWith('/account/second-factor/enable'))finishMfa=resolve;else finishOld=resolve;});};
 const earlier=c.invoke('vehicles');earlier.catch(()=>{});
 const changing=c.invoke('account/second-factor/enable','POST',{});changing.catch(()=>{});
 finishOld({status:401,ok:false,json:async()=>({message:'Earlier session was revoked'})});
 await assert.rejects(earlier,{name:'SessionChangedError'});assert.equal(c.user.id,7);
 await assert.rejects(c.invoke('charger-commands','POST',{}),{name:'SessionChangedError'});assert.equal(sent,2);
 finishMfa({status:200,ok:true,json:async()=>({reauthentication_required:false,csrf_token:'b'.repeat(40)})});
 await changing;assert.equal(c.user.id,7);assert.equal(c.client.securityChange,null);
});
