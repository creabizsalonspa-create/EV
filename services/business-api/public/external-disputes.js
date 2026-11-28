/* Provider dispute evidence and canonical holds; no payment proof comes from the browser. */
window.mountExternalDisputes = ({ container, api, ui, actor }) => {
    const identity = actor()?.id,
        role = actor()?.role,
        controller = new AbortController();
    const drafts = new Map();
    let generation = 0,
        busy = false,
        page = 1,
        attention = false;
    container.dataset.externalDisputes = "true";
    const alive = () => container.isConnected && actor()?.id === identity &&
        actor()?.role === role && !controller.signal.aborted;
    const observer = new MutationObserver(() => {
        if (!alive()) {
            generation++;
            controller.abort();
            drafts.clear();
            observer.disconnect();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const literal = (tag, value) => {
        const el = document.createElement(tag);
        el.textContent = String(value ?? "");
        return el;
    };
    const status = literal("p", ""),
        content = document.createElement("div");
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    if (!container.querySelector(":scope > h3"))
        container.append(t("h3", "Provider disputes", "نزاعات مزود الدفع"));
    container.append(
        t(
            "p",
            "Stripe TEST contracts only. No real chargeback or money movement is verified.",
            "عقود اختبار Stripe فقط. لم يتم التحقق من اعتراض دفع أو حركة أموال حقيقية.",
        ),
        status,
    );
    async function request(path, method = "GET", data) {
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        const result = await api(path, method, data, controller.signal);
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        return result;
    }
    async function perform(action) {
        if (busy || !alive()) return;
        busy = true;
        container.inert = true;
        container.setAttribute("aria-busy", "true");
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
                container.inert = false;
                container.removeAttribute("aria-busy");
            }
        }
    }
    function button(parent, en, ar, action) {
        const el = t("button", en, ar);
        el.type = "button";
        el.onclick = () => { if (el.isConnected && container.contains(el)) return perform(action); };
        parent.append(el);
        return el;
    }
    function date(parent, en, ar, raw) {
        if (!raw) return;
        if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw))
            raw = raw.replace(" ", "T") + "Z";
        const value = new Date(raw),
            line = document.createElement("p"),
            time = document.createElement("time");
        if (!Number.isNaN(value.getTime())) {
            time.dateTime = value.toISOString();
            time.dataset.disputeDate = "true";
        }
        time.textContent = Number.isNaN(value.getTime())
            ? String(raw)
            : value.toLocaleString(
                  document.documentElement.lang === "ar" ? "ar" : "en",
              );
        line.append(t("span", en, ar), literal("span", " · "), time);
        parent.append(line);
    }
    window.addEventListener(
        "ev:locale",
        () => {
            if (alive())
                for (const time of container.querySelectorAll(
                    "time[data-dispute-date]",
                ))
                    time.textContent = new Date(time.dateTime).toLocaleString(
                        document.documentElement.lang === "ar" ? "ar" : "en",
                    );
        },
        { signal: controller.signal },
    );
    function state(parent, value) {
        const labels = {
            needs_response: ["Evidence needed", "الأدلة مطلوبة"],
            warning_needs_response: [
                "Inquiry needs evidence",
                "الاستفسار يتطلب أدلة",
            ],
            under_review: ["Provider review", "مراجعة المزود"],
            warning_under_review: [
                "Inquiry under review",
                "الاستفسار قيد المراجعة",
            ],
            won: ["Provider reports won", "أبلغ المزود بكسب النزاع"],
            lost: [
                "Provider reports lost · financial resolution required",
                "أبلغ المزود بخسارة النزاع · يلزم حل مالي",
            ],
            warning_closed: ["Inquiry closed", "أُغلق الاستفسار"],
            sending: ["Submission in progress", "الإرسال جارٍ"],
            uncertain: [
                "Submission uncertain · inspect before retrying",
                "الإرسال غير مؤكد · تحقق قبل إعادة المحاولة",
            ],
            confirmed: ["Provider confirmed evidence", "أكد المزود الأدلة"],
        };
        const el = labels[value]
            ? t("p", ...labels[value])
            : literal("p", value);
        el.dataset.disputeState = value;
        parent.append(el);
    }
    const providerStates = ["needs_response", "warning_needs_response", "under_review",
        "warning_under_review", "warning_closed", "won", "lost"];
    const positiveId = value => Number.isSafeInteger(value) && value > 0;
    function verifyReceipt(row, scope = {}) {
        if (!row || !positiveId(row.id) || !positiveId(row.invoice_id) ||
            !positiveId(row.amount_minor) || !Number.isInteger(row.minor_units) ||
            row.minor_units < 0 || row.minor_units > 3 || typeof row.currency !== "string" || !/^[A-Z]{3}$/.test(row.currency) ||
            !providerStates.includes(row.status) ||
            row.evidence !== "STRIPE_TEST_CONTRACT" || row.real_money_moved !== false ||
            ["settlement_hold", "deadline_passed", "requires_financial_resolution"].some(
                key => typeof row[key] !== "boolean") ||
            row.requires_financial_resolution !== (row.status === "lost") ||
            (row.deadline_passed && !["needs_response", "warning_needs_response"].includes(row.status)) ||
            "provider_reference" in row || "charge_reference" in row ||
            (scope.currency && row.currency !== scope.currency) ||
            (scope.invoice_id && row.invoice_id !== Number(scope.invoice_id)) ||
            (scope.status && row.status !== scope.status))
            throw Error("Provider receipt cannot be verified.");
        return row;
    }
    function verifyPage(result, requested, size, scope, deadline = false) {
        const echoed = result?.scope;
        if (!result || !echoed || result.evidence !== "STRIPE_TEST_CONTRACT" ||
            result.real_money_moved !== false ||
            echoed.currency !== (scope.currency || null) ||
            echoed.invoice_id !== (scope.invoice_id ? Number(scope.invoice_id) : null) ||
            (!deadline && (echoed.status !== (scope.status || null) ||
                echoed.attention !== (scope.attention === "1"))) ||
            (deadline && (result.provider_actions_performed !== false ||
                result.window_hours !== Number(scope.window_hours))) ||
            result.current_page !== requested || result.per_page !== size ||
            !Number.isSafeInteger(result.total) || result.total < 0 ||
            result.last_page !== Math.max(1, Math.ceil(result.total / size)) ||
            !Array.isArray(result.data) ||
            result.data.length !== Math.min(size, Math.max(0, result.total - (requested - 1) * size)))
            throw Error("Provider review scope cannot be verified.");
        const ids = new Set();
        for (const row of result.data) {
            verifyReceipt(row, scope);
            if (ids.has(row.id)) throw Error("Duplicate provider receipt.");
            ids.add(row.id);
            if (deadline) {
                if (typeof row.has_uncertain_evidence !== "boolean" ||
                    typeof row.provider_observation_stale !== "boolean")
                    throw Error("Invalid deadline evidence.");
                const expected = row.status === "lost" ? "financial_resolution_required" :
                    row.has_uncertain_evidence ? "provider_submission_uncertain" :
                    row.deadline_passed ? "deadline_passed" : "evidence_due_soon";
                if (row.urgency !== expected ||
                    (expected === "evidence_due_soon" &&
                        (!["needs_response", "warning_needs_response"].includes(row.status) ||
                            typeof row.evidence_due_at !== "string")))
                    throw Error("Invalid deadline urgency.");
            }
        }
    }
    function summary(parent, row) {
        parent.append(
            literal(
                "h4",
                `#${row.id} · ${row.currency} ${EvMoney.format(row.amount_minor, row.minor_units)}`,
            ),
        );
        state(parent, row.status);
        const invoice = document.createElement("p");
        invoice.append(
            t("span", "Invoice", "الفاتورة"),
            literal("span", ` · #${row.invoice_id}`),
        );
        parent.append(invoice);
        date(
            parent,
            "Evidence deadline",
            "الموعد النهائي للأدلة",
            row.evidence_due_at,
        );
        date(
            parent,
            "Provider inspected",
            "فحص المزود",
            row.provider_observed_at,
        );
        if (row.settlement_hold)
            parent.append(
                t(
                    "p",
                    "Settlement held · provider reconciliation required",
                    "التسوية محتجزة · يلزم مطابقة المزود",
                ),
            );
        if (row.deadline_passed)
            parent.append(
                t(
                    "strong",
                    "Evidence deadline passed. Do not start a replacement submission.",
                    "انتهى الموعد النهائي للأدلة. لا تبدأ إرسالاً بديلاً.",
                ),
            );
        if (row.requires_financial_resolution)
            parent.append(
                t(
                    "p",
                    "Chargeback accounting is not posted automatically. A reviewed canonical financial resolution remains required.",
                    "لا تُرحّل محاسبة الاعتراض تلقائياً. لا يزال يلزم حل مالي معتمد في السجل الأساسي.",
                ),
            );
    }
    function evidenceForm(parent, row) {
        const form = document.createElement("form");
        form.className = "finance-form";
        form.dataset.disputeEvidence = String(row.id);
        form.append(
            t(
                "h4",
                "Provider evidence · administrator only",
                "أدلة المزود · للمسؤول فقط",
            ),
            t(
                "p",
                "Use relevant text only. Never enter card numbers, secrets or unnecessary personal information. File uploads are unavailable.",
                "استخدم النص ذي الصلة فقط. لا تدخل أرقام بطاقات أو أسراراً أو معلومات شخصية غير ضرورية. رفع الملفات غير متاح.",
            ),
        );
        const field = document.createElement("select");
        field.name = "field";
        for (const [key, en, ar] of [
            ["product_description", "Service description", "وصف الخدمة"],
            ["service_date", "Service date", "تاريخ الخدمة"],
            [
                "refund_policy_disclosure",
                "Refund policy disclosure",
                "الإفصاح عن سياسة الاسترداد",
            ],
            [
                "refund_refusal_explanation",
                "Refund refusal explanation",
                "توضيح رفض الاسترداد",
            ],
            ["access_activity_log", "Access activity log", "سجل نشاط الوصول"],
            [
                "uncategorized_text",
                "Other relevant evidence",
                "أدلة أخرى ذات صلة",
            ],
        ]) {
            const option = t("option", en, ar);
            option.value = key;
            field.append(option);
        }
        const selectLabel = document.createElement("label");
        selectLabel.append(t("span", "Evidence type", "نوع الدليل"), field);
        const text = document.createElement("textarea");
        text.name = "text";
        text.required = true;
        text.maxLength = 5000;
        text.rows = 5;
        const textLabel = document.createElement("label");
        textLabel.append(t("span", "Evidence text", "نص الدليل"), text);
        const submit = document.createElement("input");
        submit.type = "checkbox";
        submit.name = "submit";
        const submitLabel = document.createElement("label");
        submitLabel.append(
            submit,
            t(
                "span",
                "I authorize final submission to the TEST provider; otherwise save a provider draft",
                "أفوض الإرسال النهائي إلى مزود الاختبار؛ وإلا تُحفظ مسودة لدى المزود",
            ),
        );
        const send = t("button", "Send TEST evidence", "إرسال أدلة الاختبار");
        send.type = "submit";
        form.append(selectLabel, textLabel, submitLabel, send);
        const previous = drafts.get(row.id);
        if (previous) {
            field.value = previous.field;
            text.value = previous.text;
            submit.checked = previous.submit;
        }
        let key = previous?.key || crypto.randomUUID();
        const remember = () =>
            drafts.set(row.id, {
                field: field.value,
                text: text.value,
                submit: submit.checked,
                key,
            });
        const changed = () => {
            key = crypto.randomUUID();
            remember();
        };
        form.addEventListener("input", changed);
        form.addEventListener("change", changed);
        form.onsubmit = (event) => {
            event.preventDefault();
            if (!form.isConnected || !alive() || !form.reportValidity()) return;
            remember();
            const data = {
                idempotency_key: key,
                submit: submit.checked,
                evidence: { [field.value]: text.value },
            };
            perform(async () => {
                await request(
                    `commercial/external-disputes/${row.id}/evidence`,
                    "POST",
                    data,
                );
                drafts.delete(row.id);
                await detail(row.id);
            });
        };
        parent.append(form);
    }
    async function detail(id) {
        const version = ++generation;
        content.replaceChildren();
        const data = await request(`commercial/external-disputes/${id}`);
        if (!alive() || version !== generation) return;
        verifyReceipt(data.dispute);
        if (data.dispute.id !== id || !Array.isArray(data.events) ||
            !Array.isArray(data.evidence_submissions) || data.events.length > 500 ||
            data.evidence_submissions.length > 500 ||
            (role !== "admin" && data.evidence_submissions.length))
            throw Error("Provider receipt identity or history cannot be verified.");
        for (const operation of data.evidence_submissions) {
            if (!positiveId(operation.id) || !["sending", "uncertain", "confirmed"].includes(operation.status))
                throw Error("Invalid provider evidence receipt.");
        }
        content.replaceChildren();
        button(content, "Back to disputes", "العودة إلى النزاعات", () =>
            list(page, appliedList),
        );
        summary(content, data.dispute);
        if (role === "admin") {
            button(
                content,
                "Inspect provider outcome",
                "التحقق من نتيجة المزود",
                async () => {
                    await request(
                        `commercial/external-disputes/${id}/reconcile`,
                        "POST",
                        {},
                    );
                    await detail(id);
                },
            );
            if (
                ["needs_response", "warning_needs_response"].includes(
                    data.dispute.status,
                ) &&
                !data.dispute.deadline_passed
            )
                evidenceForm(content, data.dispute);
        }
        content.append(
            t(
                "h4",
                "Immutable provider history",
                "سجل المزود غير القابل للتغيير",
            ),
        );
        for (const event of data.events) {
            const card = document.createElement("article");
            card.className = "finance-item";
            state(card, event.status);
            date(card, "Recorded", "سُجل", event.created_at);
            content.append(card);
        }
        if (data.evidence_submissions.length)
            content.append(
                t("h4", "Evidence submissions", "عمليات إرسال الأدلة"),
            );
        for (const operation of data.evidence_submissions) {
            const card = document.createElement("article");
            card.className = "finance-item";
            state(card, operation.status);
            date(card, "Created", "أُنشئ", operation.created_at);
            date(
                card,
                "Same-key retry expires",
                "تنتهي إعادة المحاولة بالمفتاح نفسه",
                operation.retry_until,
            );
            if (
                role === "admin" &&
                ["uncertain", "sending"].includes(operation.status) &&
                !data.dispute.deadline_passed
            )
                button(
                    card,
                    "Retry stored TEST evidence",
                    "إعادة إرسال الأدلة التجريبية المحفوظة",
                    async () => {
                        // The server inspects first and reuses the encrypted original
                        // payload/provider identity. A lease or expired deadline fails closed.
                        await request(
                            `commercial/external-disputes/${id}/evidence/${Number(operation.id)}/retry`,
                            "POST",
                            {},
                        );
                        drafts.delete(id);
                        await detail(id);
                    },
                );
            content.append(card);
        }
    }
    const filterBox = document.createElement("div");
    filterBox.className = "finance-form";
    const currencyFilter = document.createElement("input"),
        invoiceFilter = document.createElement("input"),
        stateFilter = document.createElement("select"),
        windowFilter = document.createElement("select");
    currencyFilter.name = "dispute_currency";
    currencyFilter.maxLength = 3;
    invoiceFilter.name = "dispute_invoice";
    invoiceFilter.inputMode = "numeric";
    invoiceFilter.maxLength = 16;
    stateFilter.name = "dispute_status";
    windowFilter.name = "dispute_window";
    for (const [value, en, ar] of [
        ["", "All provider states", "جميع حالات المزود"],
        ["needs_response", "Evidence needed", "الأدلة مطلوبة"],
        ["warning_needs_response", "Inquiry needs evidence", "الاستفسار يتطلب أدلة"],
        ["under_review", "Provider review", "مراجعة المزود"],
        ["warning_under_review", "Inquiry under review", "الاستفسار قيد المراجعة"],
        ["warning_closed", "Inquiry closed", "أُغلق الاستفسار"],
        ["won", "Provider reports won", "أبلغ المزود بكسب النزاع"],
        ["lost", "Provider reports lost", "أبلغ المزود بخسارة النزاع"],
    ]) {
        const option = t("option", en, ar);
        option.value = value;
        stateFilter.append(option);
    }
    for (const hours of [0, 24, 48, 72, 168]) {
        const option = literal("option", hours);
        option.value = String(hours);
        windowFilter.append(option);
    }
    windowFilter.value = "48";
    for (const [input, en, ar] of [
        [currencyFilter, "Dispute currency (optional)", "عملة النزاع (اختياري)"],
        [invoiceFilter, "Invoice ID (optional)", "رقم الفاتورة (اختياري)"],
        [stateFilter, "Provider state (list only)", "حالة المزود (القائمة فقط)"],
        ...(role === "admin" ? [[windowFilter, "Deadline window in hours", "نافذة الموعد بالساعات"]] : []),
    ]) {
        const label = document.createElement("label");
        label.append(t("span", en, ar), input);
        filterBox.append(label);
    }
    function invalidateView() {
        generation++;
        content.replaceChildren();
        status.textContent = "";
    }
    filterBox.addEventListener("input", invalidateView);
    filterBox.addEventListener("change", invalidateView);
    container.append(filterBox);
    function filters(deadline = false) {
        const currency = currencyFilter.value.trim().toUpperCase(),
            invoice = invoiceFilter.value.trim();
        if ((currency && !/^[A-Z]{3}$/.test(currency)) ||
            (invoice && (!/^[1-9][0-9]{0,15}$/.test(invoice) || !positiveId(Number(invoice)))))
            throw Error("Invalid dispute filters.");
        return Object.freeze({
            ...(currency ? {currency} : {}),
            ...(invoice ? {invoice_id: invoice} : {}),
            ...(deadline ? {window_hours: windowFilter.value} :
                {...(stateFilter.value ? {status: stateFilter.value} : {}),
                    attention: attention ? "1" : "0"}),
        });
    }
    let appliedList = null;
    async function deadlineQueue(nextPage = 1, applied = null) {
        if (role !== "admin") return;
        const version = ++generation;
        content.replaceChildren();
        const scope = applied || filters(true);
        if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage > 10000 ||
            !["0", "24", "48", "72", "168"].includes(scope.window_hours))
            throw Error("Invalid deadline review scope.");
        const query = new URLSearchParams({...scope, per_page: "20", page: String(nextPage)});
        const results = await request("commercial/external-disputes/deadline-queue?" + query);
        if (!alive() || version !== generation) return;
        verifyPage(results, nextPage, 20, scope, true);
        content.replaceChildren(
            t("h4", "Provider evidence deadlines", "مواعيد أدلة المزود"),
            literal("p", scope.window_hours + " h"),
            t("p",
                "Read-only TEST triage · inspect current provider state before acting",
                "مراجعة اختبارية للقراءة فقط · تحقق من حالة المزود قبل اتخاذ إجراء"),
        );
        if (!results.data.length)
            content.append(t("p", "No approaching deadlines or unresolved cases.", "لا توجد مواعيد قريبة أو قضايا معلقة."));
        for (const item of results.data) {
            const card = document.createElement("article");
            card.className = "finance-item";
            card.dataset.disputeDeadlineId = String(item.id);
            summary(card, item);
            const urgency = {
                financial_resolution_required: ["Financial resolution required", "يلزم حل مالي"],
                provider_submission_uncertain: ["Provider submission outcome uncertain", "نتيجة تقديم الأدلة غير مؤكدة"],
                deadline_passed: ["Evidence deadline passed", "انتهى موعد تقديم الأدلة"],
                evidence_due_soon: [`Evidence due within ${scope.window_hours} hours`, `موعد تقديم الأدلة خلال ${scope.window_hours} ساعة`],
            };
            if (urgency[item.urgency])
                card.append(t("strong", ...urgency[item.urgency]));
            if (item.provider_observation_stale)
                card.append(t("p",
                    "Provider observation may be stale; inspect before acting",
                    "قد تكون حالة المزود قديمة؛ تحقق قبل اتخاذ إجراء"));
            button(card, "Review urgent dispute", "مراجعة النزاع العاجل", () => detail(item.id));
            content.append(card);
        }
        const pager = document.createElement("div");
        pager.className = "finance-pagination";
        pager.append(literal("p", String(results.current_page) + " / " + String(results.last_page)));
        if (results.current_page > 1)
            button(pager, "Previous deadlines", "المواعيد السابقة",
                () => deadlineQueue(results.current_page - 1, scope));
        if (results.current_page < results.last_page && results.current_page < 10000)
            button(pager, "Next deadlines", "المواعيد التالية",
                () => deadlineQueue(results.current_page + 1, scope));
        content.append(pager);
    }
    async function list(nextPage = 1, applied = null) {
        const version = ++generation;
        content.replaceChildren();
        const scope = applied || filters();
        if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage > 10000)
            throw Error("Invalid dispute review page.");
        const query = new URLSearchParams({...scope, per_page: "25", page: String(nextPage)});
        const data = await request("commercial/external-disputes?" + query);
        if (!alive() || version !== generation) return;
        verifyPage(data, nextPage, 25, scope);
        page = nextPage;
        appliedList = scope;
        content.replaceChildren();
        if (!data.data.length)
            content.append(
                t(
                    "p",
                    "No provider disputes in this view.",
                    "لا توجد نزاعات مزود في هذا العرض.",
                ),
            );
        for (const row of data.data) {
            const card = document.createElement("article");
            card.className = "finance-item";
            card.dataset.disputeId = String(row.id);
            summary(card, row);
            button(card, "Review dispute", "مراجعة النزاع", () =>
                detail(row.id),
            );
            content.append(card);
        }
        if (data.current_page > 1)
            button(content, "Previous disputes", "النزاعات السابقة", () =>
                list(page - 1, scope),
            );
        if (data.current_page < data.last_page && data.current_page < 10000)
            button(content, "Next disputes", "النزاعات التالية", () =>
                list(page + 1, scope),
            );
    }
    button(container, "Load provider disputes", "تحميل نزاعات المزود", () => {
        attention = false;
        return list(1);
    });
    button(container, "Financial exceptions", "الاستثناءات المالية", () => {
        attention = true;
        return list(1);
    });
    if (role === "admin")
        button(container, "Review evidence deadlines", "مراجعة مواعيد الأدلة", () => deadlineQueue(1));
    container.append(content);
};
