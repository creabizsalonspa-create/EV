"use strict";
// Client recovery metadata only. Eligibility and fulfillment are server-owned.
export function mountCustomerCoupons({ container, api, ui, actor }) {
    const owner = actor()?.id,
        role = actor()?.role;
    if (!Number.isSafeInteger(owner) || owner < 1 || role !== "customer")
        return;
    const storageKey = `creabiz.coupon-intent.v1.${owner}`,
        lifecycle = new AbortController();
    let generation = 0,
        busy = false,
        ready = false,
        foreground = !document.hidden,
        pending = null,
        active = null,
        page = 1,
        last = 1;
    const alive = () =>
        container.isConnected &&
        actor()?.id === owner &&
        actor()?.role === role;
    const current = (run) => alive() && foreground && run === generation;
    const n = (tag, value = "") => {
        const e = document.createElement(tag);
        e.textContent = String(value);
        return e;
    };
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const status = n("p"),
        records = n("div"),
        controls = n("div");
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");
    const label = t("label", "Coupon code", "رمز القسيمة"),
        code = document.createElement("input");
    code.maxLength = 40;
    code.autocomplete = "off";
    code.spellcheck = false;
    code.autocapitalize = "characters";
    code.dir = "ltr";
    code.dataset.coupon = "code";
    label.append(code);
    const buttons = [];
    function button(key, en, ar, action) {
        const b = t("button", en, ar);
        b.type = "button";
        b.dataset.coupon = key;
        b.onclick = () => run(action);
        controls.append(b);
        buttons.push(b);
        return b;
    }
    function message(en, ar) {
        status.replaceChildren(t("span", en, ar));
    }
    function disabled() {
        container.setAttribute("aria-busy", String(busy));
        const blocked = busy || !alive() || !foreground;
        for (const b of buttons) b.disabled = blocked;
        code.disabled = blocked || !ready;
        claim.disabled = blocked || !ready || !navigator.locks?.request;
        inspect.disabled =
            blocked || !ready || !pending || !navigator.locks?.request;
        retire.disabled =
            blocked || !ready || !pending || !navigator.locks?.request;
        previous.disabled = blocked || !ready || page <= 1;
        next.disabled = blocked || !ready || page >= last;
    }
    function read() {
        const raw = localStorage.getItem(storageKey);
        if (raw === null) return null;
        if (raw.length > 4096) throw Error("Invalid saved intent");
        const v = JSON.parse(raw);
        if (
            !v ||
            Object.keys(v).sort().join(",") !== "binding,key,owner,version" ||
            v.version !== 1 ||
            v.owner !== owner ||
            typeof v.key !== "string" ||
            !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(
                v.key,
            ) ||
            typeof v.binding !== "string" ||
            !/^[a-f0-9]{64}$/.test(v.binding)
        )
            throw Error("Invalid saved intent");
        return v;
    }
    const lock = (action) => {
        if (!navigator.locks?.request)
            throw Error("Private recovery lock unavailable");
        return navigator.locks.request(storageKey, action);
    };
    async function digest(value) {
        return [
            ...new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(value),
                ),
            ),
        ]
            .map((v) => v.toString(16).padStart(2, "0"))
            .join("");
    }
    const binding = async (value, key) =>
        digest(
            `${await digest(value)}:${await digest(`coupon-claim:${owner}:${key}`)}`,
        );
    function receipt(v, expected) {
        const id = (v) => Number.isSafeInteger(v) && v > 0;
        if (
            !v ||
            !["id", "coupon_id", "redemption_id"].every((k) => id(v[k])) ||
            !["reward_name", "reward_name_ar"].every(
                (k) =>
                    typeof v[k] === "string" &&
                    v[k].length > 0 &&
                    [...v[k]].length <= 300,
            ) ||
            !["pending", "fulfilled", "rejected"].includes(v.status) ||
            v.points_cost !== 0 ||
            v.cash_value !== false ||
            v.fulfillment !== "MANUAL_NON_MONETARY" ||
            v.source !== "COUPON" ||
            v.evidence !== "TEST_PROMOTIONAL_ECONOMICS" ||
            v.delivery_verified !== false ||
            typeof v.claimed_at !== "string" ||
            !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(
                v.claimed_at,
            ) ||
            !Number.isFinite(Date.parse(v.claimed_at)) ||
            new Date(v.claimed_at).toISOString().slice(0, 19) !==
                v.claimed_at.slice(0, 19) ||
            typeof v.request_binding !== "string" ||
            !/^[a-f0-9]{64}$/.test(v.request_binding) ||
            (expected && v.request_binding !== expected)
        )
            throw Error("Invalid coupon receipt");
        return {
            id: v.id,
            name: v.reward_name,
            nameAr: v.reward_name_ar,
            status: v.status,
            time: v.claimed_at,
        };
    }
    function draw(rows) {
        records.replaceChildren();
        for (const row of rows) {
            const article = n("article"),
                name = n("p"),
                proof = n("bdi", `#${row.id} · ${row.time}`);
            name.dataset.en = row.name;
            name.dataset.ar = row.nameAr;
            name.textContent =
                document.documentElement.lang === "ar" ? row.nameAr : row.name;
            proof.dir = "ltr";
            article.append(
                name,
                proof,
                t(
                    "p",
                    ...{
                        pending: [
                            "Pending manual fulfillment",
                            "بانتظار التسليم اليدوي",
                        ],
                        fulfilled: [
                            "Fulfilled record; delivery unverified",
                            "سجل تسليم؛ التسليم غير متحقق",
                        ],
                        rejected: ["Rejected", "مرفوض"],
                    }[row.status],
                ),
            );
            records.append(article);
        }
    }
    async function request(path, method = "GET", body) {
        if (!alive() || !foreground)
            throw new DOMException("Session changed", "AbortError");
        return api("commercial/coupons/" + path, method, body, active?.signal);
    }
    async function history(number, run) {
        if (!Number.isSafeInteger(number) || number < 1 || number > 100000)
            throw Error("Invalid coupon page");
        const result = await request(`claims?page=${number}`);
        if (!current(run)) return;
        if (
            !Array.isArray(result?.data) ||
            result.data.length > 25 ||
            result.current_page !== number ||
            result.per_page !== 25 ||
            !Number.isSafeInteger(result.total) ||
            result.total < 0 ||
            result.total > 2500000 ||
            result.last_page !==
                (result.total === 0
                    ? 1
                    : Math.floor((result.total - 1) / 25) + 1) ||
            result.data.length > result.total ||
            (number > result.last_page && result.data.length)
        )
            throw Error("Invalid coupon history");
        const rows = result.data.map((r) => receipt(r));
        if (new Set(rows.map((r) => r.id)).size !== rows.length)
            throw Error("Duplicate coupon receipt");
        page = number;
        last = result.last_page;
        draw(rows);
    }
    async function restore(run) {
        ready = false;
        pending = null;
        code.value = "";
        records.replaceChildren();
        const me = await api("me", "GET", undefined, active?.signal);
        if (!current(run)) return;
        if (me?.id !== owner || me.role !== role)
            throw new DOMException("Session changed", "AbortError");
        pending = read();
        await history(1, run);
        if (!current(run)) return;
        ready = true;
        if (pending)
            message(
                "A saved claim awaits confirmation. Re-enter its original code to resend or retire it; checking its receipt does not require the code.",
                "مطالبة محفوظة تنتظر التأكيد. أعد إدخال رمزها الأصلي لإرسالها أو إنهائها؛ لا يتطلب التحقق من الإيصال إدخال الرمز.",
            );
        else if (!navigator.locks?.request)
            message(
                "Private recovery locking is unavailable. Only coupon history is available in this browser.",
                "قفل الاستعادة الخاصة غير متاح. يتاح سجل القسائم فقط في هذا المتصفح.",
            );
        else
            message(
                "Coupons restored. No claim was automatically sent.",
                "استُعيدت القسائم. لم تُرسل أي مطالبة تلقائياً.",
            );
    }
    async function acknowledge(saved, row, run) {
        await lock(() => {
            if (!current(run)) return;
            const latest = read();
            if (latest?.key === saved.key && latest.binding === saved.binding)
                localStorage.removeItem(storageKey);
        });
        if (!current(run)) return;
        pending = null;
        code.value = "";
        page = last = 1;
        draw(row ? [row] : []);
    }
    async function submit(run, kind) {
        if (!ready) return;
        let saved = pending;
        if (kind !== "claim" && !saved) return;
        const entered = code.value.trim().toUpperCase();
        if (kind !== "claim-receipt" && !/^[A-Z0-9-]{12,40}$/.test(entered))
            throw Error("Invalid coupon code");
        if (kind === "claim") {
            saved = await lock(async () => {
                if (!current(run)) return null;
                const latest = read(),
                    key = latest?.key || crypto.randomUUID(),
                    proof = await binding(entered, key);
                if (!current(run)) return null;
                if (latest && latest.binding !== proof)
                    throw Error("Resolve original coupon request");
                const value = latest || {
                    version: 1,
                    owner,
                    key,
                    binding: proof,
                };
                localStorage.setItem(storageKey, JSON.stringify(value));
                return value;
            });
            if (!saved || !current(run)) return;
            pending = saved;
        } else if (kind === "cancel") {
            if ((await binding(entered, saved.key)) !== saved.binding)
                throw Error("Original code required");
            if (!current(run)) return;
            if (
                !window.confirm(
                    document.documentElement.lang === "ar"
                        ? "إنهاء هذا الطلب غير المقبول نهائياً؟ إذا قُبل بالفعل، يُستعاد إيصال المكافأة الموجود بدلاً من إلغائه."
                        : "Permanently retire this exact unaccepted request? If already accepted, recover its existing reward receipt instead.",
                )
            )
                return;
        }
        if (!current(run)) return;
        const result = await request(
            kind === "cancel" ? "claim-intents/cancel" : kind,
            "POST",
            kind === "claim-receipt"
                ? { idempotency_key: saved.key }
                : { code: entered, idempotency_key: saved.key },
        );
        if (!current(run)) return;
        if (!result || !Object.hasOwn(result, "claim"))
            throw Error("Unconfirmed coupon result");
        if (kind === "cancel") {
            if (
                !["accepted", "cancelled"].includes(result.state) ||
                result.request_binding !== saved.binding ||
                (result.state === "cancelled" && result.claim !== null)
            )
                throw Error("Unconfirmed cancellation fence");
            const row =
                result.state === "accepted"
                    ? receipt(result.claim, saved.binding)
                    : null;
            await acknowledge(saved, row, run);
            if (!current(run)) return;
            if (row)
                message(
                    "Existing claim recovered. Manual fulfillment remains separate; delivery is not verified.",
                    "استُعيدت المطالبة الموجودة. يبقى التسليم اليدوي منفصلاً؛ لم يُتحقق من التسليم.",
                );
            else
                message(
                    "Server permanently retired the unaccepted request. A new coupon draft may now be entered.",
                    "أنهى الخادم الطلب غير المقبول نهائياً. يمكن الآن إدخال مسودة قسيمة جديدة.",
                );
        } else if (result.claim === null) {
            message(
                "No receipt is recorded yet. Keep the original saved request; a lookup does not cancel a request in flight.",
                "لم يُسجل إيصال بعد. احتفظ بالطلب الأصلي المحفوظ؛ لا يلغي الاستعلام طلباً قيد التنفيذ.",
            );
        } else {
            const row = receipt(result.claim, saved.binding);
            await acknowledge(saved, row, run);
            if (current(run))
                message(
                    "Claim recorded. This is a noncash entitlement awaiting separate manual fulfillment, not verified delivery.",
                    "سُجلت المطالبة. هذا استحقاق غير نقدي ينتظر تسليماً يدوياً منفصلاً، وليس تسليماً متحققاً.",
                );
        }
    }
    async function run(action) {
        if (busy || !alive() || !foreground) return;
        const ticket = ++generation;
        active?.abort();
        active = new AbortController();
        busy = true;
        records.replaceChildren();
        disabled();
        try {
            await action(ticket);
        } catch (error) {
            if (current(ticket)) {
                ready = false;
                records.replaceChildren();
                message(
                    "Outcome unconfirmed or coupons unavailable. Restore the original saved request before explicitly retrying. Its identity cannot be replaced.",
                    "النتيجة غير مؤكدة أو القسائم غير متاحة. استعد الطلب الأصلي المحفوظ قبل إعادة المحاولة صراحةً. لا يمكن استبدال معرفه.",
                );
            }
        } finally {
            if (current(ticket)) {
                busy = false;
                disabled();
            }
        }
    }
    container.dataset.customerCoupons = "true";
    container.append(
        t("h4", "Reward coupons", "قسائم المكافآت"),
        t(
            "p",
            "Test noncash reward coupons. No cash value, transfer, charging discount or verified delivery. The server owns eligibility, stock and manual fulfillment.",
            "قسائم مكافآت تجريبية غير نقدية. لا قيمة نقدية ولا تحويل ولا خصم شحن ولا تسليم متحقق. الخادم مسؤول عن الأهلية والمخزون والتسليم اليدوي.",
        ),
        label,
        status,
        controls,
        records,
    );
    button(
        "restore",
        "Restore and refresh coupons",
        "استعادة القسائم وتحديثها",
        restore,
    );
    const claim = button(
        "claim",
        "Claim or resume original coupon",
        "المطالبة بالقسيمة الأصلية أو استئنافها",
        (run) => submit(run, "claim"),
    );
    const inspect = button(
        "inspect",
        "Check saved claim receipt",
        "التحقق من إيصال المطالبة المحفوظة",
        (run) => submit(run, "claim-receipt"),
    );
    const retire = button(
        "retire",
        "Resolve saved request",
        "حل الطلب المحفوظ",
        (run) => submit(run, "cancel"),
    );
    const previous = button(
        "previous",
        "Previous coupon page",
        "صفحة القسائم السابقة",
        (run) => history(page - 1, run),
    );
    const next = button(
        "next",
        "Next coupon page",
        "صفحة القسائم التالية",
        (run) => history(page + 1, run),
    );
    function clear() {
        generation++;
        active?.abort();
        pending = null;
        ready = false;
        busy = false;
        code.value = "";
        records.replaceChildren();
    }
    document.addEventListener(
        "visibilitychange",
        () => {
            foreground = !document.hidden;
            clear();
            message(
                "Private coupon details cleared. Explicitly restore before continuing.",
                "مُسحت تفاصيل القسائم الخاصة. استعدها صراحةً قبل المتابعة.",
            );
            disabled();
        },
        { signal: lifecycle.signal },
    );
    window.addEventListener(
        "storage",
        (event) => {
            if (event.key === storageKey && alive()) {
                clear();
                message(
                    "Coupon recovery changed in another tab. Restore before continuing.",
                    "تغيرت استعادة القسائم في علامة تبويب أخرى. استعدها قبل المتابعة.",
                );
                disabled();
            }
        },
        { signal: lifecycle.signal },
    );
    const observer = new MutationObserver(() => {
        if (!alive()) {
            clear();
            observer.disconnect();
            lifecycle.abort();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    disabled();
    return () => {
        clear();
        observer.disconnect();
        lifecycle.abort();
    };
}
