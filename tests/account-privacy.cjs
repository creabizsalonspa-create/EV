"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");
const { test } = require("node:test");

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.value = "";
    this.attributes = {};
    this.style = {};
    this._text = "";
    this.disabled = false;
  }
  get textContent() {
    return this._text + this.children.map((x) => x.textContent).join("");
  }
  set textContent(value) {
    this._text = String(value);
    this.children = [];
  }
  append(...items) {
    this.children.push(...items);
  }
  replaceChildren(...items) {
    this._text = "";
    this.children = items;
  }
  setAttribute(k, v) {
    this.attributes[k] = v;
  }
  removeAttribute(k) {
    delete this.attributes[k];
  }
}
function fixture() {
  const mine = new Element("div"),
    admin = new Element("div");
  const listeners = new Map();
  const window = {
    addEventListener(event, handler) {
      listeners.set("window:" + event, handler);
    },
  };
  const document = {
    hidden: false,
    addEventListener(event, handler) { listeners.set(event, handler); },
    getElementById(id) {
      if (id === "account-privacy") return mine;
      if (id === "admin-privacy") return admin;
      throw Error("Unrecognized privacy root: " + id);
    },
    createElement(tag) {
      return new Element(tag);
    },
  };
  vm.runInNewContext(
    fs.readFileSync(
      path.join(
        __dirname,
        "../services/business-api/public/account-privacy.js",
      ),
      "utf8",
    ),
    {
      window,
      document,
      AbortController,
      URLSearchParams,
      crypto: { randomUUID: require("node:crypto").randomUUID },
    },
  );
  return { mine, admin, ...window.EvPrivacyRequests,
    hide() { document.hidden = true; listeners.get("visibilitychange")?.(); },
    show() { document.hidden = false; listeners.get("visibilitychange")?.(); },
    pagehide() { listeners.get("window:pagehide")?.(); },
  };
}
function nodes(root, tag) {
  const out = [];
  function walk(item) {
    if (item.tag === tag) out.push(item);
    item.children.forEach(walk);
  }
  walk(root);
  return out;
}
const tick = () => new Promise((resolve) => setImmediate(resolve));
const record = {
  id: 22,
  kind: "erasure",
  status: "pending",
  created_at: "2026-11-07T10:00:00Z",
};
const page = (data = [record]) => ({
  current_page: 1,
  last_page: 1,
  total: data.length,
  data,
});

test("hide clears existing customer history, withdrawal controls and the private draft", async () => {
  const ui = fixture();
  ui.setSelfActor({ id: 3, role: "customer" }, async () =>
    page([{ ...record, details: "PRIVATE HISTORY THAT MUST DISAPPEAR" }]));
  await tick();
  const form = nodes(ui.mine, "form")[0];
  const draft = nodes(form, "textarea")[0];
  draft.value = "PRIVATE UNSUBMITTED DRAFT DETAIL";
  assert.match(ui.mine.textContent, /PRIVATE HISTORY THAT MUST DISAPPEAR/);
  assert(nodes(ui.mine, "button").some((b) =>
    b.textContent.startsWith("Withdraw request")));
  ui.hide();
  assert(!ui.mine.textContent.includes("PRIVATE HISTORY THAT MUST DISAPPEAR"));
  assert(!nodes(ui.mine, "button").some((b) =>
    b.textContent.startsWith("Withdraw request")));
  assert.equal(draft.value, "");
  const results = ui.mine.children.find((x) => x.tag === "div");
  assert.equal(results.attributes["aria-busy"], undefined);
  ui.show();
  assert(!ui.mine.textContent.includes("PRIVATE HISTORY THAT MUST DISAPPEAR"));
});

test("hidden submit completion preserves same request identity for deliberate retry", async () => {
  const ui = fixture(), posts = [];
  let release;
  const pending = new Promise((resolve) => { release = resolve; });
  let committed = [];
  ui.setSelfActor({id: 3, role:"customer"}, async (path, method="GET", body) => {
    if (method === "POST") {
      posts.push(body);
      if (posts.length === 1) await pending;
      committed = [{...record, details:body.details, kind:body.kind}];
      return { id: record.id };
    }
    return page(committed);
  });
  await tick();
  const form = nodes(ui.mine, "form")[0];
  const draft = nodes(form, "textarea")[0];
  draft.value = "Please check my private account details before deletion.";
  const first = form.onsubmit({preventDefault(){}});
  assert.equal(posts.length, 1);
  ui.hide();
  ui.show();
  release();
  await first;
  assert.equal(posts.length, 1);
  assert(!ui.mine.textContent.includes("Request recorded for manual review"));
  const reload = nodes(ui.mine, "button").find((b) => b.textContent.startsWith("Refresh requests"));
  await reload.onclick();
  await tick();
  // A subsequent explicit retry with identical body must reuse the prior key.
  draft.value = posts[0].details;
  await form.onsubmit({preventDefault(){}});
  await tick();
  assert.equal(posts.length, 2);
  assert.equal(posts[1].idempotency_key, posts[0].idempotency_key);
  assert.match(ui.mine.textContent,/Request recorded for manual review/);
});

test("hidden-first customer web mount and resume do not fetch private history", async () => {
  const ui = fixture();
  let reads = 0;
  ui.hide();
  ui.setSelfActor({ id: 3, role: "customer" }, async () => {
    reads++;
    return page([{ ...record, details: "PRIVATE BACKGROUND ACCOUNT DETAILS" }]);
  });
  await tick();
  assert.equal(reads, 0);
  assert(!ui.mine.textContent.includes("PRIVATE BACKGROUND ACCOUNT DETAILS"));
  ui.show();
  await tick();
  assert.equal(reads, 0, "Resume cannot silently read private account history");
  const refresh = nodes(ui.mine, "button").find((b) =>
    b.textContent.startsWith("Refresh requests"));
  assert.ok(refresh);
  await refresh.onclick();
  await tick();
  assert.equal(reads, 1);
  assert.match(ui.mine.textContent, /PRIVATE BACKGROUND ACCOUNT DETAILS/);
});

