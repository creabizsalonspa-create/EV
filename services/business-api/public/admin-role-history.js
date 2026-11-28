"use strict";

// Read-only provenance from the existing operator_role_changes ledger.
// A ticket reference is NOT proof of the shell operator's identity.
window.EvAdminOperatorRoleHistory = (() => {
    const root = document.getElementById("admin-role-history");
    let generation = 0;
    let controller = null;
    let request = null;
    let target = null;
    let role = null;
    let rows = null;
    let status = null;
    let cursor = null;
    let more = false;
    let filters = null;
    const lang = () => document.documentElement.lang === "ar" ? "ar" : "en";
    function localized(tag,en,ar) {
        const n = node(tag);
        n.dataset.en = en; n.dataset.ar = ar;
        n.textContent = lang() === "ar" ? ar : en;
        return n;
    }
    function message(en,ar) {
        status.dataset.en = en; status.dataset.ar = ar;
        status.textContent = lang() === "ar" ? ar : en;
    }
    const roleLabel = value => ({customer:"عميل",vendor:"مورد",admin:"مسؤول"})[value] || value;
    function clock(raw) {
        const label = language => window.EvStationTime?.serverEventTime(raw,language)
            || (language === "ar" ? "وقت الحدث غير متاح" : "Event time unavailable");
        return localized("p","Timestamp (UTC): " + label("en"),"وقت الحدث (UTC): " + label("ar"));
    }

    function node(tag, value) {
        const e = document.createElement(tag);
        if (value !== undefined) e.textContent = String(value);
        return e;
    }
    function button(en, ar, action) {
        const b = localized("button", en, ar);
        b.type = "button";
        b.onclick = action;
        return b;
    }
    function field(en, ar, input) {
        const wrapper = node("label");
        wrapper.append(localized("span",en,ar), input);
        return wrapper;
    }
    function current(epoch, active) {
        return generation === epoch && request !== null &&
            controller === active && !active.signal.aborted;
    }
    function clear() {
        generation++;
        controller?.abort();
        controller = null;
        request = null;
        filters = null; cursor = null; more = false;
        root.replaceChildren();
    }
    async function load(reset = true, retryApplied = false) {
        if (!request || (!reset && (!more || cursor === null))) return;
        controller?.abort();
        const active = new AbortController();
        controller = active;
        const epoch = generation;
        if (reset && !retryApplied) {
            const raw = target.value.trim();
            const id = Number(raw);
            if (raw && (!/^\d+$/.test(raw) || !Number.isSafeInteger(id) || id < 1)) {
                filters = null; cursor = null; more = false;
                rows.replaceChildren();
                message("Enter a valid positive account ID.","أدخل معرف حساب صحيحاً موجباً.");
                return;
            }
            filters = {target:raw ? String(id) : "", role:role.value};
        }
        if (!filters) return;
        const q = new URLSearchParams({limit: "25"});
        if (filters.target) q.set("target_user_id", filters.target);
        if (filters.role) q.set("assigned_role", filters.role);
        const before = reset ? null : cursor;
        if (before !== null) q.set("before_id", String(before));
        if (reset) {
            cursor = null;
            more = false;
            rows.replaceChildren();
        }
        // Remove old navigation/retry controls at dispatch, including if the
        // next read fails. Never accumulate dead private-history callbacks.
        for (const child of Array.from(rows.children))
            if (child.getAttribute?.("data-role-more") === "true") child.remove();
        message("Loading privileged role changes…","جارٍ تحميل تغييرات الأدوار ذات الصلاحيات…");
        try {
            const data = await request("admin/operator-role-changes?" + q.toString(),
                "GET", undefined, active.signal);
            if (!current(epoch, active)) return;

            for (const child of Array.from(rows.children)) {
                if (child.getAttribute?.("data-role-more") === "true") {
                    child.remove();
                }
            }
            if (!Array.isArray(data?.events) || data.events.length > 25 ||
                data.limit !== 25 || data.operator_identity_available !== false ||
                typeof data.has_more !== "boolean") throw Error("Invalid role history");
            const events = data.events;
            let last = before ?? Infinity;
            for (const event of events) {
                if (!event || !Number.isSafeInteger(event.id) || event.id <= 0 ||
                    event.id >= last || !Number.isSafeInteger(event.target_user_id) ||
                    event.target_user_id <= 0 ||
                    (filters.target && String(event.target_user_id) !== filters.target) ||
                    (filters.role && event.assigned_role !== filters.role)) throw Error("Invalid role scope");
                last = event.id;
            }
            if (data.has_more ? !events.length || data.next_before_id !== last
                : data.next_before_id !== null) throw Error("Invalid role cursor");
            for (const event of events) {
                const item = node("article");
                item.append(
                    localized("h4", "Account #" + event.target_user_id + " · " + (event.target_name || "Unknown"),
                        "الحساب رقم " + event.target_user_id + " · " + (event.target_name || "غير معروف")),
                    localized("p", (event.target_email || "") + " · " + event.previous_role + " → " + event.assigned_role,
                        (event.target_email || "") + " · " + roleLabel(event.previous_role) + " ← " + roleLabel(event.assigned_role)),
                    localized("p", "Source: " + event.source + " · Ticket: " + (event.ticket_reference || "not provided"),
                        "المصدر: " + event.source + " · التذكرة: " + (event.ticket_reference || "غير متوفرة")),
                    clock(event.created_at)
                );
                rows.append(item);
            }
            cursor = Number.isSafeInteger(data.next_before_id)
                ? data.next_before_id : null;
            more = data.has_more === true && cursor !== null;
            if (more) {
                const next = button("Load older role changes", "تحميل تغييرات الأدوار الأقدم", () => {
                    if (current(epoch,active) && next.isConnected) return load(false);
                });
                next.setAttribute("data-role-more", "true");
                rows.append(next);
            }
            if (!rows.children.length) rows.append(localized("p", "No role changes match these filters.","لا توجد تغييرات أدوار تطابق هذه المرشحات."));
            message(events.length + " role changes loaded; shell operator identity must be verified from privileged host logs.",
                events.length + " تغييرات أدوار محملة؛ يجب التحقق من هوية مشغل النظام من سجلات المضيف ذات الصلاحيات.");
        } catch {
            if (current(epoch, active)) {
                message("Role change history unavailable.","سجل تغييرات الأدوار غير متاح.");
                const retry = button("Retry", "إعادة المحاولة", () => {
                    if (current(epoch,active) && retry.isConnected) return load(reset,true);
                });
                retry.setAttribute("data-role-more", "true");
                rows.append(retry);
            }
        }
    }
    function setActor(user, api) {
        clear();
        if (user?.role !== "admin") return;
        request = api;
        const form = node("form");
        form.setAttribute("aria-labelledby", "operator-role-history-title");
        target = node("input");
        target.type = "number";
        target.min = 1;
        target.step = 1;
        role = node("select");
        for (const [value, en, ar] of [
            ["", "All assigned roles", "جميع الأدوار المعينة"], ["customer", "Customer", "عميل"],
            ["vendor", "Vendor", "مورد"], ["admin", "Admin", "مسؤول"]
        ]) {
            const option = localized("option", en, ar);
            option.value = value;
            role.append(option);
        }
        const search = button("Search role history", "بحث سجل الأدوار", () => {});
        search.type = "submit";
        form.append(field("Account ID (optional)", "معرف الحساب (اختياري)", target),
            field("Assigned role", "الدور المعين", role), search);
        const formEpoch = generation;
        form.onsubmit = event => {
            event.preventDefault();
            if (generation === formEpoch && form.isConnected && request) load(true);
        };
        status = localized("p", "CLI role history is read-only. Ticket references are not operator identities.",
            "سجل أدوار واجهة الأوامر للقراءة فقط. مراجع التذاكر لا تثبت هوية المشغل.");
        status.setAttribute("role", "status");
        rows = node("div");
        root.append(form, status, rows);
        load(true);
    }
    return {setActor, clear};
})();
