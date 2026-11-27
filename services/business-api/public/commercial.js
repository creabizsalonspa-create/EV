/* Financial workspace shares the existing session client; all text is rendered literally. */
window.createCommercialWorkspace = ({ root, api: clientApi, ui }) => {
    let cache = new Map(),
        pages = {},
        reportQuery = {},
        actor = null,
        epoch = 0,
        controller = null,
        hostedWorkspace = null;
    async function api(path, method = "GET", data, signal) {
        const result = await clientApi(path, method, data, signal);
        if (method !== "GET") {
            const paths = [];
            if (path.startsWith("commercial/hosted-checkouts")) {
                paths.push(
                    "commercial/checkouts",
                    "commercial/subscription-checkouts",
                    "commercial/subscriptions",
                    "finance/report",
                    "finance/invoices",
                );
            } else if (path.startsWith("commercial/checkouts")) {
                paths.push("commercial/checkouts");
                if (/\/(capture|refund)$/.test(path))
                    paths.push("finance/report", "finance/invoices");
            } else if (path.startsWith("commercial/plans"))
                paths.push("commercial/plans");
            else if (path.startsWith("commercial/subscription"))
                paths.push(
                    "commercial/subscriptions",
                    "commercial/subscription-checkouts",
                );
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
            if (
                path.startsWith("commercial/settlements") ||
                path.startsWith("commercial/payouts/") ||
                path.startsWith("commercial/vendors/") ||
                path.startsWith("commercial/adjustments/")
            )
                paths.push("commercial/settlements");
            if (path.startsWith("commercial/external-disputes/"))
                paths.push("commercial/cases", "commercial/settlements");
            if (path.startsWith("commercial/corporate/"))
                paths.push("finance/invoices", "finance/report");
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
        root.inert = false;
        root.removeAttribute("aria-busy");
        root.hidden = true;
        actor = null;
        hostedWorkspace = null;
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
            const account = actor?.id;
            root.inert = true;
            root.setAttribute("aria-busy", "true");
            try {
                await action();
            } catch (e) {
                if (e.name !== "SessionChangedError" && e.name !== "AbortError")
                    status(e.message);
            } finally {
                b.disabled = false;
                if (actor?.id === account) {
                    root.inert = false;
                    root.removeAttribute("aria-busy");
                }
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
        let key = crypto.randomUUID(),
            savedPayload = null;
        f.addEventListener("input", () => {
            if (f.dataset.refundFrozen !== "true") key = crypto.randomUUID();
        });
        f.onsubmit = async (event) => {
            event.preventDefault();
            submit.disabled = true;
            const account = actor?.id;
            root.inert = true;
            root.setAttribute("aria-busy", "true");
            const current = epoch;
            try {
                const payload =
                    f.dataset.refundFrozen === "true"
                        ? savedPayload
                        : Object.fromEntries(new FormData(f));
                if (f.dataset.freezePayload === "true")
                    savedPayload = structuredClone(payload);
                await action(structuredClone(payload), key);
                if (current === epoch) status("");
            } catch (e) {
                if (
                    current === epoch &&
                    !["SessionChangedError", "AbortError"].includes(e.name)
                )
                    status(e.message);
            } finally {
                if (f.dataset.freezePayload === "true") {
                    if (f.dataset.refundFrozen === "true")
                        for (const control of f.querySelectorAll(
                            "input,select",
                        ))
                            control.disabled = true;
                    else savedPayload = null;
                }
                submit.disabled = false;
                if (actor?.id === account) {
                    root.inert = false;
                    root.removeAttribute("aria-busy");
                }
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
        maxLength: ["reason", "note", "resolution"].includes(name) ? 500 : 100,
    });
    async function openHosted(context) {
        const current = epoch;
        if (!hostedWorkspace) {
            const { mountHostedCheckout } = await import("/hosted-checkout.js");
            if (current !== epoch || !actor) return;
            const container = panel(
                "Secure hosted checkout",
                "الدفع المستضاف الآمن",
            );
            hostedWorkspace = mountHostedCheckout({
                container,
                api,
                ui,
                actor: () => actor,
            });
        }
        await hostedWorkspace.open(context);
    }
    async function load() {
        const current = ++epoch;
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal;
        root.replaceChildren();
        hostedWorkspace = null;
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
        button(
            root,
            "Hosted checkout & recovery",
            "الدفع المستضاف والاستعادة",
            () => openHosted(),
        );
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
            call("commercial/subscription-checkouts"),
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
                    "commercial/subscription-checkouts",
                ];
                const data = r.value,
                    path = paths[index];
                if (data.last_page > 1) {
                    const pager = document.createElement("div");
                    pager.className = "finance-pagination";
                    const labels = [
                        ["Overview", "نظرة عامة"],
                        ["Invoices", "الفواتير"],
                        ["Refund requests", "طلبات الاسترداد"],
                        ["Plans", "الخطط"],
                        ["Subscriptions", "الاشتراكات"],
                        ["Payments", "المدفوعات"],
                        ["Disputes", "الاعتراضات"],
                        ["Subscription payments", "مدفوعات الاشتراك"],
                    ];
                    pager.append(
                        translated("p", ...labels[index]),
                        text("p", `${data.current_page} / ${data.last_page}`),
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
            if (Number(invoice.unpaid_credited_minor) > 0)
                row.append(
                    translated(
                        "p",
                        "Credited without collection; no cash refund",
                        "سويت دون تحصيل أو استرداد نقدي",
                    ),
                );
            if (
                actor.id === invoice.user_id &&
                !(Number(invoice.unpaid_credited_minor) > 0)
            ) {
                button(
                    row,
                    "Secure hosted checkout",
                    "الدفع المستضاف الآمن",
                    () =>
                        openHosted({
                            scope: "charging",
                            invoice_id: invoice.id,
                        }),
                );
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
                const refundForm = form(
                    row,
                    [
                        field(
                            "amount",
                            "Refund amount",
                            "مبلغ الاسترداد",
                            undefined,
                        ),
                        field("reason", "Reason", "السبب", undefined),
                        field(
                            "ocpi_credit_record_id",
                            "Selected source credit record",
                            "مرجع المصدر المحدد",
                            undefined,
                            "text",
                            false,
                        ),
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
                        const creditId = data.ocpi_credit_record_id
                            ? Number(data.ocpi_credit_record_id)
                            : null;
                        if (
                            creditId !== null &&
                            (!Number.isSafeInteger(creditId) || creditId <= 0)
                        )
                            throw Error("Invalid source credit reference.");
                        refundForm.dataset.refundFrozen = "true";
                        const receipt = await api(
                            `finance/invoices/${invoice.id}/refund-requests`,
                            "POST",
                            {
                                amount_minor: amount,
                                ...(data.ocpi_credit_record_id
                                    ? {
                                          ocpi_credit_record_id: Number(
                                              data.ocpi_credit_record_id,
                                          ),
                                      }
                                    : {}),
                                reason: data.reason,
                                idempotency_key: key,
                            },
                        );
                        if (
                            creditId !== null &&
                            receipt.ocpi_credit_record_id !== creditId
                        )
                            throw Error(
                                "Source credit link was not confirmed.",
                            );
                        await load();
                    },
                );
                refundForm.dataset.freezePayload = "true";
                const creditInput = refundForm.elements.namedItem(
                    "ocpi_credit_record_id",
                );
                creditInput.readOnly = true;
                const sourcePane = document.createElement("div");
                row.append(sourcePane);
                let creditGeneration = 0;
                document.addEventListener(
                    "visibilitychange",
                    () => {
                        if (document.hidden) {
                            creditGeneration++;
                            sourcePane.replaceChildren();
                        }
                    },
                    { signal: controller.signal },
                );
                const sourceEditable = () => {
                    if (refundForm.dataset.refundFrozen === "true")
                        throw Error(
                            "Retry the saved refund request before changing source evidence.",
                        );
                };
                const sourceChoices = async (page = 1) => {
                    sourceEditable();
                    const current = epoch,
                        version = ++creditGeneration;
                    const credits = await api(
                        `finance/invoices/${invoice.id}/source-credits?page=${page}`,
                    );
                    if (
                        current !== epoch ||
                        version !== creditGeneration ||
                        document.hidden ||
                        !refundForm.isConnected
                    )
                        return;
                    if (
                        credits.schema_version !== 1 ||
                        credits.invoice_id !== invoice.id ||
                        credits.financial_authority !== false ||
                        credits.automatic_refund !== false
                    )
                        throw Error(
                            "Source credit references could not be verified.",
                        );
                    sourcePane.replaceChildren(
                        translated(
                            "p",
                            "Partner amounts are evidence only. Captured funds and manual refund review are still required.",
                            "مبالغ الشريك أدلة فقط. تظل الأموال المحصلة والمراجعة اليدوية للاسترداد مطلوبتين.",
                        ),
                    );
                    if (!credits.data.length)
                        sourcePane.append(
                            translated(
                                "p",
                                "No matching source credits",
                                "لا توجد إشعارات دائنة مطابقة من المصدر",
                            ),
                        );
                    for (const credit of credits.data) {
                        const entry = document.createElement("article");
                        entry.append(text("p", credit.external_id));
                        const evidence = credit.evidence;
                        const safe =
                            evidence?.schema_version === 1 &&
                            evidence.invoice_id === invoice.id &&
                            evidence.credit_record_id === credit.id &&
                            evidence.status === "source_reference_matched" &&
                            evidence.financial_authority === false &&
                            evidence.automatic_refund === false;
                        if (
                            safe &&
                            typeof evidence.reported_cost?.excl_vat === "string"
                        )
                            entry.append(
                                text(
                                    "p",
                                    `${evidence.currency} · ${evidence.reported_cost.excl_vat}`,
                                ),
                            );
                        const select = button(
                            entry,
                            "Select credit reference",
                            "اختيار مرجع الإشعار الدائن",
                            () => {
                                sourceEditable();
                                creditInput.value = String(credit.id);
                                creditInput.dispatchEvent(
                                    new Event("input", { bubbles: true }),
                                );
                            },
                        );
                        select.disabled =
                            credit.available_for_review !== true ||
                            !safe ||
                            !Number.isSafeInteger(credit.id) ||
                            credit.id <= 0;
                        if (credit.existing_refund_state)
                            entry.append(
                                translated(
                                    "p",
                                    "Already linked to a refund review",
                                    "مرتبط مسبقاً بمراجعة استرداد",
                                ),
                            );
                        sourcePane.append(entry);
                    }
                    if (credits.current_page > 1)
                        button(sourcePane, "Previous", "السابق", () =>
                            sourceChoices(page - 1),
                        );
                    if (credits.current_page < credits.last_page)
                        button(sourcePane, "Next", "التالي", () =>
                            sourceChoices(page + 1),
                        );
                };
                button(
                    row,
                    "Choose optional partner credit",
                    "اختيار مرجع إشعار دائن اختياري",
                    () => sourceChoices(),
                );
                button(
                    row,
                    "Continue without source credit",
                    "المتابعة دون إشعار دائن من المصدر",
                    () => {
                        sourceEditable();
                        creditInput.value = "";
                        creditInput.dispatchEvent(
                            new Event("input", { bubbles: true }),
                        );
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
            if (request.ocpi_credit_record_id != null)
                row.append(
                    translated(
                        "p",
                        `Source credit record: ${request.ocpi_credit_record_id}`,
                        `مرجع مصدر الإشعار الدائن: ${request.ocpi_credit_record_id}`,
                    ),
                );
            const evidence = request.source_credit_evidence;
            if (
                evidence &&
                evidence.schema_version === 1 &&
                evidence.invoice_id === request.invoice_id &&
                evidence.credit_record_id === request.ocpi_credit_record_id &&
                evidence.financial_authority === false &&
                evidence.automatic_refund === false
            ) {
                row.append(
                    translated(
                        "p",
                        evidence.status === "source_reference_matched"
                            ? "Source reference matched; reported credit amounts do not approve a refund."
                            : "Source credit evidence requires review.",
                        evidence.status === "source_reference_matched"
                            ? "تطابق مرجع المصدر؛ مبالغ الإشعار المبلّغ عنها لا تعني الموافقة على استرداد."
                            : "تتطلب أدلة مصدر الإشعار الدائن المراجعة.",
                    ),
                );
                if (evidence.status === "source_reference_matched")
                    for (const [kind, amount] of Object.entries(
                        evidence.reported_cost || {},
                    )) {
                        if (
                            ["excl_vat", "incl_vat"].includes(kind) &&
                            typeof amount === "string" &&
                            /^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,6})?$/.test(
                                amount,
                            )
                        )
                            row.append(
                                text(
                                    "p",
                                    `${kind === "incl_vat" ? "Gross / شامل الضريبة" : "Net / قبل الضريبة"}: ${amount} ${evidence.currency}`,
                                ),
                            );
                    }
            }
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
            // Exact minor units only. A malformed server balance must never
            // render as an apparently approved currency amount or enable review.
            const amount = item.amount_minor;
            const exactAmount =
                typeof amount === "number"
                    ? Number.isSafeInteger(amount) && amount > 0 && amount <= 2000000000
                        ? String(amount)
                        : null
                    : typeof amount === "string" &&
                        /^[1-9][0-9]{0,9}$/.test(amount) &&
                        Number(amount) <= 2000000000
                      ? amount
                      : null;
            row.append(
                text("p", `#${item.id} · ${item.status || item.state}`),
                text("p", item.reason),
            );
            if (exactAmount !== null) {
                const line = document.createElement("p");
                const value = text("bdi", exactAmount);
                value.dir = "ltr";
                value.dataset.caseMinorAmount = "true";
                line.append(
                    translated(
                        "span",
                        "Disputed amount (minor units)",
                        "المبلغ المتنازع عليه (وحدات نقدية صغرى)",
                    ),
                    document.createTextNode(": "),
                    value,
                );
                row.append(line);
            } else {
                row.append(
                    translated(
                        "p",
                        "Dispute amount unverified; refresh before review.",
                        "مبلغ النزاع غير متحقق منه؛ حدّث البيانات قبل المراجعة.",
                    ),
                );
            }
            if (
                exactAmount !== null &&
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
        const subscriptionCheckouts = result(7, subscriptions)?.data || [];
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
            row.dataset.subscriptionId = String(subscription.id);
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
                for (const [feature, value] of Object.entries(
                    detail.entitlements,
                )) {
                    const label = feature.replaceAll("_", " ");
                    content.append(
                        text(
                            "p",
                            `${label}: ${typeof value === "boolean" ? (value ? "Included / مشمول" : "Unavailable / غير متاح") : value}`,
                        ),
                    );
                }
                if (detail.subscription.next_plan_id)
                    content.append(
                        text(
                            "p",
                            `Next plan / الخطة القادمة: ${detail.subscription.next_plan_id}`,
                        ),
                    );
                for (const invoice of detail.invoices) {
                    if (
                        !invoice.paid &&
                        subscription.user_id === actor.id &&
                        subscription.status !== "cancelled"
                    )
                        button(
                            content,
                            "Hosted subscription payment",
                            "دفع الاشتراك المستضاف",
                            () =>
                                openHosted({
                                    scope: "subscription",
                                    invoice_id: invoice.id,
                                    subscription_id: subscription.id,
                                }),
                        );
                    content.append(
                        text(
                            "p",
                            `${invoice.number} · ${money(invoice.total_minor, invoice.currency, invoice.minor_units)}`,
                        ),
                    );
                    if (invoice.paid) {
                        content.append(
                            text(
                                "p",
                                `${invoice.currency} ${EvMoney.format(Number(invoice.refunded_minor || 0), Number(invoice.minor_units))} · Refunds / المسترد`,
                            ),
                        );
                        button(
                            content,
                            "View subscription credit notes",
                            "عرض الإشعارات الدائنة للاشتراك",
                            async () => {
                                const notes = await api(
                                    `commercial/subscriptions/${subscription.id}/credits`,
                                );
                                if (current !== epoch) return;
                                content
                                    .querySelector("[data-credit-notes]")
                                    ?.remove();
                                const list = document.createElement("div");
                                list.dataset.creditNotes = "";
                                if (!notes.data.length)
                                    list.append(ui("p", "No records."));
                                for (const note of notes.data) {
                                    const item =
                                        document.createElement("article");
                                    item.className = "finance-row";
                                    item.append(
                                        text("h4", note.number),
                                        text(
                                            "p",
                                            money(
                                                note.amount_minor,
                                                note.currency,
                                                note.minor_units,
                                            ),
                                        ),
                                        text("p", note.reason),
                                    );
                                    list.append(item);
                                }
                                content.append(list);
                            },
                        );
                        const refundable =
                            invoice.total_minor - (invoice.refunded_minor || 0);
                        if (actor.role === "admin" && refundable > 0)
                            form(
                                content,
                                [
                                    field(
                                        "amount",
                                        "Refund amount",
                                        "مبلغ الاسترداد",
                                        EvMoney.format(
                                            refundable,
                                            Number(invoice.minor_units),
                                        ),
                                    ),
                                    field("reason", "Reason", "السبب"),
                                ],
                                "Refund subscription invoice",
                                "استرداد فاتورة الاشتراك",
                                async (data, key) => {
                                    const amount = EvMoney.parse(
                                        data.amount,
                                        Number(invoice.minor_units),
                                        2000000000,
                                    );
                                    if (!amount)
                                        throw Error(
                                            "Enter an exact positive amount.",
                                        );
                                    await api(
                                        `commercial/subscriptions/${subscription.id}/credits`,
                                        "POST",
                                        {
                                            invoice_id: invoice.id,
                                            amount_minor: amount,
                                            reason: data.reason,
                                            idempotency_key: key,
                                        },
                                    );
                                    await load();
                                },
                            );
                    }
                    const pendingCheckout = subscriptionCheckouts.some(
                        (c) =>
                            c.invoice_id === invoice.id &&
                            [
                                "pending",
                                "authorized",
                                "action_required",
                            ].includes(c.status),
                    );
                    if (
                        !invoice.paid &&
                        subscription.status !== "cancelled" &&
                        !pendingCheckout &&
                        subscription.user_id === actor.id
                    )
                        form(
                            content,
                            [],
                            "Open subscription checkout",
                            "فتح دفع الاشتراك",
                            async (_, key) => {
                                await api(
                                    `commercial/subscriptions/${subscription.id}/checkouts`,
                                    "POST",
                                    {
                                        invoice_id: invoice.id,
                                        idempotency_key: key,
                                    },
                                );
                                await load();
                            },
                        );
                    if (
                        !invoice.paid &&
                        subscription.status !== "cancelled" &&
                        !pendingCheckout
                    )
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
                const changePlanForm = form(
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
                const preview = document.createElement("section");
                preview.dataset.subscriptionChangePreview = String(subscription.id);
                content.append(preview);
                const invalidatePreview = () => preview.replaceChildren();
                changePlanForm.addEventListener("input", invalidatePreview);
                changePlanForm.addEventListener("change", invalidatePreview);
                button(
                    content,
                    "Preview next cycle charges",
                    "معاينة رسوم دورة الاشتراك القادمة",
                    async () => {
                        const selection = new FormData(changePlanForm);
                        if (selection.get("action") !== "change_plan")
                            throw Error("Select Change next cycle plan before preview.");
                        const planId = Number(selection.get("plan_id"));
                        if (!Number.isSafeInteger(planId) || planId < 1)
                            throw Error("Select a valid next-cycle plan.");
                        const quote = await api(
                            "commercial/subscriptions/" + subscription.id +
                                "/change-preview?plan_id=" + planId,
                            "GET",
                            undefined,
                            controller.signal,
                        );
                        if (current !== epoch || !preview.isConnected) return;
                        if (
                            Number(quote.subscription_id) !== Number(subscription.id) ||
                            Number(quote.proposed_plan_id) !== planId ||
                            quote.amount_due_now_minor !== 0 ||
                            quote.proration_supported !== false ||
                            quote.comparison_is_proration !== false ||
                            !Number.isSafeInteger(quote.current_plan_list_total_minor) ||
                            !["month", "year"].includes(quote.current_plan_interval) ||
                            typeof quote.cycles_comparable !== "boolean" ||
                            (quote.cycles_comparable
                                ? !Number.isSafeInteger(quote.nominal_plan_list_difference_minor)
                                : quote.nominal_plan_list_difference_minor !== null) ||
                            quote.quote_is_binding !== false ||
                            quote.real_payment_processed !== false
                        )
                            throw Error("Unsafe plan-change quote response.");
                        preview.replaceChildren(
                            translated(
                                "h4",
                                "Non-binding next cycle preview",
                                "معاينة غير ملزمة للدورة القادمة",
                            ),
                            text(
                                "p",
                                money(
                                    quote.next_cycle_subtotal_minor,
                                    quote.currency,
                                    quote.minor_units,
                                ) + " · Next cycle base / سعر الدورة القادمة",
                            ),
                            text(
                                "p",
                                money(
                                    quote.next_cycle_tax_minor,
                                    quote.currency,
                                    quote.minor_units,
                                ) + " · Estimated tax / الضريبة المتوقعة",
                            ),
                            text(
                                "strong",
                                money(
                                    quote.next_cycle_total_minor,
                                    quote.currency,
                                    quote.minor_units,
                                ) + " · Next cycle total / إجمالي الدورة القادمة",
                            ),
                            text(
                                "p",
                                money(
                                    quote.current_plan_list_total_minor,
                                    quote.currency,
                                    quote.minor_units,
                                ) +
                                " · Current published list (" + quote.current_plan_interval +
                                ") / السعر المعلن الحالي",
                            ),
                            quote.cycles_comparable
                                ? text(
                                    "p",
                                    money(
                                        quote.nominal_plan_list_difference_minor,
                                        quote.currency,
                                        quote.minor_units,
                                    ) +
                                    " · Nominal list difference, not due now / فرق السعر المعلن وليس مستحقاً الآن",
                                )
                                : translated(
                                    "p",
                                    "Different billing intervals; nominal totals cannot be compared directly.",
                                    "فترات الفوترة مختلفة؛ لا يمكن مقارنة الإجماليات المعلنة مباشرة.",
                                ),
                            text(
                                "p",
                                String(quote.effective_at_utc) +
                                    " · Effective at UTC / يبدأ بتوقيت UTC",
                            ),
                            translated(
                                "p",
                                "Due now: zero. No proration, new invoice, entitlement change or payment occurs until a separate approved action.",
                                "المستحق الآن: صفر. لا تتم تسوية نسبية أو إصدار فاتورة أو تغيير مزايا أو دفع إلا بإجراء منفصل معتمد.",
                            ),
                        );
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
        for (const checkout of subscriptionCheckouts) {
            const row = document.createElement("div");
            row.className = "finance-row";
            row.dataset.subscriptionCheckout = String(checkout.id);
            row.append(
                text(
                    "h4",
                    `Subscription checkout / دفع الاشتراك #${checkout.id} · ${checkout.status}`,
                ),
            );
            if (checkout.user_id === actor.id) {
                if (
                    checkout.status === "pending" &&
                    checkout.provider === "mock"
                )
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
                        "Authorize subscription payment",
                        "تفويض دفع الاشتراك",
                        async (data, key) => {
                            await api(
                                `commercial/subscription-checkouts/${checkout.id}/authorize`,
                                "POST",
                                { ...data, idempotency_key: key },
                            );
                            await load();
                        },
                    );
                if (checkout.status === "authorized")
                    form(
                        row,
                        [],
                        "Capture subscription payment",
                        "تحصيل دفع الاشتراك",
                        async (_, key) => {
                            await api(
                                `commercial/subscription-checkouts/${checkout.id}/capture`,
                                "POST",
                                { idempotency_key: key },
                            );
                            await load();
                        },
                    );
                if (
                    ["pending", "authorized", "action_required"].includes(
                        checkout.status,
                    )
                )
                    form(
                        row,
                        [],
                        "Cancel subscription checkout",
                        "إلغاء دفع الاشتراك",
                        async (_, key) => {
                            await api(
                                `commercial/subscription-checkouts/${checkout.id}/cancel`,
                                "POST",
                                { idempotency_key: key },
                            );
                            await load();
                        },
                    );
            }
            button(
                row,
                "Reconcile subscription payment",
                "مطابقة دفع الاشتراك",
                async () => {
                    const d = await api(
                        `commercial/subscription-checkouts/${checkout.id}/reconciliation`,
                    );
                    if (current !== epoch) return;
                    row.append(
                        text(
                            "p",
                            d.matched
                                ? "Matched / متطابق"
                                : "Reconciliation required / المطابقة مطلوبة",
                        ),
                    );
                },
            );
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
        if (["customer", "vendor"].includes(actor.role))
            mountRecurringPayments({
                container: panel("Recurring payments", "المدفوعات المتكررة"),
                api,
                ui,
                actor: () => actor,
            });
        const recoveryPanel = panel("Payment reliability", "موثوقية المدفوعات");
        mountPaymentRecovery({
            container: recoveryPanel,
            api,
            ui,
            actor: () => actor,
        });
        mountSubscriptionNotices({
            container: panel("Subscription reminders", "تذكيرات الاشتراك"),
            api,
            ui,
            actor: () => actor,
        });
        mountFinancialDocuments({
            container: panel("Financial documents", "المستندات المالية"),
            api,
            ui,
            actor: () => actor,
        });
        mountCorporateBilling({
            container: panel("Corporate billing", "فوترة الشركات"),
            api,
            ui,
            actor: () => actor,
        });
        mountPaymentCaseEvidence({
            container: panel(
                "Internal dispute evidence",
                "أدلة النزاعات الداخلية",
            ),
            api,
            ui,
            actor: () => actor,
        });
        mountExternalDisputes({
            container: panel("Provider disputes", "نزاعات مزود الدفع"),
            api,
            ui,
            actor: () => actor,
        });
        mountLoyalty({
            container: panel("Loyalty & rewards", "الولاء والمكافآت"),
            api,
            ui,
            actor: () => actor,
        });
        const analyticsPanel = panel(
            "Financial analytics",
            "التحليلات المالية",
        );
        import("/financial-analytics.js")
            .then(({ mountFinancialAnalytics }) => {
                if (current === epoch && actor)
                    mountFinancialAnalytics({
                        container: analyticsPanel,
                        api,
                        ui,
                        actor: () => actor,
                    });
            })
            .catch((error) => {
                if (current === epoch) status(error.message);
            });
        if (operations) {
            const campaignsPanel = panel(
                "Commercial campaigns",
                "الحملات التجارية",
            );
            import("/commercial-campaigns.js")
                .then(() => {
                    if (current === epoch && actor)
                        window.mountCommercialCampaigns({
                            container: campaignsPanel,
                            api,
                            ui,
                            actor: () => actor,
                        });
                })
                .catch((error) => {
                    if (current === epoch) status(error.message);
                });
        }
        if (operations) {
            mountVendorPayouts({
                container: panel("Vendor payouts", "دفعات الموردين"),
                api,
                ui,
                actor: () => actor,
            });
            await settlements(operations, current);
            vendorRevenue(operations, current);
            if (actor.role === "admin")
                financeApprovals(panel("Finance approval review", "مراجعة الموافقات المالية"), current);
        }
    }
    function validateRevenueCohort(report, vendor, currency, from, to) {
        const fields = [
            "invoice_count", "invoiced_minor", "vendor_allocated_minor",
            "commission_allocated_minor", "paid_minor", "refunded_minor",
            "vendor_refunded_minor", "unpaid_credited_minor", "vendor_credited_minor",
        ];
        const fail = () => { throw Error("Revenue cohort source cannot be verified."); };
        const max = BigInt(Number.MAX_SAFE_INTEGER);
        const exact = (value, signed = false) => {
            if (!Number.isSafeInteger(value) || (!signed && value < 0)) fail();
            return BigInt(value);
        };
        const day = value => {
            if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) fail();
            const ms = Date.parse(value + "T00:00:00Z");
            if (!Number.isFinite(ms) || new Date(ms).toISOString().slice(0, 10) !== value) fail();
            return ms / 86400000;
        };
        const first = day(from), count = day(to) - first + 1;
        if (!report || !Number.isSafeInteger(vendor) || vendor < 1 ||
            !/^[A-Z]{3}$/.test(currency) || report.vendor_id !== vendor ||
            report.currency !== currency || report.from !== from || report.to !== to ||
            !Number.isSafeInteger(report.minor_units) || report.minor_units < 0 || report.minor_units > 3 ||
            report.evidence !== "SIMULATED_CANONICAL_FINANCE" ||
            report.basis !== "INVOICE_ISSUANCE_DATE_UTC_COHORT" ||
            report.payment_and_refund_totals !== "LIFETIME_OF_SELECTED_INVOICE_COHORT_AS_OF_QUERY" ||
            report.external_psp_or_bank_verified !== false || report.real_money_movement !== false ||
            report.not_cashflow_or_payout_eligibility !== true ||
            count < 1 || count > 93 || !Array.isArray(report.data) || report.data.length !== count ||
            !report.totals || typeof report.totals !== "object" || Array.isArray(report.totals)) fail();
        if (Object.hasOwn(report, "as_of")) {
            const stamp = report.as_of;
            if (typeof stamp !== "string" ||
                !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/.test(stamp) ||
                !Number.isFinite(Date.parse(stamp)) ||
                new Date(stamp).toISOString().slice(0, 19) !== stamp.slice(0, 19)) fail();
        }
        const sums = Object.fromEntries(fields.map(field => [field, 0n]));
        const nets = row => {
            if (exact(row.net_collected_minor, true) !== exact(row.paid_minor) - exact(row.refunded_minor) ||
                exact(row.net_vendor_allocated_minor, true) !== exact(row.vendor_allocated_minor) -
                    exact(row.vendor_refunded_minor) - exact(row.vendor_credited_minor)) fail();
        };
        report.data.forEach((row, index) => {
            if (!row || typeof row !== "object" || Array.isArray(row) || day(row.date_utc) !== first + index) fail();
            for (const field of fields) {
                sums[field] += exact(row[field]);
                if (sums[field] > max) fail();
            }
            nets(row);
        });
        for (const field of fields) if (exact(report.totals[field]) !== sums[field]) fail();
        nets(report.totals);
        return report;
    }

    function vendorRevenue(parent, current) {
        // Issuance-date source cohorts, not PSP cashflow or payout eligibility.
        const area = document.createElement("section");
        area.dataset.vendorRevenue = "true";
        area.append(
            translated("h4", "Vendor revenue by invoice date", "إيرادات المشغل بحسب تاريخ الفاتورة"),
            translated(
                "p",
                "Simulated invoice cohorts only. Collections and refunds belong to the original invoice date, not their transaction date. Payout eligibility is separate.",
                "مجموعات فواتير تجريبية فقط. تُنسب المدفوعات والاستردادات إلى تاريخ الفاتورة الأصلية، وليس تاريخ المعاملة. أهلية الصرف منفصلة.",
            ),
        );
        parent.append(area);
        const results = document.createElement("section");
        results.dataset.vendorRevenueResults = "true";
        const today = new Date().toISOString().slice(0, 10);
        const from = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);
        let reportGeneration = 0;
        const requestForm = form(
            area,
            [
                field(
                    "vendor",
                    "Vendor account ID",
                    "رقم حساب المشغل",
                    actor.role === "vendor" ? actor.id : undefined,
                    "number",
                ),
                field("currency", "Currency", "العملة", "AED"),
                field("from", "Invoice date from (UTC)", "تاريخ الفاتورة من (UTC)", from, "date"),
                field("to", "Invoice date to (UTC)", "تاريخ الفاتورة إلى (UTC)", today, "date"),
            ],
            "View revenue cohorts",
            "عرض مجموعات الإيرادات",
            async (data) => {
                if (current !== epoch || !actor || !requestForm.isConnected || document.hidden) return;
                const vendor = Number(data.vendor);
                if (!Number.isSafeInteger(vendor) || vendor < 1 ||
                    (actor.role === "vendor" && vendor !== Number(actor.id)))
                    throw Error("Invalid vendor account.");
                const currency = String(data.currency).toUpperCase();
                if (!/^[A-Z]{3}$/.test(currency))
                    throw Error("Invalid three-letter currency.");
                const query = new URLSearchParams({
                    currency,
                    from: String(data.from),
                    to: String(data.to),
                });
                const requestGeneration = ++reportGeneration;
                // Clear private results before the request so revoked sessions
                // cannot retain an earlier report or downloadable CSV on errors.
                results.replaceChildren();
                const report = await api(
                    "commercial/vendors/" + vendor + "/revenue-cohorts?" + query,
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (
                    current !== epoch ||
                    requestGeneration !== reportGeneration ||
                    !results.isConnected ||
                    document.hidden
                ) return;
                validateRevenueCohort(report, vendor, currency, query.get("from"), query.get("to"));
                const metrics = [
                    ["invoice_count", "Invoices", "الفواتير"],
                    ["invoiced_minor", "Issued invoices", "الفواتير الصادرة"],
                    ["vendor_allocated_minor", "Vendor allocation", "حصة المشغل"],
                    ["commission_allocated_minor", "Commission allocation", "حصة العمولة"],
                    ["paid_minor", "Collected", "المحصل"],
                    ["refunded_minor", "Refunds", "الاستردادات"],
                    ["vendor_refunded_minor", "Vendor refunds", "استردادات المشغل"],
                    ["unpaid_credited_minor", "Unpaid credits", "الإشعارات غير المدفوعة"],
                    ["vendor_credited_minor", "Vendor credits", "إشعارات المشغل"],
                    ["net_collected_minor", "Net collected", "صافي التحصيل"],
                    ["net_vendor_allocated_minor", "Net vendor allocation", "صافي حصة المشغل"],
                ];
                const renderMetrics = (target, row) => {
                    for (const [field, en, ar] of metrics) {
                        const line = document.createElement("p");
                        line.dataset.revenueMetric = field;
                        line.append(translated("span", en + ": ", ar + ": "),
                            text("span", field === "invoice_count" ? String(row[field]) :
                                money(row[field], currency, report.minor_units)));
                        target.append(line);
                    }
                };
                results.replaceChildren(
                    translated("h5", "Invoice-cohort totals (simulated)", "إجماليات مجموعات الفواتير (تجريبية)"),
                    translated("p", "Not a cashflow statement, bank reconciliation or payable balance.",
                        "ليس كشف تدفق نقدي أو مطابقة مصرفية أو رصيداً مستحقاً للصرف."),
                );
                if (report.as_of) {
                    const cutoff = document.createElement("p");
                    cutoff.append(translated("span", "Source as of (UTC): ", "وقت المصدر (UTC): "),
                        text("span", report.as_of));
                    results.append(cutoff);
                }
                const totals = document.createElement("section");
                totals.dataset.revenueTotals = "true";
                renderMetrics(totals, report.totals);
                results.append(totals);
                for (const row of report.data) {
                    const entry = document.createElement("details");
                    entry.className = "finance-row";
                    entry.dataset.revenueDate = row.date_utc;
                    entry.append(text("summary", row.date_utc + " · " +
                        money(row.net_collected_minor, currency, report.minor_units)));
                    renderMetrics(entry, row);
                    results.append(entry);
                }
                const link = translated("a", "Download private CSV", "تنزيل CSV خاص");
                link.href = "/api/commercial/vendors/" + vendor +
                    "/revenue-cohorts/export?" + query;
                link.download = "simulated-vendor-invoice-cohorts.csv";
                link.onclick = event => {
                    if (current !== epoch || requestGeneration !== reportGeneration ||
                        !link.isConnected || document.hidden) event.preventDefault();
                };
                results.append(translated("p", "CSV rechecks the current source for this period.",
                    "يعيد ملف CSV التحقق من المصدر الحالي لهذه الفترة."), link);
            },
        );
        const clearResult = () => {
            reportGeneration++;
            results.replaceChildren();
        };
        requestForm.addEventListener("input", clearResult);
        requestForm.addEventListener("change", clearResult);
        document.addEventListener(
            "visibilitychange",
            () => {
                if (document.hidden) clearResult();
            },
            { signal: controller.signal },
        );
        area.append(results);
    }

    function financeApprovals(parent, current) {
        // All actions remain in their original canonical workflows.
        const list = document.createElement("section");
        list.dataset.financeApprovalQueue = "true";
        const select = document.createElement("select");
        select.setAttribute("aria-label", "Finance approval kind");
        for (const [key, en, ar] of [
            ["all", "All pending", "جميع الطلبات المعلقة"],
            ["refund", "Refund reviews", "مراجعات الاسترداد"],
            ["settlement", "Settlement approvals", "موافقات التسوية"],
            ["adjustment", "Adjustment approvals", "موافقات التعديل"],
        ]) {
            const o = translated("option", en, ar);
            o.value = key;
            select.append(o);
        }
        const currency = document.createElement("input");
        currency.type = "text";
        currency.maxLength = 3;
        currency.autocomplete = "off";
        currency.dataset.financeReviewCurrency = "true";
        const currencyLabel = document.createElement("label");
        currencyLabel.append(translated("span", "Review currency (optional)", "عملة المراجعة (اختياري)"), currency);
        const vendor = document.createElement("input");
        vendor.type = "text";
        vendor.inputMode = "numeric";
        vendor.maxLength = 16;
        vendor.autocomplete = "off";
        vendor.dataset.financeReviewVendor = "true";
        const vendorLabel = document.createElement("label");
        vendorLabel.append(translated("span", "Review vendor ID (optional)", "معرف مورد المراجعة (اختياري)"), vendor);
        parent.append(
            translated(
                "p",
                "Read-only TEST approvals; amounts are not bank payments or payout instructions.",
                "مراجعة موافقات اختبارية للقراءة فقط؛ المبالغ ليست مدفوعات مصرفية أو أوامر صرف.",
            ),
            select,
            currencyLabel,
            vendorLabel,
            list,
        );
        let generation = 0;
        const clear = () => {
            generation++;
            list.replaceChildren();
        };
        select.addEventListener("change", clear);
        currency.addEventListener("input", clear);
        vendor.addEventListener("input", clear);
        document.addEventListener(
            "visibilitychange",
            () => {
                if (document.hidden) {
                    clear();
                    currency.value = "";
                    vendor.value = "";
                    select.value = "all";
                }
            },
            { signal: controller.signal },
        );
        async function show(page = 1) {
            const version = ++generation;
            list.replaceChildren();
            const kind = select.value;
            const cur = currency.value.trim().toUpperCase();
            const owner = vendor.value.trim();
            if (!["all", "refund", "settlement", "adjustment"].includes(kind) ||
                (cur && !/^[A-Z]{3}$/.test(cur)) ||
                (owner && (!/^[1-9][0-9]{0,15}$/.test(owner) ||
                    !Number.isSafeInteger(Number(owner)))) ||
                !Number.isSafeInteger(page) || page < 1 || page > 10000)
                throw Error("Invalid finance review filters.");
            const query = new URLSearchParams({
                kind, per_page: "20", page: String(page),
            });
            if (cur) query.set("currency", cur);
            if (owner) query.set("vendor_id", owner);
            const payload = await api(
                "finance/approval-queue?" + query.toString(),
                "GET",
                undefined,
                controller.signal,
            );
            if (current !== epoch || version !== generation ||
                document.hidden || !list.isConnected) return;
            if (payload.read_only !== true || payload.admin_only !== true ||
                payload.real_money_moved !== false ||
                payload.mutations_performed !== false ||
                payload.not_a_payment_or_payout_authority !== true ||
                !Array.isArray(payload.data) ||
                !Number.isSafeInteger(payload.total) || payload.total < 0 ||
                !Number.isSafeInteger(payload.current_page) ||
                !Number.isSafeInteger(payload.last_page) ||
                !Number.isSafeInteger(payload.per_page) ||
                payload.current_page !== page || payload.per_page !== 20 ||
                payload.last_page !== Math.max(1, Math.ceil(payload.total / 20)) ||
                payload.data.length !== Math.min(20, Math.max(0, payload.total - (page - 1) * 20)) ||
                !payload.summary ||
                payload.summary.scope !==
                    "ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE" ||
                payload.summary.currency_and_precision_isolated !== true ||
                payload.summary.not_a_payment_or_payout_authority !== true ||
                !Array.isArray(payload.summary.groups) ||
                payload.summary.groups.length > 200)
                throw Error("Financial approval queue source cannot be verified.");

            const groupKeys = new Map();
            let reviewed = 0;
            const grouped = document.createElement("section");
            grouped.dataset.financeApprovalGroupSummary = "true";
            grouped.append(
                translated(
                    "h5",
                    "All filtered pending approvals by source, currency and precision",
                    "جميع الموافقات المعلقة المصفاة حسب المصدر والعملة والدقة",
                ),
                translated(
                    "p",
                    "Separate signed source amounts per review category. Not cash, payout or authority to approve.",
                    "المبالغ المسجلة بإشارتها منفصلة لكل فئة مراجعة؛ وليست نقداً أو صرفاً أو صلاحية للموافقة.",
                ),
            );
            for (const group of payload.summary.groups) {
                if (!group ||
                    !["refund", "settlement", "adjustment"].includes(group.kind) ||
                    (kind !== "all" && group.kind !== kind) ||
                    !/^[A-Z]{3}$/.test(group.currency) ||
                    (cur && group.currency !== cur) ||
                    !Number.isSafeInteger(group.minor_units) ||
                    group.minor_units < 0 || group.minor_units > 3 ||
                    !Number.isSafeInteger(group.review_count) ||
                    group.review_count < 1 ||
                    !Number.isSafeInteger(group.signed_amount_minor) ||
                    (group.kind !== "adjustment" && group.signed_amount_minor < 0) ||
                    group.basis !== "PENDING_RECORDS_NOT_FINANCIAL_BALANCE")
                    throw Error("Untrusted financial approval summary.");
                const identity = [
                    group.kind, group.currency, group.minor_units,
                ].join(":");
                if (groupKeys.has(identity))
                    throw Error("Duplicated financial approval summary.");
                groupKeys.set(identity, group.review_count);
                reviewed += group.review_count;
                if (!Number.isSafeInteger(reviewed))
                    throw Error("Approval count exceeds exact bounds.");
                const groupLine = document.createElement("article");
                groupLine.dataset.financeApprovalGroup = group.kind;
                groupLine.className = "finance-row";
                groupLine.append(
                    text("strong", group.kind + " · " + group.currency +
                        " · " + group.minor_units + " digits"),
                    text("p", group.review_count +
                        " pending / قيد المراجعة · " +
                        group.signed_amount_minor + " minor units / وحدات صغرى"),
                );
                grouped.append(groupLine);
            }
            if (reviewed !== payload.total)
                throw Error("Approval summary differs from filtered source count.");

            const rows = [];
            const seen = new Set();
            const pageGroups = new Map();
            for (const item of payload.data) {
                if (!item || !["refund", "settlement", "adjustment"].includes(item.kind) ||
                    (kind !== "all" && item.kind !== kind) ||
                    !Number.isSafeInteger(item.record_id) || item.record_id < 1 ||
                    !Number.isSafeInteger(item.vendor_id) || item.vendor_id < 1 ||
                    (owner && String(item.vendor_id) !== owner) ||
                    !Number.isSafeInteger(item.amount_minor) ||
                    (item.kind !== "adjustment" && item.amount_minor < 0) ||
                    !/^[A-Z]{3}$/.test(item.currency) || (cur && item.currency !== cur) ||
                    (item.minor_units !== null &&
                        (!Number.isSafeInteger(item.minor_units) ||
                         item.minor_units < 0 || item.minor_units > 3)) ||
                    (item.kind === "refund"
                        ? !Number.isSafeInteger(item.invoice_id) || item.invoice_id < 1
                        : item.invoice_id !== null) ||
                    item.status !== (item.kind === "adjustment" ? "awaiting_approval" : "pending"))
                    throw Error("Unverified financial review item.");
                const id = item.kind + ":" + item.record_id;
                if (seen.has(id)) throw Error("Duplicated financial review item.");
                seen.add(id);
                const scope = [item.kind, item.currency, item.minor_units ?? 0].join(":");
                const count = (pageGroups.get(scope) || 0) + 1;
                if (!groupKeys.has(scope) || count > groupKeys.get(scope))
                    throw Error("Financial review item is outside the summary scope.");
                pageGroups.set(scope, count);
                const line = document.createElement("article");
                line.className = "finance-row";
                line.dataset.financeApprovalKind = item.kind;
                line.append(
                    text("strong", item.kind + " #" + item.record_id),
                    text("p", item.currency + " · " + String(item.amount_minor) +
                        " minor units / وحدات صغرى"),
                    text("p", "Vendor #" + item.vendor_id + " · " + item.status),
                );
                rows.push(line);
            }
            // Commit private DOM only after every row and summary is verified.
            list.replaceChildren(
                translated("h4", "Pending financial reviews", "المراجعات المالية المعلقة"),
                text("p", String(payload.total) + " · Pending / قيد المراجعة"),
                grouped,
                ...rows,
            );
            const pager = document.createElement("div");
            pager.className = "finance-pagination";
            pager.append(text("p", String(payload.current_page) + " / " + String(payload.last_page)));
            if (payload.current_page > 1)
                button(pager, "Previous finance reviews", "المراجعات السابقة",
                    () => show(payload.current_page - 1));
            if (payload.current_page < payload.last_page && payload.current_page < 10000)
                button(pager, "Next finance reviews", "المراجعات التالية",
                    () => show(payload.current_page + 1));
            list.append(pager);
            const exportLink = translated(
                "a",
                "Download current approval page CSV",
                "تنزيل صفحة الموافقات الحالية CSV",
            );
            exportLink.href = "/api/finance/approval-queue/export?" + query.toString();
            exportLink.download = "simulated-pending-finance-approvals.csv";
            list.append(exportLink);
        }
        button(parent, "Load pending finance reviews", "عرض المراجعات المالية المعلقة", () => show(1));
    }
    async function settlements(parent, current) {
        const activeCache = cache,
            key = "commercial/settlements?";
        const list =
            activeCache.get(key) || (await api("commercial/settlements"));
        if (current !== epoch) return;
        activeCache.set(key, list);
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
                // This view is read-only. The server's settlement service owns eligibility,
                // reservations, refund debt and all canonical currency calculations.
                const vendorId = String(data.vendor);
                const currency = String(data.currency).toUpperCase();
                if (!/^[0-9]{1,18}$/.test(vendorId) || !/^[A-Z]{3}$/.test(currency))
                    throw Error("Invalid vendor or currency.");
                const earningsPane = document.createElement("section");
                earningsPane.dataset.vendorEarnings = "true";
                detail.append(earningsPane);
                async function showEarnings(page = 1) {
                    const query = new URLSearchParams({
                        currency, page: String(page), per_page: "10",
                    });
                    const path = "commercial/vendors/" + vendorId + "/earnings?" + query;
                    const result = await api(path, "GET", undefined, controller.signal);
                    if (current !== epoch || !detail.isConnected) return;
                    earningsPane.replaceChildren(
                        translated("h4", "Itemized vendor earnings", "تفاصيل أرباح المشغل"),
                        translated("p",
                            "Test evidence only · no external bank settlement verified",
                            "سجلات اختبار فقط · لا توجد تسوية بنكية مؤكدة"),
                        text("p", "All-invoice availability, including adjustments: " +
                            money(result.overall_available_minor, result.currency, result.minor_units)),
                    );
                    for (const invoice of result.data || []) {
                        const row = document.createElement("article");
                        row.className = "finance-row";
                        row.dataset.earningsInvoiceId = String(invoice.invoice_id);
                        const amount = (minor) =>
                            money(minor, invoice.currency, invoice.minor_units);
                        row.append(
                            text("h5", invoice.invoice_number),
                            text("p", amount(invoice.vendor_allocated_minor) + " · Vendor allocation / حصة المشغل"),
                            text("p", amount(invoice.commission_minor) + " · Commission / العمولة"),
                            text("p", amount(invoice.net_collected_minor) + " · Net collected / صافي التحصيل"),
                            text("p", amount(invoice.pending_reserved_minor) + " · Pending settlement / تسوية معلقة"),
                            text("p", amount(invoice.completed_settlement_minor) + " · Completed test settlement / تسوية اختبارية"),
                            text("p", amount(invoice.available_minor) + " · Available or debt / المتاح أو الدين"),
                        );
                        earningsPane.append(row);
                    }
                    if (!(result.data || []).length)
                        earningsPane.append(translated("p", "No invoices in this page.", "لا توجد فواتير في هذه الصفحة."));
                    const pager = document.createElement("div");
                    pager.className = "finance-pagination";
                    pager.append(text("p", String(result.current_page) + " / " + String(result.last_page)));
                    if (result.current_page > 1)
                        button(pager, "Previous earnings", "الأرباح السابقة",
                            () => showEarnings(result.current_page - 1));
                    if (result.current_page < result.last_page)
                        button(pager, "Next earnings", "الأرباح التالية",
                            () => showEarnings(result.current_page + 1));
                    earningsPane.append(pager);
                    const link = translated("a", "Download earnings CSV", "تنزيل أرباح CSV");
                    link.href = "/api/commercial/vendors/" + vendorId + "/earnings/export?" + query;
                    link.download = "simulated-vendor-earnings-page.csv";
                    earningsPane.append(link);
                }
                button(detail, "View itemized earnings", "عرض تفاصيل الأرباح",
                    () => showEarnings(1));
                const auditPane = document.createElement("section");
                auditPane.dataset.financialIntegrity = "true";
                detail.append(auditPane);
                button(detail, "Inspect accessible ledger integrity", "فحص سلامة السجل المالي المتاح",
                    async () => {
                        // Admin scans all accessible invoices in this currency, not only
                        // the selected vendor. Vendors remain scoped server-side.
                        const query = new URLSearchParams({ currency, per_page: "10" });
                        const report = await api("finance/reconciliation-audit?" + query,
                            "GET", undefined, controller.signal);
                        if (current !== epoch || !detail.isConnected) return;
                        auditPane.replaceChildren(
                            translated("h4", "Internal ledger integrity", "سلامة السجل المالي الداخلي"),
                            translated("p",
                                "Simulated source-only audit, not bank or PSP reconciliation",
                                "تدقيق داخلي لسجلات اختبارية وليس مطابقة مصرفية"),
                        );
                        for (const item of report.data || []) {
                            const row = document.createElement("article");
                            row.className = "finance-row";
                            row.dataset.auditInvoiceId = String(item.invoice_id);
                            row.append(
                                text("h5", item.invoice_number),
                                text("p", item.status + " · " + (item.issues || []).join(", ")),
                            );
                            auditPane.append(row);
                        }
                        if (!(report.data || []).length)
                            auditPane.append(translated("p", "No matching invoices.", "لا توجد فواتير مطابقة."));
                        const link = translated("a", "Download integrity CSV", "تنزيل تقرير السلامة CSV");
                        link.href = "/api/finance/reconciliation-audit/export?" + query;
                        link.download = "simulated-finance-reconciliation-page.csv";
                        auditPane.append(link);
                    });
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