test("customer history read started before hide cannot render after visible resume", async () => {
  const ui = fixture();
  let release;
  const pending = new Promise((resolve) => { release = resolve; });
  let reads = 0;
  ui.setSelfActor({ id: 3, role: "customer" }, async () => {
    reads++;
    if (reads === 1) return pending;
    return page([{ ...record, details: "FRESH FOREGROUND HISTORY" }]);
  });
  assert.equal(reads, 1);
  ui.hide();
  ui.show();
  release(page([{ ...record, details: "PRIVATE OBSOLETE HIDDEN HISTORY" }]));
  await tick();
  await tick();
  assert(!ui.mine.textContent.includes("PRIVATE OBSOLETE HIDDEN HISTORY"));
  assert(!nodes(ui.mine, "button").some((b) =>
    b.textContent.startsWith("Withdraw request")));
  const refresh = nodes(ui.mine, "button").find((b) =>
    b.textContent.startsWith("Refresh requests"));
  await refresh.onclick();
  await tick();
  assert.equal(reads, 2);
  assert.match(ui.mine.textContent, /FRESH FOREGROUND HISTORY/);
});

test("guest context never fetches and both private views clear on signout", async () => {
  const ui = fixture(),
    calls = [];
  ui.setSelfActor(null, async (p) => {
    calls.push(p);
    return page();
  });
  ui.setAdminActor({ role: "vendor" }, async (p) => {
    calls.push(p);
    return page();
  });
  await tick();
  assert.equal(calls.length, 0);
  ui.setSelfActor({ id: 3, role: "customer" }, async (p) => {
    calls.push(p);
    return page();
  });
  ui.setAdminActor({ role: "admin" }, async (p) => {
    calls.push(p);
    return page();
  });
  await tick();
  assert.equal(calls.length, 2);
  assert.match(ui.mine.textContent, /erasure/);
  ui.setSelfActor(null, async () => {
    throw Error("must not fetch");
  });
  ui.setAdminActor(null, async () => {
    throw Error("must not fetch");
  });
  assert.equal(ui.mine.children.length, 0);
  assert.equal(ui.admin.children.length, 0);
});

