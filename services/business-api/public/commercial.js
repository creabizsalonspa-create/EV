/* Financial workspace shares the existing session client; all text is rendered literally. */
window.createCommercialWorkspace = ({ root, api: clientApi, ui }) => {
    let cache = new Map(),
        pages = {},
        reportQuery = {},
        actor = null,
        epoch = 0,
        controller = null;
    async function api(path, method = "GET", data, signal) {
        const result = await clientApi(path, method, data, signal);
        if (method !== "GET") {
            const paths = [];
            if (path.startsWith("commercial/checkouts")) {
                paths.push("commercial/checkouts");
                if (/\/(capture|refund)$/.test(path))
                    paths.push("finance/report", "finance/invoices");
            } else if (path.startsWith("commercial/plans"))
                paths.push("commercial/plans");
            else if (path.startsWith("commercial/subscriptions"))
                paths.push("commercial/subscriptions");
            else if (path.includes("refund-requests")) {
                paths.push("finance/refund-requests");
                if (path.endsWith("/review"))
                    paths.push("finance/report", "finance/invoices");
            } else if (path.startsWith("commercial/cases"))
                paths.push(
                    "commercial/cases",
                    "finance/report",
                    "finance/invoices",
                );
            for (const key of cache.keys())
                if (paths.some((p) => key.startsWith(p + "?")))
                    cache.delete(key);
        }
        return result;
    }
    const text = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    };
    const translated = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    function clear() {
        epoch++;
        controller?.abort();
        root.replaceChildren();
        root.hidden = true;
        actor = null;
        pages = {};
        cache = new Map();
        reportQuery = {};
    }
    function money(amount, currency, digits = 2) {
        return `${currency} ${amount < 0 ? "−" : ""}${EvMoney.format(Math.abs(Number(amount)), Number(digits))}`;
    }
    function panel(en, ar) {
        const p = document.createElement("article");
        p.className = "finance-panel";
        p.append(translated("h3", en, ar));
        root.append(p);
        return p;
    }
    function button(parent, en, ar, action) {
        const b = translated("button", en, ar);
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            b.disabled = true;
            try {
                await action();
            } catch (e) {
                if (e.name !== "SessionChangedError" && e.name !== "AbortError")
                    status(e.message);
            } finally {
                b.disabled = false;
            }
        };
        return b;
    }
    function status(value) {
        const n = root.querySelector("[data-commercial-status]");
        if (n) n.textContent = value;
    }
    function form(parent, fields, en, ar, action) {
        const f = document.createElement("form");
        f.className = "finance-form";
        for (const field of fields) {
            const l = translated("label", field.en, field.ar),
                input = document.createElement(
                    field.options ? "select" : "input",
                );
            input.name = field.name;
            input.required = field.required !== false;
            if (field.options)
                for (const [value, label] of field.options) {
                    const o = text("option", label);
                    o.value = value;
                    input.append(o);
                }
            else {
                input.type = field.type || "text";
                if (field.min !== undefined) input.min = field.min;
                input.maxLength = field.maxLength || 100;
            }
            if (field.value !== undefined) input.value = field.value;
            l.append(input);
            f.append(l);
        }
        const submit = translated("button", en, ar);
        submit.type = "submit";
        f.append(submit);
        // Retain the request key across uncertain HTTP retries; editing payload rotates it.
        let key = crypto.randomUUID();
        f.addEventListener("input", () => (key = crypto.randomUUID()));
        f.onsubmit = async (event) => {
            event.preventDefault();
            submit.disabled = true;
            const current = epoch;
            try {
                await action(Object.fromEntries(new FormData(f)), key);
                if (current === epoch) status("");
            } catch (e) {
                if (
                    current === epoch &&
                    !["SessionChangedError", "AbortError"].includes(e.name)
                )
                    status(e.message);
            } finally {
                submit.disabled = false;
            }
        };
        parent.append(f);
        return f;
    }
    const field = (name, en, ar, value, type = "text", required = true) => ({
        name,
        en,
        ar,
        value,
        type,
        required,
    });
    async function load() {
        const current = ++epoch;
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal;
        root.replaceChildren();
        root.hidden = !actor;
        if (!actor) return;
        root.append(
            translated("h2", "Finance & commercial", "المالية والأعمال"),
        );
        const note = translated(
            "p",
            "Test payments only · no real money moves",
            "مدفوعات اختبار فقط · لا يتم تحويل أموال حقيقية",
        );
        note.className = "badge";
        root.append(note);
        const state = text("p", "");
        state.dataset.commercialStatus = "";
        state.setAttribute("role", "status");
        root.append(state);
        button(root, "Refresh finance", "تحديث المالية", async () => {
            cache.clear();
            await load();
        });
        const report = panel("Financial overview", "نظرة مالية"),
            invoices = panel(
                "Payments & refund requests",
                "المدفوعات وطلبات الاسترداد",
            ),
            subscriptions = panel("Plans & subscriptions", "الخطط والاشتراكات");
        const operations = ["admin", "vendor"].includes(actor.role)
            ? panel("Earnings & settlements", "الأرباح والتسويات")
            : null;
        const activeCache = cache;
        const call = async (path) => {
            const key =
                path +
                "?" +
                new URLSearchParams(
                    path === "finance/report"
                        ? reportQuery
                        : { page: pages[path] || 1 },
                );
            if (activeCache.has(key)) return activeCache.get(key);
            const result = await api(key, "GET", undefined, signal);
            activeCache.set(key, result);
            return result;
        };
        const results = await Promise.allSettled([
            call("finance/report"),
            call("finance/invoices"),
            call("finance/refund-requests"),
            call("commercial/plans"),
            call("commercial/subscriptions"),
            call("commercial/checkouts"),
            call("commercial/cases"),
        ]);
        if (current !== epoch || signal.aborted) return;
        function result(index, target) {
            const r = results[index];
            if (r.status === "fulfilled") {
                const paths = [
                    "finance/report",
                    "finance/invoices",
                    "finance/refund-requests",
                    "commercial/plans",
                    "commercial/subscriptions",
                    "commercial/checkouts",
                    "commercial/cases",
                ];
                const data = r.value,
                    path = paths[index];
                if (data.last_page > 1) {
                    const pager = document.createElement("div");
                    pager.className = "finance-pagination";
                    pager.append(
                        text(
                            "p",
                            `${path.split("/").pop()} · ${data.current_page} / ${data.last_page}`,
                        ),
                    );
                    if (data.current_page > 1)
                        button(pager, "Previous", "السابق", async () => {
                            pages[path] = data.current_page - 1;
                            await load();
                        });
                    if (data.current_page < data.last_page)
                        button(pager, "Next", "التالي", async () => {
                            pages[path] = data.current_page + 1;
                            await load();
                        });
                    target.append(pager);
                }
                return data;
            }
            target.append(text("p", r.reason.message));
            return null;
        }
        form(
            report,
            [
                field(
                    "currency",
                    "Currency",
                    "العملة",
                    reportQuery.currency,
                    "text",
                    false,
                ),
                field(
                    "from",
                    "From UTC date",
                    "من تاريخ بتوقيت UTC",
                    reportQuery.from,
                    "date",
                    false,
                ),
                field(
                    "to",
                    "Through UTC date",
                    "إلى تاريخ بتوقيت UTC",
                    reportQuery.to,
                    "date",
                    false,
                ),
            ],
            "Filter report",
            "تصفية التقرير",
            async (data) => {
                reportQuery = Object.fromEntries(
                    Object.entries(data).filter(([, v]) => v),
                );
                await load();
            },
        );
        const summary = result(0, report);
        if (summary)
            button(
                report,
                "Download report CSV",
                "تنزيل تقرير CSV",
                async () => {
                    const keys = [
                        "currency",
                        "minor_units",
                        "invoice_count",
                        "invoiced_minor",
                        "paid_minor",
                        "refunded_minor",
                        "outstanding_minor",
                        "net_collected_minor",
                    ];
                    const csv = [
                        keys.join(","),
                        ...summary.groups.map((row) =>
                            keys.map((key) => String(row[key])).join(","),
                        ),
                    ].join("\r\n");
                    const url = URL.createObjectURL(
                        new Blob([csv], { type: "text/csv;charset=utf-8" }),
                    );
                    const a = document.createElement("a");
                    a.href = url;
                    a.download = "creabiz-test-finance.csv";
                    a.click();
                    URL.revokeObjectURL(url);
                },
            );
        const grid = document.createElement("div");
        grid.className = "finance-metrics";
        report.append(grid);
        for (const group of summary?.groups || []) {
            const card = document.createElement("div");
            card.className = "finance-metric";
            card.append(
                text(
                    "strong",
                    money(
                        group.net_collected_minor,
                        group.currency,
                        group.minor_units,
                    ),
                ),
                translated(
                    "span",
                    "Net collected after refunds",
                    "صافي التحصيل بعد الاسترداد",
                ),
            );
            card.append(
                text(
                    "p",
                    `${group.invoice_count} · ${money(group.outstanding_minor, group.currency, group.minor_units)}`,
                ),
                translated(
                    "small",
                    "Invoices · outstanding",
                    "الفواتير · المستحق",
                ),
            );
            grid.append(card);
        }
        if (!summary?.groups?.length)
            report.append(
                translated(
                    "p",
                    "No posted invoices yet",
                    "لا توجد فواتير مسجلة بعد",
                ),
            );
        const invoiceRows = result(1, invoices)?.data || [],
            requests = result(2, invoices)?.data || [],
            checkouts = result(5, invoices)?.data || [],
            cases = result(6, invoices)?.data || [];
        for (const invoice of invoiceRows) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text("h4", invoice.number),
                text(
                    "p",
                    money(
                        invoice.total_minor,
                        invoice.currency,
                        invoice.minor_units,
                    ),
                ),
            );
            if (operations)
                button(
                    row,
                    "Reconcile payment records",
                    "مطابقة سجلات الدفع",
                    async () => {
                        const d = await api(
                            `commercial/reconciliation/${invoice.id}`,
                        );
                        if (current !== epoch) return;
                        row.append(
                            text(
                                "p",
                                d.matched
                                    ? "Matched / متطابق"
                                    : "Reconciliation issues / مشكلات المطابقة",
                            ),
                        );
                        for (const issue of d.issues)
                            row.append(text("p", issue.code));
                    },
                );
            if (actor.id === invoice.user_id) {
                form(
                    row,
                    [],
                    "Open test checkout",
                    "بدء دفع اختباري",
                    async (_, key) => {
                        const detail = await api(
                            `finance/invoices/${invoice.id}`,
                        );
                        await api("commercial/checkouts", "POST", {
                            invoice_id: invoice.id,
                            amount_minor: detail.amount_due_minor,
                            idempotency_key: key,
                        });
                        await load();
                    },
                );
                form(
                    row,
                    [
                        field(
                            "amount",
                            "Refund amount",
                            "مبلغ الاسترداد",
                            undefined,
                        ),
                        field("reason", "Reason", "السبب", undefined),
                    ],
                    "Request refund",
                    "طلب استرداد",
                    async (data, key) => {
                        const amount = EvMoney.parse(
                            data.amount,
                            invoice.minor_units,
                            2000000000,
                        );
                        if (!amount)
                            throw Error("Enter an exact positive amount.");
                        await api(
                            `finance/invoices/${invoice.id}/refund-requests`,
                            "POST",
                            {
                                amount_minor: amount,
                                reason: data.reason,
                                idempotency_key: key,
                            },
                        );
                        await load();
                    },
                );
                form(
                    row,
                    [
                        field(
                            "amount",
                            "Disputed amount",
                            "المبلغ المعترض عليه",
                        ),
                        field(
                            "reason",
                            "Dispute reason",
                            "سبب الاعتراض",
                            undefined,
                        ),
                    ],
                    "Open dispute",
                    "فتح اعتراض",
                    async (data, key) => {
                        await api("commercial/cases", "POST", {
                            invoice_id: invoice.id,
                            kind: "dispute",
                            amount_minor: EvMoney.parse(
                                data.amount,
                                invoice.minor_units,
                                2000000000,
                            ),
                            reason: data.reason,
                            idempotency_key: key,
                        });
                        await load();
                    },
                );
            }
            invoices.append(row);
        }
        for (const checkout of checkouts) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text(
                    "h4",
                    `#${checkout.id} · ${checkout.status || checkout.state}`,
                ),
            );
            const checkoutState = checkout.status || checkout.state;
            if (
                checkout.provider === "mock" &&
                ["created", "pending", "requires_payment_method"].includes(
                    checkoutState,
                ) &&
                Number(checkout.user_id) === actor.id
            ) {
                form(
                    row,
                    [
                        {
                            name: "token",
                            en: "Test outcome",
                            ar: "نتيجة الاختبار",
                            options: [
                                ["mock_success", "Success / نجاح"],
                                ["mock_decline", "Declined / مرفوض"],
                            ],
                        },
                    ],
                    "Authorize test payment",
                    "تفويض دفع اختباري",
                    async (data, key) => {
                        await api(
                            `commercial/checkouts/${checkout.id}/authorize`,
                            "POST",
                            { token: data.token, idempotency_key: key },
                        );
                        await load();
                    },
                );
            }
            if (
                checkoutState === "authorized" &&
                Number(checkout.user_id) === actor.id
            )
                form(
                    row,
                    [],
                    "Capture test payment",
                    "تحصيل دفع اختباري",
                    async (_, key) => {
                        await api(
                            `commercial/checkouts/${checkout.id}/capture`,
                            "POST",
                            { idempotency_key: key },
                        );
                        await load();
                    },
                );
            if (
                ["created", "pending", "authorized"].includes(checkoutState) &&
                Number(checkout.user_id) === actor.id
            )
                form(
                    row,
                    [],
                    "Cancel checkout",
                    "إلغاء الدفع",
                    async (_, key) => {
                        await api(
                            `commercial/checkouts/${checkout.id}/cancel`,
                            "POST",
                            { idempotency_key: key },
                        );
                        await load();
                    },
                );
            invoices.append(row);
        }
        for (const request of requests) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text("p", `#${request.id} · ${request.status}`),
                text("p", request.reason),
            );
            if (operations && request.status === "pending")
                form(
                    row,
                    [
                        {
                            name: "decision",
                            en: "Decision",
                            ar: "القرار",
                            options: [
                                ["approve", "Approve / موافقة"],
                                ["reject", "Reject / رفض"],
                            ],
                        },
                        field("note", "Review note", "ملاحظة المراجعة"),
                    ],
                    "Review refund",
                    "مراجعة الاسترداد",
                    async (data) => {
                        await api(
                            `finance/refund-requests/${request.id}/review`,
                            "POST",
                            data,
                        );
                        await load();
                    },
                );
            invoices.append(row);
        }
        for (const item of cases) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text("p", `#${item.id} · ${item.status || item.state}`),
                text("p", item.reason),
            );
            if (
                actor.role === "admin" &&
                ["open", "pending"].includes(item.status || item.state)
            )
                form(
                    row,
                    [
                        {
                            name: "outcome",
                            en: "Dispute decision",
                            ar: "قرار الاعتراض",
                            options: [
                                ["approved", "Approved / موافقة"],
                                ["rejected", "Rejected / رفض"],
                            ],
                        },
                        field("resolution", "Resolution", "التسوية"),
                    ],
                    "Resolve dispute",
                    "حل الاعتراض",
                    async (data) => {
                        await api(
                            `commercial/cases/${item.id}/resolve`,
                            "POST",
                            data,
                        );
                        await load();
                    },
                );
            invoices.append(row);
        }
        const plans = result(3, subscriptions)?.data || [],
            owned = result(4, subscriptions)?.data || [];
        for (const plan of plans) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text("h4", plan.name),
                text(
                    "p",
                    `${money(plan.price_minor, plan.currency, plan.minor_units)} · ${plan.interval}`,
                ),
            );
            if (actor.role === plan.audience && plan.available)
                form(row, [], "Subscribe", "اشتراك", async (_, key) => {
                    await api("commercial/subscriptions", "POST", {
                        plan_id: plan.id,
                        idempotency_key: key,
                    });
                    await load();
                });
            if (actor.role === "admin")
                button(
                    row,
                    plan.available ? "Unpublish plan" : "Publish plan",
                    plan.available ? "إلغاء نشر الخطة" : "نشر الخطة",
                    async () => {
                        await api(
                            `commercial/plans/${plan.id}/availability`,
                            "PATCH",
                            { available: !plan.available },
                        );
                        await load();
                    },
                );
            subscriptions.append(row);
        }
        for (const subscription of owned) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text("h4", `#${subscription.id} · ${subscription.status}`),
            );
            button(row, "Manage subscription", "إدارة الاشتراك", async () => {
                const detail = await api(
                    `commercial/subscriptions/${subscription.id}`,
                );
                if (current !== epoch) return;
                row.querySelector("[data-subscription-detail]")?.remove();
                const content = document.createElement("div");
                content.dataset.subscriptionDetail = "";
                row.append(content);
                content.append(
                    text(
                        "p",
                        `${detail.plan.name} · ${detail.entitlements_active ? "Active / فعال" : "Inactive / غير فعال"}`,
                    ),
                );
                content.append(
                    text(
                        "p",
                        `${detail.subscription.period_start || "—"} → ${detail.subscription.period_end || "—"} · ${detail.subscription.cancel_at_period_end ? "Cancellation scheduled / إلغاء مجدول" : ""}`,
                    ),
                );
                content.append(text("p", JSON.stringify(detail.entitlements)));
                if (detail.subscription.next_plan_id)
                    content.append(
                        text(
                            "p",
                            `Next plan / الخطة القادمة: ${detail.subscription.next_plan_id}`,
                        ),
                    );
                for (const invoice of detail.invoices) {
                    content.append(
                        text(
                            "p",
                            `${invoice.number} · ${money(invoice.total_minor, invoice.currency, invoice.minor_units)}`,
                        ),
                    );
                    if (!invoice.paid && subscription.status !== "cancelled")
                        form(
                            content,
                            [
                                {
                                    name: "outcome",
                                    en: "Test renewal payment",
                                    ar: "دفع تجديد اختباري",
                                    options: [
                                        ["succeeded", "Success / نجاح"],
                                        ["failed", "Failed / فشل"],
                                    ],
                                },
                            ],
                            "Record test payment",
                            "تسجيل دفع اختباري",
                            async (data, key) => {
                                await api(
                                    `commercial/subscriptions/${subscription.id}/mock-payment`,
                                    "POST",
                                    {
                                        ...data,
                                        invoice_id: invoice.id,
                                        idempotency_key: key,
                                    },
                                );
                                await load();
                            },
                        );
                }
                if (subscription.status === "cancelled") return;
                form(
                    content,
                    [
                        {
                            name: "action",
                            en: "Subscription action",
                            ar: "إجراء الاشتراك",
                            options: [
                                [
                                    "cancel_at_period_end",
                                    "Cancel at period end / إلغاء نهاية الفترة",
                                ],
                                ["cancel_now", "Cancel now / إلغاء الآن"],
                                ["resume", "Resume / استئناف"],
                                [
                                    "change_plan",
                                    "Change next cycle plan / تغيير خطة الدورة القادمة",
                                ],
                            ],
                        },
                        {
                            name: "plan_id",
                            en: "Next plan",
                            ar: "الخطة القادمة",
                            required: false,
                            options: plans
                                .filter(
                                    (p) =>
                                        p.available &&
                                        p.audience === detail.plan.audience &&
                                        p.currency === detail.plan.currency,
                                )
                                .map((p) => [p.id, p.name]),
                        },
                    ],
                    "Update subscription",
                    "تحديث الاشتراك",
                    async (data, key) => {
                        await api(
                            `commercial/subscriptions/${subscription.id}/change`,
                            "POST",
                            {
                                action: data.action,
                                ...(data.action === "change_plan"
                                    ? { plan_id: Number(data.plan_id) }
                                    : {}),
                                idempotency_key: key,
                            },
                        );
                        await load();
                    },
                );
                button(
                    content,
                    "Renew due cycle",
                    "تجديد الدورة المستحقة",
                    async () => {
                        await api(
                            `commercial/subscriptions/${subscription.id}/renew`,
                            "POST",
                            {},
                        );
                        await load();
                    },
                );
            });
            subscriptions.append(row);
        }
        if (actor.role === "admin") {
            form(
                subscriptions,
                [
                    field("code", "Plan code", "رمز الخطة"),
                    field("name", "Plan name", "اسم الخطة"),
                    {
                        ...field(
                            "entitlements",
                            "Entitlements JSON",
                            "صلاحيات الخطة بصيغة JSON",
                            '{"finance_reports":true}',
                        ),
                        maxLength: 4000,
                    },
                    {
                        name: "audience",
                        en: "Audience",
                        ar: "الفئة",
                        options: [
                            ["vendor", "Vendor / مشغل"],
                            ["customer", "Customer / عميل"],
                        ],
                    },
                    field("currency", "Currency", "العملة", "AED"),
                    field(
                        "minor_units",
                        "Currency decimal places",
                        "المنازل العشرية للعملة",
                        2,
                        "number",
                    ),
                    field(
                        "price_minor",
                        "Price in minor units",
                        "السعر بالوحدات الصغرى",
                        1000,
                        "number",
                    ),
                    field(
                        "tax_bps",
                        "Tax in basis points",
                        "الضريبة بنقاط الأساس",
                        500,
                        "number",
                    ),
                    {
                        name: "interval",
                        en: "Billing interval",
                        ar: "دورة الفوترة",
                        options: [
                            ["month", "Monthly / شهري"],
                            ["year", "Yearly / سنوي"],
                        ],
                    },
                ],
                "Publish subscription plan",
                "نشر خطة اشتراك",
                async (data) => {
                    for (const name of [
                        "minor_units",
                        "price_minor",
                        "tax_bps",
                    ])
                        data[name] = Number(data[name]);
                    await api("commercial/plans", "POST", {
                        ...data,
                        entitlements: JSON.parse(data.entitlements),
                    });
                    await load();
                },
            );
        }
        if (operations) await settlements(operations, current);
    }
    async function settlements(parent, current) {
        const list = await api("commercial/settlements");
        if (current !== epoch) return;
        form(
            parent,
            [
                field(
                    "vendor",
                    "Vendor account ID",
                    "رقم حساب المشغل",
                    actor.role === "vendor" ? actor.id : undefined,
                    "number",
                ),
                field("currency", "Currency", "العملة", "AED"),
            ],
            "View earnings statement",
            "عرض كشف الأرباح",
            async (data) => {
                const statement = await api(
                    `commercial/vendors/${data.vendor}/statement?currency=${encodeURIComponent(data.currency)}`,
                );
                if (current !== epoch) return;
                const detail = document.createElement("div");
                parent.append(detail);
                detail.append(
                    text(
                        "h4",
                        money(
                            statement.available_minor,
                            statement.currency,
                            statement.minor_units,
                        ),
                    ),
                    translated(
                        "p",
                        "Available for test settlement",
                        "المتاح للتسوية الاختبارية",
                    ),
                );
                if (statement.eligible_to_request)
                    form(
                        detail,
                        [],
                        "Request test settlement",
                        "طلب تسوية اختبارية",
                        async (_, key) => {
                            await api(
                                `commercial/vendors/${data.vendor}/settlements`,
                                "POST",
                                {
                                    currency: data.currency,
                                    idempotency_key: key,
                                },
                            );
                            await load();
                        },
                    );
                for (const a of statement.adjustments || []) {
                    detail.append(
                        text("p", `${a.id} · ${a.reason} · ${a.amount_minor}`),
                    );
                    if (actor.role === "admin" && !a.approved_by)
                        button(
                            detail,
                            "Approve adjustment",
                            "الموافقة على التعديل",
                            async () => {
                                await api(
                                    `commercial/adjustments/${a.id}/approve`,
                                    "POST",
                                    {},
                                );
                                await load();
                            },
                        );
                }
                if (actor.role === "admin") {
                    form(
                        detail,
                        [
                            field(
                                "hold_days",
                                "Hold days",
                                "أيام الحجز",
                                0,
                                "number",
                            ),
                            field(
                                "interval_days",
                                "Settlement interval days",
                                "فترة التسوية بالأيام",
                                30,
                                "number",
                            ),
                            field(
                                "minimum_minor",
                                "Minimum in minor units",
                                "الحد الأدنى بالوحدات الصغرى",
                                1,
                                "number",
                            ),
                            field(
                                "next_due_at",
                                "Next due date UTC",
                                "تاريخ الاستحقاق القادم بتوقيت UTC",
                                new Date().toISOString().slice(0, 10),
                                "date",
                            ),
                        ],
                        "Save settlement schedule",
                        "حفظ جدول التسوية",
                        async (schedule) => {
                            for (const key of [
                                "hold_days",
                                "interval_days",
                                "minimum_minor",
                            ])
                                schedule[key] = Number(schedule[key]);
                            await api(
                                `commercial/vendors/${data.vendor}/schedule`,
                                "PUT",
                                {
                                    ...schedule,
                                    currency: data.currency,
                                    enabled: true,
                                },
                            );
                            await load();
                        },
                    );
                    form(
                        detail,
                        [
                            field(
                                "amount_minor",
                                "Signed adjustment in minor units",
                                "تعديل موجب أو سالب بالوحدات الصغرى",
                                undefined,
                                "number",
                            ),
                            field("reason", "Adjustment reason", "سبب التعديل"),
                        ],
                        "Request adjustment approval",
                        "طلب الموافقة على تعديل",
                        async (a, key) => {
                            await api(
                                `commercial/vendors/${data.vendor}/adjustments`,
                                "POST",
                                {
                                    ...a,
                                    amount_minor: Number(a.amount_minor),
                                    currency: data.currency,
                                    idempotency_key: key,
                                },
                            );
                            await load();
                        },
                    );
                }
            },
        );
        for (const s of list.data) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.append(
                text("h4", s.number),
                text(
                    "p",
                    `${money(s.amount_minor, s.currency, s.minor_units)} · ${s.state}`,
                ),
            );
            button(
                row,
                "View settlement reconciliation",
                "عرض مطابقة التسوية",
                async () => {
                    const d = await api(`commercial/settlements/${s.id}`);
                    if (current !== epoch) return;
                    row.append(
                        text(
                            "p",
                            `${d.reconciled ? "Balanced / متوازن" : "Mismatch / عدم تطابق"} · ${d.lines.length}`,
                        ),
                    );
                },
            );
            if (actor.role === "admin")
                for (const action of s.state === "pending"
                    ? ["approve", "reject"]
                    : s.state === "approved"
                      ? ["simulate-payout", "reject"]
                      : [])
                    button(
                        row,
                        action === "simulate-payout"
                            ? "Record test payout"
                            : action === "approve"
                              ? "Approve settlement"
                              : "Reject settlement",
                        action === "simulate-payout"
                            ? "تسجيل صرف اختباري"
                            : action === "approve"
                              ? "الموافقة على التسوية"
                              : "رفض التسوية",
                        async () => {
                            await api(
                                `commercial/settlements/${s.id}/${action}`,
                                "POST",
                                {},
                            );
                            await load();
                        },
                    );
            parent.append(row);
        }
    }
    async function open() {
        if (!actor) return;
        try {
            await load();
        } catch (e) {
            if (!["SessionChangedError", "AbortError"].includes(e.name))
                status(e.message);
        }
    }
    window.addEventListener("hashchange", () => {
        if (location.hash === "#commercial-finance") open();
    });
    return {
        clear,
        async setActor(value) {
            clear();
            actor = value;
            if (!actor) return;
            root.hidden = false;
            if (location.hash === "#commercial-finance") await open();
            else {
                root.append(
                    translated(
                        "h2",
                        "Finance & commercial",
                        "المالية والأعمال",
                    ),
                );
                button(
                    root,
                    "Open financial workspace",
                    "فتح مساحة العمل المالية",
                    async () => {
                        location.hash = "commercial-finance";
                    },
                );
            }
        },
    };
};
