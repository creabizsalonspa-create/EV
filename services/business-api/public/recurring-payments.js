/* Provider-owned card entry only. This application never accepts card credentials. */
window.mountRecurringPayments = ({ container, api, ui, actor }) => {
    container.dataset.recurringPayments = "true";
    const identity = actor()?.id;
    let generation = 0,
        busy = false,
        enrollmentKey = null;
    const alive = () => container.isConnected && actor()?.id === identity;
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
    container.append(
        t("h3", "Saved payment authorizations", "تفويضات الدفع المحفوظة"),
        t(
            "p",
            "Test provider only. Card entry takes place on the provider website. Revoking consent stops future collection; uncertain payments still require reconciliation.",
            "مزود اختبار فقط. تُدخل بيانات البطاقة على موقع المزود. إلغاء التفويض يوقف التحصيل المستقبلي؛ تبقى الدفعات غير المؤكدة بحاجة للمطابقة.",
        ),
        status,
        content,
    );
    function button(parent, en, ar, fn) {
        const b = t("button", en, ar);
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            if (busy) return;
            busy = true;
            for (const n of container.querySelectorAll("button"))
                n.disabled = true;
            try {
                await fn();
            } catch (e) {
                if (alive()) status.textContent = e.message;
            } finally {
                busy = false;
                if (alive())
                    for (const n of container.querySelectorAll("button"))
                        n.disabled = false;
            }
        };
        return b;
    }
    function link(parent, url) {
        if (!url) return;
        const u = new URL(url);
        if (
            u.protocol !== "https:" ||
            !["checkout.stripe.com", "hooks.stripe.com"].includes(u.hostname) ||
            u.username ||
            u.password ||
            u.port
        )
            throw Error("Invalid hosted provider URL");
        const a = t(
            "a",
            "Continue securely with provider",
            "المتابعة الآمنة مع المزود",
        );
        a.href = u.href;
        a.target = "_blank";
        a.rel = "noopener noreferrer";
        a.referrerPolicy = "no-referrer";
        parent.append(a);
    }
    function check(parent, en, ar) {
        const label = t("label", en, ar),
            input = document.createElement("input");
        input.type = "checkbox";
        label.prepend(input);
        parent.append(label);
        return input;
    }
    async function load(kind = "methods", page = 1) {
        const epoch = ++generation;
        status.replaceChildren(t("span", "Loading…", "جار التحميل…"));
        content.replaceChildren();
        const endpoint = {
            methods: "payment-methods",
            enrollments: "payment-enrollments",
            subscriptions: "recurring-subscriptions",
            events: "recurring-events",
        }[kind];
        const result = await api("commercial/" + endpoint + "?page=" + page);
        if (!alive() || epoch !== generation) return;
        status.textContent = "";
        const rows = kind === "methods" ? result.methods : result;
        const nav = document.createElement("nav");
        content.append(nav);
        for (const [k, en, ar] of [
            ["methods", "Payment methods", "وسائل الدفع"],
            ["enrollments", "Enrollments", "التسجيلات"],
            ["subscriptions", "Renewal collection", "تحصيل التجديد"],
            ["events", "Consent history", "سجل التفويض"],
        ])
            button(nav, en, ar, () => load(k));
        if (kind === "methods") {
            if (result.hosted_available) {
                const consent = check(
                    content,
                    "I consent to saving this provider payment authorization for future subscription collection.",
                    "أوافق على حفظ تفويض الدفع لدى المزود لتحصيل الاشتراكات مستقبلاً.",
                );
                button(
                    content,
                    "Add with hosted provider",
                    "إضافة عبر موقع المزود",
                    async () => {
                        if (!consent.checked) {
                            status.replaceChildren(
                                t(
                                    "span",
                                    "Consent is required",
                                    "الموافقة مطلوبة",
                                ),
                            );
                            return;
                        }
                        enrollmentKey ||= crypto.randomUUID();
                        const r = await api(
                            "commercial/payment-enrollments",
                            "POST",
                            {
                                consent: true,
                                consent_version: result.consent_version,
                                idempotency_key: enrollmentKey,
                            },
                        );
                        if (!alive()) return;
                        enrollmentKey = null;
                        await load("enrollments");
                        if (alive()) link(content, r.hosted_url);
                    },
                );
            } else
                content.append(
                    t(
                        "p",
                        "Hosted provider enrollment is not configured. Existing consent can still be revoked.",
                        "تسجيل المزود غير مهيأ. يمكنك إلغاء التفويض المحفوظ.",
                    ),
                );
        }
        if (!rows.data.length)
            content.append(t("p", "No records", "لا توجد سجلات"));
        for (const row of rows.data) {
            const card = document.createElement("article");
            card.className = "finance-row";
            content.append(card);
            if (kind === "methods") {
                card.append(
                    literal("h4", row.brand + " •••• " + row.last_four),
                    literal("p", row.exp_month + "/" + row.exp_year),
                    literal("p", row.status),
                    t("span", "Consent expires", "انتهاء التفويض"),
                    literal("p", row.consent_expires_at),
                );
                if (row.id === result.default_method_id)
                    card.append(
                        t("strong", "Default method", "وسيلة افتراضية"),
                    );
                if (row.status === "active") {
                    button(card, "Set default", "تعيين افتراضية", async () => {
                        await api(
                            "commercial/payment-methods/" + row.id + "/default",
                            "POST",
                            { expected_version: result.version },
                        );
                        await load();
                    });
                    button(
                        card,
                        "Revoke authorization",
                        "إلغاء التفويض",
                        async () => {
                            await api(
                                "commercial/payment-methods/" + row.id,
                                "DELETE",
                                { expected_version: row.version },
                            );
                            await load();
                        },
                    );
                } else if (row.detach_status !== "detached")
                    button(
                        card,
                        "Remove from provider",
                        "إزالة من المزود",
                        async () => {
                            await api(
                                "commercial/payment-methods/" +
                                    row.id +
                                    "/detach",
                                "POST",
                                {},
                            );
                            await load();
                        },
                    );
            } else if (kind === "enrollments") {
                card.append(
                    literal("h4", "#" + row.id),
                    literal("p", row.status),
                    literal("p", row.expires_at),
                );
                button(
                    card,
                    "Inspect hosted enrollment",
                    "فحص التسجيل لدى المزود",
                    async () => {
                        const r = await api(
                            "commercial/payment-enrollments/" +
                                row.id +
                                "/inspect",
                            "POST",
                            {},
                        );
                        if (!alive()) return;
                        await load(kind, page);
                        if (alive()) link(content, r.hosted_url);
                    },
                );
            } else if (kind === "subscriptions") {
                const p = row.collection_policy,
                    j = row.latest_collection;
                card.append(
                    literal("h4", "#" + row.id),
                    literal("p", row.status),
                    t(
                        "p",
                        p?.enabled
                            ? "Automatic collection enabled"
                            : "Automatic collection disabled",
                        p?.enabled
                            ? "التحصيل التلقائي مفعّل"
                            : "التحصيل التلقائي معطّل",
                    ),
                );
                if (j)
                    card.append(
                        literal("p", j.state),
                        literal("p", j.failure_code),
                        literal("p", j.next_attempt_at),
                    );
                button(
                    card,
                    "Invoice and payment history",
                    "سجل الفواتير والمدفوعات",
                    async () => {
                        const r = await api(
                            "commercial/subscriptions/" + row.id,
                        );
                        if (!alive()) return;
                        const history = document.createElement("div");
                        card.append(history);
                        for (const i of r.invoices) {
                            history.append(
                                literal("h5", i.number),
                                literal(
                                    "p",
                                    i.currency +
                                        " " +
                                        EvMoney.format(
                                            Number(i.total_minor),
                                            Number(i.minor_units),
                                        ),
                                ),
                                t(
                                    "p",
                                    i.paid ? "Paid" : "Unpaid",
                                    i.paid ? "مدفوعة" : "غير مدفوعة",
                                ),
                            );
                            for (const receipt of r.receipts.filter(
                                (x) => x.invoice_id === i.id,
                            ))
                                history.append(
                                    t("span", "Payment receipt", "إيصال دفع"),
                                    literal(
                                        "p",
                                        i.currency +
                                            " " +
                                            EvMoney.format(
                                                Number(receipt.amount_minor),
                                                Number(i.minor_units),
                                            ),
                                    ),
                                    literal("p", receipt.evidence),
                                );
                            for (const credit of r.credits.filter(
                                (x) => x.invoice_id === i.id,
                            ))
                                history.append(
                                    t("span", "Credit note", "إشعار دائن"),
                                    literal(
                                        "p",
                                        i.currency +
                                            " " +
                                            EvMoney.format(
                                                Number(credit.amount_minor),
                                                Number(i.minor_units),
                                            ),
                                    ),
                                );
                        }
                    },
                );
                if (p?.enabled)
                    button(
                        card,
                        "Disable automatic collection",
                        "تعطيل التحصيل التلقائي",
                        async () => {
                            await api(
                                "commercial/subscriptions/" +
                                    row.id +
                                    "/collection-policy",
                                "PUT",
                                { enabled: false, expected_version: p.version },
                            );
                            await load(kind, page);
                        },
                    );
                else if (row.status !== "cancelled")
                    button(
                        card,
                        "Choose verified method",
                        "اختيار وسيلة متحققة",
                        async () => {
                            const methods = await api(
                                "commercial/payment-methods",
                            );
                            if (!alive()) return;
                            const select = document.createElement("select");
                            select.setAttribute(
                                "aria-label",
                                window.EV_LOCALE === "ar"
                                    ? "وسيلة الدفع"
                                    : "Payment method",
                            );
                            for (const m of methods.methods.data.filter(
                                (m) => m.status === "active",
                            )) {
                                const o = document.createElement("option");
                                o.value = m.id;
                                o.textContent =
                                    m.brand + " •••• " + m.last_four;
                                select.append(o);
                            }
                            card.append(select);
                            const consent = check(
                                card,
                                "I authorize recurring collection for this subscription.",
                                "أفوض التحصيل المتكرر لهذا الاشتراك.",
                            );
                            const enable = button(
                                card,
                                "Confirm recurring consent",
                                "تأكيد التفويض المتكرر",
                                async () => {
                                    if (!consent.checked || !select.value)
                                        return;
                                    await api(
                                        "commercial/subscriptions/" +
                                            row.id +
                                            "/collection-policy",
                                        "PUT",
                                        {
                                            enabled: true,
                                            mandate_id: Number(select.value),
                                            consent: true,
                                            expected_version: p?.version || 0,
                                        },
                                    );
                                    await load(kind, page);
                                },
                            );
                            enable.disabled = !methods.collection_available;
                        },
                    );
                if (j?.state === "action_required")
                    button(
                        card,
                        "Authenticate existing payment",
                        "توثيق الدفعة الحالية",
                        async () => {
                            const r = await api(
                                "commercial/subscription-checkouts/" +
                                    j.checkout_id +
                                    "/authentication",
                                "POST",
                                {},
                            );
                            if (alive()) {
                                link(card, r.hosted_url);
                                if (!r.hosted_action_supported)
                                    card.append(
                                        t(
                                            "p",
                                            "No hosted action is available. Refresh or contact support; do not start another payment.",
                                            "لا يتوفر إجراء لدى المزود. حدّث الحالة أو اتصل بالدعم؛ لا تبدأ دفعة أخرى.",
                                        ),
                                    );
                            }
                        },
                    );
            } else
                card.append(
                    literal("p", row.subject),
                    literal("p", row.kind),
                    literal("time", row.created_at),
                );
        }
        if (rows.current_page > 1)
            button(content, "Previous", "السابق", () => load(kind, page - 1));
        if (rows.current_page < rows.last_page)
            button(content, "Next", "التالي", () => load(kind, page + 1));
        button(content, "Refresh status", "تحديث الحالة", () =>
            load(kind, page),
        );
    }
    button(
        container,
        "Open saved authorizations",
        "فتح التفويضات المحفوظة",
        () => load(),
    );
};
