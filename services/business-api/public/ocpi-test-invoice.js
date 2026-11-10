/* Separate explicit TEST invoice consent. Commands and imported CDRs never bill. */
(() => {
    const pending = new Map(),
        prefix = "ev.ocpi.test-invoice.identity.v1.";
    let epoch = 0,
        close = null;
    const clearIdentity = (scope) => {
        pending.delete(scope);
        try {
            sessionStorage.removeItem(prefix + scope);
        } catch {}
    };
    window.resetOcpiTestInvoiceViews = (owner) => {
        epoch++;
        close?.();
        close = null;
        for (const scope of pending.keys())
            if (!owner || !scope.startsWith(owner + ".")) pending.delete(scope);
        try {
            for (const key of Object.keys(sessionStorage))
                if (
                    key.startsWith(prefix) &&
                    (!owner || !key.startsWith(prefix + owner + "."))
                )
                    sessionStorage.removeItem(key);
        } catch {}
    };
    window.mountOcpiTestInvoice = ({
        container,
        api,
        ui,
        actor,
        orderId,
        current,
        openInvoice,
    }) => {
        close?.();
        const viewer = actor()?.id,
            scope = viewer + "." + orderId,
            version = ++epoch;
        const owned = () =>
            version === epoch &&
            current() &&
            actor()?.id === viewer &&
            container.isConnected;
        const alive = () => owned() && !document.hidden;
        version === epoch &&
            current() &&
            actor()?.id === viewer &&
            container.isConnected;
        const t = (tag, en, ar) => {
            window.EV_MESSAGES[en] ||= { en, ar };
            return ui(tag, en);
        };
        const raw = (tag, value) => {
            const n = document.createElement(tag);
            n.textContent = value ?? "—";
            return n;
        };
        const button = (parent, en, ar, action) => {
            const b = t("button", en, ar);
            b.type = "button";
            b.onclick = async () => {
                b.disabled = true;
                try {
                    await action();
                } catch {
                    if (alive()) failure();
                } finally {
                    if (b.isConnected) b.disabled = false;
                }
            };
            parent.append(b);
            return b;
        };
        const heading = () =>
            container.replaceChildren(
                t(
                    "h4",
                    "Separate simulated invoice consent",
                    "موافقة منفصلة على فاتورة تجريبية",
                ),
                t(
                    "p",
                    "This creates an unpaid simulated invoice for a completed approved partner test session. It does not collect money or verify physical charging.",
                    "ينشئ هذا فاتورة تجريبية غير مدفوعة لجلسة اختبار مكتملة لدى شريك معتمد للاختبار. لا يُحصّل المال ولا يثبت شحناً فعلياً.",
                ),
            );
        const failure = () => {
            heading();
            container.append(
                t(
                    "p",
                    "Invoice outcome unconfirmed. Review the source order or resolve the saved request before a new consent.",
                    "نتيجة الفاتورة غير مؤكدة. راجع طلب المصدر أو احسم الطلب المحفوظ قبل موافقة جديدة.",
                ),
            );
            savedControls();
        };
        const open = (id) =>
            button(
                container,
                "View simulated invoice",
                "عرض الفاتورة التجريبية",
                () => openInvoice(id),
            );
        async function resolve() {
            const saved = pending.get(scope);
            if (!saved || !alive()) return;
            if (
                !confirm(
                    document.documentElement.lang === "ar"
                        ? "إذا كانت الفاتورة موجودة فسيتم عرضها. وإلا فسيُلغى هذا الطلب المحفوظ غير المُصدر. لا يحذف هذا فاتورة ولا يُرجع مالاً."
                        : "If an invoice exists, open it. Otherwise cancel this saved unissued request. This never deletes an invoice or refunds money.",
                )
            )
                return;
            const result = await api(
                `roaming/sandbox-orders/${orderId}/test-invoice-resolve`,
                "POST",
                { request_key: saved.key, cancel_unissued: true },
            );
            if (!alive()) return;
            if (
                result.order_id !== orderId ||
                !["issued", "cancelled_unissued"].includes(result.state) ||
                result.financial_records_changed !== false ||
                result.real_payment_processed !== false ||
                (result.state === "issued" &&
                    (!Number.isInteger(result.invoice_id) ||
                        result.invoice_id < 1))
            )
                throw Error("Invalid resolution");
            clearIdentity(scope);
            heading();
            if (result.state === "issued") open(result.invoice_id);
            else {
                container.append(
                    t(
                        "p",
                        "Unissued saved request cancelled. No invoice or payment was changed.",
                        "أُلغي الطلب المحفوظ غير المُصدر. لم تتغير فاتورة أو دفعة.",
                    ),
                );
                button(
                    container,
                    "Refresh invoice preview",
                    "تحديث معاينة الفاتورة",
                    load,
                );
            }
        }
        function savedControls() {
            const saved = pending.get(scope);
            if (!saved) return;
            if (saved.body)
                button(
                    container,
                    "Retry same simulated invoice request",
                    "إعادة نفس طلب الفاتورة التجريبية",
                    send,
                );
            button(
                container,
                "Resolve saved invoice request",
                "حسم طلب الفاتورة المحفوظ",
                resolve,
            );
        }
        async function send() {
            const saved = pending.get(scope);
            if (!alive() || !saved?.body) return;
            container
                .querySelectorAll("button,input")
                .forEach((n) => (n.disabled = true));
            try {
                const result = await api(
                    `roaming/sandbox-orders/${orderId}/test-invoice`,
                    "POST",
                    { ...saved.body, request_key: saved.key },
                );
                if (!alive()) return;
                if (
                    result.billing_origin !== "OCPI_TEST_EXPLICIT_CONSENT" ||
                    result.roaming_order_id !== orderId ||
                    result.invoice?.evidence !== "SIMULATED" ||
                    result.real_payment_processed !== false ||
                    !Number.isInteger(result.invoice.id) ||
                    result.invoice.id < 1 ||
                    result.invoice.total_minor !==
                        saved.body.expected_total_minor ||
                    result.invoice.currency !== saved.body.expected_currency
                )
                    throw Error("Unconfirmed invoice");
                clearIdentity(scope);
                heading();
                container.append(
                    t(
                        "p",
                        "Simulated invoice created. No money collected.",
                        "أُنشئت الفاتورة التجريبية. لم يُحصّل أي مال.",
                    ),
                );
                open(result.invoice.id);
            } catch {
                if (alive()) failure();
            }
        }
        async function load() {
            if (!alive()) return;
            heading();
            if (!pending.has(scope)) {
                try {
                    const key = sessionStorage.getItem(prefix + scope);
                    if (key && /^[a-f0-9-]{36}$/i.test(key))
                        pending.set(scope, { key, body: null });
                } catch {}
            }
            if (pending.has(scope)) {
                failure();
                return;
            }
            try {
                const order = await api(
                    `roaming/sandbox-orders/${orderId}`,
                    "GET",
                );
                if (!alive()) return;
                if (
                    order.test_invoice_id &&
                    order.local_invoice_created === true &&
                    order.test_invoice_evidence ===
                        "SIMULATED_CANONICAL_LEDGER" &&
                    order.real_payment_processed === false
                ) {
                    open(order.test_invoice_id);
                    return;
                }
                const p = await api(
                    `roaming/sandbox-orders/${orderId}/test-invoice-preview`,
                    "GET",
                );
                if (!alive()) return;
                if (
                    p.evidence !== "SIMULATED" ||
                    p.physical_charging_verified !== false ||
                    p.real_payment_processed !== false ||
                    p.automatic_charge !== false ||
                    p.terms_version !== "ocpi-simulated-invoice-v1" ||
                    !/^[A-Z]{3}$/.test(p.currency) ||
                    !Number.isInteger(p.minor_units) ||
                    p.minor_units < 0 ||
                    p.minor_units > 3 ||
                    typeof p.preview_token !== "string" ||
                    p.preview_token.length > 2048
                )
                    throw Error("Invalid preview");
                for (const key of [
                    "total_minor",
                    "subtotal_minor",
                    "tax_minor",
                    "energy_wh",
                    "rate_minor_per_kwh",
                    "tax_bps",
                ])
                    if (!Number.isSafeInteger(p[key]) || p[key] < 0)
                        throw Error("Inexact preview");
                if (p.total_minor < 1 || p.total_minor > 2000000000)
                    throw Error("Invalid total");
                container.append(
                    raw("p", p.provider_name + " · " + p.location_name),
                );
                for (const [label, value] of [
                    [
                        t("span", "Energy (Wh)", "الطاقة (واط ساعة)"),
                        p.energy_wh,
                    ],
                    [
                        t("span", "Net amount", "المبلغ الصافي"),
                        EvMoney.format(p.subtotal_minor, p.minor_units) +
                            " " +
                            p.currency,
                    ],
                    [
                        t("span", "Tax", "الضريبة"),
                        EvMoney.format(p.tax_minor, p.minor_units) +
                            " " +
                            p.currency,
                    ],
                    [
                        t("span", "Invoice total", "إجمالي الفاتورة"),
                        EvMoney.format(p.total_minor, p.minor_units) +
                            " " +
                            p.currency,
                    ],
                ]) {
                    const line = document.createElement("p");
                    line.append(label, raw("span", " " + value));
                    container.append(line);
                }
                const form = document.createElement("form");
                form.dataset.ocpiInvoiceConsent = "";
                const consent = t(
                    "label",
                    "I accept this exact simulated invoice amount under the approved test billing terms. This is separate from charging consent and collects no money.",
                    "أوافق على مبلغ هذه الفاتورة التجريبية بالضبط وفق شروط الفوترة التجريبية المعتمدة. هذه موافقة منفصلة عن الشحن ولا تُحصّل مالاً.",
                );
                const check = document.createElement("input");
                check.type = "checkbox";
                check.required = true;
                consent.prepend(check);
                const submit = t(
                    "button",
                    "Create simulated invoice",
                    "إنشاء فاتورة تجريبية",
                );
                submit.type = "submit";
                form.append(consent, submit);
                container.append(form);
                form.onsubmit = async (e) => {
                    e.preventDefault();
                    if (!alive() || !check.checked || pending.has(scope))
                        return;
                    const key = crypto.randomUUID();
                    const body = Object.freeze({
                        preview_token: p.preview_token,
                        terms_version: p.terms_version,
                        consent: true,
                        expected_total_minor: p.total_minor,
                        expected_currency: p.currency,
                        expected_minor_units: p.minor_units,
                        expected_energy_wh: p.energy_wh,
                    });
                    pending.set(scope, { key, body });
                    // Persist only an opaque request identity, never amounts, comments,
                    // signed preview data or partner credentials in browser storage.
                    try {
                        sessionStorage.setItem(prefix + scope, key);
                    } catch {}
                    await send();
                };
            } catch {
                if (alive()) {
                    heading();
                    container.append(
                        t(
                            "p",
                            "Approved test invoice preview unavailable. No invoice was created.",
                            "معاينة الفاتورة التجريبية المعتمدة غير متاحة. لم تُنشأ فاتورة.",
                        ),
                    );
                    button(
                        container,
                        "Refresh invoice preview",
                        "تحديث معاينة الفاتورة",
                        load,
                    );
                }
            }
        }
        const inactive = () => {
            if (document.hidden && owned()) {
                container.replaceChildren(
                    t(
                        "p",
                        "Private invoice view paused. Refresh to reload; requests are never sent on resume.",
                        "عُرض الفاتورة الخاص متوقف مؤقتاً. حدّث لإعادة التحميل؛ لا تُرسل طلبات عند الاستئناف.",
                    ),
                );
                button(
                    container,
                    "Refresh invoice preview",
                    "تحديث معاينة الفاتورة",
                    load,
                );
            }
        };
        document.addEventListener("visibilitychange", inactive);
        close = () => {
            document.removeEventListener("visibilitychange", inactive);
            container.replaceChildren();
        };
        load();
    };
})();
