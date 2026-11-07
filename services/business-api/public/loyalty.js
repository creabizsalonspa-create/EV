/* Non-monetary promotional points. No wallet, money transfer or tariff modification. */
window.mountLoyalty = ({ container, api, ui, actor }) => {
    const owner = actor()?.id,
        role = actor()?.role,
        controller = new AbortController();
    let busy = false,
        generation = 0,
        section = "account",
        page = 1;
    const alive = () => container.isConnected && actor()?.id === owner;
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
        if (!value) return;
        const p = node("p"),
            date = new Date(
                typeof value === "string" &&
                    /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(
                        value,
                    )
                    ? value.replace(" ", "T") + "Z"
                    : value,
            ),
            n = node("time");
        if (Number.isNaN(date.getTime())) n.textContent = value;
        else {
            n.dateTime = date.toISOString();
            n.dataset.loyaltyDate = "true";
            n.textContent = date.toLocaleString(
                document.documentElement.lang === "ar" ? "ar" : "en",
            );
        }
        p.append(t("span", en, ar), node("span", " · "), n);
        parent.append(p);
    }
    window.addEventListener(
        "ev:locale",
        () => {
            if (alive())
                for (const n of container.querySelectorAll(
                    "time[data-loyalty-date]",
                ))
                    n.textContent = new Date(n.dateTime).toLocaleString(
                        document.documentElement.lang === "ar" ? "ar" : "en",
                    );
        },
        { signal: controller.signal },
    );
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
        const result = await request("redemptions/" + id);
        if (!alive() || !parent.isConnected) return;
        parent.querySelector("[data-loyalty-history]")?.remove();
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
        status.replaceChildren(
            t("span", "Loading loyalty records…", "جار تحميل سجلات الولاء…"),
        );
        const result = await request(
            kind === "account" ? "account" : kind + "?page=" + requestedPage,
        );
        if (!alive() || epoch !== generation) return;
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
            content,
            result,
        );
        if (kind !== "account") pager(content, result, kind);
        status.textContent = "";
    }
    button(container, "Open loyalty & rewards", "فتح الولاء والمكافآت", () =>
        load(),
    );
    container.append(content);
};