test("self-service captures a real private reason without claiming automated deletion", async () => {
  const ui = fixture(),
    calls = [];
  let records = [];
  ui.setSelfActor(
    { id: 3, role: "vendor" },
    async (p, method = "GET", body) => {
      calls.push({ p, method, body });
      if (method === "POST") {
        records = [{ ...record, kind: body.kind }];
        return {
          id: 22,
          kind: body.kind,
          status: "pending",
          automated_erasure_performed: false,
        };
      }
      return page(records);
    },
  );
  await tick();
  const form = nodes(ui.mine, "form")[0],
    details = nodes(form, "textarea")[0];
  details.value = "short";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(calls.filter((x) => x.method === "POST").length, 0);
  details.value =
    "Please review retained charging and account records for deletion.";
  nodes(form, "select")[0].value = "erasure";
  await form.onsubmit({ preventDefault() {} });
  await tick();
  const submitted = calls.find((x) => x.method === "POST");
  assert.ok(submitted);
  assert.equal(submitted.p, "account/privacy-requests");
  assert.equal(submitted.body.kind, "erasure");
  assert.match(submitted.body.details, /retained charging/);
  assert.match(ui.mine.textContent, /No data has been automatically deleted/);
  assert.match(ui.mine.textContent, /Request #22/);
  assert.equal(details.value, "");
});


test("Admin private reviewer notes, owner identifiers and actions disappear on tab hide",async()=>{
  const ui=fixture();const calls=[];
  const privateRecord={
    ...record, kind:"access", status:"reviewing",
    details:"PRIVATE REVIEWER CASE FOR OTHER PERSON",
    owner:{id:3,name:"PRIVATE CUSTOMER NAME",email:"private.owner@example.test"},
  };
  ui.setAdminActor({id:4,role:"admin"},async(path,method="GET")=>{
    calls.push({path,method});
    return page([privateRecord]);
  });
  await tick();
  assert.match(ui.admin.textContent,/PRIVATE REVIEWER CASE FOR OTHER PERSON/);
  const notes=nodes(ui.admin,"textarea");
  const passwords=nodes(ui.admin,"input").filter(x=>x.type==="password");
  assert.equal(notes.length,1);
  assert.equal(passwords.length,1);
  notes[0].value="PRIVATE INTERNAL DECISION BEFORE HIDE";
  passwords[0].value="PRIVATE ADMIN REAUTHENTICATION";
  const oldDecision=nodes(ui.admin,"form").find(f=>nodes(f,"textarea").length);
  assert.ok(oldDecision);
  ui.hide();
  assert(!ui.admin.textContent.includes("PRIVATE CUSTOMER NAME"));
  assert(!ui.admin.textContent.includes("private.owner@example.test"));
  assert(!ui.admin.textContent.includes("PRIVATE REVIEWER CASE"));
  assert.equal(notes[0].value,"");
  assert.equal(passwords[0].value,"");
  assert.equal(nodes(ui.admin,"article").length,0);
  ui.show();
  assert.equal(calls.length,1,"resume must not auto-fetch Admin queue");
  await oldDecision.onsubmit({preventDefault(){}});
  assert.equal(calls.length,1,"detached decision cannot run after hide");
  const filters=nodes(ui.admin,"form").find(f=>f.attributes["aria-label"]==="Admin privacy request filters");
  await filters.onsubmit({preventDefault(){}});
  await tick();
  assert.equal(calls.length,2);
  assert.match(ui.admin.textContent,/PRIVATE CUSTOMER NAME/);
  assert(calls.every(c=>c.method==="GET"));
});

test("Admin initially hidden queue requires explicit foreground filter submission",async()=>{
  const ui=fixture();let reads=0;
  ui.hide();
  ui.setAdminActor({id:4,role:"admin"},async()=>{reads++;return page([])});
  await tick();assert.equal(reads,0);
  ui.show();await tick();assert.equal(reads,0);
  const filters=nodes(ui.admin,"form").find(f=>f.attributes["aria-label"]==="Admin privacy request filters");
  assert.ok(filters);
  await filters.onsubmit({preventDefault(){}});
  await tick();
  assert.equal(reads,1);
});

test("pending Admin private queue GET cannot reveal account data across BFCache restore",async()=>{
  const ui=fixture();
  let release,reads=0;
  const pending=new Promise(resolve=>{release=resolve});
  ui.setAdminActor({id:4,role:"admin"},async()=>{
    reads++;
    return reads===1?pending:page([{...record,details:"CURRENT ADMIN DATA",
      owner:{id:3,name:"Fresh owner"}}]);
  });
  ui.pagehide();
  ui.show();
  release(page([{...record,details:"PRIVATE OLD BFCache DATA",
    owner:{id:3,name:"Stale owner"}}]));
  await tick();await tick();
  assert(!ui.admin.textContent.includes("PRIVATE OLD BFCache DATA"));
  const filters=nodes(ui.admin,"form").find(f=>f.attributes["aria-label"]==="Admin privacy request filters");
  await filters.onsubmit({preventDefault(){}});
  await tick();
  assert.equal(reads,2);
  assert.match(ui.admin.textContent,/CURRENT ADMIN DATA/);
  assert(!ui.admin.textContent.includes("Stale owner"));
});


test("malformed Admin private queues fail closed instead of showing false empty or decision controls",async()=>{
  const valid={
    ...record,kind:"erasure",details:"Private data subject reason",
    owner:{id:3,name:"Owner",email:"owner@example.test"},
  };
  for(const wrong of [
    {data:"not-a-list",current_page:1,last_page:1,total:0},
    {data:[valid],current_page:2,last_page:2,total:26},
    {data:[valid],current_page:1,last_page:1,total:0},
    {data:[valid,valid],current_page:1,last_page:1,total:2},
    page([{...valid,id:"22"}]),
    page([{...valid,kind:"untrusted"}]),
    page([{...valid,status:"untrusted"}]),
    page([{...valid,owner:{id:"3"}}]),
    page([{...valid,details:123}]),
  ]){
    const ui=fixture(),calls=[];
    let corrupt=true;
    ui.setAdminActor({id:4,role:"admin"},async(path,method="GET")=>{
      calls.push({path,method});
      return corrupt?wrong:page([valid]);
    });
    await tick();
    assert.match(ui.admin.textContent,/Privacy queue unavailable/);
    assert.equal(nodes(ui.admin,"article").length,0);
    assert.equal(nodes(ui.admin,"form").filter(f=>nodes(f,"textarea").length).length,0);
    assert(!ui.admin.textContent.includes("No requests match these filters"));
    corrupt=false;
    const filters=nodes(ui.admin,"form").find(f=>f.attributes["aria-label"]==="Admin privacy request filters");
    await filters.onsubmit({preventDefault(){}});
    await tick();
    assert.match(ui.admin.textContent,/Private data subject reason/);
    assert.equal(nodes(ui.admin,"article").length,1);
    assert(calls.every(c=>c.method==="GET"));
  }
});

test("malformed Admin decision history never appears as a successful empty timeline",async()=>{
  const ui=fixture();let broken=true;
  const valid={...record,kind:"erasure",details:"Private review reason",
    owner:{id:3,name:"Private owner"}};
  ui.setAdminActor({id:4,role:"admin"},async(path)=>{
    if(path.endsWith("/reviews"))
      return broken
        ? {data:[{id:1,from_status:"pending",to_status:"reviewing",
                 note:{secret:"INVALID PRIVATE NOTE"},created_at:"2026-11-23"}]}
        : {data:[{id:1,from_status:"pending",to_status:"reviewing",
                 note:"Verified authorized decision",created_at:"2026-11-23"}]};
    return page([valid]);
  });
  await tick();
  const history=nodes(ui.admin,"button").find(x=>
    x.textContent.startsWith("Private review history"));
  await history.onclick();
  assert.match(ui.admin.textContent,/Review history unavailable/);
  assert(!ui.admin.textContent.includes("No recorded review decisions"));
  broken=false;
  await history.onclick();
  assert.match(ui.admin.textContent,/Verified authorized decision/);
});

test("Admin can filter and record a stage transition with expected prior state", async () => {
  const ui = fixture(),
    calls = [];
  let current = "pending";
  const privateRecord = {
    ...record,
    owner: { id: 3, name: "Private Customer", email: "private@example.test" },
    details: "My contact address is incorrect",
  };
  ui.setAdminActor(
    { role: "admin", id: 4 },
    async (p, method = "GET", body) => {
      calls.push({ p, method, body });
      if (method === "PATCH") {
        current = "reviewing";
        return { id: 22, status: "reviewing" };
      }
      return page(current === "pending" ? [privateRecord] : []);
    },
  );
  await tick();
  assert.match(ui.admin.textContent, /private@example.test/);
  const form = nodes(ui.admin, "form").find((x) => nodes(x, "textarea").length);
  assert.ok(form);
  nodes(form, "textarea")[0].value =
    "Reviewed customer identity and correction request";
  await form.onsubmit({ preventDefault() {} });
  await tick();
  const decision = calls.find((x) => x.method === "PATCH");
  assert.ok(decision);
  assert.equal(decision.p, "admin/privacy-requests/22/review");
  assert.equal(decision.body.expected_status, "pending");
  assert.equal(decision.body.status, "reviewing");
  assert.match(decision.body.review_note, /Reviewed customer identity/);
  assert.match(ui.admin.textContent, /No requests match these filters/);
});

test("Admin review refuses a short note and never performs a direct deletion", async () => {
  const ui = fixture(),
    calls = [];
  ui.setAdminActor({ role: "admin" }, async (p, method = "GET") => {
    calls.push({ p, method });
    return page([
      {
        ...record,
        details: "<img src=x onerror=alert(1)>",
        owner: { id: 5, name: "Private", email: "user@example.test" },
      },
    ]);
  });
  await tick();
  const form = nodes(ui.admin, "form").find((x) => nodes(x, "textarea").length);
  nodes(form, "textarea")[0].value = "short";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(calls.filter((x) => x.method === "PATCH").length, 0);
  assert.match(ui.admin.textContent, /<img src=x onerror=alert\(1\)>/);
  assert.equal(nodes(ui.admin, "img").length, 0);
  assert.match(ui.admin.textContent, /no automatic deletion/);
});

test("stale self-service response cannot repopulate after actor changes", async () => {
  const ui = fixture();
  let release;
  ui.setSelfActor(
    { id: 2, role: "customer" },
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  assert.equal(typeof release, "function");
  ui.setSelfActor(null, async () => {
    throw Error("no identity");
  });
  release(page([{ ...record, kind: "access" }]));
  await tick();
  await tick();
  assert.equal(ui.mine.children.length, 0);
  assert.equal(ui.mine.textContent, "");
});

test("stale Admin review response cannot leak private data after logout", async () => {
  const ui = fixture();
  let release;
  ui.setAdminActor(
    { role: "admin" },
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  assert.equal(typeof release, "function");
  ui.setAdminActor(null, async () => {
    throw Error("no identity");
  });
  release(
    page([{ ...record, details: "PRIVATE-DATA", owner: { name: "Secret" } }]),
  );
  await tick();
  await tick();
  assert.equal(ui.admin.children.length, 0);
  assert.equal(ui.admin.textContent, "");
});

test("Admin private decision history renders only after request and remains text-only", async () => {
  const ui = fixture(),
    calls = [];
  const markup = "<img src=x onerror=alert(1)>";
  ui.setAdminActor({ role: "admin", id: 4 }, async (p) => {
    calls.push(p);
    if (p.endsWith("/reviews"))
      return {
        data: [
          {
            id: 5,
            from_status: "pending",
            to_status: "reviewing",
            note: markup,
            created_at: "2026-11-07T12:00:00Z",
          },
        ],
      };
    return page([
      {
        ...record,
        owner: {
          id: 3,
          name: "Private Customer",
          email: "private@example.test",
        },
        details: "Request details retained for authorized review.",
      },
    ]);
  });
  await tick();
  assert.equal(
    calls.length,
    1,
    "history must not fetch without Admin interaction",
  );
  assert.equal(nodes(ui.admin, "img").length, 0);
  const history = nodes(ui.admin, "button").find((x) =>
    x.textContent.includes("Private review history"),
  );
  assert.ok(history);
  await history.onclick();
  assert.equal(calls.length, 2);
  assert.equal(calls[1], "admin/privacy-requests/22/reviews");
  assert.match(ui.admin.textContent, /pending → reviewing/);
  assert.match(ui.admin.textContent, /<img src=x onerror=alert\(1\)>/);
  assert.equal(nodes(ui.admin, "img").length, 0);
});

test("delayed private privacy review history cannot reappear after logout", async () => {
  const ui = fixture();
  let release;
  ui.setAdminActor({ role: "admin", id: 4 }, (p) => {
    if (p.endsWith("/reviews"))
      return new Promise((resolve) => {
        release = resolve;
      });
    return Promise.resolve(
      page([
        {
          ...record,
          details: "Private detail",
          owner: { id: 3, name: "Private Owner", email: "owner@example.test" },
        },
      ]),
    );
  });
  await tick();
  const history = nodes(ui.admin, "button").find((x) =>
    x.textContent.includes("Private review history"),
  );
  const pending = history.onclick();
  assert.equal(typeof release, "function");
  ui.setAdminActor(null, async () => {
    throw Error("no request");
  });
  release({
    data: [
      {
        id: 5,
        from_status: "pending",
        to_status: "reviewing",
        note: "PRIVATE DECISION",
        created_at: "2026-11-07",
      },
    ],
  });
  await pending;
  await tick();
  assert.equal(ui.admin.children.length, 0);
  assert.equal(ui.admin.textContent, "");
});

test("unknown request retains its frozen identity and rejects changed retry content", async () => {
  const ui = fixture(),
    posted = [];
  let records = [],
    first = true;
  ui.setSelfActor(
    { id: 3, role: "customer" },
    async (p, method = "GET", body) => {
      if (method === "POST") {
        posted.push(body);
        if (!records.length)
          records = [{ ...record, kind: body.kind, details: body.details }];
        if (first) {
          first = false;
          throw Error("committed response lost");
        }
        return { id: record.id };
      }
      return page(records);
    },
  );
  await tick();
  const form = nodes(ui.mine, "form")[0],
    details = nodes(form, "textarea")[0];
  details.value = "Please review my personal information.";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 1);
  assert(details.value.length > 0);
  details.value = "A different explanation must not reuse an identity.";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 1);
  details.value = posted[0].details;
  await form.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 2);
  assert.equal(posted[0].idempotency_key, posted[1].idempotency_key);
  assert.equal(details.value, "");
});

