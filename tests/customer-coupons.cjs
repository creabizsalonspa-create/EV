"use strict";
const assert = require("node:assert/strict");
const { readFileSync } = require("node:fs");
const { chromium } = require("playwright");
const script = readFileSync(
  "services/business-api/public/customer-coupons.js",
  "utf8",
);
const code = "EV-COUPON-2026";
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  let checks = 0;
  try {
    for (const lang of ["en", "ar"]) {
      const context = await browser.newContext({
          viewport: { width: 390, height: 844 },
        }),
        page = await context.newPage();
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await page.route("http://127.0.0.1:8246/**", (route) => {
        if (new URL(route.request().url()).pathname === "/customer-coupons.js")
          return route.fulfill({
            contentType: "text/javascript",
            body: script,
          });
        return route.fulfill({
          contentType: "text/html",
          body: `<!doctype html><html lang="${lang}" dir="${lang === "ar" ? "rtl" : "ltr"}"><head><meta charset="utf-8"></head><body><main id="root"></main><script type="module">
import{mountCustomerCoupons}from'/customer-coupons.js';
window.EV_MESSAGES={};window.actor={id:1,role:'customer'};window.h={calls:[],lose:false,forge:false,absent:false,accepted:null,never:false};
const digest=async v=>[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(v)))].map(x=>x.toString(16).padStart(2,'0')).join('');
const binding=async(code,key)=>digest((await digest(code))+':'+(await digest('coupon-claim:'+actor.id+':'+key)));
const receipt=b=>({id:1,coupon_id:2,redemption_id:3,reward_name:'<img src=x onerror=alert(1)> literal reward',reward_name_ar:'مكافأة تجريبية',status:'pending',claimed_at:'2026-11-21T12:00:00.000000Z',request_binding:b,source:'COUPON',evidence:h.reviewed?'REVIEWED_MANUAL_NONCASH':'TEST_PROMOTIONAL_ECONOMICS',activation_mode:h.reviewed?'reviewed_manual':'sandbox',activation_recorded:!h.unrecorded,delivery_verified:false,points_cost:0,cash_value:false,fulfillment:'MANUAL_NON_MONETARY'});
const api=async(path,method='GET',body)=>{
 h.calls.push({path,method,body:structuredClone(body),saved:localStorage.getItem('creabiz.coupon-intent.v1.1')});
 if(path==='me')return{...actor};
 if(path==='commercial/noncash/status')return{enabled:!h.disabled,activation_mode:h.disabled?'disabled':h.reviewed?'reviewed_manual':'sandbox',points_actions_enabled:!h.reviewed&&!h.disabled,money_movement:false,fulfillment:'MANUAL_NON_MONETARY',delivery_verified:false,referrals_enabled:!h.disabled,verified_live_source_required:!!h.reviewed};
 if(path.startsWith('commercial/coupons/claims?'))return{data:[],current_page:Number(new URL('http://local/'+path).searchParams.get('page')),per_page:25,last_page:1,total:0};
 if(path.endsWith('claim-intents/cancel'))return{state:h.accepted?'accepted':'cancelled',claim:h.accepted,request_binding:await binding(body.code,body.idempotency_key)};
 if(path.endsWith('claim-receipt'))return{claim:h.absent?null:h.accepted};
 const proof=await binding(body.code,body.idempotency_key);if(!h.never)h.accepted=receipt(h.forge?'0'.repeat(64):proof);
 if(h.lose)throw Error('PRIVATE-provider-token');return{claim:h.accepted};
};
const ui=(tag,en)=>{const el=document.createElement(tag),txt=EV_MESSAGES[en][document.documentElement.lang];if(tag==='label'){const span=document.createElement('span');span.textContent=txt;el.append(span);}else el.textContent=txt;return el;};
window.dispose=mountCustomerCoupons({container:document.querySelector('#root'),api,ui,actor:()=>window.actor});window.loaded=true;
</script></body></html>`,
        });
      });
      await page.goto("http://127.0.0.1:8246/");
      await page.waitForFunction(() => window.loaded);
      const button = (k) => page.locator(`[data-coupon="${k}"]`);
      await button("restore").click();
      await button("claim").waitFor();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=claim]").disabled,
      );
      assert.equal(
        (await page.evaluate(() => h.calls)).filter((x) => x.method === "POST")
          .length,
        0,
      );
      checks++;
      await page.evaluate(() => (h.lose = true));
      await button("code").fill(code);
      await button("claim").click();
      await page.waitForFunction(
        () =>
          document.querySelector("[data-coupon=claim]").disabled &&
          !document.querySelector("#root").matches("[aria-busy=true]"),
      );
      const initial = await page.evaluate(() =>
        h.calls.find((x) => x.path === "commercial/coupons/claim"),
      );
      const saved = JSON.parse(initial.saved);
      assert.equal(initial.body.code, code);
      assert.equal(saved.key, initial.body.idempotency_key);
      assert(!initial.saved.includes(code));
      assert.deepEqual(Object.keys(saved).sort(), [
        "binding",
        "key",
        "owner",
        "version",
      ]);
      assert(
        !(await page.locator("body").innerText()).includes(
          "PRIVATE-provider-token",
        ),
      );
      checks++;
      await page.reload();
      await page.waitForFunction(() => window.loaded);
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=inspect]").disabled,
      );
      assert.equal(
        (await page.evaluate(() => h.calls)).filter((x) => x.method === "POST")
          .length,
        0,
      );
      assert.equal(await button("code").inputValue(), "");
      checks++;
      await page.evaluate(() => (h.absent = true));
      await button("inspect").click();
      await page.waitForFunction(
        () => !document.querySelector("#root").matches("[aria-busy=true]"),
      );
      assert.equal(
        await page.evaluate(
          () =>
            JSON.parse(localStorage.getItem("creabiz.coupon-intent.v1.1")).key,
        ),
        saved.key,
      );
      checks++;
      await button("code").fill("DIFFERENT-COUPON");
      await button("claim").click();
      await page.waitForFunction(
        () => !document.querySelector("#root").matches("[aria-busy=true]"),
      );
      assert.equal(
        (await page.evaluate(() => h.calls)).filter(
          (x) => x.path === "commercial/coupons/claim",
        ).length,
        0,
      );
      checks++;
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=claim]").disabled,
      );
      await button("code").fill(code);
      await button("claim").click();
      await page.waitForFunction(
        () => localStorage.getItem("creabiz.coupon-intent.v1.1") === null,
      );
      const resumed = await page.evaluate(() =>
        h.calls.find((x) => x.path === "commercial/coupons/claim"),
      );
      assert.deepEqual(resumed.body, initial.body);
      await page.locator("#root article").waitFor({ state: "visible" });
      assert.equal(await page.locator("#root img").count(), 0);
      assert(
        (await page.locator("body").innerText()).includes(
          lang === "ar"
            ? "مكافأة تجريبية"
            : "<img src=x onerror=alert(1)> literal reward",
        ),
        JSON.stringify({ lang, body: await page.locator("body").innerText() }),
      );
      checks++;
      await page.evaluate(() => {
        h.lose = true;
        h.never = true;
        h.accepted = null;
      });
      await button("code").fill(code);
      await button("claim").click();
      await page.waitForFunction(
        () => !document.querySelector("#root").matches("[aria-busy=true]"),
      );
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=retire]").disabled,
      );
      await button("code").fill(code);
      page.once("dialog", (d) => d.accept());
      await button("retire").click();
      await page.waitForFunction(
        () => localStorage.getItem("creabiz.coupon-intent.v1.1") === null,
      );
      const cancelled = await page.evaluate(() =>
        h.calls.findLast((x) => x.path.endsWith("claim-intents/cancel")),
      );
      assert.equal(cancelled.body.code, code);
      checks++;
      await page.evaluate(() => {
        h.reviewed = true;
        h.lose = false;
        h.never = false;
      });
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=claim]").disabled,
      );
      await button("code").fill(code);
      await button("claim").click();
      await page.waitForFunction(
        () => localStorage.getItem("creabiz.coupon-intent.v1.1") === null,
      );
      await page
        .getByText(
          lang === "ar"
            ? "سجل غير نقدي يدوي مراجع"
            : "Reviewed manual noncash record",
          { exact: true },
        )
        .waitFor();
      checks++;
      await page.evaluate(() => {
        h.unrecorded = true;
      });
      await button("code").fill(code);
      await button("claim").click();
      await page.waitForFunction(
        () =>
          !document.querySelector("#root").matches("[aria-busy=true]") &&
          document.querySelector("[data-coupon=claim]").disabled,
      );
      assert(
        await page.evaluate(
          () => localStorage.getItem("creabiz.coupon-intent.v1.1") !== null,
        ),
      );
      checks++;
      await page.evaluate(() => {
        h.disabled = true;
      });
      await button("restore").click();
      await page.waitForFunction(
        () => !document.querySelector("[data-coupon=inspect]").disabled,
      );
      assert(await button("claim").isDisabled());
      assert(!(await button("retire").isDisabled()));
      checks++;
      await page.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          value: true,
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      assert.equal(await button("code").inputValue(), "");
      assert.equal(await page.locator("#root article").count(), 0);
      const before = (await page.evaluate(() => h.calls)).length;
      await page.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          value: false,
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      assert.equal((await page.evaluate(() => h.calls)).length, before);
      assert(await button("claim").isDisabled());
      checks++;
      assert.deepEqual(errors, []);
      checks++;
      await context.close();
    }
    console.log(
      `PASS: ${checks} controlled customer coupon EN/AR390 recovery, persistence, correlation, cancellation, privacy and literal rendering checks.`,
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
