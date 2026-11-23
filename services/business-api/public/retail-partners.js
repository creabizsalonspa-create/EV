(() => {
    const $ = (id) => document.getElementById(id);
    const messages = {
        create: ["Registered", "مسجّل"],
        home: ["Workspace", "مساحة العمل"],
        rewardReviews: ["Partner reward reviews", "مراجعات مكافآت الشريك"],
        rewardId: ["Existing reward ID", "رقم المكافأة الحالية"],
        proposeReward: ["Propose reward", "اقتراح مكافأة"],
        rewardApprove: ["Approve reward proposal", "الموافقة على اقتراح المكافأة"],
        rewardReject: ["Reject reward proposal", "رفض اقتراح المكافأة"],
        rewardRevoke: ["Revoke reward approval", "إلغاء الموافقة على المكافأة"],
        rewardRecorded: ["Recorded reward proposals", "اقتراحات المكافآت المسجلة"],
        rewardReviewOnly: ["Approval records eligibility for review. Partner fulfillment is disabled.", "تسجّل الموافقة الأهلية للمراجعة. تسليم المكافآت عبر الشريك معطّل."],
        rewardTermsStale: ["Partner terms have changed", "تغيّرت شروط الشريك"],
        propose: ["Proposed", "مقترحة"],
        rewardHistory: ["Reward decision history", "سجل قرارات المكافأة"],
        language: ["Language", "اللغة"],
        theme: ["Theme", "المظهر"],
        system: ["System", "النظام"],
        light: ["Light", "فاتح"],
        dark: ["Dark", "داكن"],
        title: ["Retail partner governance", "إدارة شركاء التجزئة"],
        notice: [
            "Internal reviewed partner records. This registry does not enable rewards, money movement or live external fulfillment.",
            "سجلات شركاء داخلية خاضعة للمراجعة. لا يُفعّل هذا السجل المكافآت أو تحويل الأموال أو التنفيذ الخارجي الفعلي.",
        ],
        search: ["Reference search", "البحث بالمرجع"],
        searchButton: ["Search", "بحث"],
        previous: ["Previous", "السابق"],
        next: ["Next", "التالي"],
        new: ["Register a retail partner", "تسجيل شريك تجزئة"],
        reference: ["Reference", "المرجع"],
        legalName: ["Legal name", "الاسم القانوني"],
        name: ["English display name", "الاسم المعروض بالإنجليزية"],
        nameAr: ["Arabic display name", "الاسم المعروض بالعربية"],
        country: ["Country code", "رمز الدولة"],
        category: ["Category", "الفئة"],
        website: ["HTTPS website (optional)", "الموقع الآمن (اختياري)"],
        from: ["Effective from (UTC)", "ساري من (التوقيت العالمي)"],
        until: ["Expires at (UTC)", "الانتهاء (التوقيت العالمي)"],
        terms: ["Reviewed contract terms", "شروط العقد المراجعة"],
        reason: ["Decision reason", "سبب القرار"],
        save: ["Save immutable draft", "حفظ مسودة ثابتة"],
        discard: ["Discard editor / new request", "إلغاء المحرر / طلب جديد"],
        retail: ["Retail", "تجزئة"],
        hospitality: ["Hospitality", "ضيافة"],
        food: ["Food", "أغذية"],
        automotive: ["Automotive", "سيارات"],
        services: ["Services", "خدمات"],
        loading: ["Loading current records…", "جارٍ تحميل السجلات الحالية…"],
        empty: [
            "No partners match this reference.",
            "لا يوجد شركاء مطابقون لهذا المرجع.",
        ],
        open: ["Review partner", "مراجعة الشريك"],
        draft: [
            "Draft — independent review required",
            "مسودة — تتطلب مراجعة مستقلة",
        ],
        active: ["Approved", "معتمد"],
        rejected: ["Rejected", "مرفوض"],
        suspended: ["Suspended", "معلّق"],
        terminated: ["Terminated", "منتهي"],
        approve: ["Approve", "اعتماد"],
        reject: ["Reject draft", "رفض المسودة"],
        suspend: ["Suspend", "تعليق"],
        reinstate: ["Reinstate", "إعادة التفعيل"],
        terminate: ["Terminate permanently", "إنهاء نهائي"],
        revise: ["Revise terms", "مراجعة الشروط"],
        effective: ["Currently effective", "ساري حاليًا"],
        notEffective: ["Not currently effective", "غير سارٍ حاليًا"],
        history: ["Immutable decision history", "سجل القرارات الثابت"],
        older: ["Older decisions", "قرارات أقدم"],
        newer: ["Newer decisions", "قرارات أحدث"],
        ready: ["Current records loaded.", "تم تحميل السجلات الحالية."],
        saved: [
            "Saved. Review the current record before another decision.",
            "تم الحفظ. راجع السجل الحالي قبل اتخاذ قرار آخر.",
        ],
        error: [
            "Request failed. Refresh the record before retrying; no automatic decision was sent.",
            "تعذر الطلب. حدّث السجل قبل المحاولة؛ لم يُرسل قرار تلقائي.",
        ],
        expired: [
            "Session unavailable. Return to the workspace to sign in.",
            "الجلسة غير متاحة. عُد لمساحة العمل لتسجيل الدخول.",
        ],
        conflict: [
            "Record or request changed. Refresh and review before deciding.",
            "تغيّر السجل أو الطلب. حدّثه وراجعه قبل اتخاذ القرار.",
        ],
        invalid: [
            "Check the fields, date range, configured country and HTTPS website.",
            "تحقق من الحقول والفترة الزمنية والدولة المهيأة والموقع الآمن.",
        ],
        decision: ["Reviewed governance decision", "قرار إداري خاضع للمراجعة"],
        version: ["Terms version", "إصدار الشروط"],
        noMoney: [
            "No reward or payment authorization",
            "لا يوجد تفويض بمكافآت أو دفع",
        ],
        refresh: ["Refresh records", "تحديث السجلات"],
    };
    let lang = "en";
    try {
        if (localStorage.getItem("creabiz.locale") === "ar") lang = "ar";
    } catch {}
    const t = (key) => messages[key]?.[lang === "ar" ? 1 : 0] || key;
    let page = 1,
        last = 1,
        actor = null,
        generation = 0,
        busy = false,
        formKey = null,
        selected = null;
    const node = (tag, text) => {
        const n = document.createElement(tag);
        if (text !== undefined) n.textContent = text;
        return n;
    };
    const status = (key) => {
        $("status").textContent = t(key);
    };
    function clearPrivate() {
        generation++;
        actor = null;
        selected = null;
        $("partners").replaceChildren();
        $("detail").replaceChildren();
        $("detail").hidden = true;
        $("workspace").hidden = true;
        $("partner-form").reset();
        formKey = null;
    }
    async function request(path, options = {}) {
        const response = await fetch(path, {
            credentials: "same-origin",
            ...options,
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
                ...options.headers,
            },
        });
        if (!response.ok) {
            const e = new Error();
            e.status = response.status;
            throw e;
        }
        return response.json();
    }
    function fail(e) {
        if ([401, 403, 419].includes(e.status)) {
            clearPrivate();
            status("expired");
        } else
            status(
                e.status === 409
                    ? "conflict"
                    : e.status === 422
                      ? "invalid"
                      : "error",
            );
    }
    function button(key, fn) {
        const b = node("button", t(key));
        b.type = "button";
        b.addEventListener("click", fn);
        return b;
    }
    function applyLanguage() {
        document.documentElement.lang = lang;
        document.documentElement.dir = lang === "ar" ? "rtl" : "ltr";
        $("language").value = lang;
        document.querySelectorAll("[data-t]").forEach((n) => {
            n.textContent = t(n.dataset.t);
        });
    }
    async function load() {
        const ticket = ++generation;
        $("partners").replaceChildren();
        $("detail").hidden = true;
        selected = null;
        status("loading");
        try {
            const me = await request("/api/me");
            if (ticket !== generation || document.hidden) return;
            if (me.role !== "admin") {
                clearPrivate();
                status("expired");
                return;
            }
            const nextActor = `${me.id}:${me.auth_epoch}`;
            if (actor && actor !== nextActor) {
                $("partner-form").reset();
                formKey = null;
                $("editor").open = false;
            }
            actor = nextActor;
            const data = await request(
                `/api/admin/retail-partners?page=${page}&search=${encodeURIComponent($("search").value.trim())}`,
            );
            if (ticket !== generation || document.hidden) return;
            $("workspace").hidden = false;
            last = data.last_page;
            $("page").textContent = `${data.current_page} / ${last}`;
            $("previous").disabled = page <= 1;
            $("next").disabled = page >= last;
            for (const partner of data.data) {
                const card = node("article");
                const state = node("p", t(partner.status));
                state.id = `retail-partner-status-${partner.id}`;
                card.append(
                    node(
                        "h2",
                        lang === "ar"
                            ? partner.version.display_name_ar
                            : partner.version.display_name,
                    ),
                    node("bdi", partner.reference),
                    state,
                    button("open", () => detail(partner.id)),
                );
                $("partners").append(card);
            }
            if (!data.data.length) $("partners").append(node("p", t("empty")));
            status("ready");
        } catch (e) {
            if (ticket === generation) fail(e);
        }
    }
    async function detail(id, eventPage = 1) {
        const ticket = ++generation;
        selected = null;
        $("detail").replaceChildren();
        $("detail").hidden = true;
        status("loading");
        try {
            const data = await request(
                `/api/admin/retail-partners/${id}?page=${eventPage}`,
            );
            if (ticket !== generation || document.hidden || !actor) return;
            selected = data.partner;
            const p = selected,
                v = p.version,
                box = $("detail");
            const listStatus = $(`retail-partner-status-${p.id}`);
            if (listStatus) listStatus.textContent = t(p.status);
            box.hidden = false;
            box.append(
                node("h2", lang === "ar" ? v.display_name_ar : v.display_name),
                node("p", v.legal_name),
                node("p", `${v.country} · ${t(v.category)}`),
                node("bdi", v.website || ""),
                node("bdi", p.reference),
                node("p", `${t(p.status)} · ${t("version")} ${v.version}`),
                node(
                    "p",
                    t(p.currently_effective ? "effective" : "notEffective"),
                ),
                node("p", t("noMoney")),
                node("pre", v.terms),
                node("bdi", `${v.effective_from} — ${v.expires_at}`),
            );
            const label = node("label", t("reason")),
                reason = node("textarea");
            reason.maxLength = 500;
            reason.minLength = 5;
            reason.dir = "auto";
            label.append(reason);
            box.append(label);
            const actions = node("div");
            actions.className = "retail-actions";
            const available = {
                draft: ["approve", "reject", "revise", "terminate"],
                rejected: ["revise", "terminate"],
                active: ["suspend", "terminate"],
                suspended: ["reinstate", "revise", "terminate"],
                terminated: [],
            }[p.status];
            for (const action of available)
                actions.append(
                    button(action, () =>
                        action === "revise"
                            ? edit(p)
                            : decide(p, action, reason.value),
                    ),
                );
            box.append(
                actions,
                button("refresh", () => detail(id, eventPage)),
                node("h3", t("history")),
            );
            for (const event of data.events.data) {
                const history = node("details");
                history.append(
                    node(
                        "summary",
                        `${event.revision} · ${t(event.action)} · ${event.created_at}`,
                    ),
                    node("pre", event.reason),
                    node("p", event.version_snapshot.legal_name),
                    node("pre", event.version_snapshot.terms),
                );
                box.append(history);
            }
            if (data.events.current_page > 1)
                box.append(button("newer", () => detail(id, eventPage - 1)));
            if (data.events.current_page < data.events.last_page)
                box.append(button("older", () => detail(id, eventPage + 1)));
            box.append(button("rewardReviews", () => {
                box.querySelector("[data-reward-reviews]")?.remove();
                const panel = node("section");
                panel.dataset.rewardReviews = "true";
                box.append(panel);
                loadRewardReviews(p, panel);
            }));
            status("ready");
        } catch (e) {
            if (ticket === generation) fail(e);
        }
    }
    async function loadRewardReviews(partner, panel, page = 1) {
        const ticket = generation;
        const owner = actor;
        const active = () => ticket === generation && owner === actor && selected === partner
            && panel.isConnected && !document.hidden;
        const endpoint = `/api/admin/retail-partners/${partner.id}/reward-reviews`;
        panel.replaceChildren();
        status("loading");
        try {
            const rows = await request(`${endpoint}?page=${page}`);
            if (!active()) return;
            panel.append(node("h3", t("rewardRecorded")), node("p", t("rewardReviewOnly")));
            function input(parent, key, tag) {
                const label = node("label", t(key));
                const field = node(tag);
                label.append(field);
                parent.append(label);
                return field;
            }
            function write(parent, key, path, data) {
                let previous, requestKey;
                parent.append(button(key, async () => {
                    if (!active() || busy) return;
                    const payload = data();
                    if (!payload) {
                        status("invalid");
                        return;
                    }
                    const fingerprint = JSON.stringify(payload);
                    if (fingerprint !== previous) {
                        previous = fingerprint;
                        requestKey = crypto.randomUUID();
                    }
                    busy = true;
                    try {
                        await request(endpoint + path, {
                            method: "POST",
                            body: JSON.stringify({ ...payload, idempotency_key: requestKey }),
                        });
                        if (active()) await loadRewardReviews(partner, panel, page);
                    } catch (error) {
                        if (active()) fail(error);
                    } finally {
                        busy = false;
                    }
                }));
            }
            if (partner.currently_effective) {
                const reward = input(panel, "rewardId", "input");
                reward.inputMode = "numeric";
                const reason = input(panel, "reason", "textarea");
                reason.maxLength = 500;
                write(panel, "proposeReward", "", () => {
                    const id = Number(reward.value);
                    return /^[0-9]+$/.test(reward.value) && id > 0 && Number.isSafeInteger(id)
                        && reason.value.trim().length >= 5
                        ? { reward_id: id, expected_partner_revision: partner.revision, reason: reason.value.trim() }
                        : null;
                });
            }
            for (const row of rows.data) {
                const card = node("article");
                card.dataset.rewardReview = row.id;
                card.append(node("p", `#${row.reward_id} · ${t(row.decision)}`), node("p", row.reason));
                if (!row.terms_current) card.append(node("p", t("rewardTermsStale")));
                const decisions = row.decision === "propose"
                    ? [...(row.terms_current ? [["rewardApprove", "approve"]] : []), ["rewardReject", "reject"]]
                    : row.decision === "approve" ? [["rewardRevoke", "revoke"]] : [];
                if (decisions.length) {
                    const reason = input(card, "reason", "textarea");
                    reason.maxLength = 500;
                    for (const [key, action] of decisions) {
                        write(card, key, `/${row.id}/${action}`, () => reason.value.trim().length >= 5
                            ? { expected_revision: row.revision, reason: reason.value.trim() }
                            : null);
                    }
                }
                const history = node("div");
                async function readHistory(eventPage = 1) {
                    history.replaceChildren();
                    try {
                        const data = await request(`${endpoint}/${row.id}?page=${eventPage}`);
                        if (!active()) return;
                        for (const event of data.events.data)
                            history.append(node("p", `${event.revision} · ${t(event.decision)} · ${event.reason}`));
                        if (data.events.current_page > 1)
                            history.append(button("newer", () => readHistory(eventPage - 1)));
                        if (data.events.current_page < data.events.last_page)
                            history.append(button("older", () => readHistory(eventPage + 1)));
                    } catch (error) {
                        if (active()) fail(error);
                    }
                }
                card.append(button("rewardHistory", () => readHistory()), history);
                panel.append(card);
            }
            if (page > 1) panel.append(button("newer", () => loadRewardReviews(partner, panel, page - 1)));
            if (page < rows.last_page) panel.append(button("older", () => loadRewardReviews(partner, panel, page + 1)));
            status("ready");
        } catch (error) {
            if (active()) fail(error);
        }
    }
    function edit(p) {
        if (busy || selected !== p) return;
        const f = $("partner-form");
        f.reset();
        formKey = null;
        for (const key of [
            "legal_name",
            "display_name",
            "display_name_ar",
            "country",
            "category",
            "website",
            "terms",
        ])
            f.elements[key].value = p.version[key] || "";
        f.elements.partner_id.value = p.id;
        f.elements.expected_revision.value = p.revision;
        f.elements.reference.value = p.reference;
        f.elements.reference.disabled = true;
        for (const key of ["effective_from", "expires_at"])
            f.elements[key].value = new Date(
                p.version[key].replace(" ", "T") +
                    (p.version[key].endsWith("Z") ? "" : "Z"),
            )
                .toISOString()
                .slice(0, 16);
        $("editor").open = true;
        f.elements.legal_name.focus();
    }
    async function decide(p, action, reason) {
        if (busy || selected !== p || reason.trim().length < 5) {
            status("invalid");
            return;
        }
        busy = true;
        const ticket = generation;
        const owner = actor;
        $("detail")
            .querySelectorAll("button")
            .forEach((b) => (b.disabled = true));
        try {
            await request(`/api/admin/retail-partners/${p.id}/${action}`, {
                method: "POST",
                body: JSON.stringify({
                    expected_revision: p.revision,
                    reason: reason.trim(),
                    idempotency_key: crypto.randomUUID(),
                }),
            });
            if (ticket !== generation || owner !== actor || document.hidden)
                return;
            await detail(p.id);
            status("saved");
        } catch (e) {
            if (ticket === generation) {
                $("detail").replaceChildren(
                    button("refresh", () => detail(p.id)),
                );
                selected = null;
                fail(e);
            }
        } finally {
            busy = false;
        }
    }
    $("partner-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !actor) return;
        busy = true;
        const f = event.currentTarget;
        const payload = Object.fromEntries(new FormData(f));
        const id = payload.partner_id;
        delete payload.partner_id;
        if (!id) delete payload.expected_revision;
        else payload.expected_revision = Number(payload.expected_revision);
        for (const key of ["effective_from", "expires_at"])
            payload[key] += ":00+00:00";
        payload.website = payload.website || null;
        formKey ||= crypto.randomUUID();
        payload.idempotency_key = formKey;
        const ticket = generation,
            owner = actor;
        f.querySelectorAll("input,textarea,select,button").forEach(
            (n) => (n.disabled = true),
        );
        try {
            await request(
                `/api/admin/retail-partners${id ? `/${id}/revise` : ""}`,
                { method: "POST", body: JSON.stringify(payload) },
            );
            if (ticket !== generation || owner !== actor || document.hidden)
                return;
            f.reset();
            formKey = null;
            $("editor").open = false;
            await load();
            status("saved");
        } catch (e) {
            if (ticket === generation) fail(e);
        } finally {
            busy = false;
            f.querySelectorAll("input,textarea,select,button").forEach(
                (n) => (n.disabled = false),
            );
            f.elements.reference.disabled = Boolean(
                f.elements.partner_id.value,
            );
        }
    });
    $("discard").addEventListener("click", () => {
        if (!busy) {
            $("partner-form").reset();
            $("partner-form").elements.reference.disabled = false;
            formKey = null;
        }
    });
    $("search-form").addEventListener("submit", (e) => {
        e.preventDefault();
        if (!busy) {
            page = 1;
            load();
        }
    });
    $("previous").addEventListener("click", () => {
        if (!busy && page > 1) {
            page--;
            load();
        }
    });
    $("next").addEventListener("click", () => {
        if (!busy && page < last) {
            page++;
            load();
        }
    });
    $("language").addEventListener("change", () => {
        lang = $("language").value;
        try {
            localStorage.setItem("creabiz.locale", lang);
        } catch {}
        applyLanguage();
        if (!busy) load();
    });
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) clearPrivate();
        else load();
    });
    window.addEventListener("pagehide", clearPrivate);
    window.addEventListener("storage", (e) => {
        if (e.key === null || e.key === "creabiz.locale") {
            lang = e.newValue === "ar" ? "ar" : "en";
            applyLanguage();
            clearPrivate();
            if (!document.hidden) load();
        }
    });
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) {
            clearPrivate();
            if (!document.hidden) load();
        }
    });
    applyLanguage();
    load();
})();