test("detached previous-account form cannot dispatch private details using a new account", async () => {
  const ui = fixture(),
    posted = [];
  ui.setSelfActor({ id: 3, role: "customer" }, async () => page([]));
  await tick();
  const old = nodes(ui.mine, "form")[0];
  nodes(old, "textarea")[0].value = "Prior account confidential explanation.";
  ui.setSelfActor({ id: 4, role: "customer" }, async (p, m = "GET", b) => {
    if (m === "POST") posted.push(b);
    return page([]);
  });
  await tick();
  await old.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 0);
  assert(!ui.mine.textContent.includes("confidential"));
});

test("owner withdrawal is explicit and removes active controls after refreshed status", async () => {
  const ui = fixture(),
    posted = [];
  let status = "pending";
  ui.setSelfActor({ id: 3, role: "customer" }, async (p, m = "GET") => {
    if (m === "POST") {
      posted.push(p);
      status = "withdrawn";
      return { id: record.id, status };
    }
    return page([{ ...record, status }]);
  });
  await tick();
  const withdraw = nodes(ui.mine, "button").find((x) =>
    x.textContent.startsWith("Withdraw request"),
  );
  await withdraw.onclick();
  await tick();
  assert.deepEqual(posted, ["account/privacy-requests/22/withdraw"]);
  assert.match(ui.mine.textContent, /withdrawn/);
  assert(
    !nodes(ui.mine, "button").some((x) =>
      x.textContent.startsWith("Withdraw request"),
    ),
  );
});

