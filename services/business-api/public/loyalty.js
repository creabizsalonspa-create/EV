/* Non-monetary promotional points. No wallet, money transfer or tariff modification. */
// Partner reward timestamps are server instants, not browser-local dates.
function validLoyaltyClock(raw) {
    return typeof raw === "string" && raw === raw.trim() &&
        !!window.EvStationTime?.displayStationTime(raw, "UTC", "en");
}
function verifyPartnerRewardPage(result, page, kind) {
    const fail = () => { throw new Error("Invalid partner reward page"); };
    if (!Number.isSafeInteger(page) || page < 1 || page > 100000 ||
        !["offers", "claims"].includes(kind) || !Array.isArray(result?.data) ||
        result.current_page !== page || result.per_page !== 25 ||
        !Number.isSafeInteger(result.total) || result.total < 0 ||
        result.last_page !== Math.max(1, Math.ceil(result.total / 25)) ||
        result.data.length !== Math.min(25, Math.max(0, result.total - (page - 1) * 25))) fail();
    let previous = Infinity;
    const redemptions = new Set();
    const positive = v => Number.isSafeInteger(v) && v > 0;
    for (const row of result.data) {
        if (!row || row.source !== "RETAIL_PARTNER_REWARD" ||
            row.evidence !== "SANDBOX_MANUAL_NONCASH" || row.external_fulfillment_verified !== false ||
            row.cash_value !== false || row.points_cost !== 0 || !positive(row.review_id)) fail();
        const id = kind === "offers" ? row.review_id : row.id;
        if (!positive(id) || id >= previous) fail();
        previous = id;
        if (kind === "claims") {
            if (!positive(row.redemption_id) || redemptions.has(row.redemption_id) ||
                !validLoyaltyClock(row.created_at) || !["pending", "fulfilled", "rejected"].includes(row.status)) fail();
            redemptions.add(row.redemption_id);
        } else if (!["name", "name_ar", "partner_name", "partner_name_ar", "description", "description_ar", "ends_at"]
            .every(field => typeof row[field] === "string") || !validLoyaltyClock(row.ends_at)) fail();
    }
    return result;
}

