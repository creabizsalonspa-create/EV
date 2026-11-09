// Read the shipped browser source; isolated DOM contract without a deployed backend.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tag = tag; this.children = []; this.dataset = {};
    this.textContent = ''; this.className = ''; this.value = '';
    this.style = {}; this.attributes = {};
  }
  append(...values) {this.children.push(...values);}
  prepend(...values) {this.children.unshift(...values);}
  replaceChildren(...values) {this.children = values;}
  setAttribute(key, value) {this.attributes[key] = value;}
  querySelector() {return null;}
  reset() {this.resetCalls=(this.resetCalls||0)+1;}
}
const doc = {documentElement: {lang:'en'},createElement: tag => new Element(tag)};
const window = {};
const path = 'services/business-api/public/privacy-requests.js';
vm.runInNewContext(fs.readFileSync(path,'utf8'),{
  document:doc,window,Object,Uint8Array,
  crypto:{getRandomValues(bytes){bytes.fill(7);return bytes;}},
},{filename:path});
assert.equal(typeof window.createPrivacyWorkspace, 'function');
const user1 = {role:'customer',id:1}, user2 = {role:'customer',id:2};
let actor = user1, epoch = 1;
const calls = [];
let holdPost=false,releasePost=null,holdAdmin=false,releaseAdmin=null;
let holdWithdrawal=false,releaseWithdrawal=null;
let poisonOwnList=false, failNextReadback=false;
const postedKeys=[];
const api = async (path,method,body) => {
  calls.push(path);
  if(path==='privacy-requests' && method==='POST'){
    postedKeys.push(body.idempotency_key);
    if(holdPost){
      holdPost=false;
      await new Promise(resolve=>{releasePost=resolve;});
    }
    return {id:100,user_id:1,request_type:body.request_type,status:'submitted'};
  }
  if(path==='privacy-requests/100/withdraw' && method==='POST'){
    if(holdWithdrawal){
      holdWithdrawal=false;
      await new Promise(resolve=>{releaseWithdrawal=resolve;});
    }
    return {id:100,status:'withdrawn'};
  }
  if(path.startsWith('admin/privacy-requests/') && method==='PATCH'){
    if(holdAdmin){
      holdAdmin=false;
      await new Promise(resolve=>{releaseAdmin=resolve;});
    }
    return {id:200,status:'closed'};
  }
  if(path==='admin/privacy-requests?page=1')return {
    data:[{id:200,request_type:'account_deletion',status:'in_review',
      reason:null,resolution:null,decision_note:null,user:{email:'review@example.invalid'}}],
    last_page:1,current_page:1,total:1,
  };
  if(failNextReadback && path.startsWith('privacy-requests?page=')) {
    failNextReadback=false;
    throw Error('Synthetic post-commit GET failure');
  }
  if(poisonOwnList && path.startsWith('privacy-requests?page=')) {
    poisonOwnList=false;
    return {data:[{id:777,user_id:999,request_type:'account_deletion',
      status:'submitted',reason:'other-account-private-secret',
      resolution:null,decision_note:null}],last_page:1,current_page:1,total:1};
  }
  return {
    data:actor.id === 1 ? [{
      id:100, user_id:1, request_type:'account_deletion', status:'submitted',
      reason:'<img src=x onerror=alert(1)> secret-private-note',
      resolution:null,decision_note:null,
    }] : [],
    last_page:1,current_page:1,total:actor.id===1?1:0,
  };
};
const mine = doc.createElement('section'), admin = doc.createElement('section');
const ui = window.createPrivacyWorkspace({selfRoot:mine,adminRoot:admin,api,actor:()=>actor,epoch:()=>epoch});
const content = root => {
  function walk(node) {return [node.textContent, ...node.children.flatMap(walk)].join(' ');}
  return walk(root);
};
(async () => {
  await ui.setActor(user1);
  assert.match(content(mine), /secret-private-note/);
  assert.match(content(mine), /not instant account deletion/);
  assert.match(content(mine), /Withdraw request/);
  const find = (node, predicate) => predicate(node) ? node :
    node.children.map(child => find(child, predicate)).find(Boolean);
  // A network-pending request must never be submitted twice.
  const pendingForm=find(mine,node=>node.tag==='form');
  const consent=find(pendingForm,node=>node.tag==='input'&&node.type==='checkbox');
  const choice=find(pendingForm,node=>node.tag==='select');
  assert.ok(consent&&choice);
  consent.checked=true;choice.value='account_deletion';
  holdPost=true;
  const firstSubmission=pendingForm.onsubmit({preventDefault(){}});
  assert.equal(typeof releasePost,'function');
  const switchRequest=find(pendingForm,node=>node.tag==='button' &&
    content(node).includes('Start a different request'));
  assert.ok(switchRequest);
  switchRequest.onclick();
  assert.equal(pendingForm.resetCalls||0,0,
    'Pending POST must not let a new draft erase its replay key');
  const sentCount=()=>calls.filter(path=>path==='privacy-requests').length;
  const beforeDuplicate=sentCount();
  await pendingForm.onsubmit({preventDefault(){}});
  assert.equal(sentCount(),beforeDuplicate,
    'Duplicate customer form submission cannot POST while original pending');
  // Refreshing the account while POST is in flight renders a new form.
  // Neither this new form nor Start a different request may discard the
  // pending idempotency identity or send another concurrent POST.
  await ui.refreshMine();
  const refreshedForm=find(mine,node=>node.tag==='form');
  const switchAfterRefresh=find(refreshedForm,node=>node.tag==='button' &&
    content(node).includes('Start a different request'));
  assert.ok(switchAfterRefresh);
  assert.equal(switchAfterRefresh.disabled,true,
    'Refreshing the account must not unlock a different request during POST');
  switchAfterRefresh.onclick();
  assert.equal(refreshedForm.resetCalls||0,0,
    'Refreshed form cannot discard in-flight replay identity');
  const newConsent=find(refreshedForm,node=>node.tag==='input' &&
    node.type==='checkbox');
  newConsent.checked=true;
  await refreshedForm.onsubmit({preventDefault(){}});
  assert.equal(sentCount(),beforeDuplicate,
    'Refreshed form cannot send a concurrent request');
  releasePost();
  await firstSubmission;
  const currentForm=find(mine,node=>node.tag==='form');
  const differentAfterSettle=find(currentForm,node=>node.tag==='button' &&
    content(node).includes('Start a different request'));
  assert.equal(differentAfterSettle.disabled,false,
    'A settled POST must unlock the latest same-account request form');

  // POST committed but the immediate GET did not. The user must SEE the
  // uncertain confirmation and be allowed to retry the SAME operation key.
  differentAfterSettle.onclick();
  const uncertainForm=find(mine,node=>node.tag==='form');
  find(uncertainForm,node=>node.tag==='input'&&node.type==='checkbox').checked=true;
  failNextReadback=true;
  const beforeUncertain=sentCount();
  await uncertainForm.onsubmit({preventDefault(){}});
  assert.equal(sentCount(),beforeUncertain+1);
  assert.match(content(mine), /Request may be recorded, but list verification failed/,
    'The post-commit GET warning must survive write-finally cleanup');
  const retryForm=find(mine,node=>node.tag==='form');
  const retrySubmit=find(retryForm,node=>node.tag==='button'&&node.type==='submit');
  assert.equal(retrySubmit.disabled,false,'The retry form must unlock');
  find(retryForm,node=>node.tag==='input'&&node.type==='checkbox').checked=true;
  await retryForm.onsubmit({preventDefault(){}});
  assert.equal(sentCount(),beforeUncertain+2);
  assert.equal(postedKeys.at(-1),postedKeys.at(-2),
    'A failed readback must replay the original idempotency key');
  const initialCalls = calls.length;
  const staleForm = find(mine, node => node.tag === 'form');
  assert.ok(staleForm);
  epoch++;
  await staleForm.onsubmit({preventDefault(){}});
  assert.equal(calls.length, initialCalls,
    'Detached previous-session form may not POST private data');
  await ui.setActor(user1);
  assert.ok(calls.length > initialCalls,
    'Same actor object in a new session must perform a fresh private request');
  const withdraw=find(mine,node=>node.tag==='button' &&
    content(node).includes('Withdraw request'));
  assert.ok(withdraw);
  holdWithdrawal=true;
  const withdrawal=withdraw.onclick();
  assert.equal(typeof releaseWithdrawal,'function');
  const writesBeforeDuplicate=calls.filter(path=>
    path==='privacy-requests/100/withdraw').length;
  await withdraw.onclick();
  assert.equal(calls.filter(path=>
    path==='privacy-requests/100/withdraw').length,writesBeforeDuplicate,
    'Repeated withdraw clicks cannot POST twice while first is pending');
  const beforeRefreshWithdraw=calls.filter(path=>path==='privacy-requests?page=1').length;
  await ui.refreshMine();
  assert.equal(calls.filter(path=>path==='privacy-requests?page=1').length,
    beforeRefreshWithdraw+1);
  releaseWithdrawal();
  await withdrawal;
  assert.equal(calls.filter(path=>path==='privacy-requests?page=1').length,
    beforeRefreshWithdraw+1,
    'Former view withdrawal must not refresh and replace the latest view');
  epoch++;
  await ui.refreshMine();
  assert.equal(mine.children.length, 0,
    'Old-session private request view must be cleared before stale refresh');
  await ui.setActor(user1);
  assert.match(content(mine), /secret-private-note/);
  poisonOwnList=true;
  await ui.refreshMine();
  assert.doesNotMatch(content(mine), /other-account-private-secret/,
    'A misrouted account page must never expose a foreign reason');
  assert.match(content(mine), /Unexpected privacy request account/);
  assert.doesNotMatch(content(mine), /Withdraw request/,
    'Mismatched account rows must not expose mutating controls');
  await ui.refreshMine();
  assert.match(content(mine), /secret-private-note/,
    'The correct account can safely recover by refreshing');
  actor = user2;
  await ui.setActor(user2);
  assert.doesNotMatch(content(mine), /secret-private-note/);
  assert.match(content(mine), /No privacy requests submitted/);
  // Admin has TWO separate workspaces. Refreshing one must not invalidate
  // the other's still-visible controls during the same authenticated session.
  const adminUser = {role:'admin',id:3};
  actor=adminUser;epoch++;
  await ui.setActor(adminUser);
  assert.match(content(admin),/Privacy request reviews/);
  const ownRefresh=find(mine,node=>node.tag==='button' &&
    content(node).includes('Refresh my requests'));
  const adminRefresh=find(admin,node=>node.tag==='button' &&
    content(node).includes('Refresh review queue'));
  assert.ok(ownRefresh&&adminRefresh);
  const ownCount=()=>calls.filter(path=>path==='privacy-requests?page=1').length;
  const adminCount=()=>calls.filter(path=>path==='admin/privacy-requests?page=1').length;
  const beforeMineRefresh=ownCount();
  await ownRefresh.onclick();
  assert.equal(ownCount(),beforeMineRefresh+1,
    'Admin own privacy list must remain refreshable after Admin queue initializes');
  const beforeAdminRefresh=adminCount();
  await adminRefresh.onclick();
  assert.equal(adminCount(),beforeAdminRefresh+1,
    'Admin review queue must remain refreshable after own list refresh');
  assert.match(content(mine),/Privacy and data requests/);
  assert.match(content(admin),/Privacy request reviews/);
  // Admin close form must also suppress duplicate PATCHes in flight.
  const reviewForm=find(admin,node=>node.tag==='form'&&
    node.className==='privacy-review-form');
  assert.ok(reviewForm);
  find(reviewForm,node=>node.tag==='select').value='review_documented';
  find(reviewForm,node=>node.tag==='textarea').value='Documented review outcome for compliance team.';
  holdAdmin=true;
  const firstClose=reviewForm.onsubmit({preventDefault(){}});
  assert.equal(typeof releaseAdmin,'function');
  const reviewCount=()=>calls.filter(path=>path==='admin/privacy-requests/200').length;
  const beforeDuplicateReview=reviewCount();
  await reviewForm.onsubmit({preventDefault(){}});
  assert.equal(reviewCount(),beforeDuplicateReview,
    'Duplicate Admin close cannot PATCH while original is pending');
  await ui.refreshAdmin();
  const adminGetsBeforeOld=adminCount();
  releaseAdmin();
  await firstClose;
  assert.equal(adminCount(),adminGetsBeforeOld,
    'Old review PATCH response must not replace newer Admin view');
  ui.clear();
  assert.equal(mine.children.length, 0);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'), /\.innerHTML\s*=/);
  console.log('PASS: scoped privacy web request view, safe text, cross-account clearing and explicit non-deletion notice.');
})().catch(e => {console.error(e);process.exitCode=1;});
