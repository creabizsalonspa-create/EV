(() => {
    const $ = (id) => document.getElementById(id),
        el = (tag, text) => {
            const n = document.createElement(tag);
            if (text !== undefined) n.textContent = text;
            return n;
        };
    const messages = {
        home: ["Workspace", "مساحة العمل"],
        title: ["Referral programmes", "برامج الإحالة"],
        notice: [
            "Verified new members may opt in before their first charging payment. A qualifying paid invoice reserves two manual noncash rewards. No money, discount, points or delivery proof is created.",
            "يمكن للأعضاء الجدد الموثقين الموافقة قبل أول دفعة شحن. تحجز الفاتورة المدفوعة المؤهلة مكافأتين يدويتين غير نقديتين. لا يوجد تحويل أموال أو خصم أو نقاط أو إثبات تسليم.",
        ],
        refresh: ["Refresh programmes", "تحديث البرامج"],
        previous: ["Previous", "السابق"],
        next: ["Next", "التالي"],
        new: ["Publish immutable programme", "نشر برنامج ثابت"],
        termsNotice: [
            "Customers see both programme terms and the mandatory qualification rules. Stock and daily limits are shared with the canonical reward catalog. Revocation stops future qualifications; existing commitments remain subject to reviewed manual fulfillment.",
            "يرى العملاء شروط البرنامج وقواعد التأهل الإلزامية. تشترك البرامج في المخزون والحدود اليومية لكتالوج المكافآت المعتمد. يوقف الإلغاء التأهل المستقبلي وتظل الالتزامات القائمة خاضعة للتنفيذ اليدوي المراجع.",
        ],
        rewardsRefresh: ["Load reward choices", "تحميل خيارات المكافآت"],
        name: ["English name", "الاسم بالإنجليزية"],
        nameAr: ["Arabic name", "الاسم بالعربية"],
        terms: ["English programme terms", "شروط البرنامج بالإنجليزية"],
        termsAr: ["Arabic programme terms", "شروط البرنامج بالعربية"],
        currency: ["Qualifying invoice currency", "عملة الفاتورة المؤهلة"],
        minimum: [
            "Minimum qualifying invoice total",
            "الحد الأدنى لإجمالي الفاتورة المؤهلة",
        ],
        referrerReward: ["Inviter reward", "مكافأة المُحيل"],
        memberReward: ["New member reward", "مكافأة العضو الجديد"],
        limit: ["Programme qualification limit", "حد التأهل للبرنامج"],
        perReferrer: ["Qualifications per inviter", "عدد التأهلات لكل مُحيل"],
        starts: ["Starts at (UTC)", "البدء (التوقيت العالمي)"],
        ends: ["Ends at (UTC)", "الانتهاء (التوقيت العالمي)"],
        publish: ["Publish programme", "نشر البرنامج"],
        resolve: ["Find saved programme", "البحث عن البرنامج المحفوظ"],
        discard: ["Clear draft", "مسح المسودة"],
        loading: ["Loading current records…", "جارٍ تحميل السجلات الحالية…"],
        ready: ["Current records loaded.", "تم تحميل السجلات الحالية."],
        empty: ["No referral programmes.", "لا توجد برامج إحالة."],
        chooseReferrer: ["Choose for inviter", "اختيار للمُحيل"],
        chooseMember: ["Choose for new member", "اختيار للعضو الجديد"],
        active: ["Active for new qualifications", "ساري للتأهلات الجديدة"],
        inactive: [
            "Not active for new qualifications",
            "غير ساري للتأهلات الجديدة",
        ],
        reason: ["Reason for revocation", "سبب الإلغاء"],
        revoke: ["Stop future qualifications", "إيقاف التأهلات المستقبلية"],
        saved: [
            "Saved. No money or delivery was performed.",
            "تم الحفظ. لم يتم تحويل أموال أو تسليم مكافأة.",
        ],
        none: [
            "No saved programme found for this request. Retain the unchanged draft before an explicit retry.",
            "لم يُعثر على برنامج محفوظ لهذا الطلب. احتفظ بالمسودة الأصلية قبل إعادة المحاولة صراحة.",
        ],
        error: [
            "Request failed. Find the original saved programme before retrying an uncertain publication. Nothing was retried automatically.",
            "تعذر الطلب. ابحث عن البرنامج الأصلي المحفوظ قبل إعادة طلب نشر غير مؤكد. لم تُعد أي عملية تلقائيًا.",
        ],
        invalid: [
            "Check the fields, matching reward currencies, amount and shared reward date window.",
            "تحقق من الحقول وتطابق عملات المكافآت والمبلغ والفترة المشتركة للمكافآت.",
        ],
        expired: [
            "Session unavailable. Return to the workspace to sign in.",
            "الجلسة غير متاحة. عُد لمساحة العمل لتسجيل الدخول.",
        ],
        conflict: [
            "State or request identity changed. Refresh and review.",
            "تغيّرت الحالة أو هوية الطلب. حدّث البيانات وراجعها.",
        ],
        disabled: [
            "Test commercial economics are disabled. No new programme was published.",
            "اقتصاديات الاختبار التجاري معطلة. لم يُنشر برنامج جديد.",
        ],
    };
    let lang = "en";
    try {
        if (localStorage.getItem("creabiz.locale") === "ar") lang = "ar";
    } catch {}
    const t = (k) => messages[k]?.[lang === "ar" ? 1 : 0] || k;
    let generation = 0,
        actor = null,
        busy = false,
        page = 1,
        last = 1,
        rewardPage = 1,
        rewardLast = 1,
        key = null;
    const selected = {},
        precisions = new Map();
    const status = (k) => {
        $("status").textContent = t(k);
    };
    const button = (k, fn) => {
        const b = el("button", t(k));
        b.type = "button";
        b.addEventListener("click", fn);
        return b;
    };
    function translate() {
        document.documentElement.lang = lang;
        document.documentElement.dir = lang === "ar" ? "rtl" : "ltr";
        $("language").value = lang;
        document
            .querySelectorAll("[data-t]")
            .forEach((n) => (n.textContent = t(n.dataset.t)));
    }
    function clearDraft() {
        $("programme-form").reset();
        key = null;
        delete selected.referrer;
        delete selected.member;
        $("referrer-choice").textContent = "";
        $("member-choice").textContent = "";
    }
    function clear() {
        generation++;
        actor = null;
        clearDraft();
        $("programmes").replaceChildren();
        $("rewards").replaceChildren();
        $("workspace").hidden = true;
    }
    async function api(path, method = "GET", body) {
        const r = await fetch("/api/" + path, {
            method,
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        if (!r.ok) {
            const e = new Error();
            e.status = r.status;
            throw e;
        }
        return r.json();
    }
    function fail(e) {
        if ([401, 403, 419].includes(e.status)) {
            clear();
            status("expired");
        } else
            status(
                e.status === 409
                    ? "conflict"
                    : e.status === 422
                      ? "invalid"
                      : e.status === 503
                        ? "disabled"
                        : "error",
            );
    }
    async function load() {
        const ticket = ++generation;
        $("programmes").replaceChildren();
        $("rewards").replaceChildren();
        status("loading");
        try {
            const me = await api("me");
            if (ticket !== generation || document.hidden) return;
            if (me.role !== "admin") {
                clear();
                status("expired");
                return;
            }
            const nextActor = String(me.id);
            if (actor && actor !== nextActor) clearDraft();
            actor = nextActor;
            const [data, regions] = await Promise.all([
                api("commercial/referral-programs?page=" + page),
                api("regions"),
            ]);
            if (ticket !== generation || document.hidden) return;
            precisions.clear();
            for (const r of regions) {
                const d = Number(r.minor_units);
                if (!precisions.has(r.currency)) precisions.set(r.currency, d);
                else if (precisions.get(r.currency) !== d)
                    precisions.set(r.currency, null);
            }
            const prior = $("currency").value;
            $("currency").replaceChildren();
            for (const [currency, digits] of precisions)
                if (Number.isInteger(digits)) {
                    const o = el("option", currency);
                    o.value = currency;
                    $("currency").append(o);
                }
            if (precisions.has(prior)) $("currency").value = prior;
            $("workspace").hidden = false;
            last = data.last_page;
            $("page").textContent = `${data.current_page} / ${last}`;
            $("previous").disabled = page <= 1;
            $("next").disabled = page >= last;
            for (const p of data.data) {
                const card = el("article");
                card.append(
                    el("h2", lang === "ar" ? p.name_ar : p.name),
                    el("p", t(p.active ? "active" : "inactive")),
                    el("pre", lang === "ar" ? p.terms_ar : p.terms),
                    el(
                        "bdi",
                        `${p.currency} ${window.EvMoney.format(Number(p.minimum_minor), p.minor_units)}`,
                    ),
                    el(
                        "p",
                        `${t("referrerReward")}: ${lang === "ar" ? p.referrer_reward.name_ar : p.referrer_reward.name}`,
                    ),
                    el(
                        "p",
                        `${t("memberReward")}: ${lang === "ar" ? p.member_reward.name_ar : p.member_reward.name}`,
                    ),
                    el("bdi", `${p.starts_at} — ${p.ends_at}`),
                );
                if (p.revocation) card.append(el("pre", p.revocation.reason));
                else {
                    const reason = el("textarea"),
                        label = el("label", t("reason"));
                    reason.minLength = 5;
                    reason.maxLength = 500;
                    reason.dir = "auto";
                    label.append(reason);
                    card.append(
                        label,
                        button("revoke", () => revoke(p.id, reason.value)),
                    );
                }
                $("programmes").append(card);
            }
            if (!data.data.length) $("programmes").append(el("p", t("empty")));
            status("ready");
        } catch (e) {
            if (ticket === generation) fail(e);
        }
    }
    function choose(role, reward, ticket, owner) {
        if (busy || ticket !== generation || owner !== actor) return;
        const other = role === "referrer" ? "member" : "referrer";
        if (selected[other] && selected[other].currency !== reward.currency) {
            status("invalid");
            return;
        }
        if (!Number.isInteger(precisions.get(reward.currency))) {
            status("invalid");
            return;
        }
        selected[role] = reward;
        const f = $("programme-form");
        f.elements[
            role === "referrer" ? "referrer_reward_id" : "member_reward_id"
        ].value = reward.id;
        $(role + "-choice").textContent =
            lang === "ar" ? reward.name_ar : reward.name;
        $("currency").value = reward.currency;
        const rows = Object.values(selected),
            from = Math.max(
                ...rows.map((r) =>
                    Date.parse(r.starts_at.replace(" ", "T") + "Z"),
                ),
            ),
            until = Math.min(
                ...rows.map((r) =>
                    Date.parse(r.ends_at.replace(" ", "T") + "Z"),
                ),
            );
        f.elements.starts_at.value = new Date(Math.ceil(from / 60000) * 60000)
            .toISOString()
            .slice(0, 16);
        f.elements.ends_at.value = new Date(Math.floor(until / 60000) * 60000)
            .toISOString()
            .slice(0, 16);
        f.elements.minimum.placeholder = window.EvMoney.format(
            0,
            precisions.get(reward.currency),
        );
    }
    async function rewards() {
        if (busy || !actor) return;
        const ticket = generation,
            owner = actor;
        $("rewards").replaceChildren();
        try {
            const d = await api(
                "commercial/loyalty/rewards?page=" + rewardPage,
            );
            if (ticket !== generation || owner !== actor || document.hidden)
                return;
            rewardLast = d.last_page;
            $("reward-page").textContent = `${d.current_page} / ${rewardLast}`;
            $("reward-previous").disabled = rewardPage <= 1;
            $("reward-next").disabled = rewardPage >= rewardLast;
            for (const r of d.data) {
                const card = el("article");
                card.append(
                    el("h3", lang === "ar" ? r.name_ar : r.name),
                    el("p", `${r.currency} · ${r.available_stock}`),
                    button("chooseReferrer", () =>
                        choose("referrer", r, ticket, owner),
                    ),
                    button("chooseMember", () =>
                        choose("member", r, ticket, owner),
                    ),
                );
                $("rewards").append(card);
            }
        } catch (e) {
            if (ticket === generation) fail(e);
        }
    }
    async function revoke(id, reason) {
        if (busy || !actor || reason.trim().length < 5) {
            status("invalid");
            return;
        }
        busy = true;
        const ticket = generation;
        try {
            await api(`commercial/referral-programs/${id}/revoke`, "POST", {
                reason: reason.trim(),
            });
            if (ticket !== generation || document.hidden) return;
            await load();
            status("saved");
        } catch (e) {
            if (ticket === generation) {
                $("programmes").replaceChildren();
                fail(e);
            }
        } finally {
            busy = false;
        }
    }
    $("programme-form").addEventListener("submit", async (e) => {
        e.preventDefault();
        if (busy || !actor) return;
        const f = e.currentTarget,
            body = Object.fromEntries(new FormData(f)),
            digits = precisions.get(body.currency),
            amount = window.EvMoney.parse(body.minimum, digits, 1000000000000);
        if (
            !selected.referrer ||
            !selected.member ||
            amount === null ||
            amount < 1
        ) {
            status("invalid");
            return;
        }
        delete body.minimum;
        body.minimum_minor = amount;
        body.minor_units = digits;
        for (const p of [
            "referrer_reward_id",
            "member_reward_id",
            "claim_limit",
            "per_referrer_limit",
        ])
            body[p] = Number(body[p]);
        for (const p of ["starts_at", "ends_at"]) body[p] += ":00+00:00";
        key ||= crypto.randomUUID();
        body.idempotency_key = key;
        busy = true;
        const ticket = generation;
        f.querySelectorAll("input,textarea,select,button").forEach(
            (n) => (n.disabled = true),
        );
        try {
            await api("commercial/referral-programs", "POST", body);
            if (ticket !== generation || document.hidden) return;
            clearDraft();
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
        }
    });
    $("resolve").addEventListener("click", async () => {
        if (busy || !actor || !key) return;
        busy = true;
        const ticket = generation;
        try {
            const d = await api(
                "commercial/referral-programs/publication-receipt",
                "POST",
                { idempotency_key: key },
            );
            if (ticket !== generation || document.hidden) return;
            if (d.program) {
                clearDraft();
                $("editor").open = false;
                await load();
                status("saved");
            } else status("none");
        } catch (e) {
            if (ticket === generation) fail(e);
        } finally {
            busy = false;
        }
    });
    $("discard").addEventListener("click", () => {
        if (!busy) clearDraft();
    });
    $("refresh").addEventListener("click", () => {
        if (!busy) load();
    });
    $("rewards-refresh").addEventListener("click", () => {
        rewardPage = 1;
        rewards();
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
    $("reward-previous").addEventListener("click", () => {
        if (!busy && rewardPage > 1) {
            rewardPage--;
            rewards();
        }
    });
    $("reward-next").addEventListener("click", () => {
        if (!busy && rewardPage < rewardLast) {
            rewardPage++;
            rewards();
        }
    });
    $("language").addEventListener("change", () => {
        lang = $("language").value;
        try {
            localStorage.setItem("creabiz.locale", lang);
        } catch {}
        translate();
        if (!busy) load();
    });
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) clear();
        else load();
    });
    window.addEventListener("pagehide", clear);
    window.addEventListener("pageshow", (e) => {
        if (e.persisted) {
            clear();
            if (!document.hidden) load();
        }
    });
    translate();
    load();
})();
