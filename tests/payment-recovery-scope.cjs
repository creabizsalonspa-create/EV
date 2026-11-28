"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
async function runRecoveryScopeChecks() {
    const listeners = new Map(), observers = [];
    class Element {
        constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.parent = null; this.disabled = false; this.textContent = ""; }
        get isConnected() { return this.root === true || !!this.parent?.isConnected; }
        append(...nodes) { for (const n of nodes) { n.parent = this; this.children.push(n); } }
        replaceChildren(...nodes) { for (const n of this.children) n.parent = null; this.children = []; this.textContent = ""; this.append(...nodes); }
        setAttribute() {}
        remove() { if (this.parent) { this.parent.children = this.parent.children.filter(n => n !== this); this.parent = null; } }
        querySelector(selector) { return this.children.find(n => selector === "[data-recovery-history]" && Object.hasOwn(n.dataset,"recoveryHistory")); }
    }
    const document = { hidden:false, documentElement:new Element("html"), createElement:tag=>new Element(tag),
        addEventListener:(name,fn)=>{ if(!listeners.has(name))listeners.set(name,new Set()); listeners.get(name).add(fn); },
        removeEventListener:(name,fn)=>listeners.get(name)?.delete(fn) };
    document.documentElement.root=true; document.documentElement.lang="en";
    class Observer { constructor(fn){this.fn=fn;observers.push(this);} observe(){} disconnect(){this.fn=null;} }
    let owner={id:1,role:"customer"}, approve=true, calls=[], pending=[];
    const window={EV_MESSAGES:{}};
    const context={window,document,MutationObserver:Observer,AbortController,EvMoney:{format:n=>String(n)},confirm:()=>approve};
    vm.runInNewContext(fs.readFileSync("services/business-api/public/station-time.js","utf8"),context);
    vm.runInNewContext(fs.readFileSync("services/business-api/public/payment-recovery.js","utf8"),context);
    const ui=(tag,key)=>{const n=new Element(tag);n.textContent=key;return n;};
    const api=(path,method,body,signal)=>{calls.push({path,method,signal});return new Promise(resolve=>pending.push(resolve));};
    const root=new Element("section");document.documentElement.append(root);
    const find=(n,label)=>n.textContent===label?n:n.children.map(x=>find(x,label)).find(Boolean);
    const page=(id)=>({current_page:1,last_page:1,data:[{id,status:"uncertain",currency:"AED",amount_minor:100,minor_units:2,kind:"authorize",scope:"charging"}]});
    window.mountPaymentRecovery({container:root,api,ui,actor:()=>owner});
    const load=find(root,"Load recovery records");
    let p=load.onclick();pending.shift()(page(7));await p;
    const oldCheck=find(root,"Check provider outcome"), history=find(root,"View recovery history");
    approve=false;await oldCheck.onclick();assert.equal(calls.length,1);
    approve=true;p=history.onclick();
    const delayed=pending.shift();
    document.hidden=true;for(const fn of listeners.get("visibilitychange"))fn();
    assert.equal(calls.at(-1).signal.aborted,true);
    delayed({data:[{created_at:"2026-11-28T08:00:00Z",status:"completed"}]});await p;
    assert.equal(find(root,"#7"),undefined);
    document.hidden=false;await oldCheck.onclick();assert.equal(calls.length,2);
    p=load.onclick();pending.shift()(page(8));await p;
    const check=find(root,"Check provider outcome");
    p=check.onclick();assert.equal(calls.at(-1).method,"POST");const outcome=pending.shift();
    owner={id:2,role:"customer"};for(const ob of observers)ob.fn?.();
    assert.equal(calls.at(-1).signal.aborted,true);
    outcome({});await p;assert.equal(calls.length,4);assert.equal(find(root,"#8"),undefined);
    await load.onclick();assert.equal(calls.length,4);
    const notices=new Element("section");document.documentElement.append(notices);
    window.mountSubscriptionNotices({container:notices,api,ui,actor:()=>owner});
    pending.shift()({current_page:1,last_page:1,data:[{number:"INV-1",attention:"payment_due",stage:1,currency:"AED",total_minor:100,minor_units:2,created_at:"2026-02-30T08:00:00Z"}]});
    await Promise.resolve();await Promise.resolve();
    assert.ok(find(notices,"Event time unavailable"));
    document.hidden=true;for(const fn of listeners.get("visibilitychange"))fn();
    assert.equal(find(notices,"INV-1"),undefined);
    document.hidden=false;
    const refresh=find(notices,"Load subscription notices");p=refresh.onclick();
    pending.shift()({current_page:1,last_page:1,data:[{number:"INV-2",attention:"settled",stage:1,currency:"AED",total_minor:100,minor_units:2,created_at:"2026-11-28T12:00:00+04:00"}]});
    await p;
    assert.ok(notices.children.some(n=>n.children.some(c=>c.children.some(x=>x.dataset.en?.includes("08:00")))));
}
module.exports=runRecoveryScopeChecks;
if(require.main===module)runRecoveryScopeChecks().then(()=>console.log("PASS browser recovery scope and clocks")).catch(e=>{console.error(e);process.exitCode=1;});
