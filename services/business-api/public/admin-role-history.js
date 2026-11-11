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
        root.replaceChildren();
    }
    async function load(reset = true) {
        if (!request || (!reset && (!more || cursor === null))) return;
        controller?.abort();
        const active = new AbortController();
        controller = active;
        const epoch = generation;
        const q = new URLSearchParams({limit: "25"});
        if (target.value.trim()) q.set("target_user_id", target.value.trim());
        if (role.value) q.set("assigned_role", role.value);
        if (!reset) q.set("before_id", String(cursor));
        if (reset) {
            cursor = null;
            more = false;
            rows.replaceChildren();
        }
        status.textContent = "Loading privileged role changes…";
        try {
            const data = await request("admin/operator-role-changes?" + q.toString(),
                "GET", undefined, active.signal);
            if (!current(epoch, active)) return;

            for (const child of Array.from(rows.children)) {
                if (child.getAttribute?.("data-role-more") === "true") {
                    child.remove();
                }
            }
            const events = Array.isArray(data.events) ? data.events : [];
            for (const event of events) {
                const item = node("article");
                item.append(
                    node("h4", "Account #" + event.target_user_id + " · " +
                        (event.target_name || "Unknown")),
                    node("p", (event.target_email || "") + " · " +
                        event.previous_role + " → " + event.assigned_role),
                    node("p", "Source: " + event.source +
                        " · Ticket: " + (event.ticket_reference || "not provided")),
                    node("p", "Timestamp (UTC): " + event.created_at)
                );
                rows.append(item);
            }
            cursor = Number.isSafeInteger(data.next_before_id)
                ? data.next_before_id : null;
            more = data.has_more === true && cursor !== null;
            if (more) {
                const next = button("Load older role changes / أحداث أقدم", () => load(false));
                next.setAttribute("data-role-more", "true");
                rows.append(next);
            }
            if (!rows.children.length) rows.append(node("p", "No role changes match these filters."));
            status.textContent = events.length + " role changes loaded; " +
                "shell operator identity must be verified from privileged host logs.";
        } catch {
            if (current(epoch, active)) {
                status.textContent = "Role change history unavailable.";
                const retry = button("Retry / إعادة المحاولة", () => load(reset));
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
        form.onsubmit = event => { event.preventDefault(); load(true); };
        status = node("p", "CLI role history is read-only. Ticket references are not operator identities.");
        status.setAttribute("role", "status");
        rows = node("div");
        root.append(form, status, rows);
        load(true);
    }
    return {setActor, clear};
})();