test("Admin can inspect their own case but receives no independent-review form", async () => {
  const ui = fixture();
  ui.setAdminActor({ id: 4, role: "admin" }, async () =>
    page([
      {
        ...record,
        details: "My privacy case",
        owner: { id: 4, name: "Owner", email: "owner@example.test" },
      },
    ]),
  );
  await tick();
  assert.match(ui.admin.textContent, /My privacy case/);
  assert.equal(
    nodes(ui.admin, "form").filter((x) => nodes(x, "textarea").length).length,
    0,
  );
});

test("owner request history pages without retaining another accounts private controls", async () => {
  const ui = fixture(),
    calls = [];
  ui.setSelfActor({ id: 3, role: "customer" }, async (p) => {
    calls.push(p);
    const second = p.endsWith("?page=2");
    return {
      data: [
        {
          ...record,
          id: second ? 11 : 22,
          details: second ? "Older private request" : "Newest request",
        },
      ],
      current_page: second ? 2 : 1,
      last_page: 2,
      total: 26,
    };
  });
  await tick();
  const next = nodes(ui.mine, "button").find((x) =>
    x.textContent.startsWith("Next requests"),
  );
  assert.equal(next.disabled, false);
  await next.onclick();
  await tick();
  assert.equal(calls[1], "account/privacy-requests?page=2");
  assert.match(ui.mine.textContent, /Older private request/);
  assert(!ui.mine.textContent.includes("Newest request"));
  const previous = nodes(ui.mine, "button").find((x) =>
    x.textContent.startsWith("Previous requests"),
  );
  ui.setSelfActor(null, async () => {
    throw Error("No signed-out request allowed");
  });
  await previous.onclick();
  await tick();
  assert.equal(calls.length, 2);
  assert.equal(ui.mine.textContent, "");
});

test("privacy create is single-flight; new-request reset cannot create parallel unconfirmed writes",async()=>{
  const ui=fixture(),posts=[];
  let release;
  const pending=new Promise(resolve=>release=resolve);
  let committed=[];
  ui.setSelfActor({id:3,role:"customer"},async (path,method="GET",body)=>{
    if(method==="POST"){
      posts.push(body);
      await pending;
      committed=[{...record,details:body.details,kind:body.kind}];
      return {id:record.id,status:"pending"};
    }
    return page(committed);
  });
  await tick();
  const form=nodes(ui.mine,"form")[0],details=nodes(form,"textarea")[0];
  details.value="Review all my retained account history before deletion.";
  const first=form.onsubmit({preventDefault(){}});
  await form.onsubmit({preventDefault(){}});
  assert.equal(posts.length,1,"one server mutation while a POST is unresolved");
  const reset=nodes(ui.mine,"button").find(x=>x.textContent.startsWith("Start different request"));
  assert.equal(reset.disabled,true);
  reset.onclick();
  assert.equal(details.value,"Review all my retained account history before deletion.");
  release();
  await first;
  assert.equal(reset.disabled,false);
  assert.equal(details.value,"");
  assert.match(ui.mine.textContent,/Request recorded for manual review/);
});
test("failed privacy refresh revokes stale access-copy controls and clears busy state",async()=>{
  const ui=fixture();
  let calls=0;
  ui.setSelfActor({id:3,role:"customer"},async()=>{
    calls++;
    if(calls>1)throw Error("Lost authenticated read");
    return page([{...record,kind:"access",copy:{status:"available",expires_at:"2026-11-17T00:00:00Z"}}]);
  });
  await tick();
  assert(nodes(ui.mine,"button").some(x=>x.textContent.startsWith("View private account copy")));
  const reload=nodes(ui.mine,"button").find(x=>x.textContent.startsWith("Refresh requests"));
  const reading=reload.onclick();
  assert(!nodes(ui.mine,"button").some(x=>x.textContent.startsWith("View private account copy")),
    "any access-copy view controls must disappear before a refresh reply");
  await reading;
  assert(!nodes(ui.mine,"button").some(x=>x.textContent.startsWith("View private account copy")));
  assert.match(ui.mine.textContent,/Could not load your request history/);
  const list=ui.mine.children.find(x=>x.tag==="div");
  assert.equal(list.attributes["aria-busy"],undefined);
});

