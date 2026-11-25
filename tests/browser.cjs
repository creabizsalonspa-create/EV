const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const phpRunner = process.env.EV_TEST_PHP_RUNNER || "./scripts/php";

const {provisionTestRole}=require('./helpers/browser-role.cjs');
const { waitForFreshWindow } = require("./helpers/auth-batches.cjs");
// Run the exact production support controller with a minimal DOM before
// the real Laravel/browser journey. No actual ticket is mutated by this test.
async function supportReceiptRegression() {
  const source = require("node:fs").readFileSync("services/business-api/public/platform.js", "utf8");
  const start = source.indexOf("async function ticketDetail(");
  const end = source.indexOf('\nel("support-create").onsubmit', start);
  assert(start >= 0 && end > start);
  class Element {
    constructor(tag, text = "") {
      this.tag = tag; this.textContent = text; this.children = [];
      this.dataset = {}; this.value = ""; this.disabled = false;
    }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    setAttribute() {}
    removeAttribute() {}
    contains(node) { return this === node || this.children.some(e => e.contains(node)); }
    find(tag) {
      if (this.tag === tag) return this;
      return this.children.map(e => e.find(tag)).find(Boolean);
    }
    querySelector(tag) { return this.find(tag); }
    get elements() {
      const field = this.find("input");
      return field?.name === "internal" ? {internal: field} : {};
    }
  }
  const event = {preventDefault() {}};
  for (const role of ["customer", "admin"]) {
    const root = new Element("div"), client = {sessionEpoch: 1}, owner = 7, id = 42;
    let reads = 0, refreshes = 0, notices = 0, finish, finishRefresh;
    const posts = [];
    const node = (tag, value) => new Element(tag, value);
    const view = new Function("document", "user", "client", "api", "el", "batteryNode", "node", "ui",
      "EvLocale", "crypto", "notice", "support",
      "let ticketDetailRequest=null,ticketDetailGeneration=0;\n" + source.slice(start,end) + "\nreturn ticketDetail;")(
        {createElement: tag => new Element(tag)}, {id: owner, role}, client,
        async (path, method, body) => {
          if (method === "GET") {
            reads++;
            return {ticket:{id,subject:"Fixture",status:"open"},
              messages:{data:[],current_page:1,last_page:1,total:0}};
          }
          posts.push({path,method,body:{...body}});
          if (posts.length === 1) return new Promise(resolve => {finish=resolve;});
          return role === "admin" ? {id,status:body.status} :
            {id:9,support_ticket_id:id,author_id:owner,body:body.body,internal:body.internal};
        }, () => root, node, node, node, {attribute(){}},
        {randomUUID}, () => notices++,
        () => {refreshes++;return new Promise(resolve => {finishRefresh=resolve;});}
      );
    await view(id);
    const forms = root.children.filter(e => e.tag === "form");
    const form = forms[role === "admin" ? 1 : 0];
    const field = form.find(role === "admin" ? "input" : "textarea");
    field.value = "Reviewed private response";
    if (role === "admin") form.find("select").value = "resolved";
    const first = form.onsubmit(event);
    await form.onsubmit(event);
    assert.equal(posts.length,1,"duplicate callback must not dispatch");
    if (role === "admin") {
      forms[0].find("textarea").value="Concurrent reply";
      await forms[0].onsubmit(event);
      assert.equal(posts.length,1,"other write must not dispatch");
    }
    finish(role === "admin" ? {id,status:"closed"} :
      {id:9,support_ticket_id:99,author_id:owner,body:field.value,internal:false});
    await first;
    assert.equal(notices,1);
    assert.equal(field.readOnly,true);
    assert.equal(field.value,"Reviewed private response");
    // A malicious/stale callback cannot silently replace the uncertain intent.
    field.value="Changed DOM value";
    const retry = form.onsubmit(event);
    await Promise.resolve(); await Promise.resolve();
    assert.equal(posts.length,2);
    assert.deepEqual(posts[0],posts[1]);
    assert.equal(refreshes,1);
    client.sessionEpoch++;
    finishRefresh();
    await retry;
    assert.equal(reads,1,"retired response cannot read ticket under successor account");
  }
  console.log("PASS exact support reply/status recovery: frozen request, duplicate denial, receipt validation and retired-account refresh");
}

async function supportCreateReceiptRegression() {
  const source = require("node:fs").readFileSync("services/business-api/public/platform.js","utf8");
  const start=source.indexOf('el("support-create").onsubmit = async (e) => {');
  const end=source.indexOf('\nel("verify-email")',start);
  assert(start>=0 && end>start);
  const subject={value:"  Original issue  "}, body={value:"  Original message  "}, submit={};
  const form={isConnected:true,elements:{namedItem:n=>n==="subject"?subject:body},
    querySelector:()=>submit,reset(){subject.value=body.value="";}};
  const client={sessionEpoch:1}, user={id:7,role:"customer"}, posts=[];
  let settle,finishRefresh,refreshes=0,notices=0,opened=0;
  new Function("el","client","user","crypto","api","support","ticketDetail","notice",
    "let pendingTicketCreate=null,ticketCreatePending=false,ticketCreateGeneration=0,supportPage=1;\n"+
    source.slice(start,end))(()=>form,client,user,{randomUUID},
      async (path,method,payload)=>{
        assert.equal(path,"support");assert.equal(method,"POST");
        posts.push({...payload});
        if(posts.length===1)return new Promise(resolve=>{settle=resolve;});
        return {id:42,user_id:7,subject:payload.subject,status:"open"};
      },()=>{refreshes++;return new Promise(resolve=>{finishRefresh=resolve;});},
      ()=>{opened++;},()=>{notices++;});
  const event={target:form,preventDefault(){}};
  const first=form.onsubmit(event);
  await form.onsubmit(event);
  assert.equal(posts.length,1);
  settle({id:42,user_id:8,subject:"Original issue",status:"open"});
  await first;
  assert.equal(notices,1);
  assert.equal(subject.readOnly,true);
  subject.value="Modified issue";body.value="Modified message";
  const retry=form.onsubmit(event);
  await Promise.resolve();await Promise.resolve();
  assert.deepEqual(posts[0],posts[1]);
  assert.equal(posts[1].subject,"Original issue");
  assert.equal(posts[1].body,"Original message");
  assert.equal(refreshes,1);
  client.sessionEpoch++;
  finishRefresh();
  await retry;
  assert.equal(opened,0);
  form.isConnected=false;
  await form.onsubmit(event);
  assert.equal(posts.length,2);
  console.log("PASS support creation exact owner/subject, frozen retry and session-bound refresh");
}

