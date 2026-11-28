/* Authenticated recovery records; no client assertion or blind charge/refund retry. */
function recoveryScope(container, actor, redact) {
    const initial = actor(), identity = initial?.id, role = initial?.role;
    let retired = false, generation = 0, controller = new AbortController();
    const owned = () => container.isConnected && actor()?.id === identity && actor()?.role === role;
    const active = () => !retired && identity != null && owned() && !document.hidden;
    function invalidate() {
        generation++;
        controller.abort();
        controller = new AbortController();
        redact();
    }
    function retire() {
        if (retired) return;
        retired = true;
        invalidate();
        observer.disconnect();
        document.removeEventListener("visibilitychange", visibility);
    }
    function visibility() {
        if (!owned()) retire();
        else if (document.hidden) invalidate();
    }
    const observer = new MutationObserver(() => { if (!owned()) retire(); });
    observer.observe(document.documentElement, {childList:true, subtree:true});
    document.addEventListener("visibilitychange", visibility);
    return {
        active,
        begin() { if (!active()) return null; invalidate(); return generation; },
        current(epoch) { return active() && generation === epoch; },
        signal() { return controller.signal; },
    };
}
function validRecoveryMoney(row, field) {
    return row && Number.isSafeInteger(row[field]) && row[field] >= 0 &&
        Number.isInteger(row.minor_units) && row.minor_units >= 0 && row.minor_units <= 3 &&
        typeof row.currency === "string" && /^[A-Z]{3}$/.test(row.currency);
}
function recoveryEventClock(node, raw) {
    return window.EvStationTime.bindServerEventTime(node, raw);
}
window.mountPaymentRecovery = ({ container, api, ui, actor }) => {
    let scope;
    function t(tag, en, ar) {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    }
    function literal(tag, value) {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    }
    const heading = t("h3", "Payment recovery", "استرداد حالة الدفع");
    container.append(
        heading,
        t(
            "p",
            "Check uncertain outcomes before starting another payment. This view never sends a payment or refund.",
            "تحقق من النتائج غير المؤكدة قبل بدء دفع آخر. لا يرسل هذا العرض دفعة أو استرداداً.",
        ),
    );
    const state = literal("p", "");
    state.setAttribute("role", "status");
    container.append(state);
    const list = document.createElement("div");
    container.append(list);
    scope = recoveryScope(container, actor, () => {
        list.replaceChildren();
        state.textContent = "";
    });
    function button(parent, en, ar, fn, valid = scope.active) {
        const b = t("button", en, ar);
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            if (!valid() || b.disabled || !b.isConnected) return;
            b.disabled = true;
            try {
                await fn();
            } catch (e) {
                if (
                    valid() &&
                    !["SessionChangedError", "AbortError"].includes(e.name)
                )
                    state.replaceChildren(t("span", "Unable to read recovery status. Refresh before another action.", "تعذر قراءة حالة الاسترداد. حدّث قبل أي إجراء آخر."));
            } finally {
                // An interrupted top-level read must remain manually reloadable
                // after the tab returns, even if its finally ran while hidden.
                if (b.isConnected) b.disabled = false;
            }
        };
        return b;
    }
    async function load(page = 1) {
        if (!Number.isSafeInteger(page) || page < 1) return;
        const epoch = scope.begin();
        if (epoch === null) return;
        const current = () => scope.current(epoch);
        state.replaceChildren(t("span", "Loading…", "جار التحميل…"));
        let rows;
        try {
            rows = await api("commercial/payment-recovery?page=" + page, "GET", undefined, scope.signal());
            if (!current()) return;
            if (!Array.isArray(rows?.data) || rows.current_page !== page ||
                !Number.isSafeInteger(rows.last_page) || rows.last_page < page ||
                rows.data.some(row => !validRecoveryMoney(row, "amount_minor") ||
                    !Number.isSafeInteger(row.id) || row.id < 1 ||
                    typeof row.kind !== "string" || typeof row.scope !== "string" ||
                    typeof row.status !== "string") ||
                new Set(rows.data.map(row => row.id)).size !== rows.data.length) throw Error("Invalid page");
        } catch (e) {
            if (current() && !["SessionChangedError","AbortError"].includes(e.name))
                state.replaceChildren(t("span", "Unable to read recovery status. Refresh before another action.", "تعذر قراءة حالة الاسترداد. حدّث قبل أي إجراء آخر."));
            return;
        }
        state.textContent = "";
        list.replaceChildren();
        if (!rows.data.length)
            list.append(
                t(
                    "p",
                    "No payment recovery records",
                    "لا توجد سجلات لاسترداد حالة الدفع",
                ),
            );
        const labels = {
            executing: [
                "Provider request in progress",
                "طلب مزود الدفع قيد التنفيذ",
            ],
            uncertain: [
                "Provider outcome uncertain",
                "نتيجة مزود الدفع غير مؤكدة",
            ],
            needs_review: [
                "Financial review required",
                "المراجعة المالية مطلوبة",
            ],
            completed: ["Operation recorded", "تم تسجيل العملية"],
            rejected: ["Operation not sent", "لم ترسل العملية"],
        };
        const statusLabel = value => Object.hasOwn(labels, value)
            ? labels[value] : ["Status unavailable", "الحالة غير متاحة"];
        for (const row of rows.data) {
            const card = document.createElement("article");
            card.className = "finance-row";
            card.append(
                literal("h4", "#" + row.id),
                t("p", ...statusLabel(row.status)),
                literal(
                    "p",
                    row.currency +
                        " " +
                        EvMoney.format(
                            row.amount_minor,
                            row.minor_units,
                        ),
                ),
            );
            card.append(
                literal("p", row.kind + " · " + row.scope),
                t(
                    "p",
                    "Test provider only · external acceptance unverified",
                    "مزود اختبار فقط · القبول الخارجي غير متحقق",
                ),
            );
            if (["uncertain", "needs_review", "executing"].includes(row.status))
                button(
                    card,
                    "Check provider outcome",
                    "التحقق من نتيجة مزود الدفع",
                    async () => {
                        if (!current() || !card.isConnected) return;
                        const ar = document.documentElement.lang === "ar";
                        if (!confirm(ar ? "قراءة حالة المزود لهذه العملية؟ لن ينشئ ذلك دفعة أو استرداداً جديداً." : "Read provider status for this operation? This does not create a new payment or refund.")) return;
                        if (!current() || !card.isConnected) return;
                        // Retire all existing action grants before reconciliation.
                        // An uncertain reply can only be followed by an explicit GET.
                        const inspection = scope.begin();
                        if (inspection === null) return;
                        state.replaceChildren(t("span", "Checking provider outcome…", "جارٍ التحقق من نتيجة المزود…"));
                        try {
                            await api("commercial/payment-recovery/" + row.id + "/reconcile",
                                "POST", {}, scope.signal());
                            if (scope.current(inspection)) await load(page);
                        } catch (error) {
                            if (scope.current(inspection) && !["SessionChangedError","AbortError"].includes(error.name))
                                state.replaceChildren(t("span", "Outcome not confirmed. Load recovery records before another action.", "لم تتأكد النتيجة. حمّل سجلات الاسترداد قبل أي إجراء آخر."));
                        }
                    }, current,
                );
            button(
                card,
                "View recovery history",
                "عرض سجل استرداد الحالة",
                async () => {
                    const history = await api(
                        "commercial/payment-recovery/" + row.id + "/events",
                        "GET", undefined, scope.signal(),
                    );
                    if (!current() || !card.isConnected) return;
                    if (!Array.isArray(history?.data) ||
                        history.data.some(event => !event || typeof event.status !== "string")) throw Error("Invalid history");
                    card.querySelector("[data-recovery-history]")?.remove();
                    const ul = document.createElement("ul");
                    ul.dataset.recoveryHistory = "";
                    for (const event of history.data) {
                        const li = literal("li", "");
                        li.append(recoveryEventClock(literal("span", ""), event.created_at),
                            literal("span", " · "),
                            t("span", ...statusLabel(event.status)));
                        ul.append(li);
                    }
                    card.append(ul);
                }, current,
            );
            list.append(card);
        }
        if (rows.current_page > 1)
            button(list, "Previous", "السابق", () => load(page - 1), current);
        if (rows.current_page < rows.last_page)
            button(list, "Next", "التالي", () => load(page + 1), current);
    }
    button(
        container,
        "Load recovery records",
        "تحميل سجلات استرداد الحالة",
        () => load(),
    );
};

