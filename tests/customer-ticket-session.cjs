"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const {test} = require("node:test");
const src = fs.readFileSync("services/business-api/public/platform.js","utf8");
const start = src.indexOf("let ticketDetailGeneration = 0;");
const end = src.indexOf("let pendingTicketCreate = null;",start);
assert(start>=0 && end>start,"support ticket source boundary");
const section = src.slice(start,end);
function harness() {
    const client={sessionEpoch:1},user={id:7,role:"customer"};
    const root={children:[],replaceChildren(...items){this.children=items;},append(item){this.children.push(item);}};
    const pending=[];
    const node=(tag,value)=>({tag,value,children:[],append(...items){this.children.push(...items);}});
    const api=path=>new Promise(resolve=>pending.push({path,resolve}));
    const env={client,user,api,el:()=>root,node,ui:node,
        document:{createElement:tag=>node(tag)},
        EvLocale:{attribute:()=>{}},notice:()=>{},
        crypto:{randomUUID:()=> "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"}};
    const detail=new Function("env","with(env){"+section+"return ticketDetail;}")(env);
    return {detail,client,user,root,pending};
}
const result=(subject)=>({ticket:{subject,status:"closed"},messages:{data:[{body:subject,internal:false}]}});
test("late ticket response cannot expose previous customer's messages",async()=>{
    const h=harness(),p=h.detail(17);
    h.client.sessionEpoch++;h.user.id=8;
    h.pending[0].resolve(result("Old customer's private case"));
    await p;
    assert.equal(h.root.children.length,0);
});
test("newer ticket selection wins over an older delayed response",async()=>{
    const h=harness(),first=h.detail(17),second=h.detail(18);
    h.pending[1].resolve(result("Current ticket"));await second;
    h.pending[0].resolve(result("Old ticket"));await first;
    assert.equal(h.root.children[0].value,"{subject} · {status}");
    assert.equal(h.root.children[0].children.length,0);
    assert.equal(h.root.children[1].value,"Current ticket");
    assert.equal(h.root.children.length,2);
});