test("untrusted customer history cannot render withdrawal or export controls",async()=>{
  const corrupt=[
    {...record,id:"22/withdraw",kind:"access",copy:{status:"available",expires_at:"2026-11-30"}},
    {...record,id:0},
    {...record,kind:"untrusted"},
    {...record,status:"untrusted"},
    {...record,kind:"access",copy:{status:"available"}},
    {...record,kind:"access",copy:{status:"untrusted"}},
    {...record,created_at:123},
    {...record,details:"X".repeat(2001)},
    null,
  ];
  for(const value of corrupt){
    const ui=fixture(),calls=[];
    let broken=true;
    ui.setSelfActor({id:3,role:"customer"},async(path,method="GET")=>{
      calls.push({path,method});return page(broken?[value]:[record]);
    });
    await tick();
    assert.match(ui.mine.textContent,/Could not load your request history/);
    assert(!nodes(ui.mine,"button").some(b=>
      b.textContent.startsWith("Withdraw request") ||
      b.textContent.startsWith("View private account copy")));
    broken=false;
    await nodes(ui.mine,"button").find(b=>b.textContent.startsWith("Refresh requests")).onclick();
    await tick();
    assert.match(ui.mine.textContent,/Request #22/);
    assert(nodes(ui.mine,"button").some(b=>b.textContent.startsWith("Withdraw request")));
    assert(calls.every(c=>c.method==="GET"));
  }
  for(const invalid of [
    {data:[record],current_page:2,last_page:2,total:26},
    {data:[record],current_page:1,last_page:1,total:0},
    {data:[record],current_page:1,last_page:1,total:26},
    {data:[record,record],current_page:1,last_page:1,total:1},
    {data:[record,record],current_page:1,last_page:1,total:2},
  ]){
    const ui=fixture();
    ui.setSelfActor({id:3,role:"customer"},async()=>invalid);
    await tick();
    assert.match(ui.mine.textContent,/Could not load your request history/);
    assert(!nodes(ui.mine,"button").some(b=>b.textContent.startsWith("Withdraw request")));
  }
});
test("malformed private history fails closed rather than showing false empty results",async()=>{
  const ui=fixture();
  ui.setSelfActor({id:3,role:"customer"},async()=>({data:"corrupt",current_page:1,last_page:1}));
  await tick();
  assert.match(ui.mine.textContent,/Could not load your request history/);
  assert(!ui.mine.textContent.includes("No privacy requests submitted"));
});

test("hidden browser tab wipes revealed private account copy and password inputs",async()=>{
  const ui=fixture();
  const accountCopy={schema_version:1,owner_id:3,request_id:22,
    scope:"self_service_account_copy",profile:{email:"confidential@example.test"}};
  ui.setSelfActor({id:3,role:"customer"},async (path,method="GET")=>{
    if(method==="POST") return accountCopy;
    return page([{...record,kind:"access",copy:{status:"available",expires_at:"2026-11-20T00:00:00Z"}}]);
  });
  await tick();
  const view=nodes(ui.mine,"button").find(x=>x.textContent.startsWith("View private account copy"));
  const inputs=nodes(ui.mine,"input");
  inputs.find(x=>x.type==="password").value="SensitiveTestPassword";
  inputs.find(x=>x.autocomplete==="one-time-code").value="123456";
  await view.onclick();
  const preview=nodes(ui.mine,"pre")[0];
  assert.match(preview.textContent,/confidential@example.test/);
  ui.hide();
  assert.equal(preview.textContent,"");
  assert.equal(inputs.find(x=>x.type==="password").value,"");
  assert.equal(inputs.find(x=>x.autocomplete==="one-time-code").value,"");
  ui.show();
  // Private controls were detached and cannot be re-used after returning.
  assert.equal(nodes(ui.mine,"pre").length,0);
  const reload=nodes(ui.mine,"button").find(x=>
    x.textContent.startsWith("Refresh requests"));
  await reload.onclick();
  await tick();
  const freshInputs=nodes(ui.mine,"input");
  freshInputs.find(x=>x.type==="password").value="Entered after foreground";
  freshInputs.find(x=>x.autocomplete==="one-time-code").value="654321";
  ui.hide();
  assert.equal(freshInputs.find(x=>x.type==="password").value,"");
  assert.equal(freshInputs.find(x=>x.autocomplete==="one-time-code").value,"");
  assert.equal(nodes(ui.mine,"pre").length,0);
});
test("private copy response stays sealed across a hidden-to-visible round trip", async () => {
  const ui = fixture();
  let releaseFirst;
  let reads = 0;
  ui.setSelfActor({ id: 3, role: "customer" }, async (path, method = "GET") => {
    if (method === "POST") {
      reads++;
      if (reads === 1)
        return new Promise((resolve) => { releaseFirst = resolve; });
      return { schema_version: 1, owner_id: 3, request_id: 22,
        scope: "self_service_account_copy",
        profile: { email: "fresh-visible-copy@example.test" } };
    }
    return page([{ ...record, kind: "access",
      copy: { status: "available", expires_at: "2026-11-20T00:00:00Z" } }]);
  });
  await tick();
  const view = nodes(ui.mine, "button").find(x =>
    x.textContent.startsWith("View private account copy"));
  const inFlight = view.onclick();
  assert.equal(typeof releaseFirst, "function");
  ui.hide();
  ui.show();
  releaseFirst({ schema_version: 1, owner_id: 3, request_id: 22,
    scope: "self_service_account_copy",
    profile: { email: "old-background-copy@example.test" } });
  await inFlight;
  assert.equal(nodes(ui.mine, "pre").length, 0);
  assert(!ui.mine.textContent.includes("old-background-copy@example.test"));
  // Returning to the tab requires a *new* read and reauthentication,
  // never replaying a detached pre-hide callback.
  await view.onclick();
  assert.equal(reads, 1, "Old detached action cannot submit again");
  const reload=nodes(ui.mine,"button").find(x=>
    x.textContent.startsWith("Refresh requests"));
  await reload.onclick();
  await tick();
  const freshView=nodes(ui.mine,"button").find(x=>
    x.textContent.startsWith("View private account copy"));
  assert.ok(freshView);
  await freshView.onclick();
  assert.equal(reads, 2);
  assert.match(nodes(ui.mine, "pre")[0].textContent, /fresh-visible-copy@example.test/);
});

test("BFCache pagehide clears credentials and invalidates a pending access copy", async () => {
  const ui = fixture();
  let release;
  ui.setSelfActor({ id: 3, role: "customer" }, (path, method = "GET") => {
    if (method === "POST")
      return new Promise(resolve => { release = resolve; });
    return Promise.resolve(page([{ ...record, kind: "access",
      copy: { status: "available", expires_at: "2026-11-20T00:00:00Z" } }]));
  });
  await tick();
  const input = nodes(ui.mine, "input").find(x => x.type === "password");
  const view = nodes(ui.mine, "button").find(x =>
    x.textContent.startsWith("View private account copy"));
  const pending = view.onclick();
  // The user may enter another secret while the first request is pending.
  input.value = "Sensitive for navigation";
  ui.pagehide();
  assert.equal(input.value, "");
  release({ schema_version: 1, owner_id: 3, request_id: 22,
    scope: "self_service_account_copy",
    profile: { email: "forbidden-bfcache-reveal@example.test" } });
  await pending;
  assert.equal(nodes(ui.mine, "pre").length, 0);
  assert(!ui.mine.textContent.includes("forbidden-bfcache-reveal@example.test"));
});

test("late private copy read cannot repopulate a hidden browser tab",async()=>{
  const ui=fixture();
  let resolveCopy;
  const copyPending=new Promise(resolve=>resolveCopy=resolve);
  ui.setSelfActor({id:3,role:"customer"},async (path,method="GET")=>{
    if(method==="POST") return copyPending;
    return page([{...record,kind:"access",copy:{status:"available",expires_at:"2026-11-20T00:00:00Z"}}]);
  });
  await tick();
  const view=nodes(ui.mine,"button").find(x=>x.textContent.startsWith("View private account copy"));
  const pending=view.onclick();
  ui.hide();
  resolveCopy({schema_version:1,owner_id:3,request_id:22,
    scope:"self_service_account_copy",profile:{email:"not-visible@example.test"}});
  await pending;
  assert.equal(nodes(ui.mine,"pre").length,0);
  assert(!ui.mine.textContent.includes("not-visible@example.test"));
});

test("contact minimization requires explicit consent and validates the exact private receipt", async()=>{
  const ui=fixture(),calls=[];
  ui.setSelfActor({id:3,role:"customer"},async(path,method="GET",body)=>{
    calls.push({path,method,body});
    if(method==="POST")return {request_id:22,case_status:"reviewing",optional_phone_removed:true,profile_phone_changed:true,
      live_challenges_cancelled:1,challenge_payloads_cleared:1,verification_receipts_retained:true,external_deletion_verified:false,
      account_deleted:false,automated_erasure_performed:false,manual_processing_required:true};
    return page([record]);
  });
  await tick();
  nodes(ui.mine,"button").find(b=>b.textContent.startsWith("Remove optional phone data")).onclick();
  const form=nodes(ui.mine,"form").find(f=>f.attributes["aria-label"]==="Optional contact minimization");
  const [password,factor,consent]=nodes(form,"input");
  password.value="password";factor.value="123456";
  await form.onsubmit({preventDefault(){}});assert.equal(calls.length,1);
  consent.checked=true;await form.onsubmit({preventDefault(){}});
  assert.equal(calls.length,2);assert.equal(calls[1].path,"account/privacy-requests/22/minimize-contact");
  assert.equal(calls[1].body.confirmation,"remove_optional_phone");
  assert.equal(password.value,"");assert.equal(factor.value,"");
  assert.match(ui.mine.textContent,/Optional phone removal recorded/);
  await form.onsubmit({preventDefault(){}});assert.equal(calls.length,2);
});
test("contact minimization rejects false external deletion and does not retry",async()=>{
  const ui=fixture();let posts=0;
  ui.setSelfActor({id:3,role:"vendor"},async(path,method="GET")=>{
    if(method==="POST"){posts++;return {request_id:22,case_status:"pending",optional_phone_removed:true,profile_phone_changed:true,
      live_challenges_cancelled:1,challenge_payloads_cleared:1,verification_receipts_retained:true,external_deletion_verified:true,
      account_deleted:false,automated_erasure_performed:false,manual_processing_required:true};}
    return page([record]);
  });
  await tick();
  nodes(ui.mine,"button").find(b=>b.textContent.startsWith("Remove optional phone data")).onclick();
  const form=nodes(ui.mine,"form").find(f=>f.attributes["aria-label"]==="Optional contact minimization");
  const [password,,consent]=nodes(form,"input");password.value="password";consent.checked=true;
  await form.onsubmit({preventDefault(){}});assert.match(ui.mine.textContent,/Phone removal unconfirmed/);
  assert(!ui.mine.textContent.includes("Optional phone removal recorded"));
  await form.onsubmit({preventDefault(){}});assert.equal(posts,1);
});
test("contact minimization clears secrets and ignores detached responses after backgrounding",async()=>{
  const ui=fixture();let release,posts=0;
  ui.setSelfActor({id:3,role:"customer"},async(path,method="GET")=>{
    if(method==="POST"){posts++;return new Promise(resolve=>{release=resolve;});}
    return page([record]);
  });
  await tick();
  nodes(ui.mine,"button").find(b=>b.textContent.startsWith("Remove optional phone data")).onclick();
  const form=nodes(ui.mine,"form").find(f=>f.attributes["aria-label"]==="Optional contact minimization");
  const [password,factor,consent]=nodes(form,"input");password.value="password";factor.value="123456";consent.checked=true;
  const pending=form.onsubmit({preventDefault(){}});ui.hide();ui.show();
  release({request_id:22,case_status:"pending",optional_phone_removed:true,profile_phone_changed:true,
    live_challenges_cancelled:1,challenge_payloads_cleared:1,verification_receipts_retained:true,external_deletion_verified:false,
    account_deleted:false,automated_erasure_performed:false,manual_processing_required:true});
  await pending;assert.equal(password.value,"");assert.equal(factor.value,"");
  assert(!ui.mine.textContent.includes("Optional phone removal recorded"));
  await form.onsubmit({preventDefault(){}});assert.equal(posts,1);
});

test("optional erasure security step explicitly confirms scoped copy discard and ends local identity",async()=>{
  const ui=fixture(),calls=[];let ended=0;
  ui.setSelfActor({id:3,role:"customer"},async(path,method="GET",body)=>{
    calls.push({path,method,body});
    if(method==="POST")return {request_id:22,case_status:"reviewing",derived_copies_discarded:1,sessions_revoked:true,
      sign_in_required:true,automated_erasure_performed:false,source_records_deleted:false,manual_processing_required:true};
    return page([record]);
  },()=>ended++);
  await tick();
  nodes(ui.mine,"button").find(b=>b.textContent.startsWith("Secure account before erasure")).onclick();
  const form=nodes(ui.mine,"form").find(f=>f.attributes["aria-label"]==="Optional erasure security preparation");
  const [password,factor,consent]=nodes(form,"input");
  password.value="password";factor.value="123456";
  await form.onsubmit({preventDefault(){}});
  assert.equal(calls.length,1);
  consent.checked=true;
  await form.onsubmit({preventDefault(){}});
  assert.equal(calls.length,2);assert.equal(calls[1].path,"account/privacy-requests/22/secure-account");
  assert.equal(calls[1].body.confirmation,"discard_copies_and_revoke_sessions");
  assert.equal(password.value,"");assert.equal(factor.value,"");assert.equal(ended,1);
  assert.equal(ui.mine.children.length,0);
  await form.onsubmit({preventDefault(){}});assert.equal(calls.length,2);
});
test("unverified erasure security receipt never reports success or retries",async()=>{
  const ui=fixture();let ended=0,posts=0;
  ui.setSelfActor({id:3,role:"vendor"},async(path,method="GET")=>{
    if(method==="POST"){posts++;return {request_id:99,sessions_revoked:true};}
    return page([record]);
  },()=>ended++);
  await tick();
  nodes(ui.mine,"button").find(b=>b.textContent.startsWith("Secure account before erasure")).onclick();
  const form=nodes(ui.mine,"form").find(f=>f.attributes["aria-label"]==="Optional erasure security preparation");
  const [password,,consent]=nodes(form,"input");password.value="password";consent.checked=true;
  await form.onsubmit({preventDefault(){}});
  assert.match(ui.mine.textContent,/Security step unconfirmed/);assert.equal(ended,0);
  await form.onsubmit({preventDefault(){}});assert.equal(posts,1);
});

test("erasure preparation requires explicit two-reviewer workflow and never authorizes deletion", async()=>{
  const ui=fixture(),calls=[];
  let p={request_id:22,review_revision:"7",review_scope:"a".repeat(64),preparation_id:null,stage:"not_prepared",
    disposition:"retain_pending_legal_and_external_review",proposed_by:null,confirmed_by:null,
    execution_authorized:false,legal_retention_verified:false,automated_erasure_performed:false};
  const api=async(path,method="GET",body)=>{
    calls.push({path,method,body});
    if(path.endsWith("/erasure-preparation")){
      if(method==="POST"){
        assert.equal(body.review_scope,p.review_scope);
        assert.equal(body.review_revision,"7");
        if(body.action==="propose")p={...p,stage:"awaiting_independent_review",preparation_id:"8",proposed_by:9};
        else{assert.equal(body.preparation_id,"8");p={...p,stage:"assisted_review_prepared",confirmed_by:10};}
      }
      return p;
    }
    return page([{...record,status:"reviewing",details:"Retained account review",owner:{id:3,name:"Account"}}]);
  };
  const mount=async id=>{ui.setAdminActor({id,role:"admin"},api);await tick();
    await nodes(ui.admin,"button").find(x=>x.textContent.startsWith("Load erasure preparation")).onclick();};
  await mount(9);
  let form=nodes(ui.admin,"form").find(f=>f.attributes["aria-label"]?.startsWith("Assisted erasure preparation"));
  nodes(form,"input")[0].value="password";
  nodes(form,"textarea")[0].value="Document retention for assisted review";
  await form.onsubmit({preventDefault(){}});
  assert.match(ui.admin.textContent,/Awaiting independent reviewer/);
  assert(!nodes(ui.admin,"button").some(b=>b.textContent.startsWith("Independently confirm preparation")));
  await mount(10);
  form=nodes(ui.admin,"form").find(f=>f.attributes["aria-label"]?.startsWith("Assisted erasure preparation"));
  nodes(form,"input")[0].value="password";
  nodes(form,"textarea")[0].value="Independent retention review recorded";
  await form.onsubmit({preventDefault(){}});
  assert.match(ui.admin.textContent,/Assisted review prepared/);
  assert.match(ui.admin.textContent,/No deletion is authorized/);
  assert.equal(calls.filter(c=>c.method==="POST").length,2);
  p.execution_authorized=true;
  await mount(10);
  assert.match(ui.admin.textContent,/Preparation unavailable/);
});

test("erasure assessment is explicit, scoped and non-authorizing", async () => {
  const ui=fixture(),calls=[];
  const report={request_id:22,owner_id:3,case_status:"pending",scope:"local_dependency_assessment",
    manual_processing_required:true,execution_authorized:false,automated_erasure_performed:false,legal_retention_verified:false,
    dependencies:["financial_records","charging_records","business_relationships","support_records","privacy_records","active_credentials","external_and_legal_retention"].map(key=>({key,state:key==="external_and_legal_retention"?"unknown":"present"}))};
  ui.setAdminActor({id:9,role:"admin"},async(path,method="GET")=>{
    calls.push({path,method});
    if(path.endsWith("/erasure-assessment"))return report;
    return page([{...record,details:"Manual erasure review",owner:{id:3,name:"Account"}}]);
  });
  await tick();
  const inspect=nodes(ui.admin,"button").find(x=>x.textContent.startsWith("Assess erasure dependencies"));
  assert.ok(inspect);
  assert.equal(calls.length,1);
  await inspect.onclick();
  assert.equal(calls[1].path,"admin/privacy-requests/22/erasure-assessment");
  assert.equal(calls[1].method,"GET");
  assert.match(ui.admin.textContent,/No deletion is authorized/);
  assert.match(ui.admin.textContent,/External and legal retention/);
  report.execution_authorized=true;
  await inspect.onclick();
  assert.match(ui.admin.textContent,/Assessment unavailable/);
  assert(!ui.admin.textContent.includes("External and legal retention"));
});
test("erasure assessment cannot return after a hidden-visible transition", async()=>{
  const ui=fixture();let release;let reads=0;
  ui.setAdminActor({id:9,role:"admin"},async path=>{
    if(path.endsWith("/erasure-assessment")){reads++;return new Promise(resolve=>{release=resolve;});}
    return page([{...record,details:"Manual erasure review",owner:{id:3,name:"Account"}}]);
  });
  await tick();
  const inspect=nodes(ui.admin,"button").find(x=>x.textContent.startsWith("Assess erasure dependencies"));
  const pending=inspect.onclick();
  ui.hide();ui.show();
  release({request_id:22,owner_id:3,case_status:"pending",scope:"local_dependency_assessment",
    manual_processing_required:true,execution_authorized:false,automated_erasure_performed:false,legal_retention_verified:false,
    dependencies:["financial_records","charging_records","business_relationships","support_records","privacy_records","active_credentials","external_and_legal_retention"].map(key=>({key,state:"unknown"}))});
  await pending;
  assert(!ui.admin.textContent.includes("External and legal retention"));
  await inspect.onclick();assert.equal(reads,1);
});