(async () => {
  await supportReceiptRegression();
  await supportCreateReceiptRegression();
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  const run = randomUUID().slice(0, 8),
    password = randomUUID() + "aA9!";
  const errors = [];
  async function account(name) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(base);
    await page.locator("#account [name=name]").fill(name);
    const email = name.toLowerCase() + run + "@example.test";
    await page.locator("#account [name=email]").fill(email);
    await page.locator("#account [name=password]").fill(password);
    for (let attempt = 0; attempt < 2; attempt++) {
      const reply = page.waitForResponse(
        (r) =>
          r.url().endsWith("/api/register") && r.request().method() === "POST",
      );
      await page.locator("button[value=register]").click();
      const response = await reply;
      if (response.status() === 429 && attempt === 0) {
        const seconds = Number(response.headers()["retry-after"] || 60);
        assert(seconds <= 60);
        console.log("Respecting authentication rate-limit retry interval.");
        await page.waitForTimeout((seconds + 1) * 1000);
        continue;
      }
      assert.equal(
        response.status(),
        201,
        (await response.json()).message || "Registration failed",
      );
      break;
    }
    await page.locator("#signed").waitFor({ state: "visible" });
    return { page, email };
  }
  try {
    const vendor = await account("Vendor");
    const theme = vendor.page.locator("#theme-choice");
    await vendor.page.emulateMedia({ colorScheme: "dark" });
    await theme.selectOption("system");
    await vendor.page.waitForFunction(
      () => document.documentElement.dataset.theme === "dark",
    );
    const darkBackground = await vendor.page.evaluate(
      () => getComputedStyle(document.body).backgroundColor,
    );
    await vendor.page.emulateMedia({ colorScheme: "light" });
    await vendor.page.waitForFunction(
      () => document.documentElement.dataset.theme === "light",
    );
    assert.notEqual(
      await vendor.page.evaluate(
        () => getComputedStyle(document.body).backgroundColor,
      ),
      darkBackground,
    );
    await theme.selectOption("dark");
    await vendor.page.reload();
    assert.equal(await theme.inputValue(), "dark");
    assert.equal(
      await vendor.page.locator("html").getAttribute("data-theme"),
      "dark",
    );
    await theme.selectOption("light");
    await vendor.page.emulateMedia({ colorScheme: "dark" });
    assert.equal(
      await vendor.page.locator("html").getAttribute("data-theme"),
      "light",
    );
    await theme.selectOption("dark");
    // Reload restores identity asynchronously; theme scripts do not imply account readiness.
    await vendor.page
      .locator("#email-status")
      .filter({ hasText: "Email not verified" })
      .waitFor();
    assert.match(
      await vendor.page.locator("#email-status").textContent(),
      /Email not verified/,
    );
    const sent = vendor.page.waitForResponse((r) =>
      r.url().endsWith("/api/email/verification-notification"),
    );
    await vendor.page.locator("#verify-email").click();
    assert.equal((await sent).status(), 202);
    // Obtain the normal notification URL in memory through the local test shell.
    // This is test-only tooling, not a public verification bypass or mail-delivery claim.
    const verificationUrl = execFileSync(
      phpRunner,
      [
        "-r",
        `
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
      Illuminate\\Support\\Facades\\URL::forceRootUrl($argv[2]);
      $user = App\\Models\\User::where("email", $argv[1])->firstOrFail();
      echo (new Illuminate\\Auth\\Notifications\\VerifyEmail)->toMail($user)->actionUrl;
    `,
        "--",
        vendor.email,
        base,
      ],
      { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
    ).trim();
    await vendor.page.goto(verificationUrl);
    await vendor.page
      .getByRole("heading", { name: /Email verified/ })
      .waitFor();
    await vendor.page
      .getByRole("link", { name: /Return to your account/ })
      .click();
    await vendor.page.locator("#signed").waitFor({ state: "visible" });
    assert.match(
      await vendor.page.locator("#email-status").textContent(),
      /Email verified/,
    );
    assert.equal(await vendor.page.locator("#verify-email").isVisible(), false);
    if(process.env.EV_ROLE_SQLITE_PROBE==='1'){
      await require('./helpers/sqlite-role-probe.cjs').provisionWithSqliteProbe(vendor.email,'vendor',{page:vendor.page,runner:phpRunner});
    }else{
      await provisionTestRole(vendor.email, "vendor", {pages: [vendor.page], runner: phpRunner});
    }
    // The audited role promotion revokes the old browser authorization
    // epoch. Verify that boundary, then sign in again as the vendor.
    const staleVendorIdentity = vendor.page.waitForResponse(
      (response) =>
        response.url().endsWith("/api/me") &&
        response.request().method() === "GET",
    );
    await vendor.page.reload();
    assert.equal((await staleVendorIdentity).status(), 401);
    await vendor.page.locator("#account").waitFor({ state: "visible" });
    await vendor.page.locator("#account [name=email]").fill(vendor.email);
    await vendor.page.locator("#account [name=password]").fill(password);
    const freshVendorLogin = vendor.page.waitForResponse(
      (response) =>
        response.url().endsWith("/api/login") &&
        response.request().method() === "POST",
    );
    await vendor.page.locator("#account button[value=login]").click();
    assert.equal((await freshVendorLogin).status(), 200);
    await vendor.page.locator("#signed").waitFor({ state: "visible" });
    await vendor.page.locator("#vendor").waitFor({ state: "visible" });
    const f = vendor.page.locator("#station-form");
    for (const [key, value] of Object.entries({
      name: "Browser station " + run,
      latitude: "25.2",
      longitude: "55.3",
      power_kw: "50",
    }))
      await f.locator(`[name=${key}]`).fill(value);
    await f.locator("[name=source]").selectOption("simulator");
    await f.locator("button").click();
    await vendor.page
      .locator("#stations")
      .getByRole("heading", { name: "Browser station " + run, exact: true })
      .waitFor();
    const customer = await account("Customer");
    const article = customer.page
      .locator("#stations article")
      .filter({ hasText: "Browser station " + run });
    const slot = new Date(Date.now() + 86400000);
    slot.setUTCMinutes(0, 0, 0);
    // Vendor station uses Asia/Dubai; book the same instant via station-local entry.
    await article.locator("select[data-booking-time-mode]").selectOption("station");
    await article
      .locator("input[type=datetime-local]")
      .fill(new Date(slot.getTime() + 4 * 3600000).toISOString().slice(0, 16));
    const bookingRequest = customer.page.waitForRequest((request) =>
      request.method() === "POST" && request.url().includes("/reservations"),
    );
    await article
      .getByRole("button", { name: "Reserve simulator slot" })
      .click();
    assert.equal(
      (await bookingRequest).postDataJSON().starts_at,
      slot.toISOString().replace(".000Z", "Z"),
    );
    await customer.page.locator("#reservation-list article").waitFor();
    assert.match(
      await customer.page.locator("#reservation-list article").first().textContent(),
      /Station local.*Asia\/Dubai/,
    );
    await vendor.page.reload();
    await vendor.page.locator("#reservation-list article").waitFor();
    await customer.page
      .locator("#reservation-list button")
      .filter({ hasText: "Cancel" })
      .click();
    await customer.page.waitForFunction(() =>
      document
        .querySelector("#reservation-list")
        .textContent.includes("cancelled"),
    );
    await customer.page.locator("#language").click();
    assert.equal(
      await customer.page.locator("html").getAttribute("dir"),
      "rtl",
    );
    assert.match(
      await customer.page.locator("#reservation-list article").first().textContent(),
      /توقيت المحطة/,
    );
    await customer.page.setViewportSize({ width: 390, height: 844 });
    assert(
      await customer.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    // Synthetic 26-row server pagination projection: browser must not truncate
    // the first page, retain stale pages after errors, or silently forge actions.
    const firstPage = await customer.page.evaluate(() => api("reservations?page=1"));
    assert.equal(firstPage.data.length, 1);
    const secondId = 900000000;
    let reservationsFail = false;
    await customer.page.route("**/api/reservations?page=*", async (route) => {
      if (reservationsFail) {
        await route.fulfill({ status: 503, contentType: "application/json",
          body: JSON.stringify({ message: "Synthetic outage" }) });
        return;
      }
      const page = Number(new URL(route.request().url()).searchParams.get("page"));
      const data = page === 2
        ? [{ ...firstPage.data[0], id: secondId, status: "cancelled" }]
        : firstPage.data;
      await route.fulfill({ status: 200, contentType: "application/json",
        body: JSON.stringify({ data, current_page: page, last_page: 2,
          total: 26, next_page_url: null, prev_page_url: null }) });
    });
    await customer.page.evaluate(() => bookings(1));
    assert.equal(await customer.page.locator("#reservation-list [data-reservation-page-action=previous]").isDisabled(), true);
    await customer.page.locator("#reservation-list [data-reservation-page-action=next]").click();
    await customer.page.waitForFunction((id) =>
      document.querySelector("#reservation-list article")?.textContent.includes("#" + id), secondId);
    assert.equal(await customer.page.locator("#reservation-list [data-reservation-page-action=next]").isDisabled(), true);
    reservationsFail = true;
    await customer.page.locator("#reservation-list [data-reservation-page-action=refresh]").click();
    await customer.page.locator("#reservation-list [data-reservation-retry]").waitFor();
    assert.equal(await customer.page.locator("#reservation-list article").count(), 0,
      "failed reload cannot leave a stale private reservation visible");
    reservationsFail = false;
    await customer.page.locator("#reservation-list [data-reservation-retry]").click();
    await customer.page.waitForFunction((id) =>
      document.querySelector("#reservation-list article")?.textContent.includes("#" + id), secondId);
    await customer.page.unroute("**/api/reservations?page=*");
    await customer.page.evaluate(() => bookings(1));
    // The canonical session feed is 25/page. Synthetic catalog rows exercise
    // navigation only; they never simulate a real charger or payment.
    const historicalSession = {
      id: 901000000, reservation_id: 1, status: "cancelled",
      energy_wh: 0, currency: "AED", total_minor: null,
      csms_linked: false, cost_projection: null,
    };
    let sessionOutage = false;
    await customer.page.route("**/api/simulations?page=*", async (route) => {
      if (sessionOutage) {
        await route.fulfill({status:503,contentType:"application/json",
          body:JSON.stringify({message:"Synthetic session outage"})});
        return;
      }
      const page = Number(new URL(route.request().url()).searchParams.get("page"));
      await route.fulfill({status:200,contentType:"application/json",
        body:JSON.stringify({
          data:[{...historicalSession,id:page===2?901000001:901000000}],
          current_page:page,last_page:2,total:26,
          next_page_url:null,prev_page_url:null,
        })});
    });
    await customer.page.evaluate(() => simulations(1));
    assert.equal(await customer.page.locator(
      "#simulation-list [data-simulation-page-action=previous]").isDisabled(), true);
    await customer.page.locator(
      "#simulation-list [data-simulation-page-action=next]").click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#simulation-list article")?.textContent.includes("#901000001"));
    assert.equal(await customer.page.locator(
      "#simulation-list [data-simulation-page-action=next]").isDisabled(), true);
    sessionOutage = true;
    await customer.page.locator(
      "#simulation-list [data-simulation-page-action=refresh]").click();
    await customer.page.locator("#simulation-list [data-simulation-retry]").waitFor();
    assert.equal(await customer.page.locator("#simulation-list article").count(), 0,
      "a failed source read cannot leave an old charging session visible");
    sessionOutage = false;
    await customer.page.locator("#simulation-list [data-simulation-retry]").click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#simulation-list article")?.textContent.includes("#901000001"));
    await customer.page.unroute("**/api/simulations?page=*");
    await customer.page.evaluate(() => simulations(1));
    assert.equal(await customer.page.locator("#simulation-list article").count(), 0);
    const outsider = await account("Outsider");
    assert.equal(
      await outsider.page.locator("#reservation-list article").count(),
      0,
    );
    await customer.page
      .locator("#vehicle-create [name=name]")
      .fill("Browser EV " + run);
    await customer.page.locator("#vehicle-create button").click();
    await customer.page.waitForFunction(
      (name) =>
        document.querySelector("#vehicle-list").textContent.includes(name),
      "Browser EV " + run,
    );
    // Vehicle garage must show pages after the first 25 and keep model /
    // manual-SOC tooling limited to the currently verified private page.
    const firstGaragePage = await customer.page.evaluate(() => api("vehicles?page=1"));
    assert.equal(firstGaragePage.data.length, 1);
    let garageOutage = false;
    const syntheticVehicleId = 901000002;
    await customer.page.route("**/api/vehicles?page=*", async (route) => {
      if (garageOutage) {
        await route.fulfill({status:503, contentType:"application/json",
          body:JSON.stringify({message:"Synthetic garage outage"})});
        return;
      }
      const page = Number(new URL(route.request().url()).searchParams.get("page"));
      const data = page === 2
        ? [{ ...firstGaragePage.data[0], id:syntheticVehicleId,
             name:"Synthetic older EV" }]
        : firstGaragePage.data;
      await route.fulfill({status:200,contentType:"application/json",
        body:JSON.stringify({data,current_page:page,last_page:2,total:26,
          next_page_url:null,prev_page_url:null})});
    });
    await customer.page.evaluate(() => garage(1));
    assert.equal(await customer.page.locator(
      "#vehicle-list [data-garage-page-action=previous]").isDisabled(),true);
    await customer.page.locator(
      "#vehicle-list [data-garage-page-action=next]").click();
    await customer.page.locator(
      "#vehicle-list [data-garage-vehicle-id='" + syntheticVehicleId + "']").waitFor();
    assert.equal(await customer.page.locator(
      "#vehicle-list [data-garage-page-action=next]").isDisabled(),true);
    garageOutage = true;
    await customer.page.locator(
      "#vehicle-list [data-garage-page-action=refresh]").click();
    await customer.page.locator("#vehicle-list [data-garage-retry]").waitFor();
    assert.equal(await customer.page.locator("#vehicle-list [data-garage-vehicle-id]").count(),0);
    garageOutage = false;
    await customer.page.locator("#vehicle-list [data-garage-retry]").click();
    await customer.page.locator(
      "#vehicle-list [data-garage-vehicle-id='" + syntheticVehicleId + "']").waitFor();
    await customer.page.unroute("**/api/vehicles?page=*");
    await customer.page.evaluate(() => garage(1));
    assert.equal(await customer.page.locator(
      "#vehicle-list [data-garage-vehicle-id='" + syntheticVehicleId + "']").count(),0);
    await customer.page.locator("#vehicle-list [data-garage-vehicle-id]").first().waitFor();
    await customer.page
      .locator("#fleet-create [name=name]")
      .fill("Browser fleet " + run);
    // Two near-simultaneous form submissions must create only one fleet.
    // The browser sends the normal form events without bypassing the API.
    const fleetCreateRequests = [];
    const onFleetCreate = (request) => {
      if (new URL(request.url()).pathname === "/api/fleets" &&
          request.method() === "POST") fleetCreateRequests.push(request);
    };
    customer.page.on("request", onFleetCreate);
    await customer.page.evaluate(() => {
      const form = document.getElementById("fleet-create");
      form.requestSubmit();
      form.requestSubmit();
    });
    // Wait for the committed fleet to appear before reading the actual first
    // page; a browser click does not await its asynchronous submit handler.
    await customer.page.locator("#fleet-list button")
      .filter({hasText:"Browser fleet " + run}).waitFor();
    customer.page.off("request", onFleetCreate);
    assert.equal(fleetCreateRequests.length,1,
      "two fast submits must not create duplicate fleet records");
    const firstFleetPage = await customer.page.evaluate(() => api("fleets?page=1"));
    const selectedFleet = firstFleetPage.data.find((f) => f.name === "Browser fleet " + run);
    assert(selectedFleet && Number.isSafeInteger(selectedFleet.id));
    let fleetsFail = false;
    const syntheticFleetId = 901400001;
    await customer.page.route("**/api/fleets?page=*", async (route) => {
      if (fleetsFail) {
        await route.fulfill({status:503,contentType:"application/json",
          body:JSON.stringify({message:"Synthetic fleet page failure"})});
        return;
      }
      const page = Number(new URL(route.request().url()).searchParams.get("page"));
      await route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({
        data:page === 2
          ? [{...selectedFleet,id:syntheticFleetId,name:"Synthetic older fleet"}]
          : firstFleetPage.data,
        current_page:page,last_page:2,total:26,
      })});
    });
    await customer.page.evaluate(() => loadFleetAccounts(1));
    assert.equal(await customer.page.locator(
      "#fleet-list [data-fleet-page-action=previous]").isDisabled(),true);
    await customer.page.locator("#fleet-list [data-fleet-page-action=next]").click();
    await customer.page.locator("#fleet-list [data-fleet-id='" + syntheticFleetId + "']").waitFor();
    fleetsFail = true;
    await customer.page.locator("#fleet-list [data-fleet-page-action=refresh]").click();
    await customer.page.locator("#fleet-list [data-fleet-retry]").waitFor();
    assert.equal(await customer.page.locator("#fleet-list [data-fleet-id]").count(),0);
    fleetsFail = false;
    await customer.page.locator("#fleet-list [data-fleet-retry]").click();
    await customer.page.locator("#fleet-list [data-fleet-id='" + syntheticFleetId + "']").waitFor();
    await customer.page.unroute("**/api/fleets?page=*");
    await customer.page.evaluate(() => loadFleetAccounts(1));
    await customer.page.locator("#fleet-list [data-fleet-id='" + selectedFleet.id + "']").click();
    await customer.page.locator("#fleet-detail input[type=email]").waitFor();
    // A membership refresh must remove detail controls even when the GET
    // fails. The customer's authority may have changed since opening them.
    await customer.page.route("**/api/fleets?page=1", (route) =>
      route.fulfill({status:503,contentType:"application/json",
        body:JSON.stringify({message:"Membership refresh unavailable"})}));
    await customer.page.evaluate(() => loadFleetAccounts(1));
    await customer.page.locator("#fleet-list [data-fleet-retry]").waitFor();
    assert.equal(await customer.page.locator("#fleet-detail input[type=email]").count(),0,
      "failed membership recheck must not leave stale invite controls");
    await customer.page.unroute("**/api/fleets?page=1");
    await customer.page.evaluate(() => loadFleetAccounts(1));
    await customer.page.locator("#fleet-list [data-fleet-id='" + selectedFleet.id + "']").click();
    await customer.page
      .locator("#fleet-detail input[type=email]")
      .fill(outsider.email);
    await customer.page
      .locator("#fleet-detail button")
      .filter({ hasText: "دعوة حساب موجود" })
      .click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#notice").textContent.includes("سُجلت الدعوة"),
    );
    await outsider.page.reload();
    // Page load precedes the authenticated identity bootstrap. Wait for its
    // canonical fleet fetch to finish before routing paginated test fixtures,
    // otherwise the late bootstrap refresh can detach a visible retry button.
    await outsider.page.locator("#fleet-invitations [data-fleet-invitation-id]").waitFor();
    const actualInvitations = await outsider.page.evaluate(() => api("fleet-invitations?page=1"));
    assert.equal(actualInvitations.data.length,1);
    const realInvitation = actualInvitations.data[0];
    const fakeInvitationId = 901400002;
    let invitationsFail = false;
    await outsider.page.route("**/api/fleet-invitations?page=*", async (route) => {
      if (invitationsFail) {
        await route.fulfill({status:503,contentType:"application/json",
          body:JSON.stringify({message:"Synthetic invitation page failure"})});
        return;
      }
      const page = Number(new URL(route.request().url()).searchParams.get("page"));
      await route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({
        data:page === 2 ? [{...realInvitation,id:fakeInvitationId}] : actualInvitations.data,
        current_page:page,last_page:2,total:26,
      })});
    });
    await outsider.page.evaluate(() => loadFleetInvitations(1));
    await outsider.page.locator(
      "#fleet-invitations [data-invitation-page-action=next]").click();
    await outsider.page.locator(
      "#fleet-invitations [data-fleet-invitation-id='" + fakeInvitationId + "']").waitFor();
    invitationsFail = true;
    await outsider.page.locator(
      "#fleet-invitations [data-invitation-page-action=refresh]").click();
    await outsider.page.locator("#fleet-invitations [data-invitation-retry]").waitFor();
    assert.equal(await outsider.page.locator(
      "#fleet-invitations [data-fleet-invitation-id]").count(),0);
    invitationsFail = false;
    await outsider.page.locator("#fleet-invitations [data-invitation-retry]").click();
    await outsider.page.locator(
      "#fleet-invitations [data-fleet-invitation-id='" + fakeInvitationId + "']").waitFor();
    await outsider.page.unroute("**/api/fleet-invitations?page=*");
    await outsider.page.evaluate(() => loadFleetInvitations(1));
    await outsider.page.locator(
      "#fleet-invitations [data-fleet-invitation-id='" + realInvitation.id + "']").click();
    await outsider.page
      .locator("#fleet-list button")
      .filter({ hasText: "Browser fleet " + run })
      .waitFor();
    assert.equal(
      await outsider.page.locator("#vehicle-list [data-garage-vehicle-id]").count(),
      0,
      "a fleet invitation cannot expose another member's private garage",
    );
    await customer.page
      .locator('#fleet-detail input[data-i18n-aria="Cost center code"]')
      .fill("QA");
    await customer.page
      .locator('#fleet-detail input[data-i18n-aria="Cost center name"]')
      .fill("Browser operations");
    const ownFleetVehicles = await customer.page.evaluate(() => api("vehicles?page=1"));
    assert.equal(ownFleetVehicles.data.length,1);
    const syntheticOlderVehicle = 901400003;
    let vehiclePageFail = true;
    await customer.page.route("**/api/vehicles?page=*", async (route) => {
      const page = Number(new URL(route.request().url()).searchParams.get("page"));
      if (page === 2 && vehiclePageFail) {
        await route.fulfill({status:503,contentType:"application/json",
          body:JSON.stringify({message:"Synthetic older vehicle page unavailable"})});
        return;
      }
      await route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({
        data:page === 2
          ? [{...ownFleetVehicles.data[0],id:syntheticOlderVehicle,name:"Older private EV"}]
          : ownFleetVehicles.data,
        current_page:page,last_page:2,total:26,
      })});
    });
    await customer.page
      .locator("#fleet-detail")
      .getByRole("button", { name: "إضافة مركز تكلفة" })
      .click();
    await customer.page.locator("#fleet-assign").waitFor();
    await customer.page.locator("#fleet-assign [data-fleet-more-vehicles]").click();
    await customer.page.waitForFunction(() =>
      !document.querySelector("#fleet-assign [data-fleet-more-vehicles]").disabled);
    assert.equal(await customer.page.locator(
      "#fleet-assign select[name=vehicle_id] option[value='" + syntheticOlderVehicle + "']").count(),0);
    vehiclePageFail = false;
    await customer.page.locator("#fleet-assign [data-fleet-more-vehicles]").click();
    // Native <option> nodes in a closed <select> are attached but hidden.
    // Assert the second-page choice exists without requiring CSS visibility.
    const olderVehicleOption = customer.page.locator(
      "#fleet-assign select[name=vehicle_id] option[value='" + syntheticOlderVehicle + "']");
    await olderVehicleOption.waitFor({state:"attached"});
    assert.equal(await olderVehicleOption.count(),1);
    await customer.page.unroute("**/api/vehicles?page=*");
    await customer.page.locator("#fleet-assign [name=vehicle_id]").selectOption(
      String(ownFleetVehicles.data[0].id));
    await customer.page.locator("#fleet-assign button[type=submit]").click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#fleet-detail").textContent.includes("المركبة #"),
    );
    await customer.page
      .locator("#support-create [name=subject]")
      .fill("Browser support " + run);
    await customer.page
      .locator("#support-create [name=body]")
      .fill("Test support message");
    const createRequest = customer.page.waitForRequest((request) =>
      request.url().endsWith("/api/support") &&
      request.method() === "POST",
    );
    await customer.page.locator("#support-create button").click();
    const createPayload = (await createRequest).postDataJSON();
    assert.match(createPayload.idempotency_key, /^[a-f0-9]{32}$/);
    await customer.page
      .locator("#ticket-detail h3")
      .filter({ hasText: "Browser support " + run })
      .waitFor();
    const savedTicketsBeforeReplay = await customer.page.evaluate(() =>
      api("support"),
    );
    const createdTicket = savedTicketsBeforeReplay.data.find(
      (row) => row.subject === "Browser support " + run,
    );
    assert(createdTicket && Number.isSafeInteger(createdTicket.id));
    assert.equal(
      await customer.page.locator("#support-create [name=subject]").inputValue(),
      "",
    );
    assert.equal(
      await customer.page.locator("#support-create [name=body]").inputValue(),
      "",
    );
    const replayCreatedTicket = await customer.page.evaluate(
      (payload) => api("support", "POST", payload), createPayload,
    );
    assert.equal(replayCreatedTicket.id, createdTicket.id);
    assert.equal(replayCreatedTicket.idempotency_key, undefined);
    const savedTicketsAfterReplay = await customer.page.evaluate(() =>
      api("support"),
    );
    assert.equal(
      savedTicketsAfterReplay.data.filter(
        (row) => row.subject === "Browser support " + run,
      ).length, 1,
    );
    // Paginated ticket API is private; UI must honor real page boundaries
    // and never leave stale ticket data after a failed read.
    const firstSupportPage = await customer.page.evaluate(() => api("support?page=1"));
    assert(firstSupportPage.data.length >= 1);
    let supportOutage = false;
    const syntheticSupportTicket = 900000001;
    await customer.page.route("**/api/support?page=*", async (route) => {
      if (supportOutage) {
        await route.fulfill({ status: 503, contentType: "application/json",
          body: JSON.stringify({ message: "Synthetic support outage" }) });
        return;
      }
      const requested = Number(new URL(route.request().url()).searchParams.get("page"));
      const data = requested === 2
        ? [{ ...firstSupportPage.data[0], id: syntheticSupportTicket,
             subject: "Synthetic older ticket" }]
        : firstSupportPage.data;
      await route.fulfill({ status: 200, contentType: "application/json",
        body: JSON.stringify({ data, current_page: requested,
          last_page: 2, total: 26, next_page_url: null, prev_page_url: null }) });
    });
    await customer.page.evaluate(() => refreshSupportTickets(1));
    assert.equal(await customer.page.locator("#ticket-list [data-support-page-action=previous]").isDisabled(), true);
    await customer.page.locator("#ticket-list [data-support-page-action=next]").click();
    await customer.page.waitForFunction((id) =>
      document.querySelector("#ticket-list [data-support-ticket-id='" + id + "']") != null,
      syntheticSupportTicket);
    assert.equal(await customer.page.locator("#ticket-list [data-support-page-action=next]").isDisabled(), true);
    supportOutage = true;
    await customer.page.locator("#ticket-list [data-support-page-action=refresh]").click();
    await customer.page.locator("#ticket-list [data-support-retry]").waitFor();
    assert.equal(await customer.page.locator("#ticket-list [data-support-ticket-id]").count(), 0);
    supportOutage = false;
    await customer.page.locator("#ticket-list [data-support-retry]").click();
    await customer.page.locator("#ticket-list [data-support-ticket-id='" + syntheticSupportTicket + "']").waitFor();
    await customer.page.unroute("**/api/support?page=*");
    await customer.page.evaluate(() => refreshSupportTickets(1));
    await customer.page.locator("#ticket-list [data-support-ticket-id='" + createdTicket.id + "']").waitFor();

    // Customer messages also paginate server-side (50/page); older and newer
    // messages remain readable without disclosing another ticket's notes.
    const firstConversation = await customer.page.evaluate(
      (id) => api("support/" + id + "?page=1"), createdTicket.id);
    const conversationRoute = (url) =>
      url.pathname === "/api/support/" + createdTicket.id &&
      url.searchParams.has("page");
    let messagesOutage = false;
    await customer.page.route(conversationRoute, async (route) => {
      if (messagesOutage) {
        await route.fulfill({ status: 503, contentType: "application/json",
          body: JSON.stringify({ message: "Synthetic messages outage" }) });
        return;
      }
      const requested = Number(new URL(route.request().url()).searchParams.get("page"));
      const data = requested === 2
        ? [{ id: 900000004, body: "Synthetic last visible message",
             internal: false, created_at: "2026-11-25T15:32:00Z" },
           { id: 900000005, body: "Malformed calendar fixture",
             internal: 0, created_at: "2026-02-30T15:32:00Z" },
           { id: 900000006, body: "Hidden internal test record",
             internal: 1, created_at: "2026-11-25T15:32:00Z" },
           { id: 900000007, body: "Unknown visibility test record",
             internal: "unknown" }]
        : firstConversation.messages.data;
      await route.fulfill({ status: 200, contentType: "application/json",
        body: JSON.stringify({
          ticket: firstConversation.ticket,
          messages: { data, current_page: requested, last_page: 2,
            total: 51, next_page_url: null, prev_page_url: null },
        }) });
    });
    await customer.page.evaluate((id) => ticketDetail(id), createdTicket.id);
    assert.equal(await customer.page.locator(
      "#ticket-detail [data-support-message-page-action=previous]").isDisabled(), true);
    await customer.page.locator(
      "#ticket-detail [data-support-message-page-action=next]").click();
    await customer.page.getByText("Synthetic last visible message").waitFor();
    const messageClocks = customer.page.locator("#ticket-detail [data-support-message-time]");
    assert.equal(await messageClocks.count(), 2);
    assert.match(await messageClocks.first().textContent(), /UTC/);
    assert.match(await messageClocks.last().textContent(), /Message time unavailable|وقت الرسالة غير متاح/);
    assert.equal(await customer.page.getByText("Hidden internal test record").count(), 0);
    assert.equal(await customer.page.getByText("Unknown visibility test record").count(), 0);
    assert.equal(await customer.page.locator(
      "#ticket-detail [data-support-message-page-action=next]").isDisabled(), true);
    messagesOutage = true;
    await customer.page.evaluate((id) => ticketDetail(id, 2), createdTicket.id);
    await customer.page.locator("#ticket-detail [data-support-message-retry]").waitFor();
    assert.equal(await customer.page.getByText("Synthetic last visible message").count(), 0);
    messagesOutage = false;
    await customer.page.locator("#ticket-detail [data-support-message-retry]").click();
    await customer.page.getByText("Synthetic last visible message").waitFor();
    await customer.page.unroute(conversationRoute);
    await customer.page.evaluate((id) => ticketDetail(id), createdTicket.id);

    // Distinct role principals avoid spending one actor's entire protected-read
    // budget on repeated full-workspace reloads. Production limits stay enabled.
    const admin = await account("Admin");
    // The operator CLI refuses Admin elevation without mailbox ownership.
    // Confirm only this synthetic test mailbox through the normal signed
    // development notification path; never bypass the production check.
    const adminVerificationSent = admin.page.waitForResponse((response) =>
      response.url().endsWith("/api/email/verification-notification"),
    );
    await admin.page.locator("#verify-email").click();
    assert.equal((await adminVerificationSent).status(), 202);
    const adminVerificationUrl = execFileSync(
      phpRunner,
      [
        "-r",
        `
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
      Illuminate\\Support\\Facades\\URL::forceRootUrl($argv[2]);
      $user = App\\Models\\User::where("email", $argv[1])->firstOrFail();
      echo (new Illuminate\\Auth\\Notifications\\VerifyEmail)->toMail($user)->actionUrl;
    `,
        "--",
        admin.email,
        base,
      ],
      { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
    ).trim();
    await admin.page.goto(adminVerificationUrl);
    await admin.page.getByRole("heading", { name: /Email verified/ }).waitFor();
    await admin.page.getByRole("link", { name: /Return to your account/ }).click();
    await admin.page.locator("#signed").waitFor({ state: "visible" });
    await provisionTestRole(admin.email, "admin", {pages: [vendor.page, customer.page, admin.page], runner: phpRunner});
    const staleAdminIdentity = admin.page.waitForResponse(
      (response) =>
        response.url().endsWith("/api/me") &&
        response.request().method() === "GET",
    );
    await admin.page.reload();
    assert.equal((await staleAdminIdentity).status(), 401);
    await admin.page.locator("#account").waitFor({ state: "visible" });
    await admin.page.locator("#account [name=email]").fill(admin.email);
    await admin.page.locator("#account [name=password]").fill(password);
    const freshAdminLogin = admin.page.waitForResponse(
      (response) =>
        response.url().endsWith("/api/login") &&
        response.request().method() === "POST",
    );
    await admin.page.locator("#account button[value=login]").click();
    assert.equal((await freshAdminLogin).status(), 200);
    await admin.page.locator("#signed").waitFor({ state: "visible" });
    await admin.page.locator("#admin").waitFor({ state: "visible" });
    await admin.page.waitForFunction(() =>
      document.querySelector("#overview").textContent.includes("Audit history"),
    );
    await admin.page.locator("#audit-search").fill("station.created");
    assert((await admin.page.locator("#overview tbody tr").count()) > 0);
    assert(
      (await admin.page.locator("#overview tbody tr").allTextContents()).every(
        (text) => text.includes("station.created"),
      ),
    );
    const auditDownload = admin.page.waitForEvent("download");
    await admin.page.locator("#audit-export").click();
    assert.equal(
      (await auditDownload).suggestedFilename(),
      "creabiz-audit.csv",
    );
    await admin.page.locator("#audit-search").fill("");
    await admin.page
      .locator("#ticket-list button")
      .filter({ hasText: "Browser support " + run })
      .click();
    await admin.page
      .locator("#ticket-detail textarea")
      .fill("Internal secret QA note " + run);
    await admin.page.locator("#ticket-detail input[name=internal]").check();
    await admin.page
      .locator("#ticket-detail button")
      .filter({ hasText: "Reply" })
      .click();
    await admin.page.waitForFunction(
      (text) =>
        document.querySelector("#ticket-detail").textContent.includes(text),
      "Internal secret QA note " + run,
    );
    assert.equal(await vendor.page.locator("#admin").isVisible(), false);
    await customer.page.reload();
    await customer.page
      .locator("#ticket-list button")
      .filter({ hasText: "Browser support " + run })
      .click();
    await customer.page.locator("#ticket-detail h3").waitFor();
    assert(
      !(await customer.page.locator("#ticket-detail").textContent()).includes(
        "Internal secret QA note",
      ),
    );
    // A replay after an uncertain HTTP acknowledgement must not create
    // duplicate public replies or duplicate vendor/customer inbox alerts.
    const browserSupportTickets = await admin.page.evaluate(
      () => api("support"),
    );
    const targetSupport = browserSupportTickets.data.find(
      (row) => row.subject === "Browser support " + run,
    );
    assert(targetSupport && Number.isSafeInteger(targetSupport.id));
    const replayKey = randomUUID().replace(/-/g, "");
    const replayBody = "Replay-safe browser support update " + run;
    const unreadBeforeReplay = await customer.page.evaluate(
      () => api("notifications/summary"),
    );
    const initialReply = await admin.page.evaluate(
      ({ ticketId, body, key }) => api(
        "support/" + ticketId + "/messages", "POST",
        { body, internal: false, idempotency_key: key },
      ),
      { ticketId: targetSupport.id, body: replayBody, key: replayKey },
    );
    const replayedReply = await admin.page.evaluate(
      ({ ticketId, body, key }) => api(
        "support/" + ticketId + "/messages", "POST",
        { body, internal: false, idempotency_key: key },
      ),
      { ticketId: targetSupport.id, body: replayBody, key: replayKey },
    );
    assert.equal(replayedReply.id, initialReply.id);
    assert.equal(replayedReply.idempotency_key, undefined);
    const unreadAfterReplay = await customer.page.evaluate(
      () => api("notifications/summary"),
    );
    assert.equal(unreadAfterReplay.unread_count,
      unreadBeforeReplay.unread_count + 1);
    const savedThread = await admin.page.evaluate(
      (id) => api("support/" + id), targetSupport.id,
    );
    assert.equal(
      savedThread.messages.data.filter((m) => m.body === replayBody).length,
      1,
    );

    // New browser + Laravel notification-cutoff acceptance. The second reply is
    // created AFTER the customer's first visible high-water mark, and must stay
    // unread when the customer marks only the previously observed messages.
    const publicReply = async (text) => {
      await admin.page.locator("#ticket-detail textarea").fill(text);
      await admin.page.locator("#ticket-detail input[name=internal]").uncheck();
      await admin.page.locator("#ticket-detail button")
        .filter({ hasText: "Reply" }).click();
      await admin.page.waitForFunction(
        (value) => document.querySelector("#ticket-detail").textContent.includes(value),
        text,
      );
    };
    const beforePublic = await customer.page.evaluate(() => api("notifications/summary"));
    await publicReply("Synthetic public update 1 " + run);
    // No page reload: the user can refresh only the account inbox, without
    // refetching an unrelated ticket list or acknowledging the new event.
    await customer.page.locator("#notification-refresh").click();
    await customer.page.locator("#notification-read-through").waitFor();
    const observed = await customer.page.evaluate(() => api("notifications/summary"));
    assert.equal(observed.unread_count, beforePublic.unread_count + 1);
    assert(observed.latest_id > beforePublic.latest_id);
    await publicReply("Synthetic public update 2 " + run);
    const bulkResponse = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/read-through") &&
          response.request().method() === "POST",
    );
    const postBulkSummary = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/summary") &&
          response.request().method() === "GET",
    );
    await customer.page.locator("#notification-read-through").click();
    assert.equal((await bulkResponse).status(), 200);
    assert.equal((await postBulkSummary).status(), 200);
    const afterBulk = await customer.page.evaluate(async () => ({
      summary: await api("notifications/summary"),
      page: await api("notifications"),
    }));
    assert(afterBulk.summary.latest_id > observed.latest_id);
    assert.equal(afterBulk.summary.unread_count, 1);
    const oldRow = afterBulk.page.data.find((row) => row.id === observed.latest_id);
    assert(oldRow && oldRow.read_at !== null);
    const newest = afterBulk.page.data.find((row) => row.id === afterBulk.summary.latest_id);
    assert(newest && newest.read_at === null);
    assert.equal(afterBulk.summary.unread_by_category.support, 1);
    assert.equal(afterBulk.summary.unread_by_category.charging, 0);
    // Older messages retain their own read receipt. Explicitly reopening one
    // cannot create an event or change the newer message's state.
    const oldNotification = customer.page.locator(
      '[data-notification-id="' + observed.latest_id + '"]',
    );
    const unreadResponse = customer.page.waitForResponse(
      (response) => response.url().endsWith(
        '/api/notifications/' + observed.latest_id + '/unread',
      ) && response.request().method() === 'POST',
    );
    await oldNotification.getByRole('button', { name: await customer.page.evaluate(() => EvLocale.text('Mark unread')), exact: true }).click();
    assert.equal((await unreadResponse).status(), 200);
    const reopened = await customer.page.evaluate(async () => ({
      summary: await api('notifications/summary'),
      notices: await api('notifications'),
    }));
    assert.equal(reopened.summary.unread_count, 2);
    assert.equal(reopened.summary.unread_by_category.support, 2);
    assert.equal(reopened.notices.total, afterBulk.page.total);
    const openedNote = reopened.notices.data.find((row) => row.id === observed.latest_id);
    assert(openedNote && openedNote.read_at === null);
    const rereadResponse = customer.page.waitForResponse(
      (response) => response.url().endsWith(
        '/api/notifications/' + observed.latest_id + '/read',
      ) && response.request().method() === 'POST',
    );
    await customer.page.locator(
      '[data-notification-id="' + observed.latest_id + '"]',
    ).getByRole('button', { name: await customer.page.evaluate(() => EvLocale.text('Mark read')), exact: true }).click();
    assert.equal((await rereadResponse).status(), 200);
    assert.equal(
      (await customer.page.evaluate(() => api('notifications/summary'))).unread_count,
      1,
    );
    const filtered = await customer.page.evaluate(
      () => api("notifications?unread_only=1"),
    );
    const classified = await customer.page.evaluate(
      () => api("notifications?category=support&unread_only=1"),
    );
    assert.equal(classified.total, 1);
    assert.equal(classified.data[0].kind, "support.reply");
    assert.equal(filtered.total, 1);
    assert.equal(filtered.data[0].id, afterBulk.summary.latest_id);
    // Rapid filter changes must settle on the latest requested category,
    // regardless of earlier network response completion order.
    const lastFilter = customer.page.locator("#notification-category");
    await lastFilter.selectOption("other");
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-category")?.value === "other",
    );
    const otherPage = await customer.page.evaluate(
      () => api("notifications?category=other"),
    );
    assert(otherPage.total >= otherPage.data.length);
    assert(otherPage.data.every((item) =>
      !["support.", "vehicle.", "charging.", "fleet."].some(
        (prefix) => typeof item.kind === "string" && item.kind.startsWith(prefix),
      ),
    ));
    await lastFilter.selectOption("fleet");
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-category")?.value === "fleet",
    );
    await lastFilter.selectOption("support");
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-category")?.value === "support",
    );
    assert.equal(await lastFilter.inputValue(), "support");
    await customer.page.locator("#notification-unread-filter").click();
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-unread-filter")?.textContent.includes(
        document.documentElement.lang === "ar" ? "جميع" : "all",
      ),
    );
    // Saved notification defaults round-trip via the actual authenticated
    // API, are applied after a fresh browser session and cannot mute critical
    // in-app events or falsely claim an external delivery provider.
    await customer.page.locator("#notification-default-category").selectOption("charging");
    await customer.page.locator("#notification-default-unread").check();
    await customer.page.locator("#notification-repeat-reminders").uncheck();
    await customer.page.locator("#notification-fault-delay").selectOption("60");
    const savedPreferences = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/preferences") &&
        response.request().method() === "PUT",
    );
    await customer.page.locator("#notification-preference-save").click();
    assert.equal((await savedPreferences).status(), 200);
    const configured = await customer.page.evaluate(
      () => api("notifications/preferences"),
    );
    assert.equal(configured.default_category, "charging");
    assert.equal(configured.default_unread_only, true);
    assert.equal(configured.remind_unresolved_simulator_faults, false);
    assert.equal(configured.fault_reminder_delay_minutes, 60);
    assert.equal(configured.external_delivery_available, false);
    const stillRecorded = await customer.page.evaluate(
      () => api("notifications/summary"),
    );
    assert(stillRecorded.unread_by_category.support >= 0);
    await customer.page.reload();
    await customer.page.locator("#notification-preferences").waitFor();
    assert.equal(
      await customer.page.locator("#notification-category").inputValue(), "charging",
    );
    assert.equal(
      await customer.page.locator("#notification-default-unread").isChecked(), true,
    );
    assert.equal(
      await customer.page.locator("#notification-repeat-reminders").isChecked(), false,
    );
    assert.equal(
      await customer.page.locator("#notification-fault-delay").inputValue(), "60",
    );
    // A second full authenticated bootstrap is a separate protected-read
    // phase. Respect its unchanged 60/minute window before sending any new
    // mutation; never raise quotas, replay a failed write or retry assertions.
    await waitForFreshWindow({wait: ms => new Promise(resolve => setTimeout(resolve, ms))});
    // "Other" is a real tenant-scoped list category and can also be saved
    // as the account's default; it must round-trip across browser sessions
    // without hiding critical notifications or enabling external delivery.
    await customer.page.locator("#notification-default-category").selectOption("other");
    const otherSaved = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/preferences") &&
        response.request().method() === "PUT",
    );
    await customer.page.locator("#notification-preference-save").click();
    assert.equal((await otherSaved).status(), 200);
    assert.equal(
      (await customer.page.evaluate(() => api("notifications/preferences"))).default_category,
      "other",
    );
    await customer.page.reload();
    await customer.page.locator("#notification-preferences").waitFor();
    assert.equal(
      await customer.page.locator("#notification-category").inputValue(), "other",
    );
    assert.equal(
      await customer.page.locator("#notification-default-category").inputValue(), "other",
    );
    const otherSummary = await customer.page.evaluate(() => api("notifications/summary"));
    assert(otherSummary.unread_by_category.support >= 0);
    // Restore the earlier explicit choice so later logout/privacy assertions
    // continue exercising their original scenario without changing scope.
    await customer.page.locator("#notification-default-category").selectOption("charging");
    const restored = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/preferences") &&
        response.request().method() === "PUT",
    );
    await customer.page.locator("#notification-preference-save").click();
    assert.equal((await restored).status(), 200);
    await customer.page.waitForFunction(() =>
      document.querySelector("#notification-category")?.value === "charging",
    );
    await vendor.page.setViewportSize({ width: 390, height: 844 });
    assert(
      await vendor.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    // Simulate an expired session response, then verify private DOM is cleared.
    await customer.page.route("**/api/me", (route) =>
      route.fulfill({
        status: 401,
        contentType: "application/json",
        body: JSON.stringify({ message: "Unauthenticated." }),
      }),
    );
    await customer.page.locator("#refresh-identity").click();
    await customer.page.locator("#account").waitFor({ state: "visible" });
    assert.equal(await customer.page.locator("#garage").isVisible(), false);
    assert.equal(
      await customer.page.locator("#vehicle-list").textContent(),
      "",
    );
    assert.equal(
      await customer.page.locator("#fleet-detail").textContent(),
      "",
    );
    assert.equal(await customer.page.locator("#fleet-list [data-fleet-id]").count(), 0);
    assert.equal(await customer.page.locator("#fleet-invitations [data-fleet-invitation-id]").count(), 0);
    assert.equal(await customer.page.locator("#fleet-list").getAttribute("aria-busy"), null);
    assert.equal(await customer.page.locator("#fleet-invitations").getAttribute("aria-busy"), null);
    assert.equal(await customer.page.locator("#who").textContent(), "");
    assert.equal(await customer.page.locator("#reservation-list article").count(), 0);
    assert.equal(await customer.page.locator("#reservation-list [data-reservation-pagination]").count(), 0);
    assert.equal(await customer.page.locator("#simulation-list article").count(), 0);
    assert.equal(await customer.page.locator("#simulation-list [data-simulation-pagination]").count(), 0);
    assert.equal(await customer.page.locator("#vehicle-list [data-garage-vehicle-id]").count(), 0);
    assert.equal(await customer.page.locator("#vehicle-list [data-garage-pagination]").count(), 0);
    assert.equal(await customer.page.locator("#ticket-list [data-support-ticket-id]").count(), 0);
    assert.equal(await customer.page.locator("#ticket-list [data-support-pagination]").count(), 0);
    assert.equal(await customer.page.locator("#reservation-list").getAttribute("aria-busy"), null);
    // Explicit vendor logout must remove its already-loaded private reservation history.
    assert(await vendor.page.locator("#reservation-list article").count() > 0);
    for(let attempt=0;attempt<2;attempt++){
      const outcome=vendor.page.waitForResponse(response=>{
        const path=new URL(response.url()).pathname;
        return (path==='/api/csrf'&&response.status()!==200)||
          (path==='/api/logout'&&response.request().method()==='POST');
      });
      const [response]=await Promise.all([outcome,
        vendor.page.locator(attempt===0?'#logout':'#logout-retry').click()]);
      if(response.status()===429&&attempt===0){
        const seconds=Number(response.headers()['retry-after']);
        assert(Number.isFinite(seconds)&&Number.isInteger(seconds)&&seconds>=0&&seconds<=60,'Bounded logout Retry-After required');
        await vendor.page.locator('#logout-retry').waitFor({state:'visible'});
        assert.equal(await vendor.page.locator('#reservation-list article').count(),0,'Unconfirmed logout must already erase private rows');
        // Only a received middleware rejection permits the existing Retry
        // sign-out action. Unknown outcomes and all other statuses fail.
        await vendor.page.waitForTimeout((seconds+1)*1000);
        continue;
      }
      assert.equal(new URL(response.url()).pathname,'/api/logout');
      assert.equal(response.status(),204);
      break;
    }
    await vendor.page.locator("#account").waitFor({state:"visible"});
    assert.equal(await vendor.page.locator("#reservation-list article").count(), 0);
    const restricted = await browser.newContext();
    await restricted.addInitScript(() => {
      Object.defineProperty(window, "localStorage", {
        get() {
          throw new DOMException("Storage unavailable", "SecurityError");
        },
      });
    });
    const restrictedPage = await restricted.newPage();
    restrictedPage.on("pageerror", (e) => errors.push(e.message));
    await restrictedPage.goto(base);
    await restrictedPage.locator("#theme-choice").selectOption("dark");
    assert.equal(
      await restrictedPage.locator("html").getAttribute("data-theme"),
      "dark",
    );
    await restricted.close();
    assert.deepEqual(errors, []);
    console.log(
      "PASS: browser Light/Dark/System themes, preference persistence and OS changes; registration, email verification request/signed confirmation/status, vendor station creation, customer reservation/cancel, vendor visibility, tenant privacy, admin overview, garage privacy, fleet invitation acceptance, support internal-note privacy, Arabic RTL, mobile width; no page errors.",
    );
  } catch (error) {
    console.error("Browser page errors:", errors);
    for (const context of browser.contexts())
      for (const page of context.pages())
        console.error(
          "UI status:",
          await page
            .locator("#notice")
            .textContent()
            .catch(() => ""),
        );
    throw error;
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
