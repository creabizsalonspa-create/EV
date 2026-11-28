"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),{test}=require("node:test");
class Element {
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.attributes={};this._text="";this.disabled=false;this.isConnected=true;this.parentNode=null;this.value="";this.classList={toggle(){}};}
 get textContent(){return this._text+this.children.map(x=>x.textContent).join("");}
 set textContent(value){this._text=String(value);this.children=[];}
 append(...items){for(const x of items){x.parentNode=this;this.children.push(x);}}
 replaceChildren(...items){for(const x of this.children)x.parentNode=null;this._text="";this.children=[];this.append(...items);}
 setAttribute(k,v){this.attributes[k]=v;}
 removeAttribute(k){delete this.attributes[k];}
 addEventListener(){}
 reportValidity(){return true;}
 remove(){if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(x=>x!==this);this.parentNode=null;}this.isConnected=false;}
 querySelectorAll(selector){const allowed=new Set(selector.split(",").map(x=>x.trim()));const out=[];const scan=n=>{for(const x of n.children){if(allowed.has(x.tag))out.push(x);scan(x);}};scan(this);return out;}
 querySelector(selector){if(selector!=="[data-loyalty-history]")return null;let found=null;const scan=n=>{for(const x of n.children){if(x.dataset.loyaltyHistory){found=x;return;}scan(x);if(found)return;}};scan(this);return found;}
}
function harness(){
 const root=new Element("div");let current={id:7,role:"customer"},next=null;const calls=[];
 const api=(path,method,data,signal)=>{
  calls.push({path,method,data,signal});if(next)return next(path,method,data,signal);
  if(path.endsWith("/account"))return Promise.resolve({user_id:7,unit:"NON_MONETARY_POINTS",cash_value:false,transferable:false,evidence:"TEST_PROMOTIONAL_ECONOMICS",balance_points:10,spendable_points:10,pending_reconciliation_points:0,qualified_lifetime_points:10,tier_by_currency:{}});
  if(path.includes("/ledger?"))return Promise.resolve({data:[{id:1,kind:"earn",points:10}],current_page:1,last_page:1,total:1});
  return Promise.reject(Error("unexpected "+path));
 };
 const window={EV_MESSAGES:{},addEventListener(){}},document={createElement:tag=>new Element(tag),documentElement:{lang:"en"}};
 const MutationObserver=class{observe(){} disconnect(){}};
 const context = vm.createContext({
  window, document, MutationObserver, AbortController, Intl,
  crypto: { randomUUID: () => "fixed-id" },
 });
 for (const script of ["station-time.js", "loyalty.js"]) {
  vm.runInContext(fs.readFileSync("services/business-api/public/" + script, "utf8"), context);
 }
 window.mountLoyalty({container:root,api,ui:(tag,en)=>{const x=new Element(tag);x.textContent=en;return x;},actor:()=>current});
 const buttons=()=>root.querySelectorAll("button"),find=label=>buttons().find(x=>x.textContent===label);
 return{root,buttons,find,calls,setNext:fn=>{next=fn;},setActor:value=>{current=value;}};
}
test("loyalty refresh clears stale points before GET and fails closed with retry",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();await h.find("Points history").onclick();
 assert.match(h.root.textContent,/Points entry/);
 let rejectRead;h.setNext(()=>new Promise((resolve,reject)=>{rejectRead=reject;}));
 const refreshing=h.find("Refresh loyalty").onclick();assert.doesNotMatch(h.root.textContent,/Points entry/);
 assert.match(h.root.textContent,/Loading loyalty records/);rejectRead(Error("503"));await refreshing;
 assert.match(h.root.textContent,/Loyalty records unavailable/);assert.doesNotMatch(h.root.textContent,/Points entry/);
 assert(h.find("Retry loyalty records"));h.setNext(()=>Promise.resolve({data:[{id:2,kind:"reversal",points:-5}],current_page:1,last_page:1,total:1}));
 await h.find("Retry loyalty records").onclick();assert.match(h.root.textContent,/Points entry/);
 assert.match(h.root.textContent,/Refund or chargeback reversal/);assert.doesNotMatch(h.root.textContent,/Loyalty records unavailable/);
});
test("malformed loyalty pagination never renders an invented empty ledger",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();
 h.setNext(()=>Promise.resolve({data:"not-an-array",current_page:1,last_page:1}));
 await h.find("Points history").onclick();assert.match(h.root.textContent,/Loyalty records unavailable/);
 assert.doesNotMatch(h.root.textContent,/No points have been posted/);
});
test("late former customer loyalty response cannot restore private points",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();let release;
 h.setNext(()=>new Promise(resolve=>{release=resolve;}));const reading=h.find("Points history").onclick();
 h.setActor({id:8,role:"customer"});release({data:[{id:99,kind:"earn",points:999}],current_page:1,last_page:1,total:1});
 await reading;assert.doesNotMatch(h.root.textContent,/Points entry/);assert.doesNotMatch(h.root.textContent,/999/);
});

