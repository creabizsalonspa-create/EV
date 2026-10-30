/* Financial facts stay separate. No browser-side accounting or fabricated analytics. */
export function mountFinancialAnalytics({ container, api, ui, actor }) {
    const identity = actor()?.id,
        controller = new AbortController();
    let busy = false,
        epoch = 0,
        report = null,
        filters = {};
    const alive = () => container.isConnected && actor()?.id === identity;
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const n = (tag, value) => {
        const e = document.createElement(tag);
        e.textContent = String(value ?? "");
        return e;
    };
    const status = n("p", ""),
        content = n("div", "");
    status.setAttribute("role", "status");
    container.dataset.financialAnalytics = "true";
    container.append(
        t(
            "p",
            "Financial source records · UTC periods · test evidence remains labeled. Invoices, receipts and instructions are separate facts, never one revenue total.",
            "سجلات المصدر المالي · فترات UTC · تبقى أدلة الاختبار مميزة. الفواتير والمقبوضات والتعليمات حقائق منفصلة وليست إجمالياً واحداً للإيراد.",
        ),
        status,
        content,
    );
    const observer = new MutationObserver(() => {
        if (!alive()) {
            epoch++;
            report = null;
            controller.abort();
            observer.disconnect();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    async function perform(task) {
        if (busy || !alive()) return;
        busy = true;
        container.inert = true;
        container.setAttribute("aria-busy", "true");
        status.replaceChildren(
            t(
                "span",
                "Loading financial source records…",
                "جار تحميل سجلات المصدر المالي…",
            ),
        );
        try {
            await task();
            if (alive()) status.textContent = "";
        } catch (e) {
            if (
                alive() &&
                !["AbortError", "SessionChangedError"].includes(e.name)
            ) {
                status.replaceChildren(
                    t(
                        "span",
                        "The report could not be loaded. Check the date/currency filters or refresh your session.",
                        "تعذر تحميل التقرير. تحقق من مرشحات التاريخ والعملة أو حدّث جلستك.",
                    ),
                );
                if (actor()?.role === "admin")
                    status.append(n("span", " · " + e.message));
            }
        } finally {
            busy = false;
            if (alive()) {
                container.inert = false;
                container.removeAttribute("aria-busy");
            }
        }
    }
    function button(parent, en, ar, fn) {
        const b = t("button", en, ar);
        b.type = "button";
        b.onclick = () => perform(fn);
        parent.append(b);
        return b;
    }
    const money = (amount, currency, digits) =>
        `${currency} ${amount < 0 ? "−" : ""}${EvMoney.format(Math.abs(amount), digits)}`;
    const label = (dictionary, key) => {
        const values = dictionary?.[key] || [key, key];
        return t("span", ...values);
    };
    function links(parent) {
        const links = n("nav", "");
        links.setAttribute(
            "aria-label",
            "Private report downloads / تنزيلات التقرير الخاصة",
        );
        for (const [kind, en, ar] of [
            ["export", "Download report CSV", "تنزيل التقرير CSV"],
            ["pdf", "Download report PDF", "تنزيل التقرير PDF"],
        ]) {
            const q = { ...filters };
            if (kind === "pdf")
                q.locale = document.documentElement.lang === "ar" ? "ar" : "en";
            const a = t("a", en, ar);
            a.href =
                "/api/financial/analytics/" +
                kind +
                "?" +
                new URLSearchParams(q);
            a.onclick = () => {
                if (kind === "pdf") {
                    const current = {
                        ...filters,
                        locale:
                            document.documentElement.lang === "ar"
                                ? "ar"
                                : "en",
                    };
                    a.href =
                        "/api/financial/analytics/pdf?" +
                        new URLSearchParams(current);
                }
            };
            a.download = "";
            a.referrerPolicy = "no-referrer";
            links.append(a, document.createTextNode(" · "));
        }
        parent.append(links);
    }
    function display() {
        content.replaceChildren();
        const form = document.createElement("form");
        form.className = "finance-form";
        for (const [name, en, ar, type] of [
            ["from", "From date (UTC)", "من تاريخ (UTC)", "date"],
            ["to", "To date (UTC)", "إلى تاريخ (UTC)", "date"],
            ["currency", "Currency (optional)", "العملة (اختياري)", "text"],
        ]) {
            const l = t("label", en, ar),
                input = document.createElement("input");
            input.name = name;
            input.type = type;
            input.value = filters[name] || "";
            if (name === "currency") {
                input.maxLength = 3;
                input.pattern = "[A-Z]{3}";
            }
            l.append(input);
            form.append(l);
        }
        const apply = t(
            "button",
            "Apply financial filters",
            "تطبيق المرشحات المالية",
        );
        apply.type = "submit";
        form.append(apply);
        form.onsubmit = (e) => {
            e.preventDefault();
            const v = Object.fromEntries(new FormData(form));
            for (const k of Object.keys(v)) if (!v[k]) delete v[k];
            perform(() => load(v));
        };
        content.append(form);
        links(content);
        const scope = t(
            "p",
            "Current and previous equal-length periods. Instruction states and receivable aging reflect the current state, not historical reconstruction.",
            "فترتان متساويتان حالية وسابقة. حالات التعليمات وأعمار المستحقات تعكس الحالة الحالية وليست إعادة بناء تاريخية.",
        );
        scope.append(
            n(
                "span",
                ` ${report.period.from} → ${report.period.to} · ${report.period.previous_from} → ${report.period.previous_to}`,
            ),
        );
        content.append(scope);
        const categoryFilter = document.createElement("select"),
            filterLabel = t("label", "Financial activity", "النشاط المالي");
        categoryFilter.setAttribute(
            "aria-label",
            "Financial activity / النشاط المالي",
        );
        categoryFilter.append(
            Object.assign(
                t("option", "All financial categories", "جميع الفئات المالية"),
                { value: "" },
            ),
        );
        for (const c of [...new Set(report.groups.map((g) => g.category))]) {
            const o = label(report.category_labels, c);
            const option = t(
                "option",
                ...(report.category_labels[c] || [c, c]),
            );
            option.value = c;
            categoryFilter.append(option);
        }
        filterLabel.append(categoryFilter);
        content.append(filterLabel);
        const rows = n("div", "");
        content.append(rows);
        function draw() {
            rows.replaceChildren();
            const pairs = new Map();
            for (const g of report.groups) {
                if (categoryFilter.value && g.category !== categoryFilter.value)
                    continue;
                const key = [
                    g.category,
                    g.currency,
                    g.minor_units,
                    g.state,
                    g.evidence,
                ].join(":");
                const pair = pairs.get(key) || {
                    meta: g,
                    current: 0,
                    previous: 0,
                    currentCount: 0,
                    previousCount: 0,
                };
                pair[g.period] = g.amount_minor;
                pair[g.period + "Count"] = g.record_count;
                pairs.set(key, pair);
            }
            if (!pairs.size)
                rows.append(
                    t(
                        "p",
                        "No financial postings match these filters.",
                        "لا توجد قيود مالية تطابق هذه المرشحات.",
                    ),
                );
            for (const p of pairs.values()) {
                const card = n("article", "");
                card.className = "finance-row";
                card.dataset.analyticsCategory = p.meta.category;
                card.dataset.analyticsCurrency = p.meta.currency;
                const h = n("h4", "");
                h.append(label(report.category_labels, p.meta.category));
                card.append(h);
                const state = n("p", "");
                state.append(
                    label(report.state_labels, p.meta.state),
                    n("span", " · " + p.meta.evidence),
                );
                card.append(state);
                for (const [period, en, ar] of [
                    ["current", "Current period", "الفترة الحالية"],
                    ["previous", "Previous period", "الفترة السابقة"],
                ]) {
                    const line = t("p", en, ar);
                    line.append(
                        document.createTextNode(" · "),
                        n(
                            "strong",
                            money(
                                p[period],
                                p.meta.currency,
                                p.meta.minor_units,
                            ),
                        ),
                        n("span", ` · ${p[period + "Count"]}`),
                    );
                    const meter = document.createElement("meter");
                    meter.min = 0;
                    meter.max = Math.max(p.current, p.previous, 1);
                    meter.value = p[period];
                    meter.style.width = "100%";
                    meter.style.accentColor = "var(--accent)";
                    meter.setAttribute(
                        "aria-label",
                        `${period} ${money(p[period], p.meta.currency, p.meta.minor_units)}`,
                    );
                    card.append(line, meter);
                }
                rows.append(card);
            }
        }
        categoryFilter.onchange = draw;
        draw();
        const run = n("article", "");
        run.className = "finance-row";
        run.append(
            t(
                "h4",
                "Paid-cycle recurring equivalents",
                "المكافئات الدورية للفترات المدفوعة",
            ),
            t(
                "p",
                "Current paid, non-cancelling cycles, net of posted credits. Rounded monthly equivalent; not recognized revenue or a collection guarantee. For customers/vendors this is their own subscription cost.",
                "الفترات الحالية المدفوعة غير المقرر إلغاؤها بعد الإشعارات الدائنة المسجلة. المكافئ الشهري مقرب وليس إيراداً معترفاً به أو ضمان تحصيل. للعميل والمورد هو تكلفة اشتراكه الخاصة.",
            ),
        );
        if (!report.recurring_run_rates.length)
            run.append(
                t(
                    "p",
                    "No qualifying paid recurring cycles.",
                    "لا توجد فترات مدفوعة دورية مؤهلة.",
                ),
            );
        for (const r of report.recurring_run_rates) {
            const line = t(
                "p",
                "Monthly / annual equivalent",
                "المكافئ الشهري / السنوي",
            );
            line.append(
                n(
                    "strong",
                    ` · ${money(r.monthly_equivalent_minor, r.currency, r.minor_units)} / ${money(r.annual_equivalent_minor, r.currency, r.minor_units)}`,
                ),
                n("span", " · " + r.evidence),
            );
            run.append(line);
        }
        content.append(run);
        const points = n("article", "");
        points.className = "finance-row";
        points.append(
            t(
                "h4",
                "Non-cash reward point movements",
                "حركات نقاط المكافآت غير النقدية",
            ),
            t(
                "p",
                "Points have no cash value. Posted movements do not include unposted expiry or reversal adjustments.",
                "لا قيمة نقدية للنقاط. الحركات المسجلة لا تشمل تعديلات الانتهاء أو العكس التي لم تسجل بعد.",
            ),
        );
        const kinds = {
            earn: ["Earned", "مكتسب"],
            redeem: ["Redeemed", "مستبدل"],
            release: ["Released", "محرر"],
            expiry: ["Expired", "منتهٍ"],
            reversal: ["Reversed", "معكوس"],
        };
        for (const p of report.points.groups) {
            const line = n("p", "");
            line.append(
                t("span", ...(kinds[p.kind] || [p.kind, p.kind])),
                document.createTextNode(" · "),
                t(
                    "span",
                    p.period === "current"
                        ? "Current period"
                        : "Previous period",
                    p.period === "current"
                        ? "الفترة الحالية"
                        : "الفترة السابقة",
                ),
                n("strong", ` · ${p.points}`),
            );
            points.append(line);
        }
        if (!report.points.groups.length)
            points.append(
                t(
                    "p",
                    "No posted point movements in these periods.",
                    "لا توجد حركات نقاط مسجلة في هاتين الفترتين.",
                ),
            );
        content.append(points);
    }
    async function load(query = filters) {
        const version = ++epoch;
        const result = await api(
            "financial/analytics?" + new URLSearchParams(query),
            "GET",
            undefined,
            controller.signal,
        );
        if (!alive() || version !== epoch) return;
        report = result;
        filters = { ...query, from: result.period.from, to: result.period.to };
        display();
    }
    button(container, "Open financial analytics", "فتح التحليلات المالية", () =>
        load(),
    );
}
