// Explicit UI resources only: fixture names/messages must never be translated.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs"),
  vm = require("node:vm");
(async () => {
  const scope = { window: {} };
  vm.runInNewContext(
    fs.readFileSync("services/business-api/public/locale-data.js", "utf8"),
    scope,
  );
  const labels = scope.window.EV_MESSAGES;
  const neutralTemplates = new Set([
    "English",
    "#{id} · {subject} · {status}",
    "{subject} · {status}",
    "{name} · {role}",
    "{evidence} · {status}",
    "Settings reading",
  ]);
  assert(Object.keys(labels).length >= 980, "Web translation registry lost substantial workflow coverage");
  for (const [key, value] of Object.entries(labels)) {
    assert(value.en && value.ar, key);
    if (!neutralTemplates.has(key)) {
      assert.match(value.ar, /[ء-ي]/, key + ": Arabic resource was replaced by an English fallback");
      assert.notEqual(value.ar, value.en, key + ": Arabic resource equals English");
    }
    const variables = (s) =>
      [...s.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]).sort();
    assert.deepEqual(variables(value.en), variables(value.ar), key);
  }
  // Literal UI labels in the new workspaces must have both resources; never scan user text.
  for (const file of [
    "vendor-ops.js",
    "roaming.js",
    "invoice-history.js",
    "tariffs.js",
  ]) {
    const source = fs.readFileSync(
      "services/business-api/public/" + file,
      "utf8",
    );
    const expressions = [
      /\bui\(\s*["'][a-z]+["']\s*,\s*["']([^"']+)["']/g,
      /\bbutton\([^,]+,\s*["']([^"']+)["']/g,
      // A literal complete translation lookup can also appear inside a native
      // browser confirm dialog rather than a rendered element. Exclude
      // concatenated state/action prefixes, which are dynamic resource keys.
      /\bEvLocale\.text\(\s*["']([^"']+)["']\s*[,)]/g,
    ];
    for (const expression of expressions)
      for (const match of source.matchAll(expression))
        assert(
          Object.hasOwn(scope.window.EV_MESSAGES, match[1]),
          file + ": missing EN/AR UI resource " + match[1],
        );
  }
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext(),
      page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
    await page.route("**/api/stations*", (route) =>
      route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: [
            {
              id: 1,
              name: "Create fleet",
              connector_type: "CCS2",
              power_kw: 50,
              country: "AE",
              currency: "AED",
              latitude: 25,
              longitude: 55,
              source: "discovery",
              evidence: "DISCOVERY_ONLY",
              availability: "unknown",
              reservable: false,
            },
          ],
          current_page: 1,
          last_page: 1,
          total: 1,
          next_page_url: null,
          prev_page_url: null,
        }),
      }),
    );
    await page.goto(base);
    await page.locator("#stations h3").waitFor();
    // Contrast is checked against computed styles on the real rendered page,
    // not just the palette constants. Pending reads must not dim live status.
    for (const theme of ["light", "dark"]) {
      await page.locator("#theme-choice").selectOption(theme);
      for (const language of ["en", "ar"]) {
        await page.evaluate((language) => window.EvLocale.set(language), language);
        const result = await page.evaluate(() => {
          function luminance(color) {
            const values = color.match(/[\d.]+/g).slice(0, 3).map(Number);
            return values.map((n) => n / 255).map((n) =>
              n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4
            ).reduce((sum, n, i) => sum + n * [0.2126, 0.7152, 0.0722][i], 0);
          }
          function contrast(a, b) {
            const x = luminance(a), y = luminance(b);
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
          }
          const input = document.querySelector("#account input[name=email]");
          const control = getComputedStyle(input);
          const section = getComputedStyle(document.querySelector("#identity"));
          const body = getComputedStyle(document.body);
          const fixture = document.createElement("section");
          fixture.setAttribute("aria-busy", "true");
          const status = document.createElement("p");
          status.setAttribute("role", "status");
          status.textContent = "Synthetic pending read";
          fixture.append(status);
          document.querySelector("#workspace").append(fixture);
          try {
            return {
              surfaceBorder: contrast(control.borderTopColor, section.backgroundColor),
              pageBorder: contrast(control.borderTopColor, body.backgroundColor),
              busyOpacity: Number(getComputedStyle(fixture).opacity),
              busyTextContrast: contrast(getComputedStyle(status).color,
                getComputedStyle(fixture).backgroundColor),
              direction: document.documentElement.dir,
            };
          } finally {
            fixture.remove();
          }
        });
        assert(result.surfaceBorder >= 3, theme + "/" + language + " input boundary on surface");
        assert(result.pageBorder >= 3, theme + "/" + language + " input boundary on page");
        assert.equal(result.busyOpacity, 1, theme + "/" + language + " pending content opacity");
        assert(result.busyTextContrast >= 4.5, theme + "/" + language + " pending status text");
        assert.equal(result.direction, language === "ar" ? "rtl" : "ltr");
      }
    }
    await page.evaluate(() => window.EvLocale.set("en"));
    await page.locator("#theme-choice").selectOption("system");
    // A ticket subject/name is literal text, not a second translation template.
    // Also cover JS replacement metacharacters and translated status resources.
    const interpolation = async (lang) => page.evaluate((lang) => {
      const locale = window.EvLocale;
      const subject = "{status} $& $$ $` $' <literal>";
      const key = '{subject} · {status}';
      const template = window.EV_MESSAGES[key][lang];
      const expectedStatus = locale.text('English');
      const expected = template.replace(/\{(subject|status)\}/g, (_, name) =>
        name === 'subject' ? subject : expectedStatus);
      const first = locale.text(key, {subject, status:{translate:'English'}});
      const reversed = locale.text(key, {status:{translate:'English'}, subject});
      const missing = locale.text(key, {subject});
      const node = document.createElement('p');
      locale.bind(node, key, {subject, status:{translate:'English'}});
      return {expected, first, reversed, missing, expectedMissing:
        template.replace(/\{subject\}/g, () => subject), bound:node.textContent};
    }, lang);
    const verifyInterpolation = async (lang) => {
      const result = await interpolation(lang);
      assert.equal(result.first, result.expected);
      assert.equal(result.reversed, result.expected);
      assert.equal(result.bound, result.expected);
      assert.equal(result.missing, result.expectedMissing);
    };
    await verifyInterpolation('en');
    await page.locator("#account input[name=name]").fill("Unchanged draft");
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.lang === "ar");
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await verifyInterpolation('ar');
    const replaceGrant = "Replace the existing grant? The staff member loses access until accepting the new permissions.";
    assert.equal(await page.evaluate(k => window.EvLocale.text(k), replaceGrant),
      "هل تريد استبدال الصلاحيات الحالية؟ سيفقد الموظف إمكانية الوصول إلى أن يقبل الصلاحيات الجديدة.");

    assert.equal(
      await page.locator("#account input[name=name]").inputValue(),
      "Unchanged draft",
    );
    assert.equal(await page.locator("#account input").count(), 4);
    assert.equal(await page.locator('#account input[name="second_factor_code"]').getAttribute('type'), 'password');
    assert.equal(await page.locator('#account input[name="second_factor_code"]').getAttribute('autocomplete'), 'one-time-code');
    assert.match(await page.locator('[data-second-factor-login] summary').textContent(), /رمز تطبيق التحقق/);
    assert.equal(
      await page.locator("#stations h3").textContent(),
      "Create fleet",
    );
    assert.match(
      await page.locator("#stations .badge").textContent(),
      /اكتشاف فقط/,
    );
    assert.equal(
      await page.locator("#fleet-create button").textContent(),
      "إنشاء أسطول",
    );
    assert.equal(await page.locator("#fleets").isVisible(), false);
    await page.reload();
    await page.locator("#stations h3").waitFor();
    assert.equal(await page.locator("html").getAttribute("lang"), "ar");
    await page.setViewportSize({ width: 390, height: 844 });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await page.goto(
      base +
        "/reset-password/synthetic-invalid-token?email=synthetic%40example.test",
    );
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await page
      .getByRole("heading", { name: "إعادة تعيين كلمة المرور", exact: true })
      .waitFor();
    assert.equal(
      await page.locator("input[name=token]").inputValue(),
      "synthetic-invalid-token",
    );
    await page.locator("input[name=password]").fill("synthetic-test-password");
    await page
      .locator("input[name=password_confirmation]")
      .fill("synthetic-test-password");
    await page.locator("#reset-password button").click();
    await page.waitForFunction(() =>
      document
        .querySelector("#status")
        .textContent.includes("تحقق من البيانات"),
    );
    await page.goto(base);
    await page.locator("#language").click();
    await page.waitForFunction(() => document.documentElement.lang === "en");
    assert.equal(await page.locator("html").getAttribute("dir"), "ltr");
    const denied = await browser.newContext();
    await denied.addInitScript(() =>
      Object.defineProperty(window, "localStorage", {
        get() {
          throw new DOMException("Disabled", "SecurityError");
        },
      }),
    );
    const restricted = await denied.newPage();
    restricted.on("pageerror", (e) => errors.push(e.message));
    await restricted.goto(base);
    await restricted.locator("#language").click();
    assert.equal(await restricted.locator("html").getAttribute("lang"), "ar");
    assert.deepEqual(errors, []);
    console.log(
      "PASS: web resource/interpolation parity; persistent EN/AR and RTL, preserved form fields and user-content text, translated dynamic provenance, mobile width, localized recovery validation, storage-denied fallback; zero page errors.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
