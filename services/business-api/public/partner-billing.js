/* Read-only source-to-ledger workbench; no source price becomes payment authority. */
window.mountPartnerBilling = ({
    root,
    provider,
    api,
    actor,
    ui,
    valid,
    signal,
}) => {
    let generation = 0;
    const ar = () => document.documentElement.lang.startsWith("ar");
    const t = (en, arabic) => (ar() ? arabic : en);
    const el = (tag, value) => {
        const node = document.createElement(tag);
        node.textContent = value;
        return node;
    };
    const live = () =>
        valid() && root.isConnected && !signal.aborted && !document.hidden;
    const filters = {};
    const labels = {
        test_invoice_linked: [
            "Canonical TEST invoice linked",
            "تم ربط فاتورة الاختبار الأصلية",
        ],
        not_invoiced: [
            "No canonical invoice; no collection inferred",
            "لا توجد فاتورة أصلية؛ لا يفترض تحصيل",
        ],
        original_requires_review: [
            "Original credit reference needs review",
            "مرجع الإشعار الدائن الأصلي يتطلب مراجعة",
        ],
        scope_requires_review: [
            "Invoice ownership requires review",
            "ملكية الفاتورة تتطلب مراجعة",
        ],
        source_requires_review: [
            "Source fingerprints require review",
            "بصمات المصدر تتطلب مراجعة",
        ],
        unpaid: ["Unpaid", "غير مدفوعة"],
        paid: ["Paid in TEST ledger", "مدفوعة في سجل الاختبار"],
        credited: [
            "Credited without cash collection",
            "تمت التسوية دون تحصيل نقدي",
        ],
        partially_paid: ["Partially paid", "مدفوعة جزئياً"],
        refunded: ["Refunded in TEST ledger", "مستردة في سجل الاختبار"],
        partially_refunded: ["Partially refunded", "مستردة جزئياً"],
    };
    const label = (key) =>
        labels[key] ? t(...labels[key]) : t("Requires review", "تتطلب مراجعة");
    async function load(page = 1) {
        const epoch = ++generation;
        root.replaceChildren(
            el(
                "h3",
                t("Partner billing reconciliation", "مطابقة فوترة الشريك"),
            ),
        );
        root.dataset.partnerBilling = "";
        const form = document.createElement("form");
        for (const [key, en, arabic, max] of [
            ["currency", "Currency", "العملة", 3],
            ["country_code", "Country code", "رمز الدولة", 2],
            ["party_id", "Party ID", "رمز الطرف", 3],
        ]) {
            const holder = el("label", t(en, arabic));
            const input = document.createElement("input");
            input.name = key;
            input.maxLength = max;
            input.value = filters[key] || "";
            holder.append(input);
            form.append(holder);
        }
        const kind = document.createElement("select");
        kind.name = "kind";
        kind.setAttribute("aria-label", t("CDR kind", "نوع سجل الشحن"));
        for (const [value, en, arabic] of [
            ["all", "All records", "كل السجلات"],
            ["charges", "Charges", "الشحنات"],
            ["credits", "Credits", "الإشعارات الدائنة"],
        ]) {
            const option = el("option", t(en, arabic));
            option.value = value;
            kind.append(option);
        }
        kind.value = filters.kind || "all";
        form.append(kind);
        const apply = el("button", t("Apply filters", "تطبيق المرشحات"));
        apply.type = "submit";
        form.append(apply);
        form.onsubmit = (event) => {
            event.preventDefault();
            if (!live()) return;
            for (const [key, value] of new FormData(form)) {
                if (value.trim()) filters[key] = value.trim().toUpperCase();
                else delete filters[key];
            }
            filters.kind = kind.value;
            load();
        };
        root.append(
            form,
            el(
                "p",
                t(
                    "Read-only partner reports and canonical TEST ledger. This does not settle a partner, authorize billing or prove a bank payment.",
                    "تقارير الشريك وسجل الاختبار الأصلي للعرض فقط. لا تُسوى مستحقات الشريك ولا تُصرح فوترة ولا يُثبت دفع مصرفي.",
                ),
            ),
        );
        const content = document.createElement("div");
        root.append(content);
        try {
            const result = await api(
                `ocpi/providers/${provider.id}/billing-reconciliation?${new URLSearchParams({ ...filters, page })}`,
                "GET",
                undefined,
                signal,
            );
            if (!live() || epoch !== generation) return;
            if (
                result.schema_version !== 1 ||
                result.provider_id !== provider.id ||
                result.read_only !== true ||
                result.billing_authority !== false ||
                result.payment_execution !== false ||
                !Array.isArray(result.data)
            )
                throw Error("Unverified report");
            const exports = document.createElement("nav");
            exports.setAttribute(
                "aria-label",
                t("Private reconciliation exports", "تصدير المطابقة الخاصة"),
            );
            for (const [format, en, arabic] of [
                ["csv", "Download filtered CSV", "تنزيل CSV المرشح"],
                ["pdf", "Download filtered PDF", "تنزيل PDF المرشح"],
            ]) {
                const link = el("a", t(en, arabic));
                const update = () => {
                    link.href = `/api/ocpi/providers/${provider.id}/billing-reconciliation/${format}?${new URLSearchParams({ ...filters, locale: ar() ? "ar" : "en" })}`;
                };
                update();
                link.onclick = (event) => {
                    if (!live()) event.preventDefault();
                    else update();
                };
                link.download = "";
                link.referrerPolicy = "no-referrer";
                exports.append(link, document.createTextNode(" · "));
            }
            content.append(
                exports,
                el(
                    "p",
                    t(
                        "Exports use applied filters across all pages (maximum 250 records). Credit rows reference original invoices; do not sum rows as revenue.",
                        "يستخدم التصدير المرشحات المطبقة لكل الصفحات (٢٥٠ سجلًا كحد أقصى). تشير الإشعارات الدائنة للفواتير الأصلية؛ لا تجمع السجلات كإيراد.",
                    ),
                ),
            );
            for (const row of result.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                card.append(
                    el(
                        "h4",
                        `${row.country_code} · ${row.party_id} · ${row.external_id}`,
                    ),
                    el(
                        "p",
                        row.credit
                            ? t("Source credit", "إشعار دائن من المصدر")
                            : t("Source charge", "شحنة من المصدر"),
                    ),
                );
                card.append(
                    el(
                        "p",
                        row.source?.status === "source_consistent"
                            ? t(
                                  "Partner session and CDR agree",
                                  "تتطابق جلسة الشريك وسجل الشحن",
                              )
                            : t(
                                  "Partner source requires review",
                                  "يتطلب مصدر الشريك مراجعة",
                              ),
                    ),
                );
                const billing = row.billing || {};
                card.append(el("p", label(billing.status)));
                if (billing.status === "test_invoice_linked") {
                    const invoice = billing.invoice;
                    if (
                        !Number.isSafeInteger(invoice?.id) ||
                        !Number.isInteger(invoice.minor_units) ||
                        invoice.minor_units < 0 ||
                        invoice.minor_units > 3 ||
                        !/^[A-Z]{3}$/.test(invoice.currency)
                    )
                        throw Error("Invalid ledger dimensions");
                    card.append(
                        el("p", invoice.number),
                        el("p", label(billing.state)),
                    );
                    if (billing.ledger_balanced !== true)
                        card.append(
                            el(
                                "p",
                                t(
                                    "Ledger requires reconciliation",
                                    "السجل المالي يتطلب مطابقة",
                                ),
                            ),
                        );
                    for (const [key, en, arabic] of [
                        ["total_minor", "Original total", "الإجمالي الأصلي"],
                        [
                            "paid_minor",
                            "Collected in TEST ledger",
                            "المحصّل في سجل الاختبار",
                        ],
                        [
                            "refunded_minor",
                            "Refunded in TEST ledger",
                            "المسترد في سجل الاختبار",
                        ],
                        [
                            "unpaid_credited_minor",
                            "Noncash credit",
                            "تسوية غير نقدية",
                        ],
                        ["due_minor", "Amount due", "المبلغ المستحق"],
                    ]) {
                        const amount =
                            key === "total_minor" ? invoice[key] : billing[key];
                        if (!Number.isSafeInteger(amount) || amount < 0)
                            throw Error("Invalid canonical amount");
                        card.append(
                            el(
                                "p",
                                `${t(en, arabic)}: ${invoice.currency} ${EvMoney.format(amount, invoice.minor_units)}`,
                            ),
                        );
                    }
                    card.append(
                        el(
                            "p",
                            `${t("Refund reviews (not refunds)", "مراجعات الاسترداد وليست استردادات")}: ${billing.refund_review_count}`,
                        ),
                    );
                    const documents = document.createElement("div");
                    card.append(documents);
                    window.mountFinancialDocuments?.({
                        container: documents,
                        sourceKind: "charging_invoice",
                        sourceId: invoice.id,
                        api,
                        ui,
                        actor: () =>
                            live() && epoch === generation ? actor() : null,
                    });
                }
                content.append(card);
            }
            if (!result.data.length)
                content.append(
                    el(
                        "p",
                        t(
                            "No matching source records",
                            "لا توجد سجلات مصدر مطابقة",
                        ),
                    ),
                );
            for (const [en, arabic, next, show] of [
                ["Previous", "السابق", page - 1, page > 1],
                ["Next", "التالي", page + 1, page < result.last_page],
            ])
                if (show) {
                    const button = el("button", t(en, arabic));
                    button.type = "button";
                    button.onclick = () => {
                        if (live()) load(next);
                    };
                    content.append(button);
                }
        } catch {
            if (!live() || epoch !== generation) return;
            content.replaceChildren(
                el(
                    "p",
                    t(
                        "Report unavailable. Refresh to retrieve current source state.",
                        "التقرير غير متاح. حدّث لجلب حالة المصدر الحالية.",
                    ),
                ),
            );
            const retry = el("button", t("Refresh", "تحديث"));
            retry.type = "button";
            retry.onclick = () => {
                if (live()) load(page);
            };
            content.append(retry);
        }
    }
    document.addEventListener(
        "visibilitychange",
        () => {
            if (document.hidden) {
                generation++;
                root.replaceChildren();
            } else if (live()) {
                const refresh = el("button", t("Refresh", "تحديث"));
                refresh.type = "button";
                refresh.onclick = () => {
                    if (live()) load();
                };
                root.append(refresh);
            }
        },
        { signal },
    );
    signal.addEventListener(
        "abort",
        () => {
            generation++;
            root.replaceChildren();
        },
        { once: true },
    );
    load();
};
