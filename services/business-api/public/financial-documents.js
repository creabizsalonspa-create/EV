/* Posted documents are private immutable snapshots; downloads recheck the session. */
window.mountFinancialDocuments = ({ container, api, ui, actor }) => {
    const owner = actor()?.id;
    let busy = false,
        generation = 0;
    const alive = () => container.isConnected && actor()?.id === owner;
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const node = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    };
    const state = node("p", ""),
        content = node("div", "");
    state.setAttribute("role", "status");
    container.dataset.financialDocuments = "true";
    container.append(
        t(
            "p",
            "Private PDF and CSV documents. Issuer details require legal review; test references do not replace official invoice numbers.",
            "مستندات PDF وCSV خاصة. بيانات الجهة المصدرة تحتاج مراجعة قانونية؛ المراجع التجريبية لا تستبدل أرقام الفواتير الرسمية.",
        ),
        state,
        content,
    );
    async function run(fn) {
        if (busy || !alive()) return;
        busy = true;
        container.setAttribute("aria-busy", "true");
        for (const e of container.querySelectorAll("button")) e.disabled = true;
        try {
            await fn();
        } catch (e) {
            if (
                alive() &&
                !["SessionChangedError", "AbortError"].includes(e.name)
            )
                state.textContent = e.message;
        } finally {
            busy = false;
            if (alive()) {
                container.removeAttribute("aria-busy");
                for (const e of container.querySelectorAll("button"))
                    e.disabled = false;
            }
        }
    }
    function button(parent, en, ar, fn) {
        const b = t("button", en, ar);
        b.type = "button";
        b.onclick = () => run(fn);
        parent.append(b);
        return b;
    }
    function form(parent, fields, en, ar, submit) {
        const f = document.createElement("form");
        f.className = "finance-form";
        let key = crypto.randomUUID();
        for (const [
            name,
            label,
            arabic,
            value,
            options,
            required = true,
        ] of fields) {
            const l = t("label", label, arabic),
                input = document.createElement(options ? "select" : "input");
            input.name = name;
            input.required = required;
            if (options)
                for (const [v, text] of options) {
                    const o = node("option", text);
                    o.value = v;
                    input.append(o);
                }
            else {
                input.type = ["source_id", "vendor_id", "minor_units"].includes(
                    name,
                )
                    ? "number"
                    : "text";
                input.maxLength = ["address", "address_ar"].includes(name)
                    ? 500
                    : 160;
                if (input.type === "number") {
                    input.min = name === "minor_units" ? 0 : 1;
                    input.step = 1;
                }
            }
            input.value = value ?? "";
            l.append(input);
            f.append(l);
        }
        const b = t("button", en, ar);
        b.type = "submit";
        f.append(b);
        f.oninput = () => {
            key = crypto.randomUUID();
        };
        f.onsubmit = (e) => {
            e.preventDefault();
            run(() => submit(Object.fromEntries(new FormData(f)), key));
        };
        parent.append(f);
    }
    function record(parent, d) {
        const row = node("article", "");
        row.className = "finance-row";
        row.append(
            node("h4", d.number),
            node("p", `${d.source_kind} #${d.source_id} · ${d.locale}`),
        );
        if (d.current_source_state) {
            const line = t("p", "Current source state", "حالة السجل الحالية");
            line.append(node("span", ` · ${d.current_source_state}`));
            row.append(
                line,
                t(
                    "p",
                    "Downloaded statements preserve their generation-time status and do not prove a bank transfer.",
                    "تحتفظ البيانات المنزلة بحالتها وقت الإصدار ولا تثبت تحويلاً بنكياً.",
                ),
            );
        }
        for (const format of ["pdf", "csv"]) {
            const a = t(
                "a",
                `Download ${format.toUpperCase()}`,
                `تنزيل ${format.toUpperCase()}`,
            );
            a.href = `/api/financial/documents/${Number(d.id)}/download/${format}`;
            a.download = "";
            a.referrerPolicy = "no-referrer";
            row.append(a, document.createTextNode(" "));
        }
        parent.append(row);
    }
    async function load(page = 1) {
        const seq = ++generation;
        state.replaceChildren(
            t("span", "Loading documents…", "جار تحميل المستندات…"),
        );
        const data = await api(`financial/documents?page=${page}`);
        if (!alive() || seq !== generation) return;
        state.textContent = "";
        content.replaceChildren();
        form(
            content,
            [
                [
                    "source_kind",
                    "Document source",
                    "مصدر المستند",
                    "charging_invoice",
                    [
                        ["charging_invoice", "Charging invoice / فاتورة شحن"],
                        [
                            "charging_credit",
                            "Charging credit / إشعار دائن للشحن",
                        ],
                        [
                            "subscription_invoice",
                            "Subscription invoice / فاتورة اشتراك",
                        ],
                        [
                            "subscription_credit",
                            "Subscription credit / إشعار دائن للاشتراك",
                        ],
                        [
                            "vendor_settlement",
                            "Vendor settlement / بيان تسوية المورد",
                        ],
                        [
                            "corporate_statement",
                            "Corporate statement / بيان الشركة",
                        ],
                    ],
                ],
                ["source_id", "Source record ID", "رقم السجل الأصلي", ""],
                [
                    "locale",
                    "Document language",
                    "لغة المستند",
                    document.documentElement.lang === "ar" ? "ar" : "en",
                    [
                        ["en", "English"],
                        ["ar", "العربية"],
                    ],
                ],
            ],
            "Generate private document",
            "إنشاء مستند خاص",
            async (v) => {
                const d = await api("financial/documents", "POST", {
                    ...v,
                    source_id: Number(v.source_id),
                });
                if (!alive()) return;
                await load();
                if (alive()) state.textContent = d.number;
            },
        );
        if (!data.data.length)
            content.append(
                t(
                    "p",
                    "No documents yet. Generate one from an accessible posted invoice or credit note.",
                    "لا توجد مستندات بعد. أنشئ مستنداً من فاتورة أو إشعار دائن مصرح لك بالوصول إليه.",
                ),
            );
        for (const d of data.data) record(content, d);
        if (data.current_page > 1)
            button(content, "Previous documents", "المستندات السابقة", () =>
                load(page - 1),
            );
        if (data.current_page < data.last_page)
            button(content, "Next documents", "المستندات التالية", () =>
                load(page + 1),
            );
        if (actor()?.role === "admin")
            button(
                content,
                "Issuer configuration",
                "إعدادات الجهة المصدرة",
                () => profiles(),
            );
    }
    async function profiles(page = 1) {
        const seq = ++generation,
            data = await api(`financial/profiles?page=${page}`);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        state.textContent = "";
        content.append(
            t(
                "p",
                "Publish reviewed issuer disclosures only. Versions are immutable. A matching effective profile is required for each invoice jurisdiction and currency.",
                "انشر بيانات الجهة المصدرة بعد المراجعة فقط. الإصدارات ثابتة. يلزم ملف نافذ يطابق اختصاص وعملة كل فاتورة.",
            ),
        );
        form(
            content,
            [
                [
                    "vendor_id",
                    "Vendor ID (empty for platform)",
                    "رقم المورد (اتركه فارغاً للمنصة)",
                    "",
                    null,
                    false,
                ],
                ["country", "Country code", "رمز الدولة", "AE"],
                ["currency", "Currency", "العملة", "AED"],
                [
                    "minor_units",
                    "Currency decimal places",
                    "المنازل العشرية للعملة",
                    2,
                ],
                [
                    "legal_name",
                    "Legal issuer name (English)",
                    "اسم الجهة القانوني بالإنجليزية",
                    "",
                ],
                [
                    "legal_name_ar",
                    "Legal issuer name (Arabic)",
                    "اسم الجهة القانوني بالعربية",
                    "",
                ],
                [
                    "address",
                    "Issuer address (English)",
                    "عنوان الجهة بالإنجليزية",
                    "",
                ],
                [
                    "address_ar",
                    "Issuer address (Arabic)",
                    "عنوان الجهة بالعربية",
                    "",
                ],
                [
                    "tax_registration",
                    "Tax registration (if applicable)",
                    "التسجيل الضريبي (إن وجد)",
                    "",
                    null,
                    false,
                ],
                [
                    "effective_from",
                    "Effective from (ISO date and timezone)",
                    "نافذ من (تاريخ ISO والمنطقة الزمنية)",
                    new Date().toISOString(),
                ],
            ],
            "Publish issuer version",
            "نشر إصدار الجهة",
            async (v, key) => {
                await api("financial/profiles", "POST", {
                    ...v,
                    vendor_id: v.vendor_id ? Number(v.vendor_id) : null,
                    minor_units: Number(v.minor_units),
                    tax_registration: v.tax_registration || null,
                    idempotency_key: key,
                });
                if (alive()) await profiles();
            },
        );
        for (const d of data.data) {
            const row = node("article", "");
            row.className = "finance-row";
            row.append(
                node("h4", `${d.legal_name} · v${d.version}`),
                node("p", `${d.country} · ${d.currency} · ${d.effective_from}`),
            );
            content.append(row);
        }
        if (data.current_page > 1)
            button(
                content,
                "Previous issuer versions",
                "إصدارات الجهة السابقة",
                () => profiles(page - 1),
            );
        if (data.current_page < data.last_page)
            button(
                content,
                "Next issuer versions",
                "إصدارات الجهة التالية",
                () => profiles(page + 1),
            );
        button(content, "Back to documents", "العودة إلى المستندات", () =>
            load(),
        );
    }
    button(container, "Open financial documents", "فتح المستندات المالية", () =>
        load(),
    );
};
