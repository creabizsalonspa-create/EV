(() => {
    const $ = (id) => document.getElementById(id);
    const messages = {
        sandbox: ["Sandbox record", "سجل اختبار"],
        reviewed_manual: [
            "Reviewed manual noncash configuration",
            "تهيئة يدوية غير نقدية خاضعة للمراجعة",
        ],
        home: ["Workspace", "مساحة العمل"],
        title: ["Noncash reward coupons", "قسائم المكافآت غير النقدية"],
        notice: [
            "Coupons reserve an existing manual noncash reward. They never discount a charging invoice, move money or prove delivery. Commercial activation requires review.",
            "تحجز القسائم مكافأة يدوية غير نقدية قائمة. لا تخصم من فاتورة الشحن ولا تحوّل الأموال ولا تثبت التسليم. يتطلب التفعيل التجاري مراجعة.",
        ],
        catalog: ["Published coupons", "القسائم المنشورة"],
        refresh: ["Refresh", "تحديث"],
        previous: ["Previous", "السابق"],
        next: ["Next", "التالي"],
        new: ["Publish a coupon", "نشر قسيمة"],
        privateCode: [
            "Distribute your code privately. The server retains only its hash; it cannot display the code again. Existing claims survive revocation and remain pending manual fulfillment.",
            "وزّع الرمز بشكل خاص. يحتفظ الخادم ببصمته فقط ولا يستطيع عرضه مجددًا. تظل المطالبات القائمة معلقة للتنفيذ اليدوي بعد الإلغاء.",
        ],
        rewardsRefresh: [
            "Load canonical reward choices",
            "تحميل خيارات المكافآت المعتمدة",
        ],
        reward: ["Reward ID", "معرّف المكافأة"],
        code: [
            "Coupon code (12–40 uppercase letters, digits or hyphens)",
            "رمز القسيمة (١٢–٤٠ حرفًا لاتينيًا كبيرًا أو رقمًا أو شرطة)",
        ],
        limit: ["Total claim limit", "الحد الإجمالي للمطالبات"],
        perUser: ["Claims per member", "المطالبات لكل عضو"],
        starts: ["Starts at (UTC)", "البدء (التوقيت العالمي)"],
        ends: ["Ends at (UTC)", "الانتهاء (التوقيت العالمي)"],
        publish: ["Publish coupon", "نشر القسيمة"],
        resolve: ["Find saved publication", "البحث عن النشر المحفوظ"],
        discard: ["Clear private draft", "مسح المسودة الخاصة"],
        loading: ["Loading current records…", "جارٍ تحميل السجلات الحالية…"],
        ready: ["Current records loaded.", "تم تحميل السجلات الحالية."],
        empty: ["No published coupons.", "لا توجد قسائم منشورة."],
        choose: ["Choose this reward", "اختيار هذه المكافأة"],
        reason: [
            "Reason for stopping new claims",
            "سبب إيقاف المطالبات الجديدة",
        ],
        revoke: ["Stop new claims", "إيقاف المطالبات الجديدة"],
        revoked: ["Revoked", "ملغاة"],
        active: [
            "Not revoked — dates and policy still apply",
            "غير ملغاة — تظل التواريخ والسياسة سارية",
        ],
        claims: ["Claims committed", "المطالبات المسجلة"],
        saved: [
            "Saved. No money or delivery was performed.",
            "تم الحفظ. لم يتم تحويل أموال أو تسليم مكافأة.",
        ],
        none: [
            "No saved publication found for this request. Retry only with the retained unchanged draft.",
            "لم يُعثر على نشر محفوظ لهذا الطلب. أعد المحاولة بالمسودة الأصلية غير المعدلة فقط.",
        ],
        error: [
            "Request failed. Use Find saved publication before retrying an uncertain publication. No action was retried automatically.",
            "تعذر الطلب. استخدم البحث عن النشر المحفوظ قبل إعادة محاولة طلب غير مؤكد. لم تُعد أي عملية تلقائيًا.",
        ],
        invalid: [
            "Check the fields and reward date range.",
            "تحقق من الحقول والفترة الزمنية للمكافأة.",
        ],
        conflict: [
            "The request changed or is unavailable. Refresh before deciding again.",
            "تغيّر الطلب أو أصبح غير متاح. حدّث البيانات قبل اتخاذ قرار آخر.",
        ],
        expired: [
            "Session unavailable. Return to the workspace to sign in.",
            "الجلسة غير متاحة. عُد لمساحة العمل لتسجيل الدخول.",
        ],
        disabled: [
            "Noncash rewards are disabled. No coupon was published or claimed.",
            "المكافآت غير النقدية معطلة. لم تُنشر أو تُطالب أي قسيمة.",
        ],
    };
    let lang = "en";
    try {
        if (localStorage.getItem("creabiz.locale") === "ar") lang = "ar";
    } catch {}
    const t = (k) => messages[k]?.[lang === "ar" ? 1 : 0] || k,
        el = (tag, text) => {
            const n = document.createElement(tag);
            if (text !== undefined) n.textContent = text;
            return n;
        };
    let generation = 0,
        busy = false,
        actor = null,
        page = 1,
        last = 1,
        rewardPage = 1,
        rewardLast = 1,
        key = null;
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
    function clear() {
        generation++;
        actor = null;
        key = null;
        $("coupon-form").reset();
        $("coupons").replaceChildren();
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
        $("coupons").replaceChildren();
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
            const current = `${me.id}:${me.auth_epoch}`;
            if (actor && actor !== current) {
                $("coupon-form").reset();
                key = null;
            }
            actor = current;
            const data = await api("commercial/coupons?page=" + page);
            if (ticket !== generation || document.hidden) return;
            $("workspace").hidden = false;
            last = data.last_page;
            $("page").textContent = `${data.current_page} / ${last}`;
            $("previous").disabled = page <= 1;
            $("next").disabled = page >= last;
            for (const c of data.data) {
                const card = el("article");
                card.append(
                    el("p", t(c.activation_mode || "sandbox")),
                    el("h3", `${t("reward")} ${c.reward_id} · #${c.id}`),
                    el("p", t(c.revoked_at ? "revoked" : "active")),
                    el("p", `${t("claims")}: ${c.claims} / ${c.claim_limit}`),
                    el("bdi", `${c.starts_at} — ${c.ends_at}`),
                );
                if (c.revocation) card.append(el("pre", c.revocation.reason));
                if (!c.revoked_at) {
                    const label = el("label", t("reason")),
                        reason = el("textarea");
                    reason.minLength = 5;
                    reason.maxLength = 500;
                    reason.dir = "auto";
                    label.append(reason);
                    card.append(
                        label,
                        button("revoke", () => revoke(c.id, reason.value)),
                    );
                }
                $("coupons").append(card);
            }
            if (!data.data.length) $("coupons").append(el("p", t("empty")));
            status("ready");
        } catch (e) {
            if (ticket === generation) fail(e);
        }
    }
    async function rewards() {
        if (busy || !actor) return;
        const ticket = generation,
            owner = actor;
        $("rewards").replaceChildren();
        status("loading");
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
                    el("bdi", `${r.starts_at} — ${r.ends_at}`),
                    button("choose", () => {
                        if (ticket !== generation || owner !== actor || busy)
                            return;
                        const f = $("coupon-form");
                        f.elements.reward_id.value = r.id;
                        // Minute controls must remain inside second-precision
                        // immutable reward bounds, never round the start down.
                        const from = Date.parse(
                            r.starts_at.replace(" ", "T") + "Z",
                        );
                        const until = Date.parse(
                            r.ends_at.replace(" ", "T") + "Z",
                        );
                        f.elements.starts_at.value = new Date(
                            Math.ceil(from / 60000) * 60000,
                        )
                            .toISOString()
                            .slice(0, 16);
                        f.elements.ends_at.value = new Date(
                            Math.floor(until / 60000) * 60000,
                        )
                            .toISOString()
                            .slice(0, 16);
                        f.elements.code.focus();
                    }),
                );
                $("rewards").append(card);
            }
            status("ready");
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
            await api(`commercial/coupons/${id}/revoke`, "POST", {
                reason: reason.trim(),
            });
            if (ticket !== generation || document.hidden) return;
            await load();
            status("saved");
        } catch (e) {
            if (ticket === generation) {
                $("coupons").replaceChildren();
                fail(e);
            }
        } finally {
            busy = false;
        }
    }
    $("coupon-form").addEventListener("submit", async (e) => {
        e.preventDefault();
        if (busy || !actor) return;
        const f = e.currentTarget,
            body = Object.fromEntries(new FormData(f));
        for (const p of ["reward_id", "claim_limit", "per_user_limit"])
            body[p] = Number(body[p]);
        for (const p of ["starts_at", "ends_at"]) body[p] += ":00+00:00";
        key ||= crypto.randomUUID();
        body.idempotency_key = key;
        busy = true;
        const ticket = generation;
        f.querySelectorAll("input,button").forEach((n) => (n.disabled = true));
        try {
            await api("commercial/coupons", "POST", body);
            if (ticket !== generation || document.hidden) return;
            f.reset();
            key = null;
            $("editor").open = false;
            await load();
            status("saved");
        } catch (e) {
            if (ticket === generation) fail(e);
        } finally {
            busy = false;
            f.querySelectorAll("input,button").forEach(
                (n) => (n.disabled = false),
            );
        }
    });
    $("resolve").addEventListener("click", async () => {
        if (busy || !actor || !key) return;
        busy = true;
        const ticket = generation;
        try {
            const data = await api(
                "commercial/coupons/publication-receipt",
                "POST",
                { idempotency_key: key },
            );
            if (ticket !== generation || document.hidden) return;
            if (data.coupon) {
                $("coupon-form").reset();
                key = null;
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
        if (!busy) {
            $("coupon-form").reset();
            key = null;
        }
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
