/* Corporate allocations consume existing fleet identities and immutable charging invoices. */
window.mountCorporateBilling = ({ container, api, ui, actor }) => {
    const identity = actor()?.id,
        role = actor()?.role,
        controller = new AbortController();
    let generation = 0,
        busy = false,
        selectedFleet = 0,
        canManage = false;
    const alive = () => container.isConnected && actor()?.id === identity;
    const observer = new MutationObserver(() => {
        if (!alive()) {
            generation++;
            controller.abort();
            observer.disconnect();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const text = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    };
    const status = text("p", ""),
        content = document.createElement("div");
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    container.dataset.corporateBilling = "true";
    container.append(
        t("h3", "Corporate billing", "الفوترة المؤسسية"),
        t(
            "p",
            "Allocate your own charging invoices to an accepted company agreement. Statements preserve the original invoices and tax. Collection is explicitly simulated; no real money or credit is issued.",
            "خصص فواتير شحنك لاتفاقية شركة مقبولة. تحتفظ الكشوف بالفواتير والضرائب الأصلية. التحصيل محاكاة صريحة؛ لا تُصدر أموال أو ائتمان حقيقي.",
        ),
        status,
    );
    async function request(path, method = "GET", values) {
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        return api(path, method, values, controller.signal);
    }
    async function perform(action) {
        if (busy || !alive()) return;
        busy = true;
        const old = new Map();
        container.setAttribute("aria-busy", "true");
        for (const field of container.querySelectorAll(
            "button,input,select,textarea",
        )) {
            old.set(field, field.disabled);
            field.disabled = true;
        }
        try {
            status.textContent = "";
            await action();
        } catch (error) {
            if (
                alive() &&
                !["AbortError", "SessionChangedError"].includes(error.name)
            )
                status.textContent = error.message;
        } finally {
            busy = false;
            if (alive()) {
                container.removeAttribute("aria-busy");
                for (const [field, disabled] of old)
                    if (field.isConnected) field.disabled = disabled;
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
    function field(parent, name, en, ar, type = "text", value = "", min) {
        const label = document.createElement("label"),
            input = document.createElement("input");
        label.append(t("span", en, ar));
        input.name = name;
        input.type = type;
        input.required = true;
        input.maxLength = 200;
        input.value = value;
        if (min !== undefined) input.min = min;
        if (type === "number") input.step = "1";
        label.append(input);
        parent.append(label);
        return input;
    }
    function form(parent, en, ar, build, submit) {
        const f = document.createElement("form");
        f.className = "finance-form";
        f.dataset.corporateForm = en;
        build(f);
        const b = t("button", en, ar);
        b.type = "submit";
        f.append(b);
        let key = crypto.randomUUID();
        const rotate = () => (key = crypto.randomUUID());
        f.addEventListener("input", rotate);
        f.addEventListener("change", rotate);
        f.onsubmit = (event) => {
            event.preventDefault();
            if (!f.reportValidity()) return;
            const values = Object.fromEntries(new FormData(f));
            // Capture before the busy fence disables controls. Retain the same key
            // after an uncertain response; changing economic input rotates it.
            perform(() => submit(values, key, f));
        };
        parent.append(f);
        return f;
    }
    function money(amount, currency, digits) {
        return `${currency} ${EvMoney.format(Number(amount), Number(digits))}`;
    }
    function date(parent, value) {
        if (!value) return;
        const n = document.createElement("time");
        n.dateTime = /Z$|[+-]\d\d:\d\d$/.test(value)
            ? value
            : value.replace(" ", "T") + "Z";
        n.dataset.corporateDate = "";
        n.textContent = new Date(n.dateTime).toLocaleString(
            document.documentElement.lang === "ar" ? "ar" : "en",
        );
        parent.append(n);
        localizeDates();
    }
    function localizeDates() {
        if (!alive()) return;
        for (const n of container.querySelectorAll("time[data-corporate-date]"))
            n.textContent = new Date(n.dateTime).toLocaleString(
                document.documentElement.lang === "ar" ? "ar" : "en",
            );
    }
    window.addEventListener("ev:locale", localizeDates, {
        signal: controller.signal,
    });
    function empty(parent, en, ar) {
        parent.append(t("p", en, ar));
    }
    function pager(parent, data, action) {
        if (data.last_page <= 1) return;
        const n = document.createElement("nav");
        n.setAttribute(
            "aria-label",
            "Corporate billing pages / صفحات الفوترة المؤسسية",
        );
        if (data.current_page > 1)
            button(n, "Previous page", "الصفحة السابقة", () =>
                action(data.current_page - 1),
            );
        n.append(text("span", `${data.current_page} / ${data.last_page}`));
        if (data.current_page < data.last_page)
            button(n, "Next page", "الصفحة التالية", () =>
                action(data.current_page + 1),
            );
        parent.append(n);
    }
    async function fleets(page = 1) {
        const seq = ++generation;
        const data = await request(`fleets?page=${page}`);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        content.append(t("h4", "Choose a company", "اختر شركة"));
        if (!data.data.length)
            empty(
                content,
                "No accepted company memberships are available. Corporate administrators can open an existing fleet by its ID.",
                "لا توجد عضويات شركة مقبولة. يمكن لمسؤولي المالية فتح أسطول موجود بمعرفه.",
            );
        for (const fleet of data.data) {
            const row = document.createElement("article");
            row.className = "finance-row";
            row.append(
                text("h4", fleet.name),
                text("p", `#${fleet.id} · ${fleet.country}`),
            );
            button(row, "Open company billing", "فتح فوترة الشركة", () =>
                agreements(fleet.id),
            );
            content.append(row);
        }
        pager(content, data, fleets);
        form(
            content,
            "Open existing company",
            "فتح شركة موجودة",
            (f) => {
                field(
                    f,
                    "fleet_id",
                    "Fleet ID",
                    "معرف الأسطول",
                    "number",
                    "",
                    1,
                );
            },
            (values) => agreements(Number(values.fleet_id)),
        );
    }
    async function agreements(fleetId) {
        const seq = ++generation;
        const result = await request(
            `commercial/corporate?fleet_id=${Number(fleetId)}`,
        );
        if (!alive() || seq !== generation) return;
        selectedFleet = Number(fleetId);
        canManage = Boolean(result.can_manage);
        content.replaceChildren();
        content.append(
            t("h4", "Company agreements", "اتفاقيات الشركة"),
            text("p", `#${selectedFleet}`),
        );
        button(content, "Choose another company", "اختيار شركة أخرى", () =>
            fleets(),
        );
        button(content, "Refresh agreements", "تحديث الاتفاقيات", () =>
            agreements(selectedFleet),
        );
        if (!result.agreements.length)
            empty(
                content,
                "No corporate agreement has been published.",
                "لم تُنشر اتفاقية مؤسسية بعد.",
            );
        for (const agreement of result.agreements) {
            const row = document.createElement("article");
            row.className = "finance-row";
            row.append(
                text("h4", agreement.legal_name),
                text("p", agreement.reference),
                text("p", agreement.terms),
            );
            row.append(
                t("span", "Credit limit", "حد الائتمان"),
                text(
                    "p",
                    money(
                        agreement.credit_limit_minor,
                        agreement.currency,
                        agreement.minor_units,
                    ),
                ),
            );
            const dates = document.createElement("p");
            date(dates, agreement.effective_from);
            dates.append(document.createTextNode(" — "));
            date(dates, agreement.expires_at);
            row.append(dates);
            row.append(
                t("span", "Payment terms in days", "أجل السداد بالأيام"),
                text("p", agreement.payment_terms_days),
            );
            if (agreement.acceptance)
                row.append(
                    t("p", "Accepted company terms", "شروط الشركة مقبولة"),
                );
            else if (canManage && role !== "admin") {
                form(
                    row,
                    "Accept company agreement",
                    "قبول اتفاقية الشركة",
                    (f) => {
                        const consent = field(
                            f,
                            "consent",
                            "I accept these company billing terms",
                            "أوافق على شروط فوترة الشركة هذه",
                            "checkbox",
                        );
                        consent.value = "1";
                    },
                    async () => {
                        await request(
                            `commercial/corporate/agreements/${agreement.id}/accept`,
                            "POST",
                            { consent: true },
                        );
                        await agreements(selectedFleet);
                    },
                );
            } else
                row.append(
                    t(
                        "p",
                        "Awaiting company owner acceptance",
                        "بانتظار موافقة مالك الشركة",
                    ),
                );
            button(row, "View corporate claims", "عرض المطالبات المؤسسية", () =>
                claims(agreement),
            );
            if (canManage)
                button(row, "View company statements", "عرض كشوف الشركة", () =>
                    statements(agreement),
                );
            if (agreement.acceptance && role !== "admin")
                button(
                    row,
                    "Allocate my charging invoice",
                    "تخصيص فاتورة شحني",
                    () => claimForm(agreement),
                );
            content.append(row);
        }
        if (role === "admin") publishForm(content);
    }
    function publishForm(parent) {
        const start = new Date(),
            end = new Date();
        end.setUTCFullYear(end.getUTCFullYear() + 1);
        parent.append(
            t("h4", "Publish company billing terms", "نشر شروط فوترة الشركة"),
        );
        form(
            parent,
            "Publish corporate agreement",
            "نشر اتفاقية مؤسسية",
            (f) => {
                field(
                    f,
                    "legal_name",
                    "Legal company name",
                    "الاسم القانوني للشركة",
                );
                const terms = field(
                    f,
                    "terms",
                    "Agreement terms",
                    "شروط الاتفاقية",
                );
                terms.maxLength = 2000;
                field(
                    f,
                    "currency",
                    "Currency",
                    "العملة",
                    "text",
                    "AED",
                ).maxLength = 3;
                field(
                    f,
                    "minor_units",
                    "Minor unit digits",
                    "منازل الوحدة النقدية الصغرى",
                    "number",
                    2,
                    0,
                ).max = 3;
                field(
                    f,
                    "credit_limit_minor",
                    "Credit limit in minor units",
                    "حد الائتمان بالوحدات الصغرى",
                    "number",
                    "",
                    1,
                ).max = 1000000000000;
                field(
                    f,
                    "payment_terms_days",
                    "Payment terms in days",
                    "أجل السداد بالأيام",
                    "number",
                    30,
                    0,
                ).max = 365;
                field(
                    f,
                    "billing_day",
                    "Intended billing day",
                    "يوم الفوترة المقصود",
                    "number",
                    1,
                    1,
                ).max = 28;
                field(
                    f,
                    "effective_from",
                    "Effective from (UTC)",
                    "ساري من (UTC)",
                    "datetime-local",
                    start.toISOString().slice(0, 16),
                );
                field(
                    f,
                    "expires_at",
                    "Expires at (UTC)",
                    "ينتهي في (UTC)",
                    "datetime-local",
                    end.toISOString().slice(0, 16),
                );
            },
            async (values, key) => {
                for (const name of [
                    "minor_units",
                    "credit_limit_minor",
                    "payment_terms_days",
                    "billing_day",
                ])
                    values[name] = Number(values[name]);
                values.effective_from = new Date(
                    values.effective_from + "Z",
                ).toISOString();
                values.expires_at = new Date(
                    values.expires_at + "Z",
                ).toISOString();
                await request(
                    `commercial/corporate/fleets/${selectedFleet}/agreements`,
                    "POST",
                    { ...values, idempotency_key: key },
                );
                await agreements(selectedFleet);
            },
        );
    }
    async function claimForm(agreement) {
        const seq = ++generation;
        const details = await request(`fleets/${selectedFleet}`);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        content.append(
            t("h4", "Allocate my invoice", "تخصيص فاتورتي"),
            text("p", agreement.reference),
        );
        button(content, "Back to agreements", "العودة للاتفاقيات", () =>
            agreements(selectedFleet),
        );
        if (!details.cost_centers.length) {
            empty(
                content,
                "The company owner must configure a cost center in the existing fleet workspace first.",
                "يجب على مالك الشركة إعداد مركز تكلفة في مساحة الأسطول أولا.",
            );
            return;
        }
        form(
            content,
            "Submit corporate claim",
            "إرسال مطالبة مؤسسية",
            (f) => {
                field(
                    f,
                    "invoice_id",
                    "My unpaid charging invoice ID",
                    "معرف فاتورة شحني غير المدفوعة",
                    "number",
                    "",
                    1,
                );
                const label = document.createElement("label"),
                    select = document.createElement("select");
                select.name = "cost_center_id";
                select.required = true;
                label.append(t("span", "Cost center", "مركز التكلفة"), select);
                for (const center of details.cost_centers) {
                    const option = text(
                        "option",
                        `${center.code} · ${center.name}`,
                    );
                    option.value = center.id;
                    select.append(option);
                }
                f.append(label);
            },
            async (values, key) => {
                await request(
                    `commercial/corporate/agreements/${agreement.id}/claims`,
                    "POST",
                    {
                        invoice_id: Number(values.invoice_id),
                        cost_center_id: Number(values.cost_center_id),
                        idempotency_key: key,
                    },
                );
                await claims(agreement);
            },
        );
    }
    async function claims(agreement, page = 1) {
        const seq = ++generation;
        const data = await request(
            `commercial/corporate/agreements/${agreement.id}/claims?page=${page}`,
        );
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        content.append(
            t("h4", "Corporate invoice claims", "مطالبات الفواتير المؤسسية"),
            text("p", agreement.reference),
        );
        button(content, "Back to agreements", "العودة للاتفاقيات", () =>
            agreements(selectedFleet),
        );
        if (!data.data.length)
            empty(
                content,
                "No invoice claims yet.",
                "لا توجد مطالبات فواتير بعد.",
            );
        const selection = new Map();
        for (const claim of data.data) {
            const row = document.createElement("article");
            row.className = "finance-row";
            row.append(
                text("h4", `#${claim.id} · ${claim.cost_center_code}`),
                text(
                    "p",
                    `#${claim.invoice_id} · ${money(claim.amount_minor, claim.currency, claim.minor_units)}`,
                ),
            );
            row.append(text("p", claim.cost_center_name));
            if (claim.decision)
                row.append(
                    t(
                        "p",
                        claim.decision.decision === "approved"
                            ? "Claim approved"
                            : "Claim rejected",
                        claim.decision.decision === "approved"
                            ? "المطالبة معتمدة"
                            : "المطالبة مرفوضة",
                    ),
                    text("p", claim.decision.reason),
                );
            else
                row.append(
                    t(
                        "p",
                        "Awaiting company approval",
                        "بانتظار اعتماد الشركة",
                    ),
                );
            if (claim.statement_id)
                row.append(
                    t("span", "Consolidated statement", "الكشف المجمع"),
                    text("p", `#${claim.statement_id}`),
                );
            if (canManage && !claim.decision)
                form(
                    row,
                    "Review corporate claim",
                    "مراجعة مطالبة مؤسسية",
                    (f) => {
                        const label = document.createElement("label"),
                            select = document.createElement("select");
                        select.name = "decision";
                        label.append(t("span", "Decision", "القرار"), select);
                        for (const [value, en, ar] of [
                            ["approved", "Approve claim", "اعتماد المطالبة"],
                            ["rejected", "Reject claim", "رفض المطالبة"],
                        ]) {
                            const option = t("option", en, ar);
                            option.value = value;
                            select.append(option);
                        }
                        f.append(label);
                        field(
                            f,
                            "reason",
                            "Review reason",
                            "سبب القرار",
                        ).maxLength = 500;
                    },
                    async (values) => {
                        await request(
                            `commercial/corporate/claims/${claim.id}/review`,
                            "POST",
                            values,
                        );
                        await claims(agreement, page);
                    },
                );
            if (
                canManage &&
                claim.decision?.decision === "approved" &&
                !claim.statement_id
            )
                selection.set(claim.id, claim);
            content.append(row);
        }
        pager(content, data, (p) => claims(agreement, p));
        if (canManage && selection.size)
            form(
                content,
                "Issue company statement",
                "إصدار كشف شركة",
                (f) => {
                    field(
                        f,
                        "period",
                        "Invoice month",
                        "شهر الفاتورة",
                        "month",
                        new Date().toISOString().slice(0, 7),
                    );
                    for (const claim of selection.values()) {
                        const input = field(
                            f,
                            `claim_${claim.id}`,
                            `Include claim #${claim.id}`,
                            `تضمين المطالبة #${claim.id}`,
                            "checkbox",
                        );
                        input.required = false;
                        input.value = claim.id;
                    }
                },
                async (values, key, f) => {
                    const ids = Array.from(
                        f.querySelectorAll('input[name^="claim_"]:checked'),
                    ).map((n) => Number(n.value));
                    if (!ids.length)
                        throw Error(
                            document.documentElement.lang === "ar"
                                ? "اختر مطالبة معتمدة واحدة على الأقل."
                                : "Select at least one approved claim.",
                        );
                    await request(
                        `commercial/corporate/agreements/${agreement.id}/statements`,
                        "POST",
                        {
                            period: values.period,
                            claim_ids: ids,
                            idempotency_key: key,
                        },
                    );
                    await statements(agreement);
                },
            );
    }
    async function statements(agreement, page = 1) {
        const seq = ++generation;
        const data = await request(
            `commercial/corporate/agreements/${agreement.id}/statements?page=${page}`,
        );
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        content.append(
            t("h4", "Company statements", "كشوف الشركة"),
            text("p", agreement.reference),
        );
        button(content, "Back to agreements", "العودة للاتفاقيات", () =>
            agreements(selectedFleet),
        );
        if (!data.data.length)
            empty(
                content,
                "No company statements have been issued.",
                "لم تُصدر كشوف شركة بعد.",
            );
        for (const statement of data.data) {
            const row = document.createElement("article");
            row.className = "finance-row";
            row.append(
                text("h4", statement.number),
                text(
                    "p",
                    `${statement.period} · ${money(statement.total_minor, statement.currency, statement.minor_units)}`,
                ),
            );
            if (statement.receipt)
                row.append(
                    t("p", "Simulated payment recorded", "سُجل دفع بالمحاكاة"),
                );
            button(
                row,
                "View statement reconciliation",
                "عرض مطابقة الكشف",
                () => statementDetail(agreement, statement.id, page),
            );
            content.append(row);
        }
        pager(content, data, (p) => statements(agreement, p));
    }
    async function statementDetail(agreement, id, page) {
        const seq = ++generation;
        const data = await request(`commercial/corporate/statements/${id}`);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        const s = data.statement;
        content.append(
            text("h4", s.number),
            text("p", money(s.total_minor, s.currency, s.minor_units)),
        );
        const states = {
            issued: ["Statement issued", "صدر الكشف"],
            overdue: ["Payment overdue", "السداد متأخر"],
            simulated_paid: [
                "Simulated payment recorded",
                "سُجل دفع بالمحاكاة",
            ],
            collection_attention: [
                "Collection requires investigation",
                "التحصيل يحتاج للتحقيق",
            ],
        };
        const state = states[data.state];
        content.append(state ? t("p", ...state) : text("p", data.state));
        date(content, s.due_at);
        button(
            content,
            "Back to company statements",
            "العودة لكشوف الشركة",
            () => statements(agreement, page),
        );
        for (const line of data.lines) {
            const row = document.createElement("article");
            row.className = "finance-row";
            row.append(
                text("h4", line.invoice_number),
                text(
                    "p",
                    `${line.cost_center_code} · ${line.cost_center_name}`,
                ),
                text("p", money(line.total_minor, s.currency, s.minor_units)),
            );
            content.append(row);
        }
        const totals = document.createElement("dl");
        for (const [en, ar, amount] of [
            ["Collected", "المحصل", data.paid_minor],
            ["Refunded", "المسترد", data.refunded_minor],
            ["Net collected", "صافي التحصيل", data.net_collected_minor],
        ]) {
            totals.append(
                t("dt", en, ar),
                text("dd", money(amount || 0, s.currency, s.minor_units)),
            );
        }
        content.append(totals);
        const link = t(
            "a",
            "Download company statement CSV",
            "تنزيل كشف الشركة CSV",
        );
        link.href = `/api/commercial/corporate/statements/${Number(id)}/export`;
        link.download = "";
        link.referrerPolicy = "no-referrer";
        content.append(link);
        if (data.receipt) content.append(text("p", data.receipt.number));
        if (
            !data.receipt &&
            Number(s.payer_id) === Number(identity) &&
            data.state !== "collection_attention"
        ) {
            const key = crypto.randomUUID();
            button(
                content,
                "Record simulated company payment",
                "تسجيل دفع شركة بالمحاكاة",
                async () => {
                    await request(
                        `commercial/corporate/statements/${id}/simulate-payment`,
                        "POST",
                        { idempotency_key: key },
                    );
                    await statementDetail(agreement, id, page);
                },
            );
        }
        if (data.credit_notes?.length) {
            content.append(
                t(
                    "h4",
                    "Original invoice credit notes",
                    "إشعارات دائنة للفواتير الأصلية",
                ),
            );
            for (const credit of data.credit_notes)
                content.append(
                    text(
                        "p",
                        `${credit.credit_note_number} · ${money(credit.amount_minor, s.currency, s.minor_units)}`,
                    ),
                );
        }
    }
    button(container, "Open corporate billing", "فتح الفوترة المؤسسية", () =>
        fleets(),
    );
    container.append(content);
};
