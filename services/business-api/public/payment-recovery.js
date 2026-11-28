/* Authenticated recovery records; no client assertion or blind charge/refund retry. */
window.mountPaymentRecovery = ({ container, api, ui, actor }) => {
    const identity = actor(),
        alive = () => container.isConnected && actor() === identity;
    let generation = 0;
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
    function button(parent, en, ar, fn, valid = alive) {
        const b = t("button", en, ar);
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            if (b.disabled || !b.isConnected || !valid()) return;
            b.disabled = true;
            try {
                await fn();
            } catch (e) {
                if (
                    b.isConnected && valid() &&
                    !["SessionChangedError", "AbortError"].includes(e.name)
                ) {
                    state.replaceChildren(t(
                        "span",
                        "Could not confirm the current recovery state. Reload the records before continuing.",
                        "تعذر تأكيد حالة الاسترداد الحالية. أعد تحميل السجلات قبل المتابعة.",
                    ));
                    list.replaceChildren();
                }
            } finally {
                b.disabled = false;
            }
        };
        return b;
    }
    async function load(page = 1) {
        if (!alive()) return;
        const epoch = ++generation;
        const current = () => alive() && epoch === generation;
        state.replaceChildren(t("span", "Loading…", "جار التحميل…"));
        list.replaceChildren();
        let rows;
        try {
            rows = await api("commercial/payment-recovery?page=" + page);
        } catch (error) {
            if (current()) state.replaceChildren(t(
                "span",
                "Could not load payment recovery records.",
                "تعذر تحميل سجلات استرداد حالة الدفع.",
            ));
            return;
        }
        if (!current()) return;
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
            prepared: ["Operation prepared", "العملية مهيأة"],
        };
        const status = value => Object.hasOwn(labels, value)
            ? labels[value]
            : ["Recorded status unavailable", "الحالة المسجلة غير متاحة"];
        for (const row of rows.data) {
            const card = document.createElement("article");
            card.className = "finance-row";
            card.append(
                literal("h4", "#" + row.id),
                t("p", ...status(row.status)),
                literal(
                    "p",
                    row.currency +
                        " " +
                        EvMoney.format(
                            Number(row.amount_minor),
                            Number(row.minor_units),
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
                        await api(
                            "commercial/payment-recovery/" +
                                row.id +
                                "/reconcile",
                            "POST",
                            {},
                        );
                        if (current()) await load(page);
                    },
                    current,
                );
            button(
                card,
                "View recovery history",
                "عرض سجل استرداد الحالة",
                async () => {
                    const history = await api(
                        "commercial/payment-recovery/" + row.id + "/events",
                    );
                    if (!current() || !card.isConnected) return;
                    card.querySelector("[data-recovery-history]")?.remove();
                    const ul = document.createElement("ul");
                    ul.dataset.recoveryHistory = "";
                    for (const event of history.data) {
                        const row = document.createElement("li");
                        const clock = window.EvStationTime.bindServerEventTime(
                            document.createElement("span"), event.created_at,
                        );
                        row.append(clock, " · ", t("span", ...status(event.status)));
                        ul.append(row);
                    }
                    card.append(ul);
                },
                current,
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
    const identity = actor();
    let generation = 0;
    const alive = () => container.isConnected && actor() === identity;
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
    async function load(page = 1) {
        if (!alive()) return;
        const epoch = ++generation;
        const current = () => alive() && epoch === generation;
        list.replaceChildren(text("p", "Loading…", "جار التحميل…"));
        try {
            const result = await api(
                "commercial/subscription-notices?page=" + page,
            );
            if (!current()) return;
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
                                Number(row.total_minor),
                                Number(row.minor_units),
                            ),
                    ),
                    window.EvStationTime.bindServerEventTime(
                        document.createElement("p"), row.created_at,
                    ),
                );
                list.append(card);
            }
            function button(en, ar, next) {
                const b = text("button", en, ar);
                b.type = "button";
                b.onclick = () => { if (current() && b.isConnected) load(next); };
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
            if (!current()) return;
            list.replaceChildren(
                text(
                    "p",
                    "Unable to load subscription notices.",
                    "تعذر تحميل تنبيهات الاشتراك.",
                ),
            );
            const retry = text("button", "Retry", "إعادة المحاولة");
            retry.type = "button";
            retry.onclick = () => { if (current() && retry.isConnected) load(page); };
            list.append(retry);
        }
    }
    load();
};
