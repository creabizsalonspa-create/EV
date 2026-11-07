/* Sponsored package operations. This client never proves a view, pays a bill or changes charger capabilities. */
window.mountCommercialCampaigns = ({ container, api, ui, actor }) => {
    const owner = actor()?.id,
        role = actor()?.role,
        abort = new AbortController();
    let busy = false,
        generation = 0,
        currentPage = 1;
    const alive = () => container.isConnected && actor()?.id === owner;
    const watch = new MutationObserver(() => {
        if (!alive()) {
            generation++;
            abort.abort();
            watch.disconnect();
        }
    });
    watch.observe(container.parentNode || container, { childList: true });
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const text = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = String(value ?? "");
        return n;
    };
    function creative(tag, en, ar) {
        const n = text(tag, document.documentElement.lang === "ar" ? ar : en);
        n.dataset.en = en;
        n.dataset.ar = ar;
        return n;
    }
    function localDate(raw) {
        const n = document.createElement("time");
        let value = String(raw || "");
        if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value))
            value = value.replace(" ", "T") + "Z";
        const d = new Date(value);
        n.dateTime = Number.isNaN(d.getTime()) ? "" : d.toISOString();
        n.dataset.campaignDate = "true";
        n.textContent = Number.isNaN(d.getTime())
            ? value
            : d.toLocaleString(
                  document.documentElement.lang === "ar" ? "ar" : "en",
              );
        return n;
    }
    window.addEventListener(
        "ev:locale",
        () => {
            for (const n of container.querySelectorAll(
                "time[data-campaign-date]",
            )) {
                if (n.dateTime)
                    n.textContent = new Date(n.dateTime).toLocaleString(
                        document.documentElement.lang === "ar" ? "ar" : "en",
                    );
            }
        },
        { signal: abort.signal },
    );
    const status = text("p", ""),
        content = text("div", "");
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    container.dataset.commercialCampaigns = "true";
    container.append(
        t(
            "p",
            "Paid package delivery units only. Sponsored placements never prove charger compatibility, availability or human views. Campaign billing uses the existing subscription invoice.",
            "وحدات تسليم من حزمة مدفوعة فقط. الإعلان لا يثبت توافق الشاحن أو توفره أو مشاهدة بشرية. تعتمد الفوترة على فاتورة الاشتراك الحالية.",
        ),
        status,
        content,
    );
    async function request(path, method = "GET", data) {
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        const result = await api(path, method, data, abort.signal);
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        return result;
    }
    async function perform(fn) {
        if (busy || !alive()) return;
        busy = true;
        container.inert = true;
        container.setAttribute("aria-busy", "true");
        status.textContent = "";
        try {
            await fn();
        } catch (e) {
            if (
                alive() &&
                !["AbortError", "SessionChangedError"].includes(e.name)
            )
                status.textContent = e.message;
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
    function form(parent, fields, en, ar, fn) {
        const f = document.createElement("form");
        f.className = "finance-form";
        let key = crypto.randomUUID();
        for (const field of fields) {
            const label = t("label", field.en, field.ar),
                input = document.createElement(
                    field.options
                        ? "select"
                        : field.area
                          ? "textarea"
                          : "input",
                );
            input.name = field.name;
            input.required = field.required !== false;
            if (field.options) {
                for (const [value, label] of field.options) {
                    const o = text("option", label);
                    o.value = value;
                    input.append(o);
                }
            } else {
                if (!field.area) input.type = field.type || "text";
                input.maxLength = field.max || 500;
                if (field.type === "number") {
                    input.min = 1;
                    input.step = 1;
                }
            }
            if (field.value !== undefined) input.value = field.value;
            label.append(input);
            f.append(label);
        }
        const submit = t("button", en, ar);
        submit.type = "submit";
        f.append(submit);
        f.oninput = () => (key = crypto.randomUUID());
        f.onsubmit = (e) => {
            e.preventDefault();
            perform(() => fn(Object.fromEntries(new FormData(f)), key));
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
    });
    const states = {
        draft: ["Draft", "مسودة"],
        submitted: ["Awaiting review", "بانتظار المراجعة"],
        approved: ["Approved", "معتمد"],
        paused: ["Paused", "متوقف مؤقتاً"],
        rejected: ["Rejected", "مرفوض"],
        cancelled: ["Cancelled", "ملغى"],
        suspended: ["Suspended by administrator", "معلق بقرار المسؤول"],
    };
    function state(parent, value) {
        const n = t(
            "p",
            ...(states[value] || ["Review required", "تحتاج مراجعة"]),
        );
        n.dataset.campaignState = value;
        parent.append(n);
    }
    function nav() {
        button(content, "Campaign list", "قائمة الحملات", () => list());
        button(
            content,
            "Paid package eligibility",
            "أهلية الحزمة المدفوعة",
            () => packages(),
        );
        if (role === "vendor")
            button(
                content,
                "Create sponsored campaign",
                "إنشاء حملة برعاية",
                () => create(),
            );
    }
    function pager(data, fn) {
        if (data.current_page > 1)
            button(content, "Previous page", "الصفحة السابقة", () =>
                fn(data.current_page - 1),
            );
        if (data.current_page < data.last_page)
            button(content, "Next page", "الصفحة التالية", () =>
                fn(data.current_page + 1),
            );
    }
    async function packages(page = 1) {
        const seq = ++generation,
            data = await request(`commercial/campaign-packages?page=${page}`);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        nav();
        for (const p of data.data) {
            const row = text("article", "");
            row.className = "finance-row";
            row.append(
                text("h4", p.number),
                text("p", `${p.currency} · ${p.evidence}`),
                t(
                    "p",
                    p.campaign_eligible
                        ? "Eligible paid campaign package"
                        : "Not currently eligible",
                    p.campaign_eligible
                        ? "حزمة حملات مدفوعة مؤهلة"
                        : "غير مؤهلة حالياً",
                ),
                text(
                    "p",
                    `#${p.invoice_id} · ${p.period_start} → ${p.period_end}`,
                ),
            );
            const terms = text("p", "");
            terms.append(
                t("span", "Campaign slots", "عدد الحملات"),
                text("span", ` · ${p.entitlements.campaign_slots || 0} · `),
                t("span", "Delivery units", "وحدات التسليم"),
                text("span", ` · ${p.entitlements.campaign_units || 0}`),
            );
            row.append(terms);
            content.append(row);
        }
        if (!data.data.length)
            content.append(
                t(
                    "p",
                    "No package invoices. Choose and pay an eligible vendor plan in Plans & subscriptions.",
                    "لا توجد فواتير حزم. اختر خطة مورد مؤهلة وادفعها في الخطط والاشتراكات.",
                ),
            );
        pager(data, packages);
    }
    async function create(packagePage = 1, stationPage = 1) {
        const seq = ++generation;
        const [packages, stations] = await Promise.all([
            request(`commercial/campaign-packages?page=${packagePage}`),
            request(`commercial/campaign-stations?page=${stationPage}`),
        ]);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        nav();
        const eligible = packages.data.filter((p) => p.campaign_eligible);
        if (!eligible.length || !stations.data.length) {
            content.append(
                t(
                    "p",
                    "An eligible paid package and an owned station are required. Existing subscriptions and station operations remain separate.",
                    "يلزم توفر حزمة مدفوعة مؤهلة ومحطة مملوكة. تبقى إدارة الاشتراكات والمحطات مستقلة.",
                ),
            );
            return;
        }
        content.append(
            t(
                "p",
                "Campaign terms are immutable. To change creative or budget, cancel and create a new campaign. Unused reserved units return only on cancellation; delivered units remain consumed.",
                "شروط الحملة ثابتة. لتغيير المحتوى أو الميزانية، ألغ الحملة وأنشئ أخرى. تعود الوحدات غير المستخدمة عند الإلغاء فقط؛ تبقى الوحدات المسلمة مستهلكة.",
            ),
        );
        form(
            content,
            [
                {
                    name: "invoice_id",
                    en: "Paid package invoice",
                    ar: "فاتورة الحزمة المدفوعة",
                    options: eligible.map((p) => [
                        p.invoice_id,
                        `${p.number} · ${p.period_end}`,
                    ]),
                },
                {
                    name: "station_id",
                    en: "Owned station",
                    ar: "المحطة المملوكة",
                    options: stations.data.map((s) => [
                        s.id,
                        `${s.name} · ${s.country}`,
                    ]),
                },
                field(
                    "title",
                    "Campaign title (English)",
                    "عنوان الحملة بالإنجليزية",
                    "",
                ),
                field(
                    "title_ar",
                    "Campaign title (Arabic)",
                    "عنوان الحملة بالعربية",
                    "",
                ),
                {
                    name: "description",
                    en: "Description (English)",
                    ar: "الوصف بالإنجليزية",
                    area: true,
                },
                {
                    name: "description_ar",
                    en: "Description (Arabic)",
                    ar: "الوصف بالعربية",
                    area: true,
                },
                field(
                    "country",
                    "Country code",
                    "رمز الدولة",
                    stations.data[0].country,
                ),
                field(
                    "region",
                    "Target region (optional exact match)",
                    "المنطقة المستهدفة (تطابق نصي اختياري)",
                    "",
                    "text",
                    false,
                ),
                {
                    name: "placement",
                    en: "Sponsored placement",
                    ar: "الموضع الإعلاني",
                    options: [
                        [
                            "discovery_banner",
                            "Discovery banner / إعلان الاكتشاف",
                        ],
                        ["station_detail", "Station details / تفاصيل المحطة"],
                    ],
                },
                field(
                    "budget_units",
                    "Reserved delivery units",
                    "وحدات التسليم المحجوزة",
                    1,
                    "number",
                ),
                field(
                    "starts_at",
                    "Starts at (ISO with timezone)",
                    "بداية الحملة (ISO مع المنطقة الزمنية)",
                    new Date().toISOString(),
                ),
                field(
                    "ends_at",
                    "Ends at (ISO with timezone)",
                    "نهاية الحملة (ISO مع المنطقة الزمنية)",
                    new Date(Date.now() + 86400000).toISOString(),
                ),
            ],
            "Save campaign draft",
            "حفظ مسودة الحملة",
            async (v, key) => {
                const result = await request("commercial/campaigns", "POST", {
                    ...v,
                    invoice_id: Number(v.invoice_id),
                    station_id: Number(v.station_id),
                    budget_units: Number(v.budget_units),
                    region: v.region || null,
                    idempotency_key: key,
                });
                if (alive()) await detail(result.campaign.id);
            },
        );
        if (packages.current_page > 1)
            button(
                content,
                "Previous package choices",
                "خيارات الحزم السابقة",
                () => create(packagePage - 1, stationPage),
            );
        if (packages.current_page < packages.last_page)
            button(
                content,
                "Next package choices",
                "خيارات الحزم التالية",
                () => create(packagePage + 1, stationPage),
            );
        if (stations.current_page > 1)
            button(
                content,
                "Previous station choices",
                "خيارات المحطات السابقة",
                () => create(packagePage, stationPage - 1),
            );
        if (stations.current_page < stations.last_page)
            button(
                content,
                "Next station choices",
                "خيارات المحطات التالية",
                () => create(packagePage, stationPage + 1),
            );
    }
    async function list(page = 1) {
        const seq = ++generation,
            data = await request(`commercial/campaigns?page=${page}`);
        if (!alive() || seq !== generation) return;
        currentPage = page;
        content.replaceChildren();
        nav();
        if (!data.data.length)
            content.append(
                t(
                    "p",
                    "No campaigns yet. Paid package eligibility and administrator review are required before a sponsored placement can serve.",
                    "لا توجد حملات بعد. يلزم تأهل الحزمة المدفوعة ومراجعة المسؤول قبل عرض الإعلان.",
                ),
            );
        for (const c of data.data) {
            const row = text("article", "");
            row.className = "finance-row";
            row.dataset.campaignRecord = c.id;
            row.append(creative("h4", c.title, c.title_ar));
            state(row, c.status);
            const counts = text("p", "");
            counts.append(
                t(
                    "span",
                    "Verified server deliveries",
                    "تسليمات الخادم الموثقة",
                ),
                text("span", ` · ${c.delivered_units}/${c.budget_units} · `),
                t("span", "Attributed server clicks", "نقرات الخادم المنسوبة"),
                text("span", ` · ${c.verified_server_clicks}`),
            );
            row.append(counts);
            button(row, "Review campaign", "مراجعة الحملة", () => detail(c.id));
            content.append(row);
        }
        pager(data, list);
    }
    async function detail(id, deliveryPage = 1) {
        const seq = ++generation,
            data = await request(
                `commercial/campaigns/${id}?page=${deliveryPage}`,
            );
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        nav();
        const c = data.campaign;
        content.append(
            creative("h4", c.title, c.title_ar),
            creative("p", c.description, c.description_ar),
        );
        state(content, c.status);
        content.append(
            t(
                "p",
                data.currently_eligible
                    ? "Eligible for a sponsored placement"
                    : "Not currently serving eligible placements",
                data.currently_eligible
                    ? "مؤهلة لموضع إعلاني"
                    : "غير مؤهلة للعرض حالياً",
            ),
        );
        const facts = text("p", "");
        facts.append(
            t("span", "Package invoice", "فاتورة الحزمة"),
            text("span", ` #${c.invoice_id} · `),
            t(
                "span",
                "Reserved / delivered units",
                "الوحدات المحجوزة / المسلمة",
            ),
            text("span", ` ${c.budget_units} / ${c.delivered_units}`),
        );
        content.append(facts);
        const actions =
            role === "admin"
                ? c.status === "submitted"
                    ? [
                          [
                              "approve",
                              "Approve sponsored campaign",
                              "اعتماد الحملة",
                          ],
                          ["reject", "Reject sponsored campaign", "رفض الحملة"],
                      ]
                    : c.status === "approved"
                      ? [
                            [
                                "suspend",
                                "Suspend sponsored campaign",
                                "تعليق الحملة",
                            ],
                        ]
                      : c.status === "suspended"
                        ? [
                              [
                                  "reinstate",
                                  "Reinstate reviewed campaign",
                                  "إعادة الحملة بعد المراجعة",
                              ],
                          ]
                        : []
                : [
                      [
                          "draft",
                          "submit",
                          "Submit for review",
                          "إرسال للمراجعة",
                      ],
                      [
                          "approved",
                          "pause",
                          "Pause campaign",
                          "إيقاف الحملة مؤقتاً",
                      ],
                      ["paused", "resume", "Resume campaign", "استئناف الحملة"],
                  ]
                      .filter((a) => a[0] === c.status)
                      .map((a) => a.slice(1));
        if (
            role === "vendor" &&
            ["draft", "submitted", "approved", "paused", "suspended"].includes(
                c.status,
            )
        )
            actions.push(["cancel", "Cancel campaign", "إلغاء الحملة"]);
        for (const [action, en, ar] of actions)
            form(
                content,
                [field("reason", "Decision reason", "سبب القرار", "")],
                en,
                ar,
                async (v) => {
                    await request(
                        `commercial/campaigns/${id}/${action}`,
                        "POST",
                        v,
                    );
                    if (alive()) await detail(id);
                },
            );
        content.append(
            t("h4", "Immutable campaign audit", "سجل الحملة الثابت"),
        );
        const eventNames = {
            created: ["Created", "أُنشئت"],
            submit: ["Submitted for review", "أُرسلت للمراجعة"],
            approve: ["Approved by administrator", "اعتمدها المسؤول"],
            reject: ["Rejected by administrator", "رفضها المسؤول"],
            pause: ["Paused by vendor", "أوقفها المورد مؤقتاً"],
            resume: ["Resumed by vendor", "استأنفها المورد"],
            cancel: ["Cancelled by vendor", "ألغاها المورد"],
            suspend: ["Suspended by administrator", "علقها المسؤول"],
            reinstate: ["Reinstated by administrator", "أعادها المسؤول"],
        };
        for (const event of data.events) {
            const line = text("p", "");
            line.append(
                t(
                    "span",
                    ...(eventNames[event.action] || [
                        "Campaign event",
                        "حدث حملة",
                    ]),
                ),
                document.createTextNode(" · "),
            );
            if (event.action === "created")
                line.append(
                    t(
                        "span",
                        "Paid package units reserved; not an additional cash charge.",
                        "حُجزت وحدات الحزمة المدفوعة؛ دون رسوم نقدية إضافية.",
                    ),
                );
            else line.append(text("span", event.reason));
            line.append(
                document.createTextNode(" · "),
                localDate(event.created_at),
            );
            content.append(line);
        }
        if (!data.deliveries.data.length)
            content.append(
                t(
                    "p",
                    "No verified publisher events. Do not infer impressions, clicks or revenue from an approved campaign.",
                    "لا توجد أحداث ناشر موثقة. اعتماد الحملة لا يثبت مشاهدات أو نقرات أو إيرادات.",
                ),
            );
        else
            for (const d of data.deliveries.data) {
                const line = text("p", "");
                line.append(
                    d.kind === "delivery"
                        ? t(
                              "span",
                              "Verified server delivery",
                              "تسليم خادم موثق",
                          )
                        : t(
                              "span",
                              "Attributed server click",
                              "نقرة خادم منسوبة",
                          ),
                    document.createTextNode(" · "),
                    localDate(d.occurred_at),
                );
                content.append(line);
            }
        pager(data.deliveries, (next) => detail(id, next));
    }
    if (["vendor", "admin"].includes(role))
        button(
            container,
            "Open commercial campaigns",
            "فتح الحملات التجارية",
            () => list(),
        );
};