// Scheduled in-app notices share canonical invoices; no payment action here.
window.mountSubscriptionNotices = ({ container, api, ui, actor }) => {
    container.dataset.subscriptionNotices = "true";
    let scope;
    const text = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const literal = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    };
    container.append(
        text("h3", "Subscription notices", "تنبيهات الاشتراك"),
        text(
            "p",
            "These in-app reminders do not initiate collection. Check renewal collection for payment attempts; uncertain payments require recovery first.",
            "لا تبدأ هذه التذكيرات التحصيل. راجع تحصيل التجديد لمعرفة محاولات الدفع؛ تتطلب الدفعات غير المؤكدة المطابقة أولاً.",
        ),
    );
    const list = document.createElement("div");
    container.append(list);
    scope = recoveryScope(container, actor, () => list.replaceChildren());
    async function load(page = 1) {
        if (!Number.isSafeInteger(page) || page < 1) return;
        const epoch = scope.begin();
        if (epoch === null) return;
        list.replaceChildren(text("p", "Loading…", "جار التحميل…"));
        try {
            const result = await api(
                "commercial/subscription-notices?page=" + page, "GET", undefined, scope.signal(),
            );
            if (!scope.current(epoch)) return;
            if (!Array.isArray(result?.data) || result.current_page !== page ||
                !Number.isSafeInteger(result.last_page) || result.last_page < page ||
                result.data.some(row => !validRecoveryMoney(row, "total_minor") ||
                    typeof row.number !== "string" || !["payment_due", "settled", "cancelled", "recovery_required"].includes(row.attention)))
                throw Error("Invalid page");
            list.replaceChildren();
            if (!result.data.length)
                list.append(
                    text(
                        "p",
                        "No subscription notices.",
                        "لا توجد تنبيهات اشتراك.",
                    ),
                );
            const labels = {
                payment_due: ["Payment due", "دفعة مستحقة"],
                settled: ["Invoice settled", "سُددت الفاتورة"],
                cancelled: ["Subscription cancelled", "أُلغي الاشتراك"],
                recovery_required: [
                    "Review uncertain payment first",
                    "راجع الدفع غير المؤكد أولاً",
                ],
            };
            for (const row of result.data) {
                const card = document.createElement("article");
                card.className = "finance-row";
                card.append(
                    literal("h4", row.number),
                    text("p", ...labels[row.attention]),
                    text("span", "Reminder stage", "مرحلة التذكير"),
                    literal("p", row.stage),
                    literal(
                        "p",
                        row.currency +
                            " " +
                            EvMoney.format(
                                row.total_minor,
                                row.minor_units,
                            ),
                    ),
                    recoveryEventClock(literal("p", ""), row.created_at),
                );
                list.append(card);
            }
            function button(en, ar, next) {
                const b = text("button", en, ar);
                b.type = "button";
                b.onclick = () => { if (scope.current(epoch) && b.isConnected) return load(next); };
                list.append(b);
            }
            if (result.current_page > 1)
                button(
                    "Previous notices",
                    "التنبيهات السابقة",
                    result.current_page - 1,
                );
            if (result.current_page < result.last_page)
                button(
                    "Next notices",
                    "التنبيهات التالية",
                    result.current_page + 1,
                );
            button("Refresh notices", "تحديث التنبيهات", page);
        } catch (_) {
            if (!scope.current(epoch)) return;
            list.replaceChildren(
                text(
                    "p",
                    "Unable to load subscription notices.",
                    "تعذر تحميل تنبيهات الاشتراك.",
                ),
            );
            const retry = text("button", "Retry", "إعادة المحاولة");
            retry.type = "button";
            retry.onclick = () => { if (scope.current(epoch) && retry.isConnected) return load(page); };
            list.append(retry);
        }
    }
    const refresh = text("button", "Load subscription notices", "تحميل تنبيهات الاشتراك");
    refresh.type = "button";
    refresh.onclick = () => { if (scope.active() && refresh.isConnected) return load(); };
    container.append(refresh);
    load();
};
