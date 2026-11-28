/* Sponsored package operations. This client never proves a view, pays a bill or changes charger capabilities. */
window.mountCommercialCampaigns = ({ container, api, ui, actor }) => {
    const owner = actor()?.id,
        role = actor()?.role,
        abort = new AbortController();
    let busy = false,
        generation = 0,
        currentPage = 1;
    const alive = () => container.isConnected && actor()?.id === owner &&
        actor()?.role === role && !abort.signal.aborted;
    const watch = new MutationObserver(() => {
        if (!alive()) {
            generation++;
            abort.abort();
            pendingCreation = null;
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
        b.onclick = () => { if (b.isConnected && container.contains(b)) return perform(fn); };
        parent.append(b);
        return b;
    }
    function form(parent, fields, en, ar, fn) {
        const f = document.createElement("form");
        f.className = "finance-form";
        let key = crypto.randomUUID();
        for (const field of fields) {
            const label = document.createElement("label"),
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
            label.append(t("span", field.en, field.ar), input);
            f.append(label);
        }
        const submit = t("button", en, ar);
        submit.type = "submit";
        f.append(submit);
        f.oninput = () => (key = crypto.randomUUID());
        f.onsubmit = (e) => {
            e.preventDefault();
            if (f.isConnected && alive() && f.reportValidity())
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
    const positive = value => Number.isSafeInteger(value) && value > 0;
    const nonnegative = value => Number.isSafeInteger(value) && value >= 0;
    const revision = value => typeof value === "string" &&
        /^[1-9][0-9]{0,15}$/.test(value) && positive(Number(value));
    const terms = ["id", "vendor_id", "invoice_id", "station_id", "budget_units",
        "title", "title_ar", "description", "description_ar", "country",
        "region", "placement", "starts_at", "ends_at"];
    const sameTerms = (a, b) => terms.every(key => a[key] === b[key]);
    function instant(value) {
        if (typeof value !== "string") return NaN;
        return Date.parse(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
            ? value.replace(" ", "T") + "Z" : value);
    }
    function verifyCampaign(c, expected = null) {
        if (!c || !["id", "vendor_id", "invoice_id", "station_id", "budget_units"].every(k => positive(c[k])) ||
            (expected !== null && c.id !== expected) ||
            (role === "vendor" && c.vendor_id !== owner) ||
            c.budget_units > 1000000 || !nonnegative(c.delivered_units) ||
            !nonnegative(c.verified_server_clicks) || c.delivered_units > c.budget_units ||
            c.verified_server_clicks > c.delivered_units || !revision(c.control_revision) ||
            !Object.hasOwn(states, c.status) || !["discovery_banner", "station_detail"].includes(c.placement) ||
            typeof c.country !== "string" || !/^[A-Z]{2}$/.test(c.country) ||
            (c.region !== null && (typeof c.region !== "string" || c.region.length > 100)) ||
            !["title", "title_ar", "description", "description_ar"].every(k =>
                typeof c[k] === "string" && c[k].trim().length &&
                c[k].length <= (k.startsWith("title") ? 120 : 500)) ||
            !(instant(c.ends_at) > instant(c.starts_at)) ||
            c.unit !== "PREPAID_PACKAGE_DELIVERY_UNIT" || c.sponsored !== true ||
            c.additional_cash_charge !== false || c.human_impressions_verified !== false ||
            "request_key" in c || "fingerprint" in c)
            throw Error("Campaign source cannot be verified.");
        return c;
    }
    function verifyPage(data, page) {
        if (!positive(page) || page > 100000 || !data || !Array.isArray(data.data) ||
            data.current_page !== page || data.per_page !== 25 || !nonnegative(data.total) ||
            data.last_page !== Math.max(1, Math.ceil(data.total / 25)) ||
            data.data.length !== Math.min(25, Math.max(0, data.total - (page - 1) * 25)))
            throw Error("Campaign page cannot be verified.");
    }
    const transitions = {
        submit: ["draft", "submitted"], approve: ["submitted", "approved"],
        reject: ["submitted", "rejected"], pause: ["approved", "paused"],
        resume: ["paused", "approved"], suspend: ["approved", "suspended"],
        reinstate: ["suspended", "approved"],
    };
    function verifyHistory(events, c) {
        if (!Array.isArray(events) || !events.length)
            throw Error("Campaign history cannot be verified.");
        let previous = 0, status = null;
        for (const event of events) {
            if (!event || !positive(event.id) || event.id <= previous ||
                event.campaign_id !== c.id || !positive(event.actor_id) ||
                typeof event.reason !== "string" || event.reason.length > 500 ||
                !Number.isFinite(instant(event.created_at)))
                throw Error("Invalid campaign history.");
            if (status === null) {
                if (event.action !== "created" || event.actor_id !== c.vendor_id)
                    throw Error("Invalid campaign creation authority.");
                status = "draft";
            } else if (event.action === "cancel") {
                if (!["draft", "submitted", "approved", "paused", "suspended"].includes(status) ||
                    event.actor_id !== c.vendor_id)
                    throw Error("Invalid campaign cancellation history.");
                status = "cancelled";
            } else {
                const next = transitions[event.action];
                if (!next || next[0] !== status ||
                    (["submit", "pause", "resume"].includes(event.action) && event.actor_id !== c.vendor_id))
                    throw Error("Invalid campaign control history.");
                status = next[1];
            }
            previous = event.id;
        }
        if (previous !== Number(c.control_revision) || status !== c.status)
            throw Error("Campaign revision does not match history.");
    }
    function verifyDeliveryHistory(page, c) {
        if (page.total !== c.delivered_units + c.verified_server_clicks)
            throw Error("Invalid campaign delivery history.");
        const parents = new Set(), rows = new Map();
        let previous = Infinity, deliveryCount = 0, clickCount = 0;
        for (const row of page.data) {
            if (!row || !positive(row.id) || row.id >= previous || row.campaign_id !== c.id ||
                !["delivery", "click"].includes(row.kind) ||
                !Number.isFinite(instant(row.occurred_at)) || !Number.isFinite(instant(row.created_at)) ||
                Object.hasOwn(row,"event_id") || Object.hasOwn(row,"fingerprint") ||
                (row.kind === "delivery" && row.parent_delivery_id !== null) ||
                (row.kind === "click" && (!positive(row.parent_delivery_id) ||
                    row.parent_delivery_id >= row.id || parents.has(row.parent_delivery_id))))
                throw Error("Invalid campaign delivery history.");
            if (row.kind === "click") {
                parents.add(row.parent_delivery_id);
                clickCount++;
            } else deliveryCount++;
            rows.set(row.id, row);
            previous = row.id;
        }
        if (deliveryCount > c.delivered_units || clickCount > c.verified_server_clicks)
            throw Error("Invalid campaign delivery history.");
        for (const row of rows.values()) {
            if (row.kind !== "click" || !rows.has(row.parent_delivery_id)) continue;
            const parent = rows.get(row.parent_delivery_id);
            if (parent.kind !== "delivery" || instant(parent.occurred_at) > instant(row.occurred_at))
                throw Error("Invalid campaign delivery history.");
        }
    }
    function verifyProgress(old, next) {
        if (old && (!sameTerms(old, next) ||
            Number(next.control_revision) < Number(old.control_revision) ||
            next.delivered_units < old.delivered_units ||
            next.verified_server_clicks < old.verified_server_clicks ||
            (old.control_revision === next.control_revision && old.status !== next.status)))
            throw Error("Campaign evidence regressed.");
    }
    function state(parent, value) {
        const n = t(
            "p",
            ...(states[value] || ["Review required", "تحتاج مراجعة"]),
        );
        n.dataset.campaignState = value;
        parent.append(n);
    }
    // Keep only an opaque actor-scoped creation identity in tab storage.
    // Creative, package details and other private values stay in memory.
    // A remounted tab can recover the receipt or cancel the unused key,
    // never silently generate a replacement request.
    const intentSlot = "ev.campaign.creation.v1." + owner;
    const validKey = key => typeof key === "string" &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(key);
    let pendingCreation = null;
    function savedKey() {
        const key = sessionStorage.getItem(intentSlot);
        if (key !== null && !validKey(key))
            throw Error("Saved campaign identity requires assisted review.");
        return key;
    }
    function recoverableCreation() {
        const key = savedKey();
        if (key === null) {
            pendingCreation = null;
            return null;
        }
        if (!pendingCreation || pendingCreation.key !== key)
            pendingCreation = {key, payload: null};
        return pendingCreation;
    }
    function verifyCreated(c, payload) {
        if (payload && (!["invoice_id", "station_id", "budget_units", "title",
            "title_ar", "description", "description_ar", "country", "region",
            "placement"].every(k => c[k] === payload[k]) ||
            Math.floor(instant(c.starts_at) / 1000) !== Math.floor(instant(payload.starts_at) / 1000) ||
            Math.floor(instant(c.ends_at) / 1000) !== Math.floor(instant(payload.ends_at) / 1000)))
            throw Error("Campaign creation receipt cannot be verified.");
        return c;
    }
    function finishCreation(intent) {
        if (!alive() || savedKey() !== intent.key)
            throw Error("Saved campaign identity changed.");
        sessionStorage.removeItem(intentSlot);
        pendingCreation = null;
    }
    async function sendCreation(intent, resolve = false) {
        if (role !== "vendor" || !alive() || savedKey() !== intent.key)
            throw Error("Saved campaign identity changed.");
        if (!resolve && !intent.payload)
            throw Error("Recover the saved creation before starting another.");
        const result = resolve
            ? await request("commercial/campaign-intents/cancel", "POST", {idempotency_key: intent.key})
            : await request("commercial/campaigns", "POST", intent.payload);
        if (resolve && result.cancelled === true && result.campaign === null) {
            finishCreation(intent);
            await create();
            status.textContent = "Unused campaign creation intent cancelled.";
            return;
        }
        if (resolve && result.cancelled !== false)
            throw Error("Campaign recovery receipt cannot be verified.");
        const created = verifyCreated(verifyCampaign(result.campaign), intent.payload);
        finishCreation(intent);
        await detail(created.id, 1, created);
    }
    function recoveryView(intent) {
        content.replaceChildren();
        nav();
        content.append(
            t("h4", "Unresolved campaign creation", "طلب إنشاء حملة غير محسوم"),
            t("p", "Do not start another campaign until the saved request is resolved. Recovery does not cancel an already created campaign.",
                "لا تبدأ حملة أخرى حتى تتم معالجة الطلب المحفوظ. لا تؤدي المعالجة إلى إلغاء حملة أُنشئت بالفعل."),
        );
        if (intent.payload)
            button(content, "Retry exact campaign creation", "إعادة طلب إنشاء الحملة نفسه",
                () => sendCreation(intent));
        button(content, "Recover or cancel unused creation", "استعادة الطلب أو إلغاء الإنشاء غير المستخدم",
            () => sendCreation(intent, true));
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
        if (data.current_page < data.last_page && data.current_page < 100000)
            button(content, "Next page", "الصفحة التالية", () =>
                fn(data.current_page + 1),
            );
    }
    function verifyPackages(data, page) {
        verifyPage(data, page);
        const ids = new Set();
        for (const p of data.data) {
            if (!p || !positive(p.invoice_id) || !positive(p.vendor_id) ||
                (role === "vendor" && p.vendor_id !== owner) || ids.has(p.invoice_id) ||
                typeof p.number !== "string" || typeof p.campaign_eligible !== "boolean" ||
                !/^[A-Z]{3}$/.test(p.currency) || !nonnegative(p.total_minor) ||
                !Number.isInteger(p.minor_units) || p.minor_units < 0 || p.minor_units > 3 ||
                !(instant(p.period_end) > instant(p.period_start)) ||
                typeof p.evidence !== "string" ||
                (p.campaign_eligible && (!p.entitlements ||
                    !["campaign_slots", "campaign_units"].every(k =>
                        positive(p.entitlements[k]) && p.entitlements[k] <= 1000000) ||
                    p.total_minor === 0 || p.evidence === "UNPAID")))
                throw Error("Campaign package source cannot be verified.");
            ids.add(p.invoice_id);
        }
    }
    async function packages(page = 1) {
        const seq = ++generation;
        content.replaceChildren();
        nav();
        const data = await request(`commercial/campaign-packages?page=${page}`);
        if (!alive() || seq !== generation) return;
        verifyPackages(data, page);
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
                text("span", ` · ${p.entitlements?.campaign_slots || 0} · `),
                t("span", "Delivery units", "وحدات التسليم"),
                text("span", ` · ${p.entitlements?.campaign_units || 0}`),
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
        if (role !== "vendor") return;
        const saved = recoverableCreation();
        if (saved) {
            recoveryView(saved);
            return;
        }
        const seq = ++generation;
        content.replaceChildren();
        nav();
        const [packages, stations] = await Promise.all([
            request(`commercial/campaign-packages?page=${packagePage}`),
            request(`commercial/campaign-stations?page=${stationPage}`),
        ]);
        if (!alive() || seq !== generation) return;
        content.replaceChildren();
        nav();
        verifyPackages(packages, packagePage);
        verifyPage(stations, stationPage);
        const stationIds = new Set();
        for (const station of stations.data) {
            if (!station || !positive(station.id) || stationIds.has(station.id) ||
                typeof station.name !== "string" || !/^[A-Z]{2}$/.test(station.country))
                throw Error("Invalid owned station source.");
            stationIds.add(station.id);
        }
        const eligible = packages.data.filter((p) => p.campaign_eligible);
        if (!eligible.length || !stations.data.length) {
            content.append(
                t(
                    "p",
                    "An eligible paid package and an owned station are required. Existing subscriptions and station operations remain separate.",
                    "يلزم توفر حزمة مدفوعة مؤهلة ومحطة مملوكة. تبقى إدارة الاشتراكات والمحطات مستقلة.",
                ),
            );
            if (packages.current_page > 1)
                button(content, "Previous package choices", "خيارات الحزم السابقة",
                    () => create(packagePage - 1, stationPage));
            if (packages.current_page < packages.last_page && packagePage < 100000)
                button(content, "Next package choices", "خيارات الحزم التالية",
                    () => create(packagePage + 1, stationPage));
            if (stations.current_page > 1)
                button(content, "Previous station choices", "خيارات المحطات السابقة",
                    () => create(packagePage, stationPage - 1));
            if (stations.current_page < stations.last_page && stationPage < 100000)
                button(content, "Next station choices", "خيارات المحطات التالية",
                    () => create(packagePage, stationPage + 1));
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
                if (!eligible.some(p => p.invoice_id === Number(v.invoice_id)) ||
                    !stations.data.some(s => s.id === Number(v.station_id) && s.country === v.country) ||
                    !positive(Number(v.budget_units)) || Number(v.budget_units) > 1000000)
                    throw Error("Select the verified package, station and delivery budget.");
                if (recoverableCreation())
                    throw Error("Resolve the saved campaign creation first.");
                if (!validKey(key)) throw Error("Invalid campaign creation identity.");
                const payload = Object.freeze({
                    ...v,
                    title: v.title.trim(), title_ar: v.title_ar.trim(),
                    description: v.description.trim(), description_ar: v.description_ar.trim(),
                    invoice_id: Number(v.invoice_id),
                    station_id: Number(v.station_id),
                    budget_units: Number(v.budget_units),
                    region: v.region.trim() || null,
                    idempotency_key: key,
                });
                // Fail closed if storage is unavailable. The identity must be
                // saved before the first potentially committing POST.
                sessionStorage.setItem(intentSlot, key);
                if (savedKey() !== key) throw Error("Could not save campaign identity.");
                pendingCreation = {key, payload};
                recoveryView(pendingCreation);
                await sendCreation(pendingCreation);
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
    let reviewScope = { status: null, country: null, vendor_id: null, station_id: null, invoice_id: null };
    function reviewFilters(parent, results) {
        const formNode = document.createElement("form");
        formNode.className = "finance-form";
        formNode.dataset.campaignFilters = "true";
        const fields = [
            ["status", "Campaign status", "حالة الحملة"],
            ["country", "Country code", "رمز الدولة"],
            ...(role === "admin" ? [["vendor_id", "Vendor ID", "رقم البائع"]] : []),
            ["station_id", "Station ID", "رقم المحطة"],
            ["invoice_id", "Package invoice ID", "رقم فاتورة الحزمة"],
        ];
        for (const [name, en, ar] of fields) {
            const label = document.createElement("label");
            const input = document.createElement(name === "status" ? "select" : "input");
            input.name = name;
            if (name === "status") {
                const all = t("option", "All statuses", "جميع الحالات");
                all.value = "";
                input.append(all);
                for (const [value, labels] of Object.entries(states)) {
                    const option = t("option", ...labels);
                    option.value = value;
                    input.append(option);
                }
            } else {
                input.type = "text";
                input.maxLength = name === "country" ? 2 : 16;
                if (name !== "country") input.inputMode = "numeric";
            }
            input.value = reviewScope[name] ?? "";
            label.append(t("span", en, ar), input);
            formNode.append(label);
        }
        const apply = t("button", "Apply campaign filters", "تطبيق مرشحات الحملات");
        apply.type = "submit";
        formNode.append(apply);
        button(formNode, "Clear campaign filters", "مسح مرشحات الحملات", () => list(1, {
            status: null, country: null, vendor_id: null, station_id: null, invoice_id: null,
        }));
        formNode.oninput = () => {
            if (!alive() || busy) return;
            results.replaceChildren();
        };
        formNode.onsubmit = event => {
            event.preventDefault();
            if (!alive() || !formNode.isConnected || busy) return;
            const raw = Object.fromEntries(new FormData(formNode));
            const scope = { status: raw.status || null, country: raw.country.trim().toUpperCase() || null,
                vendor_id: null, station_id: null, invoice_id: null };
            if (scope.status !== null && !Object.hasOwn(states, scope.status) ||
                scope.country !== null && !/^[A-Z]{2}$/.test(scope.country)) {
                status.textContent = "Invalid campaign filters.";
                return;
            }
            for (const key of ["vendor_id", "station_id", "invoice_id"]) {
                const value = (raw[key] || "").trim();
                if (!value) continue;
                if (!/^[1-9][0-9]{0,15}$/.test(value) || !positive(Number(value))) {
                    status.textContent = "Invalid campaign filter identity.";
                    return;
                }
                scope[key] = Number(value);
            }
            perform(() => list(1, scope));
        };
        parent.append(formNode);
    }
    async function list(page = 1, scope = reviewScope) {
        if (role === "vendor") {
            const saved = recoverableCreation();
            if (saved) {
                recoveryView(saved);
                return;
            }
        }
        const seq = ++generation;
        content.replaceChildren();
        nav();
        reviewScope = { ...scope };
        const results = document.createElement("div");
        reviewFilters(content, results);
        content.append(results);
        const query = new URLSearchParams({ page: String(page) });
        for (const [key, value] of Object.entries(scope)) if (value !== null) query.set(key, String(value));
        const data = await request(`commercial/campaigns?${query}`);
        if (!alive() || seq !== generation) return;
        verifyPage(data, page);
        if (!data.scope || Object.keys(scope).some(key => data.scope[key] !== scope[key]))
            throw Error("Campaign filter source cannot be verified.");
        const ids = new Set();
        for (const c of data.data) {
            verifyCampaign(c);
            if (ids.has(c.id) || (role === "vendor" && c.vendor_id !== owner) ||
                Object.keys(scope).some(key => scope[key] !== null && c[key] !== scope[key]))
                throw Error("Campaign source does not match reviewed scope.");
            ids.add(c.id);
        }
        currentPage = page;
        if (!data.data.length)
            results.append(
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
            const deliveries = text("span", `${c.delivered_units}/${c.budget_units}`);
            deliveries.dataset.campaignDeliveryCount = "true";
            deliveries.dir = "ltr";
            const clicks = text("span", c.verified_server_clicks);
            clicks.dataset.campaignClickCount = "true";
            clicks.dir = "ltr";
            counts.append(
                t(
                    "span",
                    "Verified server deliveries",
                    "تسليمات الخادم الموثقة",
                ),
                text("span", " · "), deliveries, text("span", " · "),
                t("span", "Attributed server clicks", "نقرات الخادم المنسوبة"),
                text("span", " · "), clicks,
            );
            row.append(counts);
            button(row, "Review campaign", "مراجعة الحملة", () => detail(c.id, 1, c));
            results.append(row);
        }
        if (data.current_page > 1) button(results, "Previous page", "الصفحة السابقة", () => list(page - 1, scope));
        if (data.current_page < data.last_page && page < 100000) button(results, "Next page", "الصفحة التالية", () => list(page + 1, scope));
    }
    async function detail(id, deliveryPage = 1, previous = null) {
        const seq = ++generation;
        content.replaceChildren();
        nav();
        const data = await request(`commercial/campaigns/${id}?page=${deliveryPage}`);
        if (!alive() || seq !== generation) return;
        const c = verifyCampaign(data.campaign, id);
        verifyProgress(previous, c);
        verifyHistory(data.events, c);
        verifyPage(data.deliveries, deliveryPage);
        if (typeof data.currently_eligible !== "boolean" ||
            (data.currently_eligible && (c.status !== "approved" || c.delivered_units >= c.budget_units)))
            throw Error("Invalid campaign eligibility.");
        verifyDeliveryHistory(data.deliveries, c);
        content.replaceChildren();
        nav();
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
                    if (!v.reason || v.reason.trim().length < 5 || v.reason.length > 500)
                        throw Error("A decision reason of 5–500 characters is required.");
                    const result = await request(
                        `commercial/campaigns/${id}/${action}`,
                        "POST",
                        {...v, expected_control_revision: c.control_revision},
                    );
                    const receipt = verifyCampaign(result.campaign, id);
                    verifyProgress(c, receipt);
                    if (receipt.status !== (action === "cancel" ? "cancelled" : transitions[action]?.[1]) ||
                        Number(receipt.control_revision) <= Number(c.control_revision))
                        throw Error("Campaign decision receipt cannot be verified.");
                    if (alive()) await detail(id, 1, receipt);
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
        pager(data.deliveries, (next) => detail(id, next, c));
    }
    if (["vendor", "admin"].includes(role))
        button(
            container,
            "Open commercial campaigns",
            "فتح الحملات التجارية",
            () => list(),
        );
};