test("empty authoritative ledger displays a real empty state",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();
 h.setNext(()=>Promise.resolve({data:[],current_page:1,last_page:1,total:0}));
 await h.find("Points history").onclick();
 assert.match(h.root.textContent,/No points have been posted/);
 assert.doesNotMatch(h.root.textContent,/Loyalty records unavailable/);
});
test("unverified cash-like account response cannot display invented promotional points",async()=>{
 const h=harness();
 h.setNext(()=>Promise.resolve({user_id:7,unit:"NON_MONETARY_POINTS",
  evidence:"TEST_PROMOTIONAL_ECONOMICS",cash_value:true,transferable:false,
  balance_points:12,spendable_points:12,pending_reconciliation_points:0,
  qualified_lifetime_points:12,tier_by_currency:{}}));
 await h.find("Open loyalty & rewards").onclick();
 assert.match(h.root.textContent,/Loyalty records unavailable/);
 assert.doesNotMatch(h.root.textContent,/Recorded balance/);
});

test("reward decision history clears old events before failed private refresh",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();
 h.setNext((path)=>path.endsWith("/redemptions?page=1")
  ?Promise.resolve({data:[{id:31,reward_id:2,points_cost:10,status:"pending"}],current_page:1,last_page:1,total:1})
  :Promise.resolve({redemption:{id:31,user_id:7,reward_id:2},reward:{id:2,name:"Private reward",name_ar:"مكافأة"},events:[{action:"requested",reason:"First review",created_at:"2026-11-16T00:00:00Z"}]}));
 await h.find("Reward requests").onclick();
 await h.find("Inspect reward decision history").onclick();
 assert.match(h.root.textContent,/First review/);
 let rejectRead;
 h.setNext(()=>new Promise((resolve,reject)=>{rejectRead=reject;}));
 const loading=h.find("Inspect reward decision history").onclick();
 assert.doesNotMatch(h.root.textContent,/First review/);
 assert.match(h.root.textContent,/Loading reward decisions/);
 rejectRead(Error("503"));
 await loading;
 assert.match(h.root.textContent,/Reward decisions unavailable/);
 assert.doesNotMatch(h.root.textContent,/First review/);
});
test("successful reward decision history is read-only and source-scoped",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();
 h.setNext(path=>path.endsWith("/redemptions?page=1")
  ?Promise.resolve({data:[{id:31,reward_id:2,points_cost:10,status:"pending"}],current_page:1,last_page:1,total:1})
  :Promise.resolve({redemption:{id:31,user_id:7,reward_id:2},reward:{id:2,name:"Private reward",name_ar:"مكافأة"},events:[{action:"requested",reason:"Authorized review",created_at:"2026-11-16T00:00:00Z"}]}));
 await h.find("Reward requests").onclick();
 await h.find("Inspect reward decision history").onclick();
 assert.match(h.root.textContent,/Authorized review/);
 assert.doesNotMatch(h.root.textContent,/Reward decisions unavailable/);
 assert(!h.calls.some(x=>x.method && x.method!=="GET"));
});

test("wrong-customer reward decision response fails closed without leaking events",async()=>{
 const h=harness();await h.find("Open loyalty & rewards").onclick();
 h.setNext(path=>path.endsWith("/redemptions?page=1")
  ?Promise.resolve({data:[{id:31,reward_id:2,points_cost:10,status:"pending"}],current_page:1,last_page:1,total:1})
  :Promise.resolve({redemption:{id:31,user_id:999,reward_id:2},
   reward:{id:2,name:"Private reward",name_ar:"مكافأة"},
   events:[{action:"requested",reason:"Foreign account event",created_at:"2026-11-16T00:00:00Z"}]}));
 await h.find("Reward requests").onclick();
 await h.find("Inspect reward decision history").onclick();
 assert.match(h.root.textContent,/Reward decisions unavailable/);
 assert.doesNotMatch(h.root.textContent,/Foreign account event/);
});


test("loyalty timestamps bind verified UTC values and localized fallbacks", async () => {
 const h = harness();
 await h.find("Open loyalty & rewards").onclick();
 const data = [
  { id: 1, kind: "earn", points: 10, created_at: "2026-10-28T01:30:00+04:00" },
  { id: 2, kind: "earn", points: 20, created_at: "2026-02-30T00:00:00Z" },
 ];
 h.setNext(() => Promise.resolve({ data, current_page: 1, last_page: 1, total: data.length }));
 await h.find("Points history").onclick();
 const spans = h.root.querySelectorAll("span");
 const sourceClock = spans.find((span) => span.dataset.en?.endsWith("(UTC)"));
 assert.match(sourceClock?.textContent ?? "", /27 Oct 2026.*21:30.*\(UTC\)/);
 assert.match(sourceClock.dataset.ar, /\(UTC\)$/);
 const fallback = spans.find((span) => span.dataset.en === "Event time unavailable");
 assert.equal(fallback?.dataset.ar, "وقت الحدث غير متاح");
 assert.doesNotMatch(h.root.textContent, /2026-02-30|T01:30:00/);
});
