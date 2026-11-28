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
    function button(label, action) {
        const b = node("button", label);
        b.type = "button";
        b.onclick = action;
        return b;
    }
    function field(label, input) {
        const wrapper = node("label");
        wrapper.append(node("span", label), input);
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
    async function load(reset = true) {
        if (!request || (!reset && (!more || cursor === null))) return;
        controller?.abort();
        const active = new AbortController();
        controller = active;
        const epoch = generation;
        if (reset) filters = {target:target.value.trim(), role:role.value};
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
                    node("h4", "Account #" + event.target_user_id + " · " +
                        (event.target_name || "Unknown")),
                    node("p", (event.target_email || "") + " · " +
                        event.previous_role + " → " + event.assigned_role),
                    node("p", "Source: " + event.source +
                        " · Ticket: " + (event.ticket_reference || "not provided")),
                    clock(event.created_at)
                );
                rows.append(item);
            }
            cursor = Number.isSafeInteger(data.next_before_id)
                ? data.next_before_id : null;
            more = data.has_more === true && cursor !== null;
            if (more) {
                const next = button("Load older role changes / أحداث أقدم", () => {
                    if (current(epoch,active) && next.isConnected) return load(false);
                });
                next.setAttribute("data-role-more", "true");
                rows.append(next);
            }
            if (!rows.children.length) rows.append(node("p", "No role changes match these filters."));
            message(events.length + " role changes loaded; shell operator identity must be verified from privileged host logs.",
                events.length + " تغييرات أدوار محملة؛ يجب التحقق من هوية مشغل النظام من سجلات المضيف ذات الصلاحيات.");
        } catch {
            if (current(epoch, active)) {
                message("Role change history unavailable.","سجل تغييرات الأدوار غير متاح.");
                const retry = button("Retry / إعادة المحاولة", () => {
                    if (current(epoch,active) && retry.isConnected) return load(reset);
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
        form.setAttribute("aria-label", "Operator role history filters");
        target = node("input");
        target.type = "number";
        target.min = 1;
        target.setAttribute("aria-label", "Target account ID");
        role = node("select");
        role.setAttribute("aria-label", "Assigned role");
        for (const [value, title] of [
            ["", "All assigned roles"], ["customer", "Customer"],
            ["vendor", "Vendor"], ["admin", "Admin"]
        ]) {
            const option = node("option", title);
            option.value = value;
            role.append(option);
        }
        const search = button("Search role history / بحث", () => {});
        search.type = "submit";
        form.append(field("Account ID (optional)", target),
            field("Assigned role", role), search);
        const formEpoch = generation;
        form.onsubmit = event => {
            event.preventDefault();
            if (generation === formEpoch && form.isConnected && request) load(true);
        };
        status = node("p", "CLI role history is read-only. Ticket references are not operator identities.");
        status.setAttribute("role", "status");
        rows = node("div");
        root.append(form, status, rows);
        load(true);
    }
    return {setActor, clear};
})();
