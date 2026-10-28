/* Vendor payout operations use the canonical immutable settlement source.
 * Provider test evidence is never presented as a real bank transfer. */
window.mountVendorPayouts = ({ container, api, ui, actor }) => {
    const identity = actor()?.id,
        role = actor()?.role,
        controller = new AbortController();
    let generation = 0,
        busy = false,
        currentKind = "accounts",
        currentPage = 1;
    container.dataset.vendorPayouts = "true";
    const alive = () => container.isConnected && actor()?.id === identity;
    const observer = new MutationObserver(() => {
        if (!alive()) {
            generation++;
            controller.abort();
            observer.disconnect();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    const localizeDates = () => {
        if (!alive()) return;
        for (const time of container.querySelectorAll("time[data-payout-date]"))
            time.textContent = new Date(time.dateTime).toLocaleString(
                document.documentElement.lang === "ar" ? "ar" : "en",
            );
    };
    window.addEventListener("ev:locale", localizeDates, {
        signal: controller.signal,
    });
    function t(tag, en, ar) {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    }
    function literal(tag, value) {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    }
    const status = literal("p", ""),
        content = document.createElement("div");
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    container.append(
        t("h3", "Vendor payouts", "تحويلات الموردين"),
        t(
            "p",
            "Mock and Stripe test evidence only. No real money moves. Provider confirmation, vendor consent and independent administrator approval are required.",
            "أدلة محاكاة واختبار Stripe فقط. لا تتحرك أموال حقيقية. يلزم تأكيد المزود وموافقة المورد واعتماد مسؤول مستقل.",
        ),
        status,
    );
    async function request(path, method = "GET", data) {
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        return api(path, method, data, controller.signal);
    }
    function message(en, ar) {
        if (alive()) status.replaceChildren(t("span", en, ar));
    }
    async function perform(action) {
        if (busy || !alive()) return;
        busy = true;
        container.setAttribute("aria-busy", "true");
        const previous = new Map();
        for (const input of container.querySelectorAll(
            "button, input, select",
        )) {
            previous.set(input, input.disabled);
            input.disabled = true;
        }
        try {
            status.textContent = "";
            await action();
        } catch (e) {
            if (
                alive() &&
                !["AbortError", "SessionChangedError"].includes(e.name)
            )
                status.textContent = e.message;
        } finally {
            busy = false;
            if (alive()) {
                container.removeAttribute("aria-busy");
                for (const [input, wasDisabled] of previous)
                    if (input.isConnected) input.disabled = wasDisabled;
            }
        }
    }
    function button(parent, en, ar, action) {
        const b = t("button", en, ar);
        b.type = "button";
        b.onclick = () => perform(action);
        parent.append(b);
        return b;
    }
    function labelled(parent, en, ar, input) {
        const label = document.createElement("label");
        label.append(t("span", en, ar), input);
        parent.append(label);
        return input;
    }
    function field(parent, name, en, ar, type = "text", value = "") {
        const input = document.createElement("input");
        input.type = type;
        input.name = name;
        input.required = true;
        input.maxLength = 100;
        if (type === "number") {
            input.min = "1";
            input.step = "1";
        }
        input.value = value;
        return labelled(parent, en, ar, input);
    }
    function form(parent, en, ar, build, submit) {
        const f = document.createElement("form");
        f.className = "finance-form";
        f.dataset.payoutForm = en;
        build(f);
        const b = t("button", en, ar);
        b.type = "submit";
        f.append(b);
        let key = crypto.randomUUID();
        f.addEventListener("input", () => (key = crypto.randomUUID()));
        f.addEventListener("change", () => (key = crypto.randomUUID()));
        f.onsubmit = (event) => {
            event.preventDefault();
            if (!f.reportValidity()) return;
            // Capture values before the busy fence disables form controls.
            const values = Object.fromEntries(new FormData(f));
            perform(() => submit(values, key));
        };
        parent.append(f);
        return f;
    }
    function state(parent, value) {
        const states = {
            pending_consent: [
                "Awaiting vendor consent",
                "بانتظار موافقة المورد",
            ],
            pending_verification: [
                "Awaiting provider verification",
                "بانتظار تحقق المزود",
            ],
            active: ["Verified account", "حساب متحقق"],
            revoked: ["Consent revoked", "أُلغيت الموافقة"],
            prepared: ["Awaiting independent approval", "بانتظار اعتماد مستقل"],
            approved: ["Approved for test execution", "معتمد للتنفيذ التجريبي"],
            executing: [
                "Provider request in progress",
                "طلب المزود قيد التنفيذ",
            ],
            pending: ["Provider confirmation pending", "بانتظار تأكيد المزود"],
            uncertain: [
                "Outcome uncertain — reconcile first",
                "النتيجة غير مؤكدة — طابق أولاً",
            ],
            paid: [
                "Provider reported paid — test only",
                "أبلغ المزود بالدفع — اختبار فقط",
            ],
            failed: ["Known failed attempt", "محاولة فاشلة مؤكدة"],
            reversed: [
                "Provider reversal recorded",
                "سُجل عكس التحويل من المزود",
            ],
            cancelled: ["Cancelled locally", "أُلغي محلياً"],
            needs_review: [
                "Financial investigation required",
                "يلزم تحقيق مالي",
            ],
        };
        const labels = states[value];
        const n = labels ? t("p", ...labels) : literal("p", value);
        n.dataset.payoutState = value;
        parent.append(n);
    }
    function evidence(parent, provider) {
        const n =
            provider === "mock"
                ? t(
                      "strong",
                      "MOCK · no bank transfer",
                      "محاكاة · لا يوجد تحويل بنكي",
                  )
                : t(
                      "strong",
                      "STRIPE TEST · no real bank transfer",
                      "اختبار STRIPE · لا يوجد تحويل بنكي حقيقي",
                  );
        n.className = "badge";
        parent.append(n);
    }
    function value(parent, en, ar, v) {
        const line = document.createElement("p");
        line.append(t("span", en, ar), literal("span", " · " + (v ?? "—")));
        parent.append(line);
    }
    function parseDate(raw) {
        if (
            typeof raw === "string" &&
            /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw)
        )
            raw = raw.replace(" ", "T") + "Z";
        return new Date(raw);
    }
    function date(parent, en, ar, raw) {
        if (!raw) return;
        const d = parseDate(raw);
        const line = document.createElement("p");
        const time = literal(
            "time",
            Number.isNaN(d.getTime())
                ? raw
                : d.toLocaleString(
                      document.documentElement.lang === "ar" ? "ar" : "en",
                  ),
        );
        if (!Number.isNaN(d.getTime())) {
            time.dateTime = d.toISOString();
            time.dataset.payoutDate = "true";
        }
        line.append(t("span", en, ar), literal("span", " · "), time);
        parent.append(line);
    }
    function amount(row) {
        return (
            row.currency +
            " " +
            EvMoney.format(Number(row.amount_minor), Number(row.minor_units))
        );
    }
    function header(parent, row, en, ar) {
        const card = document.createElement("article");
        card.className = "finance-row";
        card.dataset.payoutRecord = row.id;
        const heading = document.createElement("h4");
        heading.append(t("span", en, ar), literal("span", " #" + row.id));
        card.append(heading);
        evidence(card, row.provider);
        state(card, row.status);
        value(card, "Vendor ID", "معرف المورد", row.vendor_id);
        parent.append(card);
        return card;
    }
    function pager(parent, rows, kind) {
        const nav = document.createElement("nav");
        const label = t("p", "Record pages", "صفحات السجلات");
        nav.append(label);
        value(
            nav,
            "Page",
            "الصفحة",
            rows.current_page + " / " + rows.last_page,
        );
        if (rows.current_page > 1)
            button(nav, "Previous payout page", "صفحة التحويلات السابقة", () =>
                load(kind, rows.current_page - 1),
            );
        if (rows.current_page < rows.last_page)
            button(nav, "Next payout page", "صفحة التحويلات التالية", () =>
                load(kind, rows.current_page + 1),
            );
        parent.append(nav);
    }
    function configure(parent) {
        parent.append(
            t(
                "h4",
                "Configure test payout recipient",
                "تهيئة مستلم تحويل تجريبي",
            ),
        );
        parent.append(
            t(
                "p",
                "Use provider account and external-account references only. Never enter an IBAN, card number or bank credentials here.",
                "استخدم مراجع حساب المزود والحساب الخارجي فقط. لا تدخل رقم IBAN أو بطاقة أو بيانات دخول بنكية هنا.",
            ),
        );
        form(
            parent,
            "Configure test recipient",
            "تهيئة مستلم تجريبي",
            (f) => {
                field(f, "vendor_id", "Vendor ID", "معرف المورد", "number");
                const select = document.createElement("select");
                select.name = "provider";
                for (const [id, en, ar] of [
                    ["mock", "Mock provider", "مزود محاكاة"],
                    ["stripe_test", "Stripe test", "اختبار Stripe"],
                ]) {
                    const option = t("option", en, ar);
                    option.value = id;
                    select.append(option);
                }
                labelled(f, "Payout provider", "مزود التحويل", select);
                const currency = field(
                    f,
                    "currency",
                    "Currency",
                    "العملة",
                    "text",
                    "AED",
                );
                currency.pattern = "[A-Z]{3}";
                currency.maxLength = 3;
                const account = field(
                    f,
                    "account_reference",
                    "Provider account reference",
                    "مرجع حساب المزود",
                );
                account.pattern = "(acct_|mock_)[A-Za-z0-9]+";
                account.autocomplete = "off";
                const bank = field(
                    f,
                    "bank_reference",
                    "Provider external-account reference",
                    "مرجع الحساب الخارجي لدى المزود",
                );
                bank.pattern = "(ba_|mock_)[A-Za-z0-9]+";
                bank.autocomplete = "off";
            },
            async (data, key) => {
                await request(
                    "commercial/vendors/" +
                        Number(data.vendor_id) +
                        "/payout-accounts",
                    "POST",
                    {
                        provider: data.provider,
                        currency: data.currency,
                        account_reference: data.account_reference,
                        bank_reference: data.bank_reference,
                        idempotency_key: key,
                    },
                );
                if (alive()) await load("accounts");
            },
        );
    }
    function hosted(parent, url, expires) {
        if (!Number.isFinite(expires) || expires <= Date.now())
            throw Error("Hosted payout onboarding link has expired");
        const link = new URL(url);
        if (
            link.protocol !== "https:" ||
            link.hostname !== "connect.stripe.com" ||
            link.username ||
            link.password ||
            link.port
        )
            throw Error("Invalid hosted payout onboarding URL");
        const a = t(
            "a",
            "Continue on secure provider website",
            "المتابعة على موقع المزود الآمن",
        );
        a.href = link.href;
        a.target = "_blank";
        a.rel = "noopener noreferrer";
        a.referrerPolicy = "no-referrer";
        parent.append(a);
        date(
            parent,
            "Provider link expires",
            "تنتهي صلاحية رابط المزود",
            expires,
        );
        parent.append(
            t(
                "p",
                "Returning from the provider does not verify this account. An administrator must inspect provider evidence.",
                "العودة من المزود لا تتحقق من الحساب. يجب على المسؤول فحص أدلة المزود.",
            ),
        );
    }
    function accounts(parent, rows) {
        if (role === "admin") configure(parent);
        if (!rows.data.length)
            parent.append(
                t(
                    "p",
                    "No payout recipients. An administrator must configure an authorized test recipient before vendor consent.",
                    "لا يوجد مستلمو تحويلات. يجب على المسؤول تهيئة مستلم تجريبي مصرح به قبل موافقة المورد.",
                ),
            );
        for (const row of rows.data) {
            const card = header(parent, row, "Payout account", "حساب التحويل"),
                actions = document.createElement("div");
            value(card, "Currency", "العملة", row.currency);
            date(card, "Consent recorded", "سُجلت الموافقة", row.consented_at);
            date(card, "Provider verification", "تحقق المزود", row.verified_at);
            card.append(actions);
            if (role === "vendor" && row.status === "pending_consent") {
                const label = document.createElement("label"),
                    consent = document.createElement("input");
                consent.type = "checkbox";
                label.append(
                    consent,
                    t(
                        "span",
                        "I authorize this provider test payout account for my vendor settlements (vendor-payout-v1).",
                        "أفوض حساب التحويل التجريبي هذا لتسوياتي كمورد (vendor-payout-v1).",
                    ),
                );
                actions.append(label);
                button(
                    actions,
                    "Consent to test payout account",
                    "الموافقة على حساب التحويل التجريبي",
                    async () => {
                        if (!consent.checked) {
                            message(
                                "Explicit consent is required",
                                "الموافقة الصريحة مطلوبة",
                            );
                            return;
                        }
                        await request(
                            "commercial/payout-accounts/" + row.id + "/consent",
                            "POST",
                            {
                                consent: true,
                                consent_version: "vendor-payout-v1",
                            },
                        );
                        if (alive()) await load("accounts", currentPage);
                    },
                );
            }
            if (
                role === "vendor" &&
                row.provider === "stripe_test" &&
                ["pending_verification", "active"].includes(row.status)
            ) {
                const providerLink = document.createElement("div");
                actions.append(providerLink);
                let onboardingKey = crypto.randomUUID();
                button(
                    actions,
                    "Open provider onboarding",
                    "فتح التسجيل لدى المزود",
                    async () => {
                        const result = await request(
                            "commercial/payout-accounts/" +
                                row.id +
                                "/onboarding",
                            "POST",
                            { idempotency_key: onboardingKey },
                        );
                        if (!alive()) return;
                        providerLink.replaceChildren();
                        hosted(
                            providerLink,
                            result.url,
                            Number(result.expires_at) * 1000,
                        );
                        onboardingKey = crypto.randomUUID();
                    },
                );
            }
            if (
                role === "admin" &&
                ["pending_verification", "active"].includes(row.status)
            )
                button(
                    actions,
                    "Verify with payout provider",
                    "التحقق لدى مزود التحويل",
                    async () => {
                        await request(
                            "commercial/payout-accounts/" + row.id + "/verify",
                            "POST",
                            {},
                        );
                        if (alive()) await load("accounts", currentPage);
                    },
                );
            if (row.status !== "revoked")
                button(
                    actions,
                    "Revoke payout consent",
                    "إلغاء موافقة التحويل",
                    async () => {
                        await request(
                            "commercial/payout-accounts/" + row.id + "/revoke",
                            "POST",
                            {},
                        );
                        if (alive()) await load("accounts", currentPage);
                    },
                );
        }
    }
    function prepare(parent) {
        parent.append(
            t(
                "h4",
                "Prepare approved settlement payout",
                "إعداد تحويل لتسوية معتمدة",
            ),
            t(
                "p",
                "Choose an approved settlement and a verified account for the same vendor and currency. A different administrator must approve the prepared payout before execution.",
                "اختر تسوية معتمدة وحساباً متحققاً لنفس المورد والعملة. يجب على مسؤول آخر اعتماد التحويل المُعد قبل التنفيذ.",
            ),
        );
        form(
            parent,
            "Prepare test payout",
            "إعداد تحويل تجريبي",
            (f) => {
                field(
                    f,
                    "settlement_id",
                    "Approved settlement ID",
                    "معرف التسوية المعتمدة",
                    "number",
                );
                field(
                    f,
                    "account_id",
                    "Verified payout account ID",
                    "معرف حساب التحويل المتحقق",
                    "number",
                );
            },
            async (data, key) => {
                await request(
                    "commercial/settlements/" +
                        Number(data.settlement_id) +
                        "/payouts",
                    "POST",
                    {
                        account_id: Number(data.account_id),
                        idempotency_key: key,
                    },
                );
                if (alive()) await load("payouts");
            },
        );
    }
    async function details(card, row) {
        const r = await request("commercial/payouts/" + row.id);
        if (!alive() || !card.isConnected) return;
        card.querySelector("[data-payout-history]")?.remove();
        const history = document.createElement("div");
        history.dataset.payoutHistory = "true";
        history.append(
            t(
                "h5",
                "Provider attempts and immutable audit",
                "محاولات المزود وسجل التدقيق غير القابل للتعديل",
            ),
        );
        for (const attempt of r.attempts) {
            const item = document.createElement("article");
            value(item, "Attempt", "المحاولة", attempt.number);
            state(item, attempt.status);
            value(
                item,
                "Provider reference",
                "مرجع المزود",
                attempt.provider_reference,
            );
            value(item, "Failure code", "رمز الإخفاق", attempt.failure_code);
            date(
                item,
                "Provider evidence observed",
                "رُصدت أدلة المزود",
                attempt.observed_at,
            );
            date(
                item,
                "Same-attempt retry deadline",
                "موعد إعادة المحاولة نفسها",
                attempt.retry_until,
            );
            history.append(item);
        }
        if (!r.attempts.length)
            history.append(
                t(
                    "p",
                    "No provider attempt has been dispatched",
                    "لم تُرسل أي محاولة إلى المزود",
                ),
            );
        for (const event of r.events) {
            const item = document.createElement("p");
            item.append(
                literal("span", event.kind || event.action),
                literal("span", " · " + (event.created_at || "")),
            );
            history.append(item);
        }
        const latest = r.attempts[r.attempts.length - 1];
        if (
            role === "admin" &&
            ["uncertain", "executing"].includes(r.payout.status) &&
            latest &&
            parseDate(latest.retry_until).getTime() > Date.now() &&
            (!latest.lease_until ||
                parseDate(latest.lease_until).getTime() <= Date.now())
        ) {
            history.append(
                t(
                    "p",
                    "Reconcile first. A same-attempt replay reuses the existing provider request within its retention window; it does not create a replacement payout.",
                    "طابق أولاً. تعيد المحاولة نفسها استخدام طلب المزود الحالي ضمن مهلة الاحتفاظ؛ ولا تنشئ تحويلاً بديلاً.",
                ),
            );
            const label = document.createElement("label"),
                confirm = document.createElement("input");
            confirm.type = "checkbox";
            label.append(
                confirm,
                t(
                    "span",
                    "I have reviewed this uncertain attempt and authorize replay of the same test request.",
                    "راجعت هذه المحاولة غير المؤكدة وأفوض إعادة إرسال طلب الاختبار نفسه.",
                ),
            );
            history.append(label);
            button(
                history,
                "Replay same provider attempt",
                "إعادة إرسال محاولة المزود نفسها",
                async () => {
                    if (!confirm.checked) {
                        message(
                            "Explicit confirmation is required",
                            "التأكيد الصريح مطلوب",
                        );
                        return;
                    }
                    await request(
                        "commercial/payouts/" + row.id + "/execute",
                        "POST",
                        {},
                    );
                    if (alive()) await load("payouts", currentPage);
                },
            );
        }
        card.append(history);
    }
    function payouts(parent, rows) {
        if (role === "admin") prepare(parent);
        if (!rows.data.length)
            parent.append(
                t(
                    "p",
                    "No payout records. Approved settlements remain separate from provider-confirmed payout evidence.",
                    "لا توجد سجلات تحويلات. تبقى التسويات المعتمدة منفصلة عن أدلة التحويل المؤكدة من المزود.",
                ),
            );
        for (const row of rows.data) {
            const card = header(parent, row, "Payout", "التحويل");
            card.append(literal("p", amount(row)));
            value(card, "Settlement ID", "معرف التسوية", row.settlement_id);
            value(
                card,
                "Payout account ID",
                "معرف حساب التحويل",
                row.account_id,
            );
            value(
                card,
                "Prepared by administrator",
                "أعدّه المسؤول",
                row.created_by,
            );
            value(
                card,
                "Approved by administrator",
                "اعتمده المسؤول",
                row.approved_by,
            );
            date(card, "Last updated", "آخر تحديث", row.updated_at);
            button(card, "Inspect payout history", "فحص سجل التحويل", () =>
                details(card, row),
            );
            if (role !== "admin") continue;
            const actions = [];
            if (row.status === "prepared") {
                if (Number(row.created_by) !== Number(identity))
                    actions.push([
                        "approve",
                        "Approve payout independently",
                        "اعتماد التحويل بشكل مستقل",
                    ]);
                else
                    card.append(
                        t(
                            "p",
                            "Another administrator must approve this payout.",
                            "يجب على مسؤول آخر اعتماد هذا التحويل.",
                        ),
                    );
            }
            if (row.status === "approved")
                actions.push([
                    "execute",
                    "Execute test payout",
                    "تنفيذ التحويل التجريبي",
                ]);
            if (
                [
                    "pending",
                    "uncertain",
                    "executing",
                    "paid",
                    "failed",
                    "reversed",
                    "needs_review",
                ].includes(row.status)
            )
                actions.push([
                    "reconcile",
                    "Reconcile with provider",
                    "المطابقة مع المزود",
                ]);
            if (["failed", "reversed"].includes(row.status))
                actions.push([
                    "retry",
                    "Prepare retry for independent approval",
                    "إعداد إعادة المحاولة لاعتماد مستقل",
                ]);
            if (
                ["prepared", "approved", "failed", "reversed"].includes(
                    row.status,
                )
            )
                actions.push([
                    "cancel",
                    "Cancel unexecuted payout",
                    "إلغاء تحويل غير منفذ",
                ]);
            for (const [action, en, ar] of actions)
                button(card, en, ar, async () => {
                    await request(
                        "commercial/payouts/" + row.id + "/" + action,
                        "POST",
                        {},
                    );
                    if (alive()) await load("payouts", currentPage);
                });
            if (["uncertain", "executing", "needs_review"].includes(row.status))
                card.append(
                    t(
                        "p",
                        "Do not create a replacement payout while its outcome is uncertain. Inspect provider evidence and reconcile the existing attempt.",
                        "لا تنشئ تحويلاً بديلاً بينما النتيجة غير مؤكدة. افحص أدلة المزود وطابق المحاولة الحالية.",
                    ),
                );
        }
    }
    async function load(kind = currentKind, page = 1) {
        const epoch = ++generation;
        currentKind = kind;
        currentPage = page;
        message("Loading payout records…", "جار تحميل سجلات التحويل…");
        const rows = await request(
            "commercial/" +
                (kind === "accounts" ? "payout-accounts" : "payouts") +
                "?page=" +
                page,
        );
        if (!alive() || epoch !== generation) return;
        content.replaceChildren();
        const nav = document.createElement("nav");
        nav.className = "finance-pagination";
        button(nav, "Payout recipients", "مستلمو التحويلات", () =>
            load("accounts"),
        );
        button(nav, "Payout records", "سجلات التحويلات", () => load("payouts"));
        button(nav, "Refresh payouts", "تحديث التحويلات", () =>
            load(kind, page),
        );
        content.append(nav);
        if (kind === "accounts") accounts(content, rows);
        else payouts(content, rows);
        pager(content, rows, kind);
        status.textContent = "";
    }
    button(
        container,
        "Open vendor payout operations",
        "فتح عمليات تحويلات الموردين",
        () => load(),
    );
    container.append(content);
};