window.mountLoyalty = ({ container, api, ui, actor }) => {
    const owner = actor()?.id,
        role = actor()?.role,
        controller = new AbortController();
    let busy = false,
        generation = 0,
        section = "account",
        page = 1;
    const alive = () => container.isConnected &&
        actor()?.id === owner && actor()?.role === role;
    const observer = new MutationObserver(() => {
        if (!alive()) {
            generation++;
            controller.abort();
            observer.disconnect();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    container.dataset.loyalty = "true";
    function t(tag, en, ar) {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    }
    function node(tag, value = "") {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    }
    const status = node("p"),
        content = node("div");
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    container.append(
        t("h4", "Member points & rewards", "نقاط العضوية ومكافآتها"),
        t(
            "p",
            "Test promotional points only. Points have no cash value, cannot be transferred and do not change charging prices. Rewards require manual fulfillment; no partner fulfillment is verified.",
            "نقاط ترويجية تجريبية فقط. لا قيمة نقدية للنقاط ولا يمكن تحويلها ولا تغيّر أسعار الشحن. تتطلب المكافآت تسليماً يدوياً؛ لم يتم التحقق من تسليم الشركاء.",
        ),
        status,
    );
    async function request(path, method = "GET", data) {
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        return api(
            "commercial/loyalty/" + path,
            method,
            data,
            controller.signal,
        );
    }
    async function run(action) {
        if (busy || !alive()) return;
        busy = true;
        const previous = new Map();
        container.setAttribute("aria-busy", "true");
        for (const input of container.querySelectorAll(
            "button,input,select,textarea",
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
                for (const [input, disabled] of previous)
                    if (input.isConnected) input.disabled = disabled;
            }
        }
    }
    function button(parent, en, ar, action) {
        const b = t("button", en, ar);
        b.type = "button";
        b.onclick = () => run(action);
        parent.append(b);
        return b;
    }
    function field(
        parent,
        name,
        en,
        ar,
        value = "",
        type = "text",
        required = true,
    ) {
        const label = node("label"),
            input = document.createElement(
                type === "textarea" ? "textarea" : "input",
            );
        label.append(t("span", en, ar), input);
        input.name = name;
        input.required = required;
        input.maxLength = type === "textarea" ? 1000 : 150;
        if (type !== "textarea") input.type = type;
        if (type === "datetime-local") input.step = "1";
        if (type === "number") {
            input.min = "0";
            input.step = "1";
        }
        input.value = value;
        parent.append(label);
        return input;
    }
    function form(parent, en, ar, build, submit) {
        const f = node("form");
        f.className = "finance-form";
        f.dataset.loyaltyForm = en;
        let key = crypto.randomUUID();
        build(f);
        f.addEventListener("input", () => (key = crypto.randomUUID()));
        f.addEventListener("change", () => (key = crypto.randomUUID()));
        const b = t("button", en, ar);
        b.type = "submit";
        f.append(b);
        f.onsubmit = (event) => {
            event.preventDefault();
            if (!f.reportValidity()) return;
            const data = Object.fromEntries(new FormData(f));
            run(() => submit(data, key));
        };
        parent.append(f);
        return f;
    }
    function line(parent, en, ar, value) {
        const p = node("p");
        p.append(t("span", en, ar), node("span", " · " + (value ?? "—")));
        parent.append(p);
    }
    function time(parent, en, ar, value) {
        const line = node("p");
        const clock = window.EvStationTime.bindServerEventTime(node("span"), value);
        clock.dataset.loyaltyDate = "true";
        if (validLoyaltyClock(value)) {
            const sql = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(value);
            clock.dataset.loyaltyInstant = new Date(sql ? value.replace(" ", "T") + "Z" : value).toISOString();
        }
        line.append(t("span", en, ar), node("span", " · "), clock);
        parent.append(line);
    }
    function card(parent, en, ar, id) {
        const n = node("article");
        n.className = "finance-row";
        const heading = node("h4");
        heading.append(t("span", en, ar), node("span", id ? " #" + id : ""));
        n.append(heading);
        parent.append(n);
        return n;
    }
    function pager(parent, rows, kind) {
        line(
            parent,
            "Page",
            "الصفحة",
            rows.current_page + " / " + rows.last_page,
        );
        if (rows.current_page > 1)
            button(parent, "Previous loyalty page", "صفحة الولاء السابقة", () =>
                load(kind, rows.current_page - 1),
            );
        if (rows.current_page < rows.last_page)
            button(parent, "Next loyalty page", "صفحة الولاء التالية", () =>
                load(kind, rows.current_page + 1),
            );
    }
    function localized(tag, en, ar) {
        const n = node(tag, document.documentElement.lang === "ar" ? ar : en);
        n.dataset.en = en;
        n.dataset.ar = ar;
        return n;
    }
    function points(parent, en, ar, value) {
        const box = node("div");
        box.className = "finance-metric";
        box.append(
            t("span", en, ar),
            node(
                "strong",
                new Intl.NumberFormat(
                    document.documentElement.lang === "ar" ? "ar" : "en",
                ).format(value),
            ),
            t("span", "Non-monetary points", "نقاط غير نقدية"),
        );
        parent.append(box);
    }
    function account(parent, data) {
        const metrics = node("div");
        metrics.className = "finance-metrics";
        points(
            metrics,
            "Recorded balance",
            "الرصيد المسجل",
            data.balance_points,
        );
        points(
            metrics,
            "Available for rewards",
            "المتاح للمكافآت",
            data.spendable_points,
        );
        points(
            metrics,
            "Pending refund or expiry reconciliation",
            "بانتظار مطابقة الاسترداد أو الانتهاء",
            data.pending_reconciliation_points,
        );
        points(
            metrics,
            "Qualified lifetime points",
            "النقاط المؤهلة مدى الحياة",
            data.qualified_lifetime_points,
        );
        parent.append(metrics);
        if (data.redemption_hold)
            parent.append(
                t(
                    "p",
                    "Rewards are held while payment, refund or dispute evidence is unresolved. Refresh and reconcile before requesting a reward.",
                    "المكافآت موقوفة أثناء عدم حسم أدلة الدفع أو الاسترداد أو النزاع. حدّث وطابق قبل طلب مكافأة.",
                ),
            );
        if (data.balance_points < 0)
            parent.append(
                t(
                    "p",
                    "Refund reversals exceed the remaining points balance. New points must cover this non-monetary deficit before further rewards.",
                    "عكس نقاط الاسترداد يتجاوز رصيد النقاط المتبقي. يجب أن تغطي نقاط جديدة هذا العجز غير النقدي قبل طلب مكافآت أخرى.",
                ),
            );
        for (const [currency, detail] of Object.entries(
            data.tier_by_currency || {},
        )) {
            const n = card(parent, "Membership tier", "فئة العضوية");
            const tier = typeof detail === "string" ? detail : detail.tier;
            n.append(node("p", currency));
            n.append(
                t(
                    "strong",
                    tier === "Platinum"
                        ? "Platinum"
                        : tier === "Gold"
                          ? "Gold"
                          : "Silver",
                    tier === "Platinum"
                        ? "بلاتيني"
                        : tier === "Gold"
                          ? "ذهبي"
                          : "فضي",
                ),
            );
        }
        parent.append(
            t(
                "p",
                "Points are earned only from fully captured canonical charging invoices under an effective policy. Refunds, chargebacks, daily limits and expiry can reduce available points. Current invoice and payment records remain authoritative.",
                "تُكتسب النقاط فقط من فواتير الشحن المعتمدة والمدفوعة بالكامل بموجب سياسة سارية. قد تخفض الاستردادات والاعتراضات والحدود اليومية والانتهاء النقاط المتاحة. تبقى سجلات الفواتير والدفع الحالية مرجعاً معتمداً.",
            ),
        );
        form(
            parent,
            "Earn points from paid invoice",
            "اكتساب نقاط من فاتورة مدفوعة",
            (f) => {
                field(
                    f,
                    "invoice_id",
                    "Paid charging invoice ID",
                    "معرف فاتورة الشحن المدفوعة",
                    "",
                    "number",
                ).min = "1";
            },
            async (data) => {
                await request(
                    "invoices/" + Number(data.invoice_id) + "/earn",
                    "POST",
                    {},
                );
                if (alive()) await load("account");
            },
        );
        button(
            parent,
            "Reconcile point reversals and expiry",
            "مطابقة عكس النقاط وانتهائها",
            async () => {
                await request("reconcile", "POST", {});
                if (alive()) await load("account");
            },
        );
    }
    function ledger(parent, rows) {
        if (!rows.data.length)
            parent.append(
                t(
                    "p",
                    "No points have been posted. Eligible invoice awards appear here after earning.",
                    "لم تُسجل أي نقاط. تظهر نقاط الفواتير المؤهلة هنا بعد اكتسابها.",
                ),
            );
        const names = {
            earn: ["Points earned", "نقاط مكتسبة"],
            redeem: ["Reward requested", "مكافأة مطلوبة"],
            release: [
                "Rejected reward points released",
                "إعادة نقاط المكافأة المرفوضة",
            ],
            reversal: [
                "Refund or chargeback reversal",
                "عكس بسبب استرداد أو اعتراض",
            ],
            expiry: ["Points expired", "نقاط منتهية"],
        };
        for (const row of rows.data) {
            const n = card(parent, "Points entry", "قيد النقاط", row.id);
            n.append(
                names[row.kind]
                    ? t("p", ...names[row.kind])
                    : node("p", row.kind),
            );
            line(n, "Points", "النقاط", row.points);
            if (row.award_id)
                line(n, "Award ID", "معرف النقاط المكتسبة", row.award_id);
            if (row.redemption_id)
                line(n, "Redemption ID", "معرف الاستبدال", row.redemption_id);
            time(n, "Posted at", "وقت التسجيل", row.created_at);
        }
    }
    const numericPolicies = [
        [
            "minor_units",
            "Currency minor-unit digits",
            "عدد الخانات العشرية للعملة",
            2,
            0,
            3,
        ],
        [
            "points_numerator",
            "Points numerator",
            "بسط نسبة النقاط",
            1,
            1,
            10000,
        ],
        [
            "minor_denominator",
            "Invoice minor-unit denominator",
            "مقام وحدات الفاتورة الصغرى",
            100,
            1,
            1000000000,
        ],
        [
            "gold_threshold",
            "Gold lifetime threshold",
            "حد النقاط مدى الحياة للفئة الذهبية",
            1000,
            1,
            100000000,
        ],
        [
            "platinum_threshold",
            "Platinum lifetime threshold",
            "حد النقاط مدى الحياة للفئة البلاتينية",
            10000,
            1,
            1000000000,
        ],
        [
            "gold_multiplier_bps",
            "Gold multiplier in basis points",
            "مضاعف الذهبي بالنقاط الأساسية",
            12500,
            10000,
            100000,
        ],
        [
            "platinum_multiplier_bps",
            "Platinum multiplier in basis points",
            "مضاعف البلاتيني بالنقاط الأساسية",
            15000,
            10000,
            100000,
        ],
        [
            "invoice_cap",
            "Per-invoice points cap",
            "الحد الأقصى لنقاط الفاتورة",
            1000,
            1,
            1000000,
        ],
        [
            "daily_cap",
            "Daily earning cap",
            "الحد اليومي لاكتساب النقاط",
            5000,
            1,
            10000000,
        ],
        [
            "expiry_days",
            "Points validity in days",
            "صلاحية النقاط بالأيام",
            365,
            1,
            3650,
        ],
        [
            "daily_redemption_limit",
            "Daily reward-request limit",
            "الحد اليومي لطلبات المكافآت",
            5,
            1,
            100,
        ],
    ];
    function localDate(value = new Date()) {
        const d = new Date(value.getTime() - value.getTimezoneOffset() * 60000);
        return d.toISOString().slice(0, 16);
    }
    function policyForm(parent) {
        parent.append(
            t(
                "h4",
                "Publish versioned test points policy",
                "نشر نسخة من سياسة النقاط التجريبية",
            ),
            t(
                "p",
                "Published policies are immutable. Publish a new effective version to change earning or disable future awards. Existing awards retain their original terms. These example settings are editable test economics.",
                "السياسات المنشورة غير قابلة للتعديل. انشر نسخة جديدة بتاريخ سريان لتغيير الاكتساب أو تعطيل النقاط المستقبلية. تحتفظ النقاط المكتسبة بشروطها الأصلية. هذه إعدادات اقتصادية تجريبية قابلة للتغيير.",
            ),
        );
        form(
            parent,
            "Publish points policy",
            "نشر سياسة النقاط",
            (f) => {
                const currency = field(
                    f,
                    "currency",
                    "Policy currency",
                    "عملة السياسة",
                    "AED",
                );
                currency.pattern = "[A-Z]{3}";
                currency.maxLength = 3;
                const label = node("label"),
                    select = node("select");
                select.name = "enabled";
                for (const [v, en, ar] of [
                    ["false", "Disabled", "معطلة"],
                    ["true", "Enabled", "مفعلة"],
                ]) {
                    const option = t("option", en, ar);
                    option.value = v;
                    select.append(option);
                }
                label.append(
                    t("span", "Earning policy state", "حالة سياسة الاكتساب"),
                    select,
                );
                f.append(label);
                for (const [name, en, ar, value, min, max] of numericPolicies) {
                    const input = field(f, name, en, ar, value, "number");
                    input.min = min;
                    input.max = max;
                }
                field(
                    f,
                    "effective_from",
                    "Effective from (local time)",
                    "سارية من (التوقيت المحلي)",
                    localDate(),
                    "datetime-local",
                );
            },
            async (data, key) => {
                for (const [name] of numericPolicies)
                    data[name] = Number(data[name]);
                data.enabled = data.enabled === "true";
                data.effective_from = new Date(
                    data.effective_from,
                ).toISOString();
                await request("policies", "POST", {
                    ...data,
                    idempotency_key: key,
                });
                if (alive()) await load("policies");
            },
        );
    }
    function policies(parent, rows) {
        if (role === "admin") policyForm(parent);
        if (!rows.data.length)
            parent.append(
                t(
                    "p",
                    "No published points policy. Earning is unavailable until an administrator publishes effective test economics.",
                    "لا توجد سياسة نقاط منشورة. لن يتاح الاكتساب حتى ينشر المسؤول سياسة تجريبية سارية.",
                ),
            );
        for (const row of rows.data) {
            const n = card(parent, "Points policy", "سياسة النقاط", row.id);
            line(n, "Policy currency", "عملة السياسة", row.currency);
            n.append(
                t(
                    "p",
                    row.enabled ? "Earning enabled" : "Earning disabled",
                    row.enabled ? "اكتساب النقاط مفعل" : "اكتساب النقاط معطل",
                ),
            );
            for (const [name, en, ar] of numericPolicies)
                line(n, en, ar, row[name]);
            time(n, "Effective from", "سارية من", row.effective_from);
        }
    }
    function rewardForm(parent) {
        parent.append(
            t(
                "h4",
                "Publish manual non-monetary reward",
                "نشر مكافأة غير نقدية بتسليم يدوي",
            ),
            t(
                "p",
                "Describe only a reward you are authorized to fulfill. Publishing does not establish a partner agreement or verify delivery. Currency chooses the applicable redemption policy; it is not a cash value for points.",
                "صف فقط مكافأة مصرحاً لك بتسليمها. النشر لا يُنشئ اتفاقية شريك ولا يتحقق من التسليم. تحدد العملة سياسة الاستبدال المناسبة؛ ولا تعني قيمة نقدية للنقاط.",
            ),
        );
        form(
            parent,
            "Publish loyalty reward",
            "نشر مكافأة الولاء",
            (f) => {
                field(
                    f,
                    "name",
                    "Reward name in English",
                    "اسم المكافأة بالإنجليزية",
                ).maxLength = 200;
                field(
                    f,
                    "name_ar",
                    "Reward name in Arabic",
                    "اسم المكافأة بالعربية",
                ).maxLength = 200;
                field(
                    f,
                    "description",
                    "Reward description in English",
                    "وصف المكافأة بالإنجليزية",
                    "",
                    "textarea",
                ).maxLength = 3000;
                field(
                    f,
                    "description_ar",
                    "Reward description in Arabic",
                    "وصف المكافأة بالعربية",
                    "",
                    "textarea",
                ).maxLength = 3000;
                const currency = field(
                    f,
                    "currency",
                    "Redemption-policy currency",
                    "عملة سياسة الاستبدال",
                    "AED",
                );
                currency.pattern = "[A-Z]{3}";
                currency.maxLength = 3;
                for (const [name, en, ar, value, max] of [
                    [
                        "points_cost",
                        "Reward points cost",
                        "تكلفة المكافأة بالنقاط",
                        100,
                        10000000,
                    ],
                    [
                        "stock",
                        "Available reward units",
                        "وحدات المكافأة المتاحة",
                        10,
                        1000000,
                    ],
                    [
                        "per_user_limit",
                        "Per-member reward limit",
                        "حد المكافأة لكل عضو",
                        1,
                        100,
                    ],
                ]) {
                    const input = field(f, name, en, ar, value, "number");
                    input.min = 1;
                    input.max = max;
                }
                field(
                    f,
                    "starts_at",
                    "Reward starts (local time)",
                    "بداية المكافأة (التوقيت المحلي)",
                    localDate(),
                    "datetime-local",
                );
                field(
                    f,
                    "ends_at",
                    "Reward ends (local time)",
                    "نهاية المكافأة (التوقيت المحلي)",
                    localDate(new Date(Date.now() + 30 * 86400000)),
                    "datetime-local",
                );
            },
            async (data, key) => {
                for (const name of ["points_cost", "stock", "per_user_limit"])
                    data[name] = Number(data[name]);
                for (const name of ["starts_at", "ends_at"])
                    data[name] = new Date(data[name]).toISOString();
                await request("rewards", "POST", {
                    ...data,
                    idempotency_key: key,
                });
                if (alive()) await load("rewards");
            },
        );
    }
    const redemptionKeys = new Map();
    function rewards(parent, rows) {
        if (role === "admin") rewardForm(parent);
        if (!rows.data.length)
            parent.append(
                t(
                    "p",
                    "No rewards are available. Your point history remains available independently of the reward catalog.",
                    "لا تتوفر مكافآت. يبقى سجل نقاطك متاحاً بشكل مستقل عن دليل المكافآت.",
                ),
            );
        for (const row of rows.data) {
            const n = card(parent, "Loyalty reward", "مكافأة الولاء", row.id);
            n.append(
                localized("h5", row.name, row.name_ar),
                localized("p", row.description, row.description_ar),
                t(
                    "strong",
                    "Manual non-monetary fulfillment",
                    "تسليم يدوي غير نقدي",
                ),
            );
            line(
                n,
                "Reward points cost",
                "تكلفة المكافأة بالنقاط",
                row.points_cost,
            );
            line(
                n,
                "Available reward units",
                "وحدات المكافأة المتاحة",
                row.available_stock,
            );
            line(
                n,
                "Per-member reward limit",
                "حد المكافأة لكل عضو",
                row.per_user_limit,
            );
            line(
                n,
                "Redemption-policy currency",
                "عملة سياسة الاستبدال",
                row.currency,
            );
            time(n, "Reward starts", "بداية المكافأة", row.starts_at);
            time(n, "Reward ends", "نهاية المكافأة", row.ends_at);
            if (Number(row.available_stock) <= 0) {
                n.append(
                    t(
                        "p",
                        "Reward inventory is exhausted",
                        "نفدت وحدات المكافأة",
                    ),
                );
                continue;
            }
            const confirmation = node("label"),
                checkbox = node("input");
            checkbox.type = "checkbox";
            confirmation.append(
                checkbox,
                t(
                    "span",
                    "I request this manual reward using non-monetary points. Fulfillment is subject to review and is not guaranteed by this request.",
                    "أطلب هذه المكافأة اليدوية باستخدام نقاط غير نقدية. يخضع التسليم للمراجعة ولا يضمنه هذا الطلب.",
                ),
            );
            n.append(confirmation);
            button(
                n,
                "Request reward with points",
                "طلب المكافأة بالنقاط",
                async () => {
                    if (!checkbox.checked) {
                        status.replaceChildren(
                            t(
                                "span",
                                "Confirm the reward request first",
                                "أكد طلب المكافأة أولاً",
                            ),
                        );
                        return;
                    }
                    if (!redemptionKeys.has(row.id))
                        redemptionKeys.set(row.id, crypto.randomUUID());
                    await request("rewards/" + row.id + "/redeem", "POST", {
                        idempotency_key: redemptionKeys.get(row.id),
                    });
                    if (!alive()) return;
                    redemptionKeys.delete(row.id);
                    await load("redemptions");
                },
            );
        }
    }
    const redemptionStates = {
        pending: [
            "Awaiting manual reward review",
            "بانتظار مراجعة المكافأة اليدوية",
        ],
        fulfilled: ["Manually marked fulfilled", "سُجل التسليم يدوياً"],
        rejected: ["Reward request rejected", "رُفض طلب المكافأة"],
    };
    async function redemptionHistory(parent, id) {
        const epoch = generation;
        // An old decision history is not authoritative once refreshed.
        parent.querySelector("[data-loyalty-history]")?.remove();
        const loading = t("p", "Loading reward decisions…",
            "جارٍ تحميل قرارات المكافأة…");
        loading.dataset.loyaltyHistoryLoading = "true";
        parent.append(loading);
        let result;
        try {
            result = await request("redemptions/" + id);
            if (!alive() || epoch !== generation || !parent.isConnected) return;
            if (!result?.reward || !result.redemption ||
                !Array.isArray(result.events) ||
                !Number.isSafeInteger(result.redemption.id) ||
                result.redemption.id !== id ||
                !Number.isSafeInteger(result.reward.id) ||
                result.reward.id !== result.redemption.reward_id ||
                (role !== "admin" && result.redemption.user_id !== owner))
                throw Error("Unreadable reward decision history");
        } catch {
            if (alive() && epoch === generation && parent.isConnected)
                status.replaceChildren(t("span",
                    "Reward decisions unavailable. Retry to verify the current status.",
                    "تعذر تحميل قرارات المكافأة؛ أعد المحاولة للتحقق من الحالة."));
            return;
        } finally {
            loading.remove();
        }
        const history = node("div");
        history.dataset.loyaltyHistory = "true";
        history.append(
            t("h5", "Reward decision history", "سجل قرارات المكافأة"),
            localized("p", result.reward.name, result.reward.name_ar),
        );
        for (const event of result.events) {
            const eventCard = node("article");
            const labels =
                redemptionStates[event.action] ||
                (event.action === "requested"
                    ? ["Reward requested", "مكافأة مطلوبة"]
                    : null);
            eventCard.append(
                labels ? t("strong", ...labels) : node("strong", event.action),
                node("p", event.reason),
            );
            time(eventCard, "Recorded at", "سُجل في", event.created_at);
            history.append(eventCard);
        }
        parent.append(history);
    }
    function redemptions(parent, rows) {
        if (!rows.data.length)
            parent.append(
                t(
                    "p",
                    "No reward requests. Explore the reward catalog after earning eligible points.",
                    "لا توجد طلبات مكافآت. استكشف دليل المكافآت بعد اكتساب نقاط مؤهلة.",
                ),
            );
        for (const row of rows.data) {
            const n = card(parent, "Reward request", "طلب المكافأة", row.id);
            n.append(
                redemptionStates[row.status]
                    ? t("p", ...redemptionStates[row.status])
                    : node("p", row.status),
            );
            line(n, "Reward ID", "معرف المكافأة", row.reward_id);
            line(n, "Points used", "النقاط المستخدمة", row.points_cost);
            if (role === "admin")
                line(n, "Member ID", "معرف العضو", row.user_id);
            time(n, "Requested at", "وقت الطلب", row.created_at);
            button(
                n,
                "Inspect reward decision history",
                "فحص سجل قرارات المكافأة",
                () => redemptionHistory(n, row.id),
            );
            if (role === "admin" && row.status === "pending") {
                n.append(
                    t(
                        "p",
                        "Mark fulfilled only after the authorized manual reward has actually been delivered. This records your decision, not external partner verification.",
                        "سجّل التسليم فقط بعد تسليم المكافأة اليدوية المصرح بها فعلياً. يسجل هذا قرارك ولا يثبت تحقق شريك خارجي.",
                    ),
                );
                form(
                    n,
                    "Record manual reward decision",
                    "تسجيل قرار المكافأة اليدوية",
                    (f) => {
                        const label = node("label"),
                            select = node("select");
                        select.name = "action";
                        select.required = true;
                        for (const [v, en, ar] of [
                            [
                                "",
                                "Choose reward decision",
                                "اختر قرار المكافأة",
                            ],
                            [
                                "fulfill",
                                "Confirm manual fulfillment",
                                "تأكيد التسليم اليدوي",
                            ],
                            [
                                "reject",
                                "Reject and release points",
                                "الرفض وإعادة النقاط",
                            ],
                        ]) {
                            const option = t("option", en, ar);
                            option.value = v;
                            select.append(option);
                        }
                        label.append(
                            t("span", "Reward decision", "قرار المكافأة"),
                            select,
                        );
                        f.append(label);
                        const reason = field(
                            f,
                            "reason",
                            "Decision reason or fulfillment reference",
                            "سبب القرار أو مرجع التسليم",
                        );
                        reason.minLength = 5;
                        reason.maxLength = 255;
                    },
                    async (data) => {
                        await request(
                            "redemptions/" + row.id + "/" + data.action,
                            "POST",
                            { reason: data.reason },
                        );
                        if (alive()) await load("redemptions", page);
                    },
                );
            }
        }
    }
    async function load(kind = section, requestedPage = 1) {
        const epoch = ++generation;
        section = kind;
        page = requestedPage;
        // Clear sensitive ledger/reward actions before the network read.
        // A rejected or unresponsive refresh is not a verified empty account.
        content.replaceChildren();
        content.setAttribute("aria-busy", "true");
        status.replaceChildren(
            t("span", "Loading loyalty records…", "جار تحميل سجلات الولاء…"),
        );
        try {
            const result = await request(
                kind === "account" ? "account" : kind + "?page=" + requestedPage,
            );
            if (!alive() || epoch !== generation) return;
            if (!["account", "ledger", "policies", "rewards", "redemptions"].includes(kind) ||
                !result) throw Error("Unreadable loyalty response");
            if (kind === "account") {
                // Only present a verified own-account noncash ledger summary.
                // Never render malformed figures as "undefined" points.
                if (result.user_id !== owner ||
                    result.unit !== "NON_MONETARY_POINTS" ||
                    result.evidence !== "TEST_PROMOTIONAL_ECONOMICS" ||
                    result.cash_value !== false || result.transferable !== false ||
                    !["balance_points", "spendable_points",
                        "pending_reconciliation_points", "qualified_lifetime_points"]
                        .every(key => Number.isSafeInteger(result[key])))
                    throw Error("Unreadable account points");
            } else if (!Array.isArray(result.data) ||
                !Number.isSafeInteger(result.current_page) ||
                !Number.isSafeInteger(result.last_page) ||
                result.current_page < 1 || result.last_page < 1 ||
                result.current_page > result.last_page ||
                !Number.isSafeInteger(result.total) || result.total < 0)
                throw Error("Unreadable loyalty page");
            content.replaceChildren();
            const nav = node("nav");
            nav.className = "finance-pagination";
            for (const [id, en, ar] of [
                ["account", "Points & membership", "النقاط والعضوية"],
                ["ledger", "Points history", "سجل النقاط"],
                ["rewards", "Reward catalog", "دليل المكافآت"],
                ["redemptions", "Reward requests", "طلبات المكافآت"],
                ["policies", "Points policies", "سياسات النقاط"],
            ])
                button(nav, en, ar, () => load(id));
            button(nav, "Refresh loyalty", "تحديث الولاء", () =>
                load(kind, requestedPage),
            );
            content.append(nav);
            ({ account, ledger, policies, rewards, redemptions })[kind](
                content, result,
            );
            if (kind !== "account") pager(content, result, kind);
            status.textContent = "";
        } catch {
            if (!alive() || epoch !== generation) return;
            content.replaceChildren();
            status.replaceChildren(t("span",
                "Loyalty records unavailable. Retry to verify points and rewards.",
                "تعذر تحميل سجلات الولاء؛ أعد المحاولة للتحقق من النقاط والمكافآت."));
            button(content, "Retry loyalty records", "إعادة تحميل سجلات الولاء",
                () => load(kind, requestedPage));
        } finally {
            if (alive() && epoch === generation)
                content.removeAttribute("aria-busy");
        }
    }
    button(container, "Open loyalty & rewards", "فتح الولاء والمكافآت", () =>
        load(),
    );
    if (role === "customer" || role === "admin") {
        const panel = node("section"), records = node("div"), feedback = node("p");
        panel.dataset.couponPanel = "true";
        feedback.setAttribute("role", "status");
        const couponRequest = (path = "", method = "GET", data) => {
            if (!alive()) throw new DOMException("Session changed", "AbortError");
            return api("commercial/coupons" + path, method, data, controller.signal);
        };
        panel.append(t("h4", "Reward coupons", "قسائم المكافآت"), feedback);
        const code = field(panel, "coupon_code", "Coupon code", "رمز القسيمة");
        code.autocomplete = "off";
        code.maxLength = 40;
        code.pattern = "[A-Za-z0-9\\-]{12,40}";
        const scope = "creabiz.coupon-claim.v1." + owner;
        let pending;
        function readPending() {
            if (role !== "customer") return pending;
            const value = JSON.parse(sessionStorage.getItem(scope) ?? "null");
            if (value === null) return undefined;
            if (typeof value.idempotency_key !== "string" ||
                !/^[a-f0-9-]{36}$/.test(value.idempotency_key) ||
                !(Number.isSafeInteger(value.coupon) && value.coupon > 0 && value.codeHash === undefined ||
                  value.coupon === undefined && /^[a-f0-9]{64}$/.test(value.codeHash))) {
                throw Error("Invalid saved coupon");
            }
            return value;
        }
        function savePending(value) {
            if (role === "customer") {
                if (value) sessionStorage.setItem(scope, JSON.stringify(value));
                else sessionStorage.removeItem(scope);
            }
            pending = value;
        }
        async function digest(value) {
            const bytes = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
            return Array.from(new Uint8Array(bytes), value => value.toString(16).padStart(2, "0")).join("");
        }
        const locale = () => document.documentElement.lang === "ar";
        const labelStatus = {
            pending: ["Pending fulfillment", "بانتظار التسليم"],
            fulfilled: ["Fulfilled", "تم التسليم"],
            rejected: ["Rejected", "مرفوض"],
        };
        function rewardName(row) {
            const name = node("span", locale() ? row.reward_name_ar : row.reward_name);
            name.dataset.en = row.reward_name;
            name.dataset.ar = row.reward_name_ar;
            return name;
        }
        const validOffer = row => Number.isSafeInteger(row?.id) && row.id > 0
            && typeof row.reward_name === "string" && typeof row.reward_name_ar === "string";
        function receiptMode(row) {
            const legacy = !Object.hasOwn(row, "activation_mode") &&
                !Object.hasOwn(row, "activation_recorded");
            const mode = legacy ? "sandbox" : row.activation_mode;
            const recorded = legacy ? false : row.activation_recorded;
            if (mode === "sandbox" && typeof recorded === "boolean" &&
                row.evidence === "TEST_PROMOTIONAL_ECONOMICS") return mode;
            if (mode === "reviewed_manual" && recorded === true &&
                row.evidence === "REVIEWED_MANUAL_NONCASH") return mode;
            throw Error("Unsupported noncash provenance");
        }
        function receiptCard(row) {
            if (!validOffer(row) || !Object.hasOwn(labelStatus, row.status)
                || row.points_cost !== 0 || row.cash_value !== false
                || row.source !== "COUPON" || row.delivery_verified !== false
                || row.fulfillment !== "MANUAL_NON_MONETARY" || typeof row.claimed_at !== "string")
                throw Error("Invalid receipt");
            const card = node("article");
            card.append(node("p", "#" + row.id), rewardName(row), t("p", ...labelStatus[row.status]));
            time(card, "Claimed", "تاريخ المطالبة", row.claimed_at);
            const mode = receiptMode(row);
            card.append(mode === "reviewed_manual"
                ? t("p", "Reviewed manual noncash record", "سجل غير نقدي يدوي مراجع")
                : t("p", "Sandbox test record", "سجل تجريبي"));
            return card;
        }
        const say = (en, ar) => feedback.replaceChildren(t("span", en, ar));
        let couponBusy = false;
        function couponButton(parent, en, ar, action, operation = "read") {
            return button(parent, en, ar, async () => {
                // Claim, cancellation and receipt recovery share a single
                // draft. Serialize all controls in this mounted workspace,
                // not only repeated clicks on an individual button.
                if (couponBusy || !alive()) return;
                couponBusy = true;
                panel.setAttribute("aria-busy", "true");
                feedback.replaceChildren();
                try {
                    await action();
                } catch (error) {
                    if (!alive() || ["AbortError", "SessionChangedError"].includes(error.name)) return;
                    if (operation === "read") {
                        records.replaceChildren();
                        say("Could not load coupons. Please retry.", "تعذر تحميل القسائم. أعد المحاولة.");
                    } else if ([404, 409, 422].includes(error.status)) {
                        if (operation === "draft" && role === "admin") savePending(undefined);
                        say("Coupon request declined. Check the code, dates and limits.",
                            "رُفض طلب القسيمة. تحقق من الرمز والتواريخ والحدود.");
                    } else {
                        say("Coupon request could not be confirmed. Check the receipt or retry the same request.",
                            "تعذر تأكيد طلب القسيمة. تحقق من الإيصال أو أعد الطلب نفسه.");
                    }
                } finally {
                    couponBusy = false;
                    if (alive()) panel.removeAttribute("aria-busy");
                }
            });
        }
        async function showClaim(result, saved) {
            const receipt = receiptCard(result?.claim);
            const row = result.claim;
            if (saved.coupon !== undefined && row.coupon_id !== saved.coupon)
                throw Error("Coupon receipt identity changed");
            if (saved.codeHash) {
                const request = await digest("coupon-claim:" + owner + ":" + saved.idempotency_key);
                if (row.request_binding !== await digest(saved.codeHash + ":" + request))
                    throw Error("Coupon receipt binding changed");
            }
            if (!alive() || readPending()?.idempotency_key !== saved.idempotency_key) return;
            savePending(undefined);
            code.value = "";
            records.replaceChildren(receipt);
        }
        async function refresh(number = 1) {
            const result = await couponRequest((role === "admin" ? "" : "/claims") + "?page=" + number);
            if (!alive()) return;
            if (!Array.isArray(result.data)) throw Error("Invalid coupon history");
            records.replaceChildren();
            for (const row of result.data) {
                if (role === "customer") {
                    records.append(receiptCard(row));
                    continue;
                }
                const card = node("article");
                card.dataset.couponId = row.id;
                card.append(node("p", "#" + row.id));
                line(card, "Total claims", "إجمالي المطالبات", row.claims + "/" + row.claim_limit);
                line(card, "Claims per member", "المطالبات لكل عضو", row.per_user_limit);
                card.append(row.listed
                    ? t("p", "Listed for eligible members", "مدرجة للأعضاء المؤهلين")
                    : t("p", "Private code", "رمز خاص"));
                time(card, "Starts", "البداية", row.starts_at);
                time(card, "Expires", "الانتهاء", row.ends_at);
                if (row.revoked_at) card.append(t("p", "Revoked", "ملغاة"));
                else {
                    const reason = field(card, "revocation_reason", "Revocation reason", "سبب الإلغاء");
                    reason.maxLength = 500;
                    couponButton(card, "Revoke coupon", "إلغاء القسيمة", async () => {
                        await couponRequest("/" + row.id + "/revoke", "POST", { reason: reason.value.trim() });
                        await refresh(number);
                    }, "revoke");
                }
                records.append(card);
            }
            if (!result.data.length) records.append(role === "admin"
                ? t("p", "No published coupons", "لا توجد قسائم منشورة")
                : t("p", "No coupon claims", "لا توجد مطالبات بالقسائم"));
            if (number > 1) couponButton(records, "Previous", "السابق", () => refresh(number - 1));
            if (number < result.last_page) couponButton(records, "Next", "التالي", () => refresh(number + 1));
        }
        if (role === "admin") {
            const listed = field(panel, "coupon_listed", "Show to eligible members", "عرض للأعضاء المؤهلين", "", "checkbox", false);
            const reward = field(panel, "coupon_reward", "Reward ID", "معرف المكافأة", "", "number");
            const cap = field(panel, "coupon_cap", "Total claims", "إجمالي المطالبات", "1", "number");
            const perUser = field(panel, "coupon_user_cap", "Claims per member", "المطالبات لكل عضو", "1", "number");
            const start = field(panel, "coupon_start", "Starts (local time)", "البداية (التوقيت المحلي)", "", "datetime-local");
            const end = field(panel, "coupon_end", "Expires (local time)", "الانتهاء (التوقيت المحلي)", "", "datetime-local");
            function publicationFields() {
                return {
                    code: code.value.trim().toUpperCase(), reward_id: Number(reward.value),
                    listed: listed.checked, claim_limit: Number(cap.value), per_user_limit: Number(perUser.value),
                    starts_at: new Date(start.value).toISOString(), ends_at: new Date(end.value).toISOString(),
                };
            }
            couponButton(panel, "Publish coupon", "نشر القسيمة", async () => {
                if (!validCode() || !reward.value || !cap.value || !perUser.value
                    || ![reward, cap, perUser].every(input => Number.isSafeInteger(Number(input.value)))
                    || Number(reward.value) < 1 || Number(cap.value) < 1 || Number(cap.value) > 1000000
                    || Number(perUser.value) < 1 || Number(perUser.value) > Math.min(Number(cap.value), 100)
                    || !start.value || !end.value || new Date(start.value) >= new Date(end.value)) {
                    say("Enter a valid code, reward ID, limits and date window.",
                        "أدخل رمزاً صالحاً ومعرف المكافأة والحدود والفترة الزمنية.");
                    return;
                }
                const values = publicationFields();
                if (pending && Object.entries(values).some(([key, value]) => pending[key] !== value)) {
                    say("Restore the original values to retry, or clear the coupon draft.",
                        "استعد القيم الأصلية لإعادة المحاولة، أو امسح مسودة القسيمة.");
                    return;
                }
                pending ??= { ...values, idempotency_key: crypto.randomUUID() };
                const result = await couponRequest("", "POST", pending);
                if (!alive()) return;
                if (!Number.isSafeInteger(result?.id)) throw Error("Invalid publication");
                pending = undefined;
                code.value = "";
                say("Coupon published", "تم نشر القسيمة");
                await refresh();
            }, "draft");
        } else {
            async function claimsEnabled() {
                const value = await api("commercial/noncash/status", "GET", undefined, controller.signal);
                if (!alive()) return false;
                if (!value || typeof value.enabled !== "boolean" ||
                    typeof value.points_actions_enabled !== "boolean" ||
                    typeof value.referrals_enabled !== "boolean" ||
                    typeof value.verified_live_source_required !== "boolean" ||
                    !["disabled", "sandbox", "reviewed_manual"].includes(value.activation_mode) ||
                    value.enabled !== (value.activation_mode !== "disabled") ||
                    value.money_movement !== false ||
                    value.fulfillment !== "MANUAL_NON_MONETARY" ||
                    value.delivery_verified !== false ||
                    (value.activation_mode !== "sandbox" && value.points_actions_enabled) ||
                    (!value.enabled && value.referrals_enabled)) {
                    throw Error("Unsupported noncash capability");
                }
                if (!value.enabled) say(
                    "New coupon claims are disabled. Existing receipts remain available.",
                    "المطالبات الجديدة بالقسائم معطلة. تبقى الإيصالات الموجودة متاحة.",
                );
                return value.enabled;
            }
            function resolveFirst() {
                say("Check the previous claim receipt or clear the coupon draft.",
                    "تحقق من إيصال المطالبة السابقة أو امسح مسودة القسيمة.");
            }
            panel.append(t("p", "Enter a code you received to claim a noncash reward.", "أدخل الرمز الذي تلقيته للمطالبة بمكافأة غير نقدية."));
            couponButton(panel, "Claim coupon", "المطالبة بالقسيمة", async () => {
                const entered = code.value.trim().toUpperCase();
                if (!validCode()) {
                    say("Enter a valid coupon code.", "أدخل رمز قسيمة صالحاً.");
                    return;
                }
                const codeHash = await digest(entered);
                if (!alive()) return;
                const previous = readPending();
                if (previous && previous.codeHash !== codeHash) return resolveFirst();
                if (!await claimsEnabled()) return;
                const saved = previous ?? { codeHash, idempotency_key: crypto.randomUUID() };
                savePending(saved);
                const result = await couponRequest("/claim", "POST", {
                    code: entered, idempotency_key: saved.idempotency_key,
                });
                if (alive()) await showClaim(result, saved);
            }, "draft");
            async function eligible(number = 1) {
                const result = await couponRequest("/eligible?page=" + number);
                if (!alive()) return;
                if (!Array.isArray(result.data) || result.data.some(row => !validOffer(row) || typeof row.ends_at !== "string"))
                    throw Error("Invalid eligible coupons");
                records.replaceChildren();
                for (const row of result.data) {
                    const card = node("article");
                    card.dataset.couponId = row.id;
                    card.append(rewardName(row));
                    time(card, "Expires", "الانتهاء", row.ends_at);
                    couponButton(card, "Claim listed reward", "المطالبة بالمكافأة المدرجة", async () => {
                        const previous = readPending();
                        if (previous && previous.coupon !== row.id) return resolveFirst();
                        if (!await claimsEnabled()) return;
                        const saved = previous ?? { coupon: row.id, idempotency_key: crypto.randomUUID() };
                        savePending(saved);
                        const reply = await couponRequest("/eligible/" + row.id + "/claim", "POST", {
                            idempotency_key: saved.idempotency_key,
                        });
                        if (alive()) await showClaim(reply, saved);
                    }, "draft");
                    records.append(card);
                }
                if (!result.data.length) records.append(t("p", "No eligible coupons", "لا توجد قسائم مؤهلة"));
                if (number > 1) couponButton(records, "Previous coupons", "القسائم السابقة", () => eligible(number - 1));
                if (number < result.last_page) couponButton(records, "Next coupons", "القسائم التالية", () => eligible(number + 1));
            }
            couponButton(panel, "Browse eligible coupons", "تصفح القسائم المؤهلة", () => eligible());
            couponButton(panel, "Check last claim receipt", "التحقق من إيصال المطالبة", async () => {
                const saved = readPending();
                if (!saved) {
                    say("Open coupon history to review recorded claims.", "افتح سجل القسائم لمراجعة المطالبات المسجلة.");
                    return;
                }
                const result = await couponRequest("/claim-receipt", "POST", { idempotency_key: saved.idempotency_key });
                if (!alive()) return;
                if (result?.claim === null) {
                    say("No receipt has been recorded for this request.", "لم يتم تسجيل إيصال لهذا الطلب.");
                    return;
                }
                await showClaim(result, saved);
            }, "receipt");
        }
        function validCode() {
            return /^[A-Z0-9-]{12,40}$/.test(code.value.trim().toUpperCase());
        }
        couponButton(panel, "Clear coupon draft", "مسح مسودة القسيمة", async () => {
            if (role === "admin") {
                savePending(undefined);
                code.value = "";
                return;
            }
            const saved = readPending();
            if (!saved) {
                code.value = "";
                return;
            }
            const same = () => alive() && readPending()?.idempotency_key === saved.idempotency_key;
            const entered = code.value.trim().toUpperCase();
            if (saved.codeHash && (!validCode() || await digest(entered) !== saved.codeHash)) {
                say("Re-enter the original code to cancel the saved claim.",
                    "أعد إدخال الرمز الأصلي لإلغاء المطالبة المحفوظة.");
                return;
            }
            if (!same()) return;
            const request = await digest("coupon-claim:" + owner + ":" + saved.idempotency_key);
            const binding = await digest(saved.coupon === undefined
                ? saved.codeHash + ":" + request
                : "coupon-cancel-v1:" + saved.coupon + ":" + request);
            if (!same()) return;
            const result = await couponRequest("/claim-intents/cancel", "POST", {
                idempotency_key: saved.idempotency_key,
                ...(saved.coupon === undefined ? { code: entered } : { coupon_id: saved.coupon }),
            });
            if (!same()) return;
            if (result?.request_binding !== binding ||
                (saved.coupon !== undefined && result.coupon_id !== saved.coupon))
                throw Error("Invalid cancellation binding");
            if (result.state === "accepted") {
                await showClaim(result, saved);
            } else if (result.state === "cancelled" && result.claim === null) {
                savePending(undefined);
                code.value = "";
                records.replaceChildren();
                say("Saved claim cancelled by the server.", "ألغى الخادم المطالبة المحفوظة.");
            } else {
                throw Error("Invalid cancellation state");
            }
        }, "receipt");
        couponButton(panel, "Refresh coupons", "تحديث القسائم", () => refresh());
        panel.append(records);
        container.append(panel);
    }
    if (role === "customer") {
        const panel = node("section"), programmes = node("div"), accountStatus = node("div"), history = node("div");
        panel.dataset.referralPanel = "true";
        const call = (path, method = "GET", data) => {
            if (!alive()) throw new DOMException("Session changed", "AbortError");
            return api("commercial/" + path, method, data, controller.signal);
        };
        const show = (parent, en, ar) => parent.append(t("p", en, ar));
        const localized = (parent, en, ar) => {
            const text = node("p", document.documentElement.lang === "ar" ? ar : en);
            text.dataset.en = en;
            text.dataset.ar = ar;
            parent.append(text);
        };
        const codePattern = /^REF-[A-F0-9]{20}$/;
        const versionPattern = /^[a-f0-9]{64}$/;
        function message(en, ar) {
            return new Error(document.documentElement.lang === "ar" ? ar : en);
        }
        async function loadReferral() {
            accountStatus.replaceChildren();
            const result = await call("referrals/account");
            if (!alive()) return;
            const referral = result.referral;
            if (referral === null) {
                show(accountStatus, "No referral recorded", "لا توجد إحالة مسجلة");
                return;
            }
            const states = {
                accepted: ["Accepted, awaiting a qualifying invoice", "مقبولة، بانتظار فاتورة مؤهلة"],
                qualified: ["Qualified", "مؤهلة"],
                expired: ["Expired", "منتهية الصلاحية"],
                withdrawn: ["Consent withdrawn", "تم سحب الموافقة"],
            };
            if (!referral || !Object.hasOwn(states, referral.status) || referral.cash_value !== false) {
                throw message("Referral status is unavailable.", "حالة الإحالة غير متاحة.");
            }
            show(accountStatus, ...states[referral.status]);
            if (referral.status === "accepted") {
                const invoice = field(accountStatus, "referral_invoice_id", "Qualifying invoice ID", "رقم الفاتورة المؤهلة");
                invoice.inputMode = "numeric";
                let request;
                button(accountStatus, "Verify invoice eligibility", "التحقق من أهلية الفاتورة", async () => {
                    const id = Number(invoice.value);
                    if (!/^[0-9]+$/.test(invoice.value) || !Number.isSafeInteger(id) || id < 1) {
                        throw message("Enter a valid invoice ID.", "أدخل رقم فاتورة صالحاً.");
                    }
                    if (request && request.invoice_id !== id) {
                        throw message("Refresh referral status before changing the invoice.", "حدّث حالة الإحالة قبل تغيير الفاتورة.");
                    }
                    request ??= { invoice_id: id, idempotency_key: crypto.randomUUID() };
                    await call("referrals/qualify", "POST", request);
                    if (alive()) await loadReferral();
                });
            }
            if (!["accepted", "expired"].includes(referral.status)) return;
            const reason = field(accountStatus, "referral_withdrawal_reason", "Withdrawal reason", "سبب سحب الموافقة");
            reason.maxLength = 500;
            button(accountStatus, "Withdraw referral consent", "سحب الموافقة على الإحالة", async () => {
                if (reason.value.trim().length < 5) {
                    throw message("Enter a withdrawal reason.", "أدخل سبب سحب الموافقة.");
                }
                await call("referrals/withdraw", "POST", { reason: reason.value.trim() });
                if (alive()) await loadReferral();
            });
        }
        async function loadProgrammes(page = 1) {
            programmes.replaceChildren();
            const result = await call("referral-programs?page=" + page);
            if (!alive()) return;
            for (const programme of result.data) {
                const card = node("article");
                localized(card, programme.name, programme.name_ar);
                localized(card, programme.terms, programme.terms_ar);
                if (programme.active && Number.isSafeInteger(programme.id) && versionPattern.test(programme.terms_version)) {
                    const detail = node("div");
                    button(card, "Open referral programme", "فتح برنامج الإحالة", async () => {
                        detail.replaceChildren();
                        const account = await call("referrals/account?program_id=" + programme.id);
                        if (alive() && account.program_id === programme.id) renderAccount(detail, programme, account);
                    });
                    card.append(detail);
                }
                programmes.append(card);
            }
            if (!result.data.length) show(programmes, "No referral programmes", "لا توجد برامج إحالة");
            if (page > 1) button(programmes, "Previous programmes", "البرامج السابقة", () => loadProgrammes(page - 1));
            if (page < result.last_page) button(programmes, "Next programmes", "البرامج التالية", () => loadProgrammes(page + 1));
        }
        function renderAccount(parent, programme, account) {
            parent.replaceChildren();
            const reload = async () => {
                const current = await call("referrals/account?program_id=" + programme.id);
                if (alive() && current.program_id === programme.id) renderAccount(parent, programme, current);
            };
            button(parent, "Refresh referral account", "تحديث حساب الإحالة", reload);
            if (codePattern.test(account.own_code?.code || "")) {
                parent.append(t("p", "Your referral code", "رمز الإحالة الخاص بك"), node("code", account.own_code.code));
            } else if (account.software_activation === true) {
                let codeKey;
                button(parent, "Create referral code", "إنشاء رمز إحالة", async () => {
                    codeKey ??= crypto.randomUUID();
                    await call("referrals/codes", "POST", { program_id: programme.id, idempotency_key: codeKey });
                    if (alive()) await reload();
                });
            }
            if (account.referral) {
                show(parent, "A referral is recorded for this account.", "توجد إحالة مسجلة لهذا الحساب.");
            }
            if (account.can_accept !== true || account.software_activation !== true) return;
            const code = field(parent, "referral_code", "Referral code", "رمز الإحالة");
            code.maxLength = 24;
            code.autocomplete = "off";
            const consent = field(parent, "referral_consent", "I accept these programme terms", "أوافق على شروط هذا البرنامج", "", "checkbox");
            let pending;
            button(parent, "Accept referral", "قبول الإحالة", async () => {
                const entered = code.value.trim().toUpperCase();
                if (!consent.checked || !codePattern.test(entered)) {
                    throw message("Enter a valid referral code and accept the terms.", "أدخل رمز إحالة صالحاً ووافق على الشروط.");
                }
                if (pending && pending.code !== entered) {
                    throw message("Refresh the referral account before changing the request.", "حدّث حساب الإحالة قبل تعديل الطلب.");
                }
                pending ??= { code: entered, terms_version: programme.terms_version, consent: true, idempotency_key: crypto.randomUUID() };
                await call("referrals/accept", "POST", pending);
                if (alive()) {
                    pending = undefined;
                    code.value = "";
                    await reload();
                }
            });
        }
        async function loadRewards(page = 1) {
            history.replaceChildren();
            const result = await call("referrals/rewards?page=" + page);
            if (!alive()) return;
            const statuses = {
                pending: ["Pending fulfillment", "بانتظار التسليم"],
                fulfilled: ["Fulfilled", "تم التسليم"],
                rejected: ["Rejected", "مرفوض"],
            };
            for (const row of result.data) {
                if (row.source !== "REFERRAL" || row.cash_value !== false || row.points_cost !== 0 || !Object.hasOwn(statuses, row.status)) continue;
                const card = node("article");
                localized(card, row.reward_name, row.reward_name_ar);
                show(card, ...statuses[row.status]);
                history.append(card);
            }
            if (!result.data.length) show(history, "No referral rewards", "لا توجد مكافآت إحالة");
            if (page > 1) button(history, "Previous referral rewards", "مكافآت الإحالة السابقة", () => loadRewards(page - 1));
            if (page < result.last_page) button(history, "Next referral rewards", "مكافآت الإحالة التالية", () => loadRewards(page + 1));
        }
        panel.append(t("h4", "Referrals", "الإحالات"));
        button(panel, "Browse referral programmes", "تصفح برامج الإحالة", () => loadProgrammes());
        button(panel, "Check my referral status", "التحقق من حالة إحالتي", loadReferral);
        button(panel, "View referral rewards", "عرض مكافآت الإحالة", () => loadRewards());
        panel.append(programmes, accountStatus, history);
        container.append(panel);
    }
    if (role === "customer") {
        const panel = node("section"), rows = node("div");
        panel.dataset.partnerRewardPanel = "true";
        const storageKey = `creabiz.partner-reward.v1.${owner}`;
        let pending;
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(storageKey));
            if (Number.isSafeInteger(stored?.review) && stored.review > 0 &&
                typeof stored.key === "string" && /^[0-9a-f-]{36}$/.test(stored.key)) pending = stored;
        } catch {}
        const evidence = (row) => row?.source === "RETAIL_PARTNER_REWARD" &&
            row.evidence === "SANDBOX_MANUAL_NONCASH" && row.external_fulfillment_verified === false &&
            row.cash_value === false && row.points_cost === 0;
        const states = {
            pending: ["Pending manual fulfillment", "بانتظار التسليم اليدوي"],
            fulfilled: ["Marked fulfilled in sandbox", "مسجلة كمسلّمة تجريبياً"],
            rejected: ["Rejected", "مرفوضة"],
        };
        const label = (parent, en, ar) => parent.append(t("p", en, ar));
        function localText(parent, en, ar) {
            const line = node("p", document.documentElement.lang === "ar" ? ar : en);
            line.dataset.en = en;
            line.dataset.ar = ar;
            parent.append(line);
        }
        function receipt(row) {
            if (!evidence(row) || !Number.isSafeInteger(row.id) || row.id < 1 ||
                !Number.isSafeInteger(row.review_id) || row.review_id < 1 ||
                !Number.isSafeInteger(row.redemption_id) || row.redemption_id < 1 ||
                !validLoyaltyClock(row.created_at) || !Object.hasOwn(states, row.status)) {
                throw new Error("Invalid partner reward receipt");
            }
            const card = node("article");
            card.append(node("p", "#" + row.id));
            label(card, ...states[row.status]);
            time(card, "Claim recorded at", "وقت تسجيل المطالبة", row.created_at);
            return card;
        }
        function partnerRequest(path, method = "GET", data) {
            if (!alive()) throw new DOMException("Session changed", "AbortError");
            return api("commercial/partner-rewards/" + path, method, data, controller.signal);
        }
        function keep(intent) {
            window.sessionStorage.setItem(storageKey, JSON.stringify(intent));
            pending = intent;
            retry.hidden = false;
            lookup.hidden = false;
            cancel.hidden = false;
        }
        function clear() {
            window.sessionStorage.removeItem(storageKey);
            pending = undefined;
            retry.hidden = true;
            lookup.hidden = true;
            cancel.hidden = true;
        }
        async function recover() {
            if (!pending) return;
            const intent = pending;
            const result = await partnerRequest("claim-receipt", "POST", { idempotency_key: intent.key });
            if (!alive() || pending !== intent) return;
            rows.replaceChildren();
            if (result.claim === null) {
                label(rows, "No partner reward receipt was found for this request.", "لم يُعثر على إيصال مكافأة الشريك لهذا الطلب.");
                return;
            }
            const card = receipt(result.claim);
            if (result.claim.review_id !== intent.review) throw new Error("Unexpected partner reward receipt");
            clear();
            rows.append(card);
        }
        async function claim(review) {
            if (pending && pending.review !== review) {
                label(rows, "Retry the previous partner reward claim first.", "أعد محاولة المطالبة السابقة بمكافأة الشريك أولاً.");
                return;
            }
            if (!pending) {
                try {
                    keep({ review, key: crypto.randomUUID() });
                } catch {
                    label(rows, "Claim recovery is unavailable in this browser session.", "يتعذر استرداد المطالبة في جلسة المتصفح هذه.");
                    return;
                }
            }
            const intent = pending;
            rows.replaceChildren();
            let result;
            try {
                result = await partnerRequest(`offers/${review}/claim`, "POST", { idempotency_key: intent.key });
            } catch (error) {
                if (!alive() || pending !== intent) return;
                if ([404, 409, 422].includes(error.status)) {
                    label(rows, "This offer could not be claimed. Check the saved receipt or cancel the same request before starting another.", "تعذرت المطالبة بهذا العرض. تحقق من الإيصال المحفوظ أو ألغ الطلب نفسه قبل بدء طلب آخر.");
                    return;
                }
                if ([401, 419].includes(error.status)) throw error;
                label(rows, "Claim could not be confirmed. Retry the same request or view claim history.", "تعذر تأكيد المطالبة. أعد محاولة الطلب نفسه أو اعرض سجل المطالبات.");
                return;
            }
            if (!alive() || pending !== intent) return;
            const card = receipt(result.claim);
            if (result.claim.review_id !== review) throw new Error("Unexpected partner reward receipt");
            clear();
            rows.replaceChildren(card);
        }
        async function cancelSaved() {
            if (!pending) return;
            const intent = pending;
            if (!window.confirm(document.documentElement.lang === "ar"
                ? "إلغاء الطلب المحفوظ؟ إذا وُجدت مطالبة فسيُحفظ إيصالها."
                : "Cancel the saved request? If a claim already exists, its receipt will be preserved.")) return;
            const hash = async (value) => Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value))), byte => byte.toString(16).padStart(2, "0")).join("");
            try {
                const request = await hash(`partner-reward-claim:${owner}:${intent.key}`);
                const binding = await hash(`partner-reward-cancel-v1:${intent.review}:${request}`);
                if (!alive() || pending !== intent) return;
                const result = await partnerRequest("claim-intents/cancel", "POST", {
                    review_id: intent.review, idempotency_key: intent.key,
                });
                if (!alive() || pending !== intent) return;
                if (result?.review_id !== intent.review || result.request_binding !== binding) throw new Error("Invalid cancellation acknowledgement");
                if (result.state === "accepted" && result.claim?.review_id === intent.review) {
                    const card = receipt(result.claim);
                    clear();
                    rows.replaceChildren(card);
                } else if (result.state === "cancelled" && result.claim === null) {
                    clear();
                    rows.replaceChildren();
                    label(rows, "Saved request cancelled by the server.", "ألغى الخادم الطلب المحفوظ.");
                } else {
                    throw new Error("Invalid cancellation state");
                }
            } catch (error) {
                if (!alive() || pending !== intent) return;
                if ([401, 419].includes(error.status)) throw error;
                label(rows, "Cancellation not confirmed. Keep the same saved request.", "لم يتأكد الإلغاء. احتفظ بالطلب المحفوظ نفسه.");
            }
        }
        async function load(kind, page = 1) {
            rows.replaceChildren();
            let result;
            try {
                result = await partnerRequest(kind + "?page=" + page);
            } catch (error) {
                if (alive() && kind === "offers" && error.status === 503) {
                    label(rows, "New partner reward claims are disabled. Existing claims remain available.", "مطالبات مكافآت الشركاء الجديدة معطّلة. تظل المطالبات الحالية متاحة.");
                    return;
                }
                throw error;
            }
            if (!alive()) return;
            // Validate the entire page before creating any actionable row.
            verifyPartnerRewardPage(result, page, kind);
            if (!result.data.length) {
                if (kind === "offers") label(rows, "No eligible partner rewards", "لا توجد مكافآت شركاء مؤهلة");
                else label(rows, "No partner reward claims", "لا توجد مطالبات بمكافآت الشركاء");
            }
            for (const item of result.data) {
                if (kind === "claims") {
                    rows.append(receipt(item));
                    continue;
                }
                if (!evidence(item) || !Number.isSafeInteger(item.review_id) || item.review_id < 1 ||
                    !["name", "name_ar", "partner_name", "partner_name_ar", "description", "description_ar"]
                        .every((field) => typeof item[field] === "string")) throw new Error("Invalid partner reward offer");
                const card = node("article");
                localText(card, item.name, item.name_ar);
                localText(card, item.partner_name, item.partner_name_ar);
                localText(card, item.description, item.description_ar);
                time(card, "Offer expires", "انتهاء العرض", item.ends_at);
                button(card, "Claim partner reward", "المطالبة بمكافأة الشريك", () => claim(item.review_id));
                rows.append(card);
            }
            if (page > 1) button(rows, "Previous partner rewards", "مكافآت الشركاء السابقة", () => load(kind, page - 1));
            if (page < result.last_page && page < 100000) button(rows, "Next partner rewards", "مكافآت الشركاء التالية", () => load(kind, page + 1));
        }
        panel.append(t("h4", "Partner rewards", "مكافآت الشركاء"));
        label(panel, "Sandbox rewards require manual fulfillment. External partner delivery is unverified.", "تتطلب المكافآت التجريبية تسليماً يدوياً. لم يتم التحقق من تسليم الشريك الخارجي.");
        button(panel, "Browse partner rewards", "تصفح مكافآت الشركاء", () => load("offers"));
        button(panel, "View partner reward claims", "عرض مطالبات مكافآت الشركاء", () => load("claims"));
        const retry = button(panel, "Retry partner reward claim", "إعادة محاولة المطالبة بمكافأة الشريك", () => pending && claim(pending.review));
        const lookup = button(panel, "Check partner reward receipt", "التحقق من إيصال مكافأة الشريك", recover);
        const cancel = button(panel, "Cancel saved partner reward claim", "إلغاء مطالبة مكافأة الشريك المحفوظة", cancelSaved);
        retry.hidden = !pending;
        lookup.hidden = !pending;
        cancel.hidden = !pending;
        panel.append(rows);
        container.append(panel);
    }
    container.append(content);
};
