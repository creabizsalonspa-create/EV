/* Explicit noncash TEST correction. Storage contains only the opaque request UUID. */
window.mountUnpaidCredit = ({
    root,
    summary,
    actor,
    api,
    ui,
    valid,
    signal,
    refresh,
}) => {
    const invoice = summary.invoice;
    if (!["vendor", "admin"].includes(actor?.role)) return;
    const storageKey = `ev-unpaid-credit:${location.origin}:${actor.id}:${invoice.id}`;
    let frozen = null,
        version = 0,
        busy = false;
    const messages = {
        "Unpaid TEST credit": [
            "Unpaid TEST invoice credit",
            "تسوية فاتورة اختبار غير مدفوعة",
        ],
        "Unpaid credit notice": [
            "Only a full matching partner credit can cancel an untouched unpaid TEST invoice. This reverses the receivable, tax and allocations. It creates no refund and moves no cash.",
            "يمكن لإشعار دائن كامل ومطابق من الشريك تسوية فاتورة اختبار غير مدفوعة لم تبدأ عليها أية عملية مالية. يعكس الرصيد المستحق والضريبة والتخصيصات دون استرداد أو حركة نقدية.",
        ],
        "Load full credit references": [
            "Load full credit references",
            "تحميل مراجع الإشعارات الدائنة الكاملة",
        ],
        "Select full credit": [
            "Select full source credit",
            "اختيار إشعار دائن كامل من المصدر",
        ],
        "No full credits": [
            "No unused full matching credits on this page",
            "لا توجد إشعارات دائنة كاملة مطابقة وغير مستخدمة في هذه الصفحة",
        ],
        "Noncash reason": ["Correction reason", "سبب التسوية"],
        "Noncash consent": [
            "I confirm this full TEST noncash correction; no payment or refund is requested.",
            "أؤكد هذه التسوية الاختبارية الكاملة غير النقدية؛ لا أطلب دفعاً أو استرداداً.",
        ],
        "Issue noncash credit": [
            "Issue full noncash TEST credit",
            "إصدار تسوية اختبارية كاملة غير نقدية",
        ],
        "Retry noncash credit": [
            "Retry the saved credit request",
            "إعادة طلب التسوية المحفوظ",
        ],
        "Resolve noncash credit": [
            "Resolve result or cancel the unissued request",
            "التحقق من النتيجة أو إلغاء الطلب غير المنفذ",
        ],
        "Noncash uncertain": [
            "A credit request needs resolution. Retry its exact saved body or resolve/cancel it before starting another.",
            "يحتاج طلب التسوية إلى التحقق. أعد نفس الطلب المحفوظ أو تحقق منه وألغه قبل بدء طلب آخر.",
        ],
        "Noncash failed": [
            "Credit result unavailable. Refresh or resolve the saved request; no replacement request was sent.",
            "نتيجة التسوية غير متاحة. حدّث أو تحقق من الطلب المحفوظ؛ لم يرسل طلب بديل.",
        ],
    };
    for (const [key, [en, ar]] of Object.entries(messages))
        window.EV_MESSAGES[key] ||= { en, ar };
    const live = () =>
        valid() && root.isConnected && !signal?.aborted && !document.hidden;
    const readKey = () => sessionStorage.getItem(storageKey);
    function button(parent, key, action) {
        const b = ui("button", key);
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            if (busy || !live()) return;
            busy = true;
            b.disabled = true;
            try {
                await action();
            } catch {
                if (live()) {
                    const error = ui("p", "Noncash failed");
                    error.setAttribute("role", "status");
                    root.append(error);
                }
            } finally {
                busy = false;
                if (b.isConnected) b.disabled = false;
            }
        };
        return b;
    }
    function checkedNote(note, key, body = null) {
        return (
            note?.invoice_id === invoice.id &&
            note.actor_id === actor.id &&
            note.request_key === key &&
            note.amount_minor === invoice.total_minor &&
            note.currency === invoice.currency &&
            note.minor_units === invoice.minor_units &&
            (!body ||
                (note.credit_record_id === body.credit_record_id &&
                    note.reason === body.reason)) &&
            note.evidence === "SIMULATED_NONCASH"
        );
    }
    async function send() {
        const key = readKey();
        if (!frozen || frozen.idempotency_key !== key)
            throw Error("Unresolved credit");
        const body = structuredClone(frozen);
        const result = await api(
            `finance/invoices/${invoice.id}/unpaid-credit`,
            "POST",
            body,
            signal,
        );
        if (!live()) return;
        if (
            result.invoice_id !== invoice.id ||
            result.cash_moved !== false ||
            result.refund_created !== false ||
            !checkedNote(result.credit_note, key, body)
        )
            throw Error("Unconfirmed credit");
        sessionStorage.removeItem(storageKey);
        frozen = null;
        await refresh();
    }
    function paint() {
        if (!live()) return;
        const token = ++version;
        root.replaceChildren();
        const key = readKey();
        if (!summary.can_issue_unpaid_credit && !key) return;
        root.append(
            ui("h4", "Unpaid TEST credit"),
            ui("p", "Unpaid credit notice"),
        );
        if (key) {
            root.append(ui("p", "Noncash uncertain"));
            if (frozen) button(root, "Retry noncash credit", send);
            button(root, "Resolve noncash credit", async () => {
                const result = await api(
                    `finance/invoices/${invoice.id}/unpaid-credit-resolve`,
                    "POST",
                    {
                        expected_actor_id: actor.id,
                        expected_invoice_number: invoice.number,
                        idempotency_key: key,
                        cancel_unissued: true,
                    },
                    signal,
                );
                if (!live()) return;
                if (
                    result.invoice_id !== invoice.id ||
                    result.cash_moved !== false ||
                    !["issued", "cancelled_unissued"].includes(result.state) ||
                    (result.state === "issued" &&
                        !checkedNote(result.credit_note, key))
                )
                    throw Error("Unconfirmed resolution");
                sessionStorage.removeItem(storageKey);
                frozen = null;
                await refresh();
            });
            return;
        }
        const amount = document.createElement("p");
        amount.textContent = `${invoice.currency} ${EvMoney.format(invoice.total_minor, invoice.minor_units)}`;
        root.append(amount);
        let selected = null;
        const chosen = document.createElement("p"),
            choices = document.createElement("div");
        root.append(chosen, choices);
        const reasonLabel = ui("label", "Noncash reason"),
            reason = document.createElement("textarea");
        reason.maxLength = 500;
        reasonLabel.append(reason);
        root.append(reasonLabel);
        const label = ui("label", "Noncash consent"),
            consent = document.createElement("input");
        consent.type = "checkbox";
        label.prepend(consent);
        root.append(label);
        async function options(page = 1) {
            const result = await api(
                `finance/invoices/${invoice.id}/source-credits?page=${page}&full_reversal=1`,
                "GET",
                undefined,
                signal,
            );
            if (!live() || token !== version) return;
            if (
                result.schema_version !== 1 ||
                result.invoice_id !== invoice.id ||
                result.financial_authority !== false ||
                result.automatic_refund !== false
            )
                throw Error("Unverified references");
            choices.replaceChildren();
            let count = 0;
            for (const row of result.data)
                if (
                    row.available_for_review === true &&
                    row.full_reversal_source_match === true &&
                    Number.isSafeInteger(row.id) &&
                    row.id > 0 &&
                    row.evidence?.invoice_id === invoice.id &&
                    row.evidence?.credit_record_id === row.id &&
                    row.evidence?.status === "source_reference_matched" &&
                    row.evidence?.schema_version === 1 &&
                    row.evidence?.financial_authority === false &&
                    row.evidence?.automatic_refund === false
                ) {
                    count++;
                    const item = document.createElement("article");
                    const name = document.createElement("p");
                    name.textContent = row.external_id;
                    item.append(name);
                    button(item, "Select full credit", () => {
                        selected = row.id;
                        chosen.textContent = row.external_id;
                    });
                    choices.append(item);
                }
            if (!count) choices.append(ui("p", "No full credits"));
            if (result.current_page > 1)
                button(choices, "Previous", () => options(page - 1));
            if (result.current_page < result.last_page)
                button(choices, "Next", () => options(page + 1));
        }
        button(root, "Load full credit references", () => options());
        button(root, "Issue noncash credit", async () => {
            if (
                !selected ||
                !consent.checked ||
                reason.value.trim().length < 10
            )
                throw Error("Incomplete correction");
            const key = crypto.randomUUID();
            frozen = {
                expected_actor_id: actor.id,
                expected_invoice_number: invoice.number,
                credit_record_id: selected,
                idempotency_key: key,
                reason: reason.value.trim(),
                consent: true,
                expected_total_minor: invoice.total_minor,
                expected_currency: invoice.currency,
                expected_minor_units: invoice.minor_units,
            };
            sessionStorage.setItem(storageKey, key);
            paint();
            await send();
        });
    }
    document.addEventListener(
        "visibilitychange",
        () => {
            if (document.hidden) {
                version++;
                frozen = null;
                root.replaceChildren();
            } else paint();
        },
        { signal },
    );
    try {
        paint();
    } catch {
        root.replaceChildren(ui("p", "Noncash failed"));
    }
};
