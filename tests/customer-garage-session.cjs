"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const {test} = require("node:test");
const src = fs.readFileSync("services/business-api/public/platform.js", "utf8");
const begin = src.indexOf("let garageGeneration = 0");
const end = src.indexOf('async function support()', begin);
assert(begin >= 0 && end > begin, "customer garage source boundary");
const code = src.slice(begin,end);
function harness() {
    const elements = new Map();
    const el = id => {
        if (!elements.has(id)) elements.set(id,{
            children:[],
            elements:{name:{value:""},locale:{value:""}},
            replaceChildren(...children){this.children=children;},
            append(...children){this.children.push(...children);},
            querySelector(selector){return selector === "button" ? this.submit || null : null;}
        });
        return elements.get(id);
    };
    const user = {id:7,role:"customer"};
    const client = {sessionEpoch:1};
    let reads = [], creates = [], profiles = [], requests = [], notices = [], localeUpdates = [];
    const api = (path,method) => {
        requests.push({path,method});
        if (path === "vehicles" && method === "POST")
            return new Promise(resolve => {creates.push(resolve);});
        if (path === "profile" && method === "PATCH")
            return new Promise(resolve => {profiles.push(resolve);});
        if (path === "vehicles")
            return new Promise(resolve => {reads.push(resolve);});
        throw Error("unexpected "+path);
    };
    const node = (tag,value) => ({tag,value,children:[],append(x){this.children.push(x);}});
    const env = {el,client,user,api,node,batteryNode:node,
        batteryEditor:()=>{},driverPreferences:{open:()=>{}},
        vehicleModelCatalog:{renderGarage:()=>{}},
        vehicleManualSoc:{renderGarage:()=>{}},
        document:{createElement:()=>({dataset:{}}),documentElement:{lang:"en"}},
        notice:e=>notices.push(e),
        EvLocale:{set:value=>localeUpdates.push(value)},
        identity:async()=>{},
        FormData:class {constructor(){} *[Symbol.iterator](){}}
    };
    const methods = new Function("env", "with(env){"+code+"return {garage,resetCustomerMutationState};}")(env);
    return {methods,el,client,user,requests,notices,localeUpdates,
        resolveRead:(index,value)=>reads[index](value),
        resolveCreate:(index=0)=>creates[index](),
        resolveProfile:(value,index=0)=>profiles[index](value)};
}
test("late garage response cannot render a previous customer's private vehicles",async()=>{
    const h=harness(),p=h.methods.garage();
    h.client.sessionEpoch++;
    h.user.id=8;
    h.resolveRead(0,{data:[{id:55,name:"Private",connector_type:"CCS2"}]});
    await p;
    assert.equal(h.el("vehicle-list").children.length,0);
});
test("overlapping garage reads cannot replace the newest list",async()=>{
    const h=harness(),first=h.methods.garage();
    const second=h.methods.garage();
    h.resolveRead(1,{data:[]});await second;
    h.resolveRead(0,{data:[{id:55,name:"Old",connector_type:"CCS2"}]});await first;
    assert.equal(h.el("vehicle-list").children.length,0);
});
test("vehicle create submits only once while pending and does not render after account switch",async()=>{
    const h=harness(),form=h.el("vehicle-create");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const first=form.onsubmit(event);
    await form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.method==="POST").length,1);
    assert.equal(form.submit.disabled,true);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.resolveCreate();
    await first;
    assert.equal(h.requests.filter(x=>x.path==="vehicles" && !x.method).length,0);
    assert.equal(form.submit.disabled,false);
});

test("late profile PATCH cannot overwrite another signed-in account or locale",async()=>{
    const h=harness(),form=h.el("profile-form");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const first=form.onsubmit(event);
    await form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.method==="PATCH").length,1);
    assert.equal(form.submit.disabled,true);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.resolveProfile({id:7,name:"Old account",locale:"ar"});
    await first;
    assert.equal(h.user.id,8);
    assert.equal(h.localeUpdates.length,0);
    assert.equal(form.submit.disabled,false);
});

test("starting a garage refresh immediately clears previously displayed private rows",async()=>{
    const h=harness(),list=h.el("vehicle-list");
    list.append({value:"Prior account vehicle"});
    const pending=h.methods.garage();
    assert.equal(list.children.length,0);
    h.client.sessionEpoch++;
    h.resolveRead(0,{data:[{id:1,name:"Previous",connector_type:"CCS2"}]});
    await pending;
    assert.equal(list.children.length,0);
});

test("new account vehicle create stays locked independently of old pending acknowledgement",async()=>{
    const h=harness(),form=h.el("vehicle-create");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const former=form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="vehicles"&&x.method==="POST").length,1);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.methods.resetCustomerMutationState();
    const current=form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="vehicles"&&x.method==="POST").length,2);
    assert.equal(form.submit.disabled,true);
    h.resolveCreate(0);
    await former;
    assert.equal(form.submit.disabled,true,
        "late former actor cannot release the current actor's in-flight lock");
    h.resolveCreate(1);
    await Promise.resolve();
    h.resolveRead(0,{data:[]});
    await current;
    assert.equal(form.submit.disabled,false);
});
test("new account profile PATCH survives older account acknowledgement",async()=>{
    const h=harness(),form=h.el("profile-form");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const former=form.onsubmit(event);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.methods.resetCustomerMutationState();
    const current=form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="profile"&&x.method==="PATCH").length,2);
    assert.equal(form.submit.disabled,true);
    h.resolveProfile({id:7,locale:"ar"},0);
    await former;
    assert.equal(form.submit.disabled,true);
    assert.equal(h.localeUpdates.length,0);
    h.resolveProfile({id:8,locale:"en"},1);
    await current;
    assert.equal(form.submit.disabled,false);
    assert.equal(h.localeUpdates.length,1);
    assert.equal(h.localeUpdates[0],"en");
});
